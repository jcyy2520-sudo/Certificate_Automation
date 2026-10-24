<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Import;
use App\Models\ImportMappingProfile;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ImportMappingProfileService
{
    public const MAX_NAME_LENGTH = 80;

    private const OPTION_KEYS = [
        'required_columns', 'answer_map', 'score_mode', 'default_maximum_score',
        'timestamp_timezone', 'allow_import_time', 'response_mode',
    ];

    /** Own profiles of this form type, including mismatches explained by compatibility(). */
    public function matching(Import $import, User $user): Collection
    {
        $current = $this->availableImport($import, $user);

        return ImportMappingProfile::query()->where('user_id', $user->id)
            ->where('form_type', $current->form->type)->latest('id')->get();
    }

    /** @return array{compatible:bool,message:string} */
    public function compatibility(Import $import, ImportMappingProfile $profile): array
    {
        $current = Import::query()->with(['form', 'webinar'])->findOrFail($import->id);
        abort_unless((int) $profile->user_id === (int) auth()->id(), 404);
        Gate::authorize('permission', [$current->webinar, 'manage_participants']);

        try {
            $this->applicablePreferences($current, $profile);

            return ['compatible' => true, 'message' => 'The columns match exactly. Applying this profile requires a new dry check and source confirmation.'];
        } catch (RuntimeException $exception) {
            return ['compatible' => false, 'message' => $exception->getMessage()];
        }
    }

    public function save(Import $import, User $user, string $name): ImportMappingProfile
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new RuntimeException('Enter a profile name of 1–80 characters.');
        }

        return DB::transaction(function () use ($import, $user, $name): ImportMappingProfile {
            $current = $this->availableImport($import, $user, lock: true);
            $stored = $current->column_map ?? [];
            $reviewHash = $stored['review_hash'] ?? '';
            if (! is_string($reviewHash) || $reviewHash === '') {
                throw new RuntimeException('Run the dry check with the current columns and options before saving a profile.');
            }
            $options = $stored['options'] ?? [];
            $map = CsvImportService::validateMap($current, $stored['map'] ?? [], $options['response_mode'] ?? 'individual');
            $format = CsvImportService::validateTimestampFormat($stored['timestamp_format'] ?? null);
            $options = CsvImportService::validateOptions($current, $options, $map);
            if (! hash_equals($reviewHash, CsvImportService::reviewHash($current, $map, $format, $options))) {
                throw new RuntimeException('Run the dry check with the current columns and options before saving a profile.');
            }

            $native = $this->nativeDefinitions($current->form);
            $bindings = array_intersect_key($native, $options['answer_map'] ?? []);

            return ImportMappingProfile::query()->create([
                'user_id' => $user->id,
                'form_type' => $current->form->type,
                'name' => $name,
                'schema' => ['headers' => $stored['headers'] ?? [], 'native_columns' => $bindings],
                'preferences' => [
                    'map' => $map, 'timestamp_format' => $format,
                    'options' => array_intersect_key($options, array_flip(self::OPTION_KEYS)),
                ],
            ]);
        }, attempts: 3);
    }

    /** Apply choices only. A profile never supplies source confirmation or a dry-check token. */
    public function apply(Import $import, User $user, ImportMappingProfile $profile): Import
    {
        return DB::transaction(function () use ($import, $user, $profile): Import {
            $current = $this->availableImport($import, $user, lock: true);
            $ownedProfile = $this->ownedProfile($profile, $user);
            $preferences = $this->applicablePreferences($current, $ownedProfile);
            $stored = $current->column_map ?? [];
            $preferences['options']['source_description'] = (string) ($stored['options']['source_description'] ?? '');
            $preferences['options']['source_confirmed'] = false;
            foreach (array_keys($stored) as $key) {
                if (str_starts_with($key, 'review') || in_array($key, ['dry_run', 'last_error', 'last_error_at', 'response_schema'], true)) {
                    unset($stored[$key]);
                }
            }
            $current->update(['column_map' => array_merge($stored, $preferences)]);

            return $current;
        }, attempts: 3);
    }

    public function remove(Import $import, User $user, ImportMappingProfile $profile): void
    {
        $this->availableImport($import, $user);
        $this->ownedProfile($profile, $user)->delete();
    }

    private function ownedProfile(ImportMappingProfile $profile, User $user): ImportMappingProfile
    {
        return ImportMappingProfile::query()->whereKey($profile->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function availableImport(Import $import, User $user, bool $lock = false): Import
    {
        $query = Import::query()->whereKey($import->id)
            ->where('status', Import::STATUS_PENDING)->whereNull('privacy_erased_at');
        if ($lock) {
            // Follow the shared webinar → form → child lock order.
            $webinar = Webinar::query()->whereKey($import->webinar_id)->lockForUpdate()->firstOrFail();
            $form = Form::query()->whereKey($import->form_id)->where('webinar_id', $webinar->id)->lockForUpdate()->firstOrFail();
            $current = $query->lockForUpdate()->firstOrFail()->setRelation('webinar', $webinar)->setRelation('form', $form);
        } else {
            $current = $query->with(['webinar', 'form'])->firstOrFail();
        }
        abort_unless($current->form?->webinar_id === $current->webinar_id, 404);
        Gate::forUser($user)->authorize('permission', [$current->webinar, 'manage_participants']);
        if ($current->webinar->deletion_started_at !== null || $current->webinar->retention_due_at?->isPast()) {
            throw new RuntimeException('This upload is no longer available. Upload a file to a current webinar before using mapping profiles.');
        }

        return $current;
    }

    private function applicablePreferences(Import $import, ImportMappingProfile $profile): array
    {
        if ($profile->form_type !== $import->form->type) {
            throw new RuntimeException('This profile belongs to a different form type. Confirm the columns for this destination.');
        }
        $savedHeaders = $profile->schema['headers'] ?? [];
        $headers = $import->column_map['headers'] ?? [];
        if ($savedHeaders !== $headers) {
            throw new RuntimeException($this->headerDifference($savedHeaders, $headers));
        }

        $preferences = $profile->preferences;
        $options = array_intersect_key($preferences['options'] ?? [], array_flip(self::OPTION_KEYS));
        $native = $this->nativeDefinitions($import->form);
        $bindings = $profile->schema['native_columns'] ?? [];
        $answerMap = [];
        foreach ($options['answer_map'] ?? [] as $oldKey => $index) {
            $definition = $bindings[$oldKey] ?? null;
            $matches = $definition === null ? [] : array_keys(array_filter($native, fn ($candidate) => $candidate === $definition));
            if (count($matches) !== 1 || isset($answerMap[$matches[0]])) {
                throw new RuntimeException('A saved question has changed, is missing or is ambiguous in this form. Review the question columns and run a fresh dry check.');
            }
            $answerMap[$matches[0]] = $index;
        }
        $options['answer_map'] = $answerMap;
        $map = CsvImportService::validateMap($import, $preferences['map'] ?? [], $options['response_mode'] ?? 'individual');
        $format = CsvImportService::validateTimestampFormat($preferences['timestamp_format'] ?? null);
        // Validate structure with disposable context. No source claim is stored
        // in a profile, and applying one always clears the real confirmation.
        $validated = CsvImportService::validateOptions($import, $options + [
            'source_description' => 'Mapping profile structure validation', 'source_confirmed' => true,
        ], $map);

        return ['map' => $map, 'timestamp_format' => $format, 'options' => array_intersect_key($validated, array_flip(self::OPTION_KEYS))];
    }

    private function nativeDefinitions(Form $form): array
    {
        $form->unsetRelation('fields')->unsetRelation('questions');
        $native = app(FormResponseService::class)->nativeColumns($form);
        $definitions = [];
        foreach ($native as $key => $column) {
            $definitions[$key] = [
                'kind' => str_starts_with($key, 'field_') ? 'field' : 'question',
                'label' => $column['label'], 'type' => $column['type'],
                'choice_labels' => array_values($column['options'] ?? []),
            ];
        }

        return $definitions;
    }

    private function headerDifference(array $saved, array $current): string
    {
        if (count($saved) !== count($current)) {
            return 'The profile has '.count($saved).' columns; this file has '.count($current).'. Confirm the columns again before saving a new profile.';
        }
        foreach ($saved as $index => $label) {
            if (($current[$index] ?? null) !== $label) {
                return 'Column '.($index + 1).' has different text or position. Header spelling, case, whitespace and order must match exactly. Confirm the columns again.';
            }
        }

        return 'The column layout differs. Confirm the columns again before using a profile.';
    }
}
