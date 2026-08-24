<?php

namespace App\Services;

use App\Models\Form;

final class PublicFormResolver
{
    /** Resolve an active share token without revealing why an invalid link failed. */
    public function resolve(string $token): Form
    {
        return Form::query()
            ->select([
                'id', 'webinar_id', 'public_token', 'public_token_hash', 'type', 'title', 'description', 'status',
                'opens_at', 'closes_at', 'max_attempts', 'show_score',
            ])
            ->with('webinar:id,title,status,requires_verification,registration_opens_at,registration_closes_at,registration_capacity,data_retention_days,retention_due_at,deletion_started_at,archived_at')
            ->where('public_token_hash', Form::publicTokenHash($token))
            // The webinar must still exist as a data-bearing event with a live
            // privacy deadline. Whether it is currently *accepting* responses
            // (published, within its registration window, not yet closed) is
            // decided by Form::acceptsResponses(), so that a draft, completed,
            // or window-closed form shows a closed notice rather than a 404 —
            // and a submission to one is rejected with 403, not silently lost.
            ->whereHas('webinar', fn ($query) => $query
                ->whereNull('archived_at')
                ->whereNull('deletion_started_at')
                ->whereNotNull('retention_due_at')
                ->where('retention_due_at', '>', now()))
            ->firstOrFail();
    }
}
