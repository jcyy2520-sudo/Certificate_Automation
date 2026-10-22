<?php

namespace App\Services;

use App\Models\AdminTrustedDevice;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class AdminDeviceService
{
    public const COOKIE = 'admin_device';

    private const SESSION = 'admin_verified_context';

    public function binding(User $user): string
    {
        return $this->digest(implode("\0", [$user->id, $user->getAuthPassword(), $user->auth_version,
            $user->two_factor_secret, $user->two_factor_confirmed_at?->getTimestamp()]), 'credential');
    }

    public function browser(Request $request): string
    {
        // Ignore patch versions; a browser family/OS change still requires MFA.
        $agent = substr((string) $request->userAgent(), 0, 1024);

        return $this->digest(preg_replace('/(\d+)(?:[._]\d+)+/', '$1', $agent) ?? '', 'browser');
    }

    public function network(Request $request): ?string
    {
        // Use only Laravel's proxy-validated address, never raw forwarded headers.
        $ip = $request->ip();
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        $packed = inet_pton($ip);

        // /24 for IPv4, /56 for IPv6: routine address rotation is not a new device.
        return $this->digest(substr($packed, 0, strlen($packed) === 4 ? 3 : 7), 'network');
    }

    public function acceptSession(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION, [
            'binding' => $this->binding($user), 'browser' => $this->browser($request), 'network' => $this->network($request),
        ]);
    }

    public function sessionMatches(Request $request, User $user): bool
    {
        $context = $request->session()->get(self::SESSION);

        return is_array($context)
            && hash_equals($this->binding($user), (string) ($context['binding'] ?? ''))
            && hash_equals($this->browser($request), (string) ($context['browser'] ?? ''))
            && ($context['network'] ?? null) === $this->network($request);
    }

    public function trusted(Request $request, User $user): bool
    {
        if (! $user->isAdministrator() || ! $user->two_factor_confirmed_at) {
            return false;
        }
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            return false;
        }
        $device = AdminTrustedDevice::query()->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (! $device) {
            return false;
        }
        $matches = hash_equals($this->binding($user), $device->credential_binding)
            && hash_equals($this->browser($request), $device->browser_hash)
            && $device->network_hash === $this->network($request);
        $suspicious = AuditLog::query()->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id)
            ->whereIn('action', ['administrator.login_failed', 'administrator.two_factor_failed'])
            ->where('created_at', '>', now()->subMinutes(15))->where('created_at', '>', $device->verified_at)->count() >= 5;
        if (! $matches || $suspicious) {
            $device->update(['revoked_at' => now()]);
            Cookie::queue(Cookie::forget(self::COOKIE));

            return false;
        }
        $device->update(['last_used_at' => now()]);

        return true;
    }

    public function remember(Request $request, User $user): void
    {
        if (! $user->isAdministrator() || ! $request->isSecure()) {
            return; // This persistent credential is never issued over HTTP.
        }
        $token = Str::random(64);
        $days = max(1, min(30, (int) config('security.trusted_device_days', 30)));
        // A bounded number of active devices per account; enrollment never extends old cookies.
        $ids = AdminTrustedDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->latest('verified_at')->skip(9)->take(100)->pluck('id');
        AdminTrustedDevice::query()->whereIn('id', $ids)->update(['revoked_at' => now()]);
        AdminTrustedDevice::query()->create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'credential_binding' => $this->binding($user),
            'browser_hash' => $this->browser($request), 'network_hash' => $this->network($request),
            'verified_at' => now(), 'last_used_at' => now(), 'expires_at' => now()->addDays($days),
        ]);
        // Laravel EncryptCookies encrypts this host-only, HttpOnly credential.
        Cookie::queue(cookie(self::COOKIE, $token, $days * 1440, '/', null, true, true, false, 'lax'));
    }

    public function revokeAll(User $user): void
    {
        AdminTrustedDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    private function digest(string $value, string $purpose): string
    {
        return hash_hmac('sha256', 'admin-device-'.$purpose."\0".$value, (string) config('app.key'));
    }
}
