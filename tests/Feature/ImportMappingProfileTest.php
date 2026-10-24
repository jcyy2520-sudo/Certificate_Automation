<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ImportMappingProfileController;
use App\Models\Form;
use App\Models\Import;
use App\Models\ImportMappingProfile;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\ImportMappingProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImportMappingProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($this->owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id, 'timezone' => 'Asia/Manila']);
    }

    public function test_saving_a_reviewed_profile_stores_only_encrypted_schema_and_preferences(): void
    {
        $import = $this->reviewed();
        $mapping = $import->column_map;
        $mapping['analysis']['sample'] = ['private respondent value'];
        $mapping['last_error'] = 'private error detail';
        $import->update(['column_map' => $mapping]);

        $profile = $this->profiles()->save($import, $this->owner, 'Registration layout');

        $this->assertSame(['headers' => ['Email', 'Full Name', 'Feedback'], 'native_columns' => []], $profile->schema);
        $this->assertSame(['email' => 0, 'full_name' => 1], $profile->preferences['map']);
        $this->assertSame([], $profile->preferences['options']['required_columns']);
        $stored = json_encode($profile->toArray(), JSON_THROW_ON_ERROR);
        foreach (['synthetic-person@example.test', 'Synthetic Person', 'private respondent value', 'private error detail', 'source_description', 'source_confirmed', 'review_hash', 'original_filename', 'analysis', 'sample'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
        }
        $raw = (array) DB::table('import_mapping_profiles')->find($profile->id);
        $this->assertStringNotContainsString('Full Name', $raw['schema']);
        $this->assertStringNotContainsString('full_name', $raw['preferences']);
        $this->assertStringNotContainsString('Registration layout', $raw['name']);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_unreviewed_mapping_cannot_be_saved(): void
    {
        $import = $this->pending();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dry check');

        $this->profiles()->save($import, $this->owner, 'Unreviewed layout');
    }

    public function test_a_mapping_changed_after_its_dry_check_cannot_be_saved(): void
    {
        $import = $this->reviewed();
        $mapping = $import->column_map;
        $mapping['map']['organization'] = 2;
        $import->update(['column_map' => $mapping]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dry check');

        $this->profiles()->save($import, $this->owner, 'Stale layout');
    }

    public function test_applying_an_exact_profile_keeps_target_source_context_and_requires_a_new_review(): void
    {
        $profile = $this->profiles()->save($this->reviewed(), $this->owner, 'Reusable layout');
        $target = $this->reviewed();
        $mapping = $target->column_map;
        $mapping['options']['source_description'] = 'Current target export context';
        $mapping['review_counts'] = ['valid' => 1];
        $mapping['reviewed_at'] = now()->toIso8601String();
        $mapping['last_error'] = 'Old error';
        $target->update(['column_map' => $mapping]);

        $this->assertTrue($this->profiles()->compatibility($target, $profile)['compatible']);
        $this->profiles()->apply($target, $this->owner, $profile);
        $applied = $target->fresh()->column_map;

        $this->assertSame(['email' => 0, 'full_name' => 1], $applied['map']);
        $this->assertSame('Current target export context', $applied['options']['source_description']);
        $this->assertFalse($applied['options']['source_confirmed']);
        foreach (['review_hash', 'review_counts', 'reviewed_at', 'last_error'] as $key) {
            $this->assertArrayNotHasKey($key, $applied);
        }
        $this->assertSame(Import::STATUS_PENDING, $target->fresh()->status);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_reordered_headers_never_reuse_profile_positions(): void
    {
        $this->assertHeaderMismatch(['Full Name', 'Email', 'Feedback']);
    }

    public function test_case_or_whitespace_changes_never_reuse_profile_positions(): void
    {
        $this->assertHeaderMismatch(['email', 'Full Name', 'Feedback']);
        $this->assertHeaderMismatch(['Email ', 'Full Name', 'Feedback']);
    }

    public function test_missing_and_added_headers_require_fresh_column_confirmation(): void
    {
        $this->assertHeaderMismatch(['Email', 'Full Name']);
        $this->assertHeaderMismatch(['Email', 'Full Name', 'Feedback', 'Extra question']);
    }

    public function test_matching_lists_only_the_actors_same_type_profiles_across_webinars(): void
    {
        $own = $this->profiles()->save($this->reviewed(), $this->owner, 'Own registration');
        $evaluation = $this->reviewed('evaluation');
        $this->profiles()->save($evaluation, $this->owner, 'Own evaluation');
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $foreign = ImportMappingProfile::query()->create([
            'user_id' => $other->id, 'form_type' => 'registration', 'name' => 'Private layout',
            'schema' => $own->schema, 'preferences' => $own->preferences,
        ]);
        $secondWebinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $target = $this->pending(webinar: $secondWebinar);

        $this->assertSame([$own->id], $this->profiles()->matching($target, $this->owner)->modelKeys());
        $this->expectException(ModelNotFoundException::class);
        $this->profiles()->apply($target, $this->owner, $foreign);
    }

    public function test_even_an_administrator_cannot_apply_a_profile_to_another_users_pending_upload(): void
    {
        $profile = $this->profiles()->save($this->reviewed(), $this->owner, 'Own layout');
        $target = $this->pending();
        $administrator = User::factory()->create();
        $this->expectException(ModelNotFoundException::class);

        $this->profiles()->apply($target, $administrator, $profile);
    }

    public function test_unique_exact_native_questions_can_be_reused_across_forms_without_reusing_numeric_ids(): void
    {
        $sourceForm = $this->form('evaluation');
        $sourceField = $sourceForm->fields()->create(['key' => 'rating', 'label' => 'Speaker rating', 'field_type' => 'radio', 'options' => ['Good', 'Excellent']]);
        $source = $this->reviewed('evaluation', $sourceForm, ['Email', 'Speaker rating']);
        $profile = $this->profiles()->save($source, $this->owner, 'Feedback layout');
        $secondWebinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $targetForm = $this->form('evaluation', $secondWebinar);
        $targetField = $targetForm->fields()->create(['key' => 'other_rating', 'label' => 'Speaker rating', 'field_type' => 'radio', 'options' => ['Good', 'Excellent']]);
        $target = $this->pending('evaluation', $targetForm, ['Email', 'Speaker rating'], $secondWebinar);

        $this->profiles()->apply($target, $this->owner, $profile);

        $this->assertNotSame($sourceField->id, $targetField->id);
        $this->assertSame(['field_'.$targetField->id => 1], $target->fresh()->column_map['options']['answer_map']);
    }

    public function test_a_changed_native_type_or_choice_set_requires_fresh_mapping(): void
    {
        $form = $this->form('evaluation');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Speaker rating', 'field_type' => 'radio', 'options' => ['Good', 'Excellent']]);
        $profile = $this->profiles()->save($this->reviewed('evaluation', $form, ['Email', 'Speaker rating']), $this->owner, 'Feedback layout');
        $target = $this->pending('evaluation', $form, ['Email', 'Speaker rating']);
        $field->update(['options' => ['Poor', 'Excellent']]);
        $check = $this->profiles()->compatibility($target, $profile);

        $this->assertFalse($check['compatible']);
        $this->assertStringContainsString('question', $check['message']);
        $this->expectException(RuntimeException::class);
        $this->profiles()->apply($target, $this->owner, $profile);
    }

    public function test_ambiguous_native_labels_are_never_guessed(): void
    {
        $form = $this->form('evaluation');
        $form->fields()->create(['key' => 'rating', 'label' => 'Speaker rating', 'field_type' => 'text']);
        $profile = $this->profiles()->save($this->reviewed('evaluation', $form, ['Email', 'Speaker rating']), $this->owner, 'Feedback layout');
        $target = $this->pending('evaluation', $form, ['Email', 'Speaker rating']);
        $form->fields()->create(['key' => 'duplicate', 'label' => 'Speaker rating', 'field_type' => 'text']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('question');
        $this->profiles()->apply($target, $this->owner, $profile);
    }

    public function test_profiles_are_removed_only_by_their_owner(): void
    {
        $import = $this->reviewed();
        $profile = $this->profiles()->save($import, $this->owner, 'Remove me');
        $this->profiles()->remove($import, $this->owner, $profile);

        $this->assertDatabaseMissing('import_mapping_profiles', ['id' => $profile->id]);
    }

    public function test_foreign_profile_removal_is_rejected_without_deleting_it(): void
    {
        $import = $this->reviewed();
        $profile = $this->profiles()->save($import, $this->owner, 'Private layout');
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $profile->update(['user_id' => $other->id]);
        try {
            $this->profiles()->remove($import, $this->owner, $profile);
            $this->fail('Another user removed a private profile.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('import_mapping_profiles', ['id' => $profile->id, 'user_id' => $other->id]);
        }
    }

    public function test_a_committed_upload_cannot_be_changed_using_a_profile(): void
    {
        $import = $this->reviewed();
        $profile = $this->profiles()->save($import, $this->owner, 'Private layout');
        $import->update(['status' => Import::STATUS_COMMITTED]);
        $this->expectException(ModelNotFoundException::class);

        $this->profiles()->apply($import, $this->owner, $profile);
    }

    public function test_controller_saves_applies_and_removes_profiles_and_audits_only_identifiers(): void
    {
        $import = $this->reviewed();
        $request = Request::create('/profiles', 'POST', ['name' => 'Reusable registration']);
        $request->setUserResolver(fn () => $this->owner);
        $request->setLaravelSession(app('session')->driver());
        $controller = app(ImportMappingProfileController::class);
        $controller->store($request, $this->webinar, $import);
        $profile = ImportMappingProfile::query()->sole();
        $target = $this->pending();
        $controller->apply($request, $this->webinar, $target, $profile);
        $this->assertFalse($target->fresh()->column_map['options']['source_confirmed']);
        $controller->destroy($request, $this->webinar, $target, $profile);

        $this->assertDatabaseCount('import_mapping_profiles', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import_mapping_profile.saved', 'auditable_id' => $profile->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import_mapping_profile.applied', 'auditable_id' => $profile->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import_mapping_profile.removed', 'auditable_id' => $profile->id]);
        $metadata = DB::table('audit_logs')->where('action', 'like', 'import_mapping_profile.%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('Reusable registration', $metadata);
        $this->assertStringNotContainsString('synthetic-person@example.test', $metadata);
    }

    public function test_controller_does_not_turn_foreign_profile_rejection_into_a_successful_redirect(): void
    {
        $import = $this->reviewed();
        $profile = $this->profiles()->save($import, $this->owner, 'Private layout');
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $profile->update(['user_id' => $other->id]);
        $request = Request::create('/profiles', 'POST');
        $request->setUserResolver(fn () => $this->owner);
        $request->setLaravelSession(app('session')->driver());
        $this->expectException(ModelNotFoundException::class);

        app(ImportMappingProfileController::class)->apply($request, $this->webinar, $import, $profile);
    }

    public function test_profile_routes_apply_ownership_scope_and_require_the_existing_recent_password_policy(): void
    {
        $import = $this->reviewed();
        $this->post(route('admin.webinars.imports.profiles.store', [$this->webinar, $import]), ['name' => 'HTTP registration'])
            ->assertRedirect(route('admin.webinars.imports.map', [$this->webinar, $import, 'step' => 'review']));
        $profile = ImportMappingProfile::query()->sole();
        $target = $this->pending();
        $this->post(route('admin.webinars.imports.profiles.apply', [$this->webinar, $target, $profile]))
            ->assertRedirect(route('admin.webinars.imports.map', [$this->webinar, $target, 'step' => 'columns']));
        $this->assertFalse($target->fresh()->column_map['options']['source_confirmed']);
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $profile->update(['user_id' => $other->id]);
        $this->post(route('admin.webinars.imports.profiles.apply', [$this->webinar, $target, $profile]))->assertNotFound();
        $this->delete(route('admin.webinars.imports.profiles.destroy', [$this->webinar, $target, $profile]))->assertNotFound();

        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->post(route('admin.webinars.imports.profiles.store', [$this->webinar, $import]), ['name' => 'Needs recent password'])
            ->assertRedirect(route('admin.password.confirm'));
        $this->assertDatabaseCount('import_mapping_profiles', 1);
    }

    public function test_profile_controls_offer_plain_forms_and_explain_incompatible_headers(): void
    {
        $import = $this->reviewed();
        $profile = $this->profiles()->save($import, $this->owner, '<Named> layout');
        $target = $this->pending(headers: ['Full Name', 'Email', 'Feedback']);
        $check = $this->profiles()->compatibility($target, $profile);
        $html = view('admin.webinars.imports._profiles', [
            'mappingProfiles' => $this->profiles()->matching($target, $this->owner),
            'profileChecks' => [$profile->id => $check], 'import' => $target, 'webinar' => $this->webinar,
        ])->render();

        $this->assertStringContainsString('&lt;Named&gt; layout', $html);
        $this->assertStringContainsString('Column 1 has different text or position', $html);
        $this->assertStringNotContainsString(route('admin.webinars.imports.profiles.apply', [$this->webinar, $target, $profile]), $html);
        $this->assertStringContainsString(route('admin.webinars.imports.profiles.destroy', [$this->webinar, $target, $profile]), $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
    }

    public function test_an_unattributed_evaluation_profile_preserves_explicit_mode_without_attributing_any_response(): void
    {
        $source = $this->pending('evaluation', headers: ['Feedback', 'Suggestions']);
        $options = CsvImportService::validateOptions($source, [
            'response_mode' => 'unattributed', 'required_columns' => [],
            'source_description' => 'Synthetic anonymous evaluation source', 'source_confirmed' => true,
        ]);
        app(CsvImportService::class)->process($source, [], dryRun: true, options: $options);
        $source->update(['column_map' => array_merge($source->column_map, [
            'map' => [], 'timestamp_format' => 'auto', 'options' => $options,
            'review_hash' => CsvImportService::reviewHash($source, [], 'auto', $options),
        ])]);
        $profile = $this->profiles()->save($source, $this->owner, 'Anonymous feedback');
        $target = $this->pending('evaluation', headers: ['Feedback', 'Suggestions']);

        $this->profiles()->apply($target, $this->owner, $profile);

        $applied = $target->fresh()->column_map;
        $this->assertSame('unattributed', $applied['options']['response_mode']);
        $this->assertSame([], $applied['map']);
        $this->assertSame('', $applied['options']['source_description']);
        $this->assertFalse($applied['options']['source_confirmed']);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_profile_names_are_bounded_and_cannot_be_blank(): void
    {
        $import = $this->reviewed();
        foreach (['   ', str_repeat('x', 81)] as $name) {
            try {
                $this->profiles()->save($import, $this->owner, $name);
                $this->fail('Invalid profile name was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('name', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('import_mapping_profiles', 0);
    }

    private function assertHeaderMismatch(array $headers): void
    {
        $profile = $this->profiles()->save($this->reviewed(), $this->owner, 'Original layout');
        $target = $this->pending(headers: $headers);
        $before = $target->column_map;
        $check = $this->profiles()->compatibility($target, $profile);
        $this->assertFalse($check['compatible']);
        $this->assertStringContainsString('column', $check['message']);
        try {
            $this->profiles()->apply($target, $this->owner, $profile);
            $this->fail('A changed schema reused profile positions.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('column', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->column_map);
    }

    private function profiles(): ImportMappingProfileService
    {
        return app(ImportMappingProfileService::class);
    }

    private function form(string $type = 'registration', ?Webinar $webinar = null): Form
    {
        return ($webinar ?? $this->webinar)->forms()->firstOrCreate(['type' => $type], ['title' => ucfirst($type), 'status' => 'published']);
    }

    private function pending(string $type = 'registration', ?Form $form = null, array $headers = ['Email', 'Full Name', 'Feedback'], ?Webinar $webinar = null): Import
    {
        $webinar ??= $this->webinar;
        $form ??= $this->form($type, $webinar);
        $values = array_map(fn ($header) => match ($header) {
            'Email' => 'synthetic-person@example.test',
            'Full Name' => 'Synthetic Person',
            'Speaker rating' => 'Excellent',
            default => 'Synthetic value',
        }, $headers);
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, escape: '');
        fputcsv($stream, $values, escape: '');
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return app(CsvImportService::class)->storePending($webinar, $form, UploadedFile::fake()->createWithContent('private-source.csv', $contents));
    }

    private function reviewed(string $type = 'registration', ?Form $form = null, array $headers = ['Email', 'Full Name', 'Feedback']): Import
    {
        $import = $this->pending($type, $form, $headers);
        $map = CsvImportService::validateMap($import, $import->column_map['map']);
        $options = CsvImportService::validateOptions($import, [
            'required_columns' => $type === 'registration' ? [] : [count($headers) - 1],
            'answer_map' => $import->column_map['suggested_answers'],
            'source_description' => 'Synthetic original export source',
            'source_confirmed' => true,
            'allow_import_time' => true,
        ], $map);
        app(CsvImportService::class)->process($import, $map, dryRun: true, options: $options);
        $import->update(['column_map' => $import->column_map + [
            'options' => $options, 'timestamp_format' => 'auto',
            'review_hash' => CsvImportService::reviewHash($import, $map, 'auto', $options),
        ]]);

        return $import->fresh();
    }
}
