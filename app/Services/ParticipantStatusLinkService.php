<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Webinar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ParticipantStatusLinkService
{
    public const PURPOSE = 'status_access';

    public const DELIVERY_TYPE = 'participant_status_access';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Queue a status link only for an existing participant. Returning nothing
     * keeps the public response identical when the address has no record.
     */
    public function request(Webinar $webinar, string $email): void
    {
        $email = Str::lower(trim($email));
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->tokenLifetimeMinutes());

        DB::transaction(function () use ($webinar, $email, $rawToken, $expiresAt): void {
            $lockedWebinar = $this->lockAvailableWebinar($webinar);

            if (! $lockedWebinar) {
                return;
            }

            // A status URL is intentionally hung off an existing form token so
            // the webinar needs no second public identifier or schema column.
            $form = Form::query()
                ->where('webinar_id', $lockedWebinar->id)
                ->orderByRaw("case when type = 'registration' then 0 else 1 end")
                ->orderBy('id')
                ->sharedLock()
                ->first(['id', 'webinar_id', 'public_token']);

            if (! $form) {
                return;
            }

            $participant = Participant::withTrashed()
                ->where('webinar_id', $lockedWebinar->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if (! $participant || $participant->trashed() || $participant->privacy_erased_at) {
                return;
            }

            ParticipantAccessToken::query()->create([
                'participant_id' => $participant->id,
                'webinar_id' => $lockedWebinar->id,
                'form_id' => null,
                'token_hash' => $this->tokenHash($rawToken),
                'purpose' => self::PURPOSE,
                'expires_at' => $expiresAt,
            ]);

            $keptIds = ParticipantAccessToken::query()
                ->where('participant_id', $participant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->take($this->maximumLiveTokens())
                ->pluck('id');

            ParticipantAccessToken::query()
                ->where('participant_id', $participant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->whereNotIn('id', $keptIds)
                ->update(['used_at' => now()]);

            // URL fragments are not sent to the web server, reverse proxy, or
            // access log. The confirmation page moves it into a protected POST.
            $confirmationUrl = route('forms.public.status.access.confirm', $form->public_token)
                .'#token='.$rawToken;
            $html = view('emails.participant-status-access', [
                'confirmationUrl' => $confirmationUrl,
                'expiresInMinutes' => $this->tokenLifetimeMinutes(),
            ])->render();

            $this->notifications->queue(
                $lockedWebinar,
                $participant,
                self::DELIVERY_TYPE,
                (string) $participant->email,
                'Your secure participant status link',
                $html,
                expiresAt: $expiresAt,
            );
        }, attempts: 3);
    }

    /** Atomically spend a webinar-bound token and establish a session grant. */
    public function consume(Request $request, Webinar $webinar, string $rawToken): bool
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $rawToken)) {
            return false;
        }

        $participant = DB::transaction(function () use ($webinar, $rawToken): ?Participant {
            $lockedWebinar = $this->lockAvailableWebinar($webinar);

            if (! $lockedWebinar) {
                return null;
            }

            $subject = ParticipantAccessToken::query()
                ->where('token_hash', $this->tokenHash($rawToken))
                ->where('purpose', self::PURPOSE)
                ->where('webinar_id', $lockedWebinar->id)
                ->whereNull('form_id')
                ->select(['id', 'participant_id'])
                ->first();

            if (! $subject) {
                return null;
            }

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
                ->whereNull('form_id')
                ->lockForUpdate()
                ->first();

            if (! $accessToken || ! $accessToken->isUsable()) {
                return null;
            }

            if (! $participant || $participant->trashed() || $participant->privacy_erased_at) {
                $accessToken->update(['used_at' => now()]);

                return null;
            }

            ParticipantAccessToken::query()
                ->where('participant_id', $participant->id)
                ->where('purpose', self::PURPOSE)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            $participant->update(['last_access_at' => now()]);

            return $participant;
        }, attempts: 3);

        if (! $participant) {
            return false;
        }

        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($webinar->id), [
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'email_fingerprint' => $this->emailFingerprint((string) $participant->email),
            'expires_at' => now()->addMinutes($this->sessionLifetimeMinutes())->timestamp,
        ]);

        return true;
    }

    /** Return the participant bound to a current, webinar-scoped status grant. */
    public function participant(Request $request, Webinar $webinar): ?Participant
    {
        $key = $this->sessionKey($webinar->id);
        $grant = $request->session()->get($key);

        if (! $this->validGrant($grant, $webinar->id)) {
            $request->session()->forget($key);

            return null;
        }

        $participant = DB::transaction(function () use ($webinar, $grant): ?Participant {
            $lockedWebinar = $this->lockAvailableWebinar($webinar);

            if (! $lockedWebinar) {
                return null;
            }

            return Participant::query()
                ->whereKey((int) $grant['participant_id'])
                ->where('webinar_id', $lockedWebinar->id)
                ->whereNull('privacy_erased_at')
                ->sharedLock()
                ->first();
        }, attempts: 3);

        if (! $participant
            || ! hash_equals($grant['email_fingerprint'], $this->emailFingerprint((string) $participant->email))) {
            $request->session()->forget($key);

            return null;
        }

        return $participant;
    }

    private function lockAvailableWebinar(Webinar $webinar): ?Webinar
    {
        $locked = Webinar::query()->whereKey($webinar->id)->sharedLock()->first();

        if (! $locked
            || ! in_array($locked->status, ['published', 'completed'], true)
            || $locked->archived_at !== null
            || $locked->deletion_started_at !== null
            || $locked->retention_due_at === null
            || ! $locked->retention_due_at->isFuture()) {
            return null;
        }

        return $locked;
    }

    private function tokenHash(string $rawToken): string
    {
        return hash_hmac('sha256', "participant-status-link\0".$rawToken, $this->key());
    }

    private function emailFingerprint(string $email): string
    {
        return hash_hmac('sha256', "participant-status-email\0".Str::lower(trim($email)), $this->key());
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
        return 'participant_status_access.webinars.'.$webinarId;
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

    private function validGrant(mixed $grant, int $webinarId): bool
    {
        return is_array($grant)
            && is_numeric($grant['webinar_id'] ?? null)
            && is_numeric($grant['participant_id'] ?? null)
            && is_numeric($grant['expires_at'] ?? null)
            && is_string($grant['email_fingerprint'] ?? null)
            && (int) $grant['webinar_id'] === $webinarId
            && (int) $grant['expires_at'] > now()->timestamp;
    }
}
