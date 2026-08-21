<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    public function record(Request $request, string $action, ?Model $subject = null, array $metadata = []): void
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            // IP addresses have a small enough search space that an unkeyed hash
            // is readily reversible. A keyed, context-separated digest retains
            // abuse correlation without retaining the address itself.
            'ip_address_hash' => filled($ip) ? $this->fingerprint($ip, 'audit-ip') : null,
            // User-agent strings can be identifying too. Retain only a stable
            // fingerprint, not browser/device details or attacker-controlled text.
            'user_agent' => filled($userAgent) ? $this->fingerprint($userAgent, 'audit-user-agent') : null,
            'metadata' => $this->sanitizeMetadata($metadata),
        ]);
    }

    public function fingerprint(string $value, string $context = 'audit-metadata'): string
    {
        return hash_hmac('sha256', $context."\0".$value, (string) config('app.key'));
    }

    /**
     * Defensively strip common personal/secret values from metadata. Audit calls
     * should already pass minimal identifiers; this is the final safety net.
     *
     * @return array<string|int, mixed>
     */
    private function sanitizeMetadata(array $metadata, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['truncated' => true];
        }

        $clean = [];

        foreach (array_slice($metadata, 0, 50, true) as $key => $value) {
            $key = is_string($key) ? Str::limit($key, 100, '') : $key;

            if (is_string($key) && ! str_ends_with($key, '_fingerprint') && preg_match(
                '/(?:^|_)(?:email|full_name|name|organization|recipient|token|code|password|secret|answer|response|payload|content|html|attachment|reason|prompt|label|title|ip_address|user_agent)(?:$|_)/i',
                $key,
            )) {
                $clean[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeMetadata($value, $depth + 1);
            } elseif (is_string($value)) {
                $withoutControls = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
                $clean[$key] = Str::limit($withoutControls, 500, '');
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[unsupported]';
            }
        }

        if (count($metadata) > 50) {
            $clean['truncated'] = true;
        }

        return $clean;
    }
}
