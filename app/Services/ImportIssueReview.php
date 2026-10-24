<?php

namespace App\Services;

use App\Models\ImportIssue;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Builder;

class ImportIssueReview
{
    public const GROUPS = ['all' => 'All held rows', 'duplicates' => 'Duplicates', 'unmatched' => 'Unmatched emails', 'invalid' => 'Invalid values', 'identity' => 'Identity conflicts'];

    public function query(Webinar $webinar, ?string $type = null, string $group = 'all', ?int $importId = null): Builder
    {
        $query = ImportIssue::query()->where('webinar_id', $webinar->id)->whereNull('resolved_at')
            ->whereHas('import', fn ($q) => $q->whereNull('privacy_erased_at'))
            ->when($type, fn ($q) => $q->whereHas('import.form', fn ($form) => $form->where('type', $type)))
            ->when($importId, fn ($q) => $q->where('import_id', $importId));
        $duplicates = [ImportIssue::TYPE_DUPLICATE_IN_FILE, ImportIssue::TYPE_DUPLICATE_EXISTING];
        $identity = [ImportIssue::TYPE_NAME_CONFLICT, ImportIssue::TYPE_IDENTITY_CONFLICT];

        return match ($group) {
            'duplicates' => $query->whereIn('issue_type', $duplicates),
            'unmatched' => $query->where('issue_type', ImportIssue::TYPE_UNMATCHED_EMAIL),
            'identity' => $query->whereIn('issue_type', $identity),
            'invalid' => $query->whereNotIn('issue_type', [...$duplicates, ...$identity, ImportIssue::TYPE_UNMATCHED_EMAIL]),
            default => $query,
        };
    }

    public function counts(Webinar $webinar, ?string $type = null): array
    {
        $types = $this->query($webinar, $type)->selectRaw('issue_type, count(*) as total')->groupBy('issue_type')->pluck('total', 'issue_type');
        $groups = array_fill_keys(array_keys(self::GROUPS), 0);
        foreach ($types as $type => $count) {
            $group = match ($type) {
                ImportIssue::TYPE_DUPLICATE_IN_FILE, ImportIssue::TYPE_DUPLICATE_EXISTING => 'duplicates',
                ImportIssue::TYPE_UNMATCHED_EMAIL => 'unmatched',
                ImportIssue::TYPE_NAME_CONFLICT, ImportIssue::TYPE_IDENTITY_CONFLICT => 'identity',
                default => 'invalid',
            };
            $groups[$group] += $count;
            $groups['all'] += $count;
        }

        return ['types' => $types, 'groups' => $groups];
    }
}
