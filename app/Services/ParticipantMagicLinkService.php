<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Webinar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->tokenLifetimeMinutes());

        try {
            $this->persistAccessRequest($form, $email, $rawToken, $expiresAt, createParticipant: true);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first request may have created the identity after our
            // lookup. Retry through the complete locked lifecycle check, but do
            // not attempt a second insert or bypass a form that has since closed.
            $this->persistAccessRequest($form, $email, $rawToken, $expiresAt, createParticipant: false);
        }
    }

    /**
     * Atomically spend a token and establish an absolute-lived participant grant.
     */
    public function consume(Request $request, Form $form, string $rawToken): bool
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $rawToken)) {
            return false;
        }

        $result = DB::transaction(function () use ($form, $rawToken): ?array {
            $context = $this->lockAcceptingContext($form, shared: true);

            if (! $context) {
                return null;
            }

            ['webinar' => $lockedWebinar, 'form' => $lockedForm] = $context;

            $tokenHash = $this->tokenHash($rawToken);
            $subject = ParticipantAccessToken::query()
                ->where('token_hash', $tokenHash)
                ->where('purpose', self::PURPOSE)
                ->where('webinar_id', $lockedWebinar->id)
                ->where('form_id', $lockedForm->id)
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
                ->where('webinar_id', $lockedWebinar->id)
                ->lockForUpdate()
                ->first();
            $accessToken = ParticipantAccessToken::query()
                ->whereKey($subject->id)
                ->where('participant_id', $subject->participant_id)
                ->where('token_hash', $this->tokenHash($rawToken))
                ->where('purpose', self::PURPOSE)
                ->where('webinar_id', $lockedWebinar->id)
                ->where('form_id', $lockedForm->id)
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

            return [
                'participant' => $participant,
                'pass_expires_at' => $this->passExpiryTimestamp($lockedWebinar),
            ];
        }, attempts: 3);

        if (! $result) {
            return false;
        }

        /** @var Participant $participant */
        $participant = $result['participant'];

        // Rotate the session identifier at the trust boundary. The grant is
        // scoped to one event and one participant and has an absolute expiry.
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($form->webinar_id), [
            'webinar_id' => $form->webinar_id,
            'participant_id' => $participant->id,
            'email_fingerprint' => $this->emailFingerprint((string) $participant->email),
            'expires_at' => now()->addMinutes($this->sessionLifetimeMinutes())->getTimestamp(),
        ]);
        $this->queuePass($form->webinar_id, $participant, (int) $result['pass_expires_at']);

        return true;
    }

    /** Return the currently bound participant, invalidating stale grants. */
    public function participant(Request $request, Form $form): ?Participant
    {
        $key = $this->sessionKey($form->webinar_id);
        $grant = $request->session()->get($key);

        if (! $this->validGrant($grant, $form->webinar_id)) {
            $request->session()->forget($key);
            $grant = $this->passGrant($request, $form->webinar_id);

            if (! $grant) {
                return null;
            }
        }

        $participant = DB::transaction(function () use ($form, $grant): ?Participant {
            $context = $this->lockAcceptingContext($form, shared: true);

            if (! $context) {
                return null;
            }

            return Participant::query()
                ->whereKey((int) $grant['participant_id'])
                ->where('webinar_id', $context['webinar']->id)
                ->whereNull('privacy_erased_at')
                ->whereNotNull('email_verified_at')
                ->sharedLock()
                ->first();
        }, attempts: 3);

        if (! $participant) {
            $this->forget($request, $form->webinar_id);

            return null;
        }

        if (! hash_equals($grant['email_fingerprint'], $this->emailFingerprint((string) $participant->email))) {
            $this->forget($request, $form->webinar_id);

            return null;
        }

        return $participant;
    }

    /** Clear a grant after a privacy erasure or explicit local sign-out. */
    public function forget(Request $request, int $webinarId): void
    {
        $request->session()->forget($this->sessionKey($webinarId));
        Cookie::expire($this->passCookieName($webinarId));
    }

    public function passCookieName(int $webinarId): string
    {
        return 'participant_pass_'.$webinarId;
    }

    private function persistAccessRequest(
        Form $form,
        string $email,
        string $rawToken,
        mixed $expiresAt,
        bool $createParticipant,
    ): void {
        DB::transaction(function () use ($form, $email, $rawToken, $expiresAt, $createParticipant): void {
            $context = $this->lockAcceptingContext($form, shared: true);

            if (! $context) {
                return;
            }

            ['webinar' => $lockedWebinar, 'form' => $lockedForm] = $context;

            // Serialize issuance for this identity. Several recent emails remain
            // valid so an attacker cannot invalidate a victim's link merely by
            // requesting a resend from another origin.
            $lockedParticipant = Participant::withTrashed()
                ->where('webinar_id', $lockedWebinar->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if (! $lockedParticipant && $createParticipant) {
                $lockedParticipant = Participant::query()->create([
                    'webinar_id' => $lockedWebinar->id,
                    'email' => $email,
                ]);
            }

            if (! $lockedParticipant
                || $lockedParticipant->trashed()
                || $lockedParticipant->privacy_erased_at) {
                return;
            }

            ParticipantAccessToken::query()->create([
                'participant_id' => $lockedParticipant->id,
                'webinar_id' => $lockedWebinar->id,
                'form_id' => $lockedForm->id,
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

            // The fragment is never included in the HTTP request made by an
            // email client, link scanner, reverse proxy, or access log.
            $confirmationUrl = route('forms.public.access.confirm', $lockedForm->public_token)
                .'#token='.$rawToken;
            $html = view('emails.participant-form-access', [
                'confirmationUrl' => $confirmationUrl,
                'expiresInMinutes' => $this->tokenLifetimeMinutes(),
            ])->render();

            // Persist the encrypted outbox row under the same participant lock.
            // The encrypted form pointer lets the worker recheck this exact form
            // before sending, without exposing a public token in a plain column.
            $delivery = $this->notifications->queue(
                $lockedWebinar,
                $lockedParticipant,
                self::DELIVERY_TYPE,
                (string) $lockedParticipant->email,
                'Your secure form access link',
                $html,
                expiresAt: $expiresAt,
            );
            $payload = $delivery->payload;
            $payload['form_id'] = $lockedForm->id;
            $delivery->update(['payload' => $payload]);
        }, attempts: 3);
    }

    /**
     * Lock and re-read the authoritative public-access boundary.
     *
     * Lock order is always webinar -> form. Callers that need an identity then
     * lock participant -> child token/delivery records. `acceptsResponses()` is
     * evaluated only after the fresh form has been bound to the fresh webinar.
     *
     * @return array{webinar: Webinar, form: Form}|null
     */
    private function lockAcceptingContext(Form $form, bool $shared = false): ?array
    {
        $webinarQuery = Webinar::query()->whereKey($form->webinar_id);
        $lockedWebinar = ($shared ? $webinarQuery->sharedLock() : $webinarQuery->lockForUpdate())->first();

        if (! $lockedWebinar
            || $lockedWebinar->status !== 'published'
            || $lockedWebinar->archived_at !== null
            || $lockedWebinar->deletion_started_at !== null
            || $lockedWebinar->retention_due_at === null
            || ! $lockedWebinar->retention_due_at->isFuture()
            || ! $lockedWebinar->requiresVerification()) {
            return null;
        }

        $formQuery = Form::query()
            ->whereKey($form->id)
            ->where('webinar_id', $lockedWebinar->id);
        $lockedForm = ($shared ? $formQuery->sharedLock() : $formQuery->lockForUpdate())->first();

        if (! $lockedForm) {
            return null;
        }

        $lockedForm->setRelation('webinar', $lockedWebinar);

        return $lockedForm->acceptsResponses()
            ? ['webinar' => $lockedWebinar, 'form' => $lockedForm]
            : null;
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

    private function passLifetimeHours(): int
    {
        return max(1, min(168, (int) config('webinar.participant_pass_hours', 24)));
    }

    private function passExpiryTimestamp(Webinar $webinar): int
    {
        $configuredExpiry = now()->addHours($this->passLifetimeHours());

        if ($webinar->retention_due_at && $webinar->retention_due_at->lessThan($configuredExpiry)) {
            return $webinar->retention_due_at->getTimestamp();
        }

        return $configuredExpiry->getTimestamp();
    }

    private function queuePass(int $webinarId, Participant $participant, int $expiresAt): void
    {
        // Laravel's EncryptCookies middleware authenticates and encrypts this
        // payload on the response. It remains only a durable identity pointer;
        // participant() rechecks all authoritative state on every use.
        $payload = json_encode([
            'version' => 1,
            'webinar_id' => $webinarId,
            'participant_id' => $participant->id,
            'email_fingerprint' => $this->emailFingerprint((string) $participant->email),
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR);
        $minutes = (int) floor(($expiresAt - now()->getTimestamp()) / 60);

        if ($minutes < 1) {
            return;
        }

        Cookie::queue(
            $this->passCookieName($webinarId),
            $payload,
            $minutes,
            '/',
            null,
            null,
            true,
            false,
            'lax',
        );
    }

    /** @return array<string, mixed>|null */
    private function passGrant(Request $request, int $webinarId): ?array
    {
        $name = $this->passCookieName($webinarId);
        $payload = $request->cookie($name);

        if (! is_string($payload)) {
            return null;
        }

        try {
            $grant = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Cookie::expire($name);

            return null;
        }

        if (($grant['version'] ?? null) !== 1 || ! $this->validGrant($grant, $webinarId)) {
            Cookie::expire($name);

            return null;
        }

        return $grant;
    }

    private function validGrant(mixed $grant, int $webinarId): bool
    {
        return is_array($grant)
            && is_numeric($grant['webinar_id'] ?? null)
            && is_numeric($grant['participant_id'] ?? null)
            && is_numeric($grant['expires_at'] ?? null)
            && is_string($grant['email_fingerprint'] ?? null)
            && (int) $grant['webinar_id'] === $webinarId
            && (int) $grant['expires_at'] > now()->getTimestamp();
    }
}
