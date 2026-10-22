<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    public function record(Request $request, string $action, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?string $outcome = null): void
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $actor ??= $request->user();
        $webinar = $request->route('webinar');
        $webinarId = $subject instanceof Webinar ? $subject->id : ($subject?->getAttribute('webinar_id') ?? ($webinar instanceof Webinar ? $webinar->id : null));
        $outcome ??= preg_match('/(?:failed|denied|rejected)$/', $action) ? 'failure' : 'success';
        // Only operational values are copied into generic diffs. Personal
        // identity snapshots have their own encrypted columns below.
        $allowed = ['role', 'is_active', 'approval_status', 'status', 'starts_at', 'ends_at', 'archived_at', 'checked_in_at', 'is_required', 'minimum_score', 'type', 'sort_order', 'manage_participants', 'manage_certificates', 'send_emails'];
        if ($subject && ! $subject->wasRecentlyCreated && $subject->getChanges()) {
            $before = array_intersect_key($subject->getPrevious(), array_flip($allowed));
            $after = array_intersect_key($subject->getChanges(), array_flip($allowed));
            if ($after) {
                $metadata += ['before' => $before, 'after' => $after];
            }
        }

        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'webinar_id' => $webinarId,
            'outcome' => $outcome,
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
        $request->attributes->set('audit_recorded', true);
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
                '/(?:^|_)(?:email|full_name|name|organization|recipient|token|code|password|secret|answer|response|payload|content|html|attachment|reasons?|prompt|label|title|ip_address|user_agent)(?:$|_)/i',
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
