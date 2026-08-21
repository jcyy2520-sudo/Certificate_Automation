<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Webinar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ParticipantMagicLinkService
{
    public const PURPOSE = 'form_access';

    public const DELIVERY_TYPE = 'participant_form_access';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Issue a short-lived credential and queue its delivery.
     *
     * This method intentionally returns nothing: callers must produce exactly
     * the same response for a new, existing, erased, or otherwise unavailable
     * identity. The raw credential exists only long enough to build the queued,
     * encrypted email payload; the lookup table receives only a keyed digest.
     */
    public function request(Form $form, string $email): void
    {
        $email = Str::lower(trim($email));
        $participant = $this->findOrCreateParticipant($form, $email);

        if (! $participant) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->tokenLifetimeMinutes());

        // The fragment is never included in the HTTP request made by an email
        // client, link scanner, reverse proxy, or web-server access log.
        $confirmationUrl = route('forms.public.access.confirm', $form->public_token)
            .'#token='.$rawToken;
        $html = view('emails.participant-form-access', [
            'confirmationUrl' => $confirmationUrl,
            'expiresInMinutes' => $this->tokenLifetimeMinutes(),
        ])->render();

        DB::transaction(function () use ($participant, $form, $rawToken, $expiresAt, $html): void {
            $lockedWebinar = Webinar::query()
                ->whereKey($form->webinar_id)
                ->whereNull('deletion_started_at')
                ->whereNot('status', 'archived')
                ->where(fn ($retention) => $retention
                    ->whereNull('retention_due_at')
                    ->orWhere('retention_due_at', '>', now()))
                ->lockForUpdate()
                ->first();

            if (! $lockedWebinar) {
                return;
            }

            // Serialize issuance for this identity. Several recent emails remain
            // valid so an attacker cannot invalidate a victim's link merely by
            // requesting a resend from another origin.
            $lockedParticipant = Participant::query()
                ->whereKey($participant->id)
                ->where('webinar_id', $form->webinar_id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->first();

            if (! $lockedParticipant) {
                return;
            }

            ParticipantAccessToken::query()->create([
                'participant_id' => $lockedParticipant->id,
                'webinar_id' => $form->webinar_id,
                'form_id' => $form->id,
                'token_hash' => $this->tokenHash($rawToken),
                'purpose' => self::PURPOSE,
                'expires_at' => $expiresAt,
            ]);

            $keptIds = ParticipantAccessToken::query()
                ->where('participant_id', $lockedParticipant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->take($this->maximumLiveTokens())
                ->pluck('id');

            ParticipantAccessToken::query()
                ->where('participant_id', $lockedParticipant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->whereNotIn('id', $keptIds)
                ->update(['used_at' => now()]);

            // Persist the encrypted outbox row under the same participant lock.
            // Privacy erasure takes this lock too, so a token can never commit
            // without its delivery being included in a later erasure snapshot.
            $this->notifications->queue(
                $lockedWebinar,
                $lockedParticipant,
                self::DELIVERY_TYPE,
                (string) $lockedParticipant->email,
                'Your secure form access link',
                $html,
                expiresAt: $expiresAt,
            );
        }, attempts: 3);
    }

    /**
     * Atomically spend a token and establish an absolute-lived participant grant.
     */
    public function consume(Request $request, Form $form, string $rawToken): bool
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $rawToken)) {
            return false;
        }

        $participant = DB::transaction(function () use ($form, $rawToken): ?Participant {
            $lockedWebinar = Webinar::query()
                ->whereKey($form->webinar_id)
                ->whereNull('deletion_started_at')
                ->whereNot('status', 'archived')
                ->where(fn ($retention) => $retention
                    ->whereNull('retention_due_at')
                    ->orWhere('retention_due_at', '>', now()))
                ->lockForUpdate()
                ->first();

            if (! $lockedWebinar) {
                return null;
            }

            $tokenHash = $this->tokenHash($rawToken);
            $subject = ParticipantAccessToken::query()
                ->where('token_hash', $tokenHash)
                ->where('purpose', self::PURPOSE)
                ->where('webinar_id', $form->webinar_id)
                ->where('form_id', $form->id)
                ->select(['id', 'participant_id'])
                ->first();

            if (! $subject) {
                return null;
            }

            // Every privacy-sensitive flow locks participant first, then child
            // records. Prelooking up the opaque subject avoids the inverse
            // token->participant order that can deadlock with erasure.
            $participant = Participant::withTrashed()
                ->whereKey($subject->participant_id)
                ->where('webinar_id', $form->webinar_id)
                ->lockForUpdate()
                ->first();
            $accessToken = ParticipantAccessToken::query()
                ->whereKey($subject->id)
                ->where('participant_id', $subject->participant_id)
                ->where('token_hash', $this->tokenHash($rawToken))
                ->where('purpose', self::PURPOSE)
                ->where('webinar_id', $form->webinar_id)
                ->where('form_id', $form->id)
                ->lockForUpdate()
                ->first();

            if (! $accessToken || ! $accessToken->isUsable()) {
                return null;
            }

            if (! $participant || $participant->trashed() || $participant->privacy_erased_at) {
                // Spend a credential whose subject was withdrawn so it cannot be
                // retried after any later data repair or restoration.
                $accessToken->update(['used_at' => now()]);

                return null;
            }

            // Spending any valid link revokes every sibling credential for this
            // identity. A forwarded or older email cannot establish another
            // session after the owner has confirmed one of the requests.
            ParticipantAccessToken::query()
                ->where('participant_id', $participant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            $participant->forceFill([
                'email_verified_at' => $participant->email_verified_at ?? now(),
                'last_access_at' => now(),
            ])->save();

            return $participant;
        }, attempts: 3);

        if (! $participant) {
            return false;
        }

        // Rotate the session identifier at the trust boundary. The grant is
        // scoped to one event and one participant and has an absolute expiry.
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($form->webinar_id), [
            'webinar_id' => $form->webinar_id,
            'participant_id' => $participant->id,
            'email_fingerprint' => $this->emailFingerprint((string) $participant->email),
            'expires_at' => now()->addMinutes($this->sessionLifetimeMinutes())->getTimestamp(),
        ]);

        return true;
    }

    /** Return the currently bound participant, invalidating stale grants. */
    public function participant(Request $request, Form $form): ?Participant
    {
        $key = $this->sessionKey($form->webinar_id);
        $grant = $request->session()->get($key);

        if (! is_array($grant)
            || ! is_numeric($grant['webinar_id'] ?? null)
            || ! is_numeric($grant['participant_id'] ?? null)
            || ! is_numeric($grant['expires_at'] ?? null)
            || ! is_string($grant['email_fingerprint'] ?? null)
            || (int) $grant['webinar_id'] !== $form->webinar_id
            || (int) $grant['expires_at'] <= now()->getTimestamp()) {
            $request->session()->forget($key);

            return null;
        }

        $participant = Participant::query()
            ->whereKey((int) $grant['participant_id'])
            ->where('webinar_id', $form->webinar_id)
            ->whereNull('privacy_erased_at')
            ->first();

        if (! Webinar::query()
            ->whereKey($form->webinar_id)
            ->whereNull('deletion_started_at')
            ->whereNot('status', 'archived')
            ->where(fn ($retention) => $retention
                ->whereNull('retention_due_at')
                ->orWhere('retention_due_at', '>', now()))
            ->exists()) {
            $request->session()->forget($key);

            return null;
        }

        if (! $participant
            || ! $participant->email_verified_at
            || ! hash_equals($grant['email_fingerprint'], $this->emailFingerprint((string) $participant->email))) {
            $request->session()->forget($key);

            return null;
        }

        return $participant;
    }

    /** Clear a grant after a privacy erasure or explicit local sign-out. */
    public function forget(Request $request, int $webinarId): void
    {
        $request->session()->forget($this->sessionKey($webinarId));
    }

    private function findOrCreateParticipant(Form $form, string $email): ?Participant
    {
        try {
            return DB::transaction(function () use ($form, $email): ?Participant {
                $webinar = Webinar::query()
                    ->whereKey($form->webinar_id)
                    ->whereNull('deletion_started_at')
                    ->whereNot('status', 'archived')
                    ->where(fn ($retention) => $retention
                        ->whereNull('retention_due_at')
                        ->orWhere('retention_due_at', '>', now()))
                    ->lockForUpdate()
                    ->first();

                if (! $webinar) {
                    return null;
                }

                $participant = Participant::withTrashed()
                    ->where('webinar_id', $form->webinar_id)
                    ->where('email_normalized', $email)
                    ->lockForUpdate()
                    ->first();

                if (! $participant) {
                    $participant = Participant::query()->create([
                        'webinar_id' => $form->webinar_id,
                        'email' => $email,
                    ]);
                }

                return $participant->trashed() || $participant->privacy_erased_at
                    ? null
                    : $participant;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Resolve a concurrent first request through the same lookup path.
            $participant = Participant::withTrashed()
                ->where('webinar_id', $form->webinar_id)
                ->where('email_normalized', $email)
                ->first();

            return $participant && ! $participant->trashed() && ! $participant->privacy_erased_at
                ? $participant
                : null;
        }
    }

    private function tokenHash(string $rawToken): string
    {
        return hash_hmac('sha256', "participant-magic-link\0".$rawToken, $this->key());
    }

    private function emailFingerprint(string $email): string
    {
        return hash_hmac('sha256', "participant-session-email\0".Str::lower(trim($email)), $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (Str::startsWith($key, 'base64:')) {
            $decoded = base64_decode(Str::after($key, 'base64:'), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function sessionKey(int $webinarId): string
    {
        return 'participant_access.webinars.'.$webinarId;
    }

    private function tokenLifetimeMinutes(): int
    {
        return max(5, min(60, (int) config('webinar.verification_token_minutes', 15)));
    }

    private function sessionLifetimeMinutes(): int
    {
        return max(5, min(1440, (int) config('webinar.participant_session_minutes', 120)));
    }

    private function maximumLiveTokens(): int
    {
        return max(2, min(20, (int) config('webinar.maximum_live_verification_tokens', 12)));
    }
}
