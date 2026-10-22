<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ImportedResponseSchema;
use App\Services\ParticipantPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AdaptiveResponseImportTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $owner->id, 'requires_verification' => false, 'timezone' => 'Asia/Manila']);
        foreach (FormResponseService::TYPES as $type => $title) {
            $this->webinar->forms()->create(['type' => $type, 'title' => $title, 'status' => 'published']);
        }
    }

    private function form(string $type = 'registration')
    {
        return $this->webinar->forms()->where('type', $type)->firstOrFail();
    }

    private function importOptions(array $extra = []): array
    {
        return $extra + ['allow_import_time' => true, 'source_description' => 'Synthetic response export v1', 'source_confirmed' => true];
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('responses.csv', $contents);
    }

    private function xlsx(array $rows, bool $secondSheet = false, array $styles = [], bool $blankSheets = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'response-test-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            if ($blankSheets) {
                $writer->addRow(Row::fromValues(['', '']));
                $writer->addNewSheetAndMakeItCurrent();
            }
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValuesWithStyles($row, null, $styles));
            }
            if ($blankSheets) {
                $writer->addNewSheetAndMakeItCurrent();
                $writer->addRow(Row::fromValues(['', '']));
            }
            if ($secondSheet) {
                $writer->addNewSheetAndMakeItCurrent();
                $writer->addRow(Row::fromValues(['Another response sheet']));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent('responses.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    private function import($form, UploadedFile $file, array $options = [], ?array $map = null): Import
    {
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, $file);
        $map ??= $import->column_map['map'];
        $options = $this->importOptions($options + ['answer_map' => $import->column_map['suggested_answers'] ?? []]);
        $result = $service->process($import, $map, options: $options);
        $this->assertGreaterThan(0, $result['valid'], json_encode($import->issues()->pluck('issue_message')));

        return $import->fresh();
    }

    public function test_reordered_registration_and_multiple_structures_build_a_union_without_changing_forms(): void
    {
        $form = $this->form();
        $first = $this->import($form, $this->csv("Course,Email Address,Full Name,Year Level,School\nBiology,one@example.test,Person One,0,Local School\n"));
        $this->import($form->fresh(), $this->csv("Suggestions,Participant Name,Email,Course\nMore examples,Person Two,two@example.test,Chemistry\n"));
        $this->assertSame(0, $form->fields()->count());
        $this->assertSame(0, $form->questions()->count());
        $columns = app(FormResponseService::class)->columns($form->fresh());
        $this->assertSame(['Course', 'Year Level', 'School', 'Suggestions'], array_column($columns, 'label'));
        $response = $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('Biology')->assertSee('More examples');
        $response->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->assertSame(1, $first->column_map['analysis']['rows']);
        $this->assertSame('number', $first->column_map['analysis']['columns'][3]['type']);
        $this->assertSame('0', app(FormResponseService::class)->values($form->submissions()->oldest('id')->first(), $columns)[ImportedResponseSchema::extraKey('Year Level')][0]);
    }

    public function test_xlsx_dates_scores_blank_cells_and_original_formulas_are_safe(): void
    {
        $this->webinar->participants()->create(['email' => 'one@example.test', 'full_name' => 'Person One', 'verified_at' => now()]);
        $form = $this->form('pretest');
        $file = $this->xlsx([
            ['Question A', 'Email', 'Score', 'Timestamp', 'Optional', 'Literal formula'],
            ['Answer', 'one@example.test', '8/10', new \DateTimeImmutable('2026-10-20 14:30:00'), '', '=1+1'],
        ], styles: [3 => (new Style)->setFormat('yyyy-mm-dd hh:mm:ss')]);
        $import = $this->import($form, $file);
        $row = $form->submissions()->sole();
        $this->assertSame('8.00', $row->score);
        $this->assertSame('2026-10-20 06:30:00', $row->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame('xlsx', $import->column_map['format']);
        $this->assertSame(1, $import->column_map['analysis']['columns'][4]['empty']);
        $this->assertSame('=1+1', $row->imported_data['original'][5]['value']);
        $this->assertStringContainsString("'=1+1", $this->get(route('admin.responses.export', [$this->webinar, 'type' => 'pretest']))->streamedContent());
        $this->assertStringNotContainsString('one@example.test', DB::table('submissions')->where('id', $row->id)->value('imported_data'));
    }

    public function test_unique_exact_question_mapping_combines_native_and_imported_choice_analytics(): void
    {
        $form = $this->form('evaluation');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Speaker rating', 'field_type' => 'radio', 'options' => ['1', '2', '3', '4', '5'], 'is_required' => true]);
        $participant = $this->webinar->participants()->create(['email' => 'one@example.test', 'full_name' => 'Person One', 'verified_at' => now()]);
        $native = $form->submissions()->create(['participant_id' => $participant->id, 'attempt_number' => 1, 'status' => 'submitted', 'submitted_at' => now()]);
        $native->answers()->create(['form_field_id' => $field->id, 'value' => ['5']]);
        $this->webinar->participants()->create(['email' => 'two@example.test', 'full_name' => 'Person Two', 'verified_at' => now()]);
        $import = $this->import($form->fresh(), $this->csv("Feedback,Email,Speaker rating,Suggestions\nHelpful,two@example.test,4,More time\n"), ['required_columns' => [2]]);
        $this->assertSame(['field_'.$field->id => 2], $import->column_map['suggested_answers']);
        $columns = app(FormResponseService::class)->columns($form->fresh());
        $summary = app(FormResponseService::class)->summary($form->fresh(), $columns);
        $this->assertSame(['5' => 1, '4' => 1], $summary['questions']['field_'.$field->id]['counts']);
        $this->assertSame(['Helpful'], $summary['questions'][ImportedResponseSchema::extraKey('Feedback')]['texts']);
        $this->get(route('admin.responses.index', [$this->webinar, 'type' => 'evaluation', 'view' => 'summary']))->assertOk()->assertSee('1 (50%)')->assertSee('More time');
    }

    public function test_similar_or_duplicate_native_labels_are_not_auto_mapped(): void
    {
        $form = $this->form();
        foreach (['Rating', 'Rating', 'Content quality'] as $index => $label) {
            $form->fields()->create(['key' => 'q'.$index, 'label' => $label, 'field_type' => 'text']);
        }
        $import = app(CsvImportService::class)->storePending($this->webinar, $form->fresh(), $this->csv("Email,Name,Rating,Content rating\none@example.test,Person One,5,Great\n"));
        $this->assertSame([], $import->column_map['suggested_answers']);
        $this->get(route('admin.webinars.imports.map', [$this->webinar, $import]))->assertOk()->assertSee('Additional imported field')->assertSee('4 columns');
    }

    public function test_exact_duplicate_xlsx_and_cross_format_reimports_do_not_overwrite(): void
    {
        $form = $this->form();
        $file = $this->xlsx([['Email', 'Name', 'Course'], ['one@example.test', 'Person One', 'Math']]);
        $this->import($form, $file);
        $service = app(CsvImportService::class);
        foreach ([$file, $this->csv("Name,Course,Email\nPerson One,Physics,one@example.test\n")] as $again) {
            $import = $service->storePending($this->webinar, $form->fresh(), $again);
            $counts = $service->process($import, $import->column_map['map'], options: $this->importOptions());
            $this->assertSame(0, $counts['valid']);
            $this->assertSame(1, $counts['duplicate']);
        }
        $this->assertDatabaseCount('submissions', 1);
        $this->assertSame('Math', $form->submissions()->sole()->imported_data['original'][2]['value']);
    }

    public function test_numeric_extra_answers_are_not_scores_or_ratings_and_missing_required_score_holds_completion(): void
    {
        $this->webinar->participants()->create(['email' => 'one@example.test', 'full_name' => 'Person One', 'verified_at' => now()]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'posttest', 'is_required' => true, 'minimum_score' => 8]);
        $form = $this->form('posttest');
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, $this->csv("Email,Year Level,Question A\none@example.test,99,Yes\n"));
        $this->assertArrayNotHasKey('score', $import->column_map['map']);
        $result = $service->process($import, $import->column_map['map'], options: $this->importOptions(['required_columns' => [2]]));
        $this->assertSame(1, $result['invalid']);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertFalse(app(EligibilityService::class)->evaluate($this->webinar->participants()->sole())['eligible']);
    }

    public function test_ambiguous_dates_invalid_scores_and_missing_optional_values_are_accounted_for(): void
    {
        $form = $this->form('pretest');
        foreach (['one', 'two', 'three'] as $name) {
            $this->webinar->participants()->create(['email' => $name.'@example.test', 'full_name' => $name, 'verified_at' => now()]);
        }
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, $this->csv("Email,Score,Timestamp,Optional\none@example.test,8/10,10/11/2026 10:00,\ntwo@example.test,n/a,2026-10-20 10:00,\nthree@example.test,0,2026-10-20 10:00,\n"));
        $result = $service->process($import, $import->column_map['map'], options: $this->importOptions());
        $this->assertSame(1, $result['valid']);
        $this->assertSame(2, $result['invalid']);
        $this->assertSame('0.00', $form->submissions()->sole()->score);
        $this->assertSame(3, $import->fresh()->total_rows);
    }

    public function test_new_native_definition_snapshot_and_retired_questions_preserve_historical_answers(): void
    {
        $form = $this->form();
        $field = $form->fields()->create(['key' => 'feedback', 'label' => 'Original question wording', 'field_type' => 'text']);
        $this->post($form->shareUrl(), ['full_name' => 'Person One', 'email' => 'one@example.test', 'fields' => [$field->id => 'Historical answer'], 'privacy_acknowledged' => '1'])->assertSessionHasNoErrors();
        $submission = $form->submissions()->sole();
        $field->update(['label' => 'Updated wording']);
        $this->get(route('admin.responses.show', [$this->webinar, $submission]))->assertOk()->assertSee('Original question wording')->assertSee('Historical answer');
        $this->delete(route('admin.forms.fields.destroy', [$this->webinar, $form, $field]))->assertSessionHasNoErrors();
        $this->assertSame(0, $form->fields()->count());
        $this->assertSame(1, $submission->answers()->count());
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('Updated wording (retired)')->assertSee('Historical answer');
    }

    public function test_bad_workbooks_and_ambiguous_headers_leave_no_pending_import_or_participant(): void
    {
        $files = [
            UploadedFile::fake()->createWithContent('bad.xlsx', 'not a zip'),
            $this->xlsx([['Email', 'Name'], ['one@example.test', 'Person One']], secondSheet: true),
            $this->csv("Email,Email\na,b\n"),
            $this->csv("Email,,Name\na,b,c\n"),
            $this->csv("Email,Name\none@example.test,\xff\n"),
        ];
        foreach ($files as $file) {
            try {
                app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
                $this->fail('Invalid response file was accepted.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('imports', 0);
                $this->assertDatabaseCount('participants', 0);
            }
        }
    }

    public function test_unsafe_archive_xml_and_huge_column_references_are_rejected_before_parsing(): void
    {
        foreach (['<!DOCTYPE x [<!ENTITY evil SYSTEM "file:///secret">]><x/>', '<worksheet><dimension ref="A1:ZZZZZZ999999"/></worksheet>'] as $xml) {
            $file = $this->xlsx([['Email', 'Name'], ['one@example.test', 'Person One']]);
            $archivePath = tempnam(sys_get_temp_dir(), 'unsafe-response-');
            copy($file->getRealPath(), $archivePath);
            $zip = new ZipArchive;
            $zip->open($archivePath);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
            $file = UploadedFile::fake()->createWithContent('unsafe.xlsx', file_get_contents($archivePath));
            unlink($archivePath);
            try {
                app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
                $this->fail('Unsafe archive accepted.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('imports', 0);
            }
        }
    }

    public function test_imported_xss_is_escaped_and_privacy_erasure_clears_extra_answers(): void
    {
        $form = $this->form();
        $this->import($form, $this->csv("Email,Name,Feedback\none@example.test,Person One,<script>alert(1)</script>\n"));
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
        $row = $form->submissions()->sole();
        app(ParticipantPrivacyService::class)->erase($row->participant);
        $this->assertNull($row->fresh()->imported_data);
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertDontSee('alert(1)');
    }

    public function test_structure_preview_and_final_reviewed_xlsx_import_work_for_authorized_owner(): void
    {
        $form = $this->form();
        $field = $form->fields()->create(['key' => 'course', 'label' => 'Course', 'field_type' => 'text']);
        $this->post(route('admin.webinars.imports.preview', $this->webinar), ['form_id' => $form->id, 'file' => $this->xlsx([['Course', 'Name', 'Email'], ['Math', 'Person One', 'one@example.test']])])->assertSessionHasNoErrors();
        $import = Import::query()->sole();
        $this->get(route('admin.webinars.imports.map', [$this->webinar, $import]))->assertOk()->assertSee('XLSX')->assertSee('1 response rows')->assertSee('Math');
        $payload = $this->importOptions(['map' => ['email' => 2, 'full_name' => 1], 'answer_map' => ['field_'.$field->id => 0], 'review_confirmed' => '1']);
        $this->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), $payload)->assertSessionHas('dry_run');
        $this->assertDatabaseCount('submissions', 0);
        $this->post(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload)->assertSessionHasNoErrors();
        $this->assertSame('committed', $import->fresh()->status);
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('Math');
        Storage::disk('local')->assertMissing('imports-pending/import-'.$import->id.'.csv');
    }

    public function test_importing_one_form_does_not_change_another_webinar_or_form(): void
    {
        $other = Webinar::factory()->create(['created_by' => auth()->id()]);
        $otherForm = $other->forms()->create(['type' => 'registration', 'title' => 'Other form', 'status' => 'published']);
        $person = $other->participants()->create(['email' => 'one@example.test', 'full_name' => 'Original Identity']);
        $existing = $otherForm->submissions()->create(['participant_id' => $person->id, 'attempt_number' => 1, 'status' => 'submitted']);
        $this->import($this->form(), $this->csv("Name,Email,New field\nPerson One,one@example.test,New answer\n"));
        $this->assertSame('Original Identity', $person->fresh()->full_name);
        $this->assertNull($existing->fresh()->imported_data);
        $this->assertSame([], app(FormResponseService::class)->columns($otherForm));
        $this->assertSame([], app(FormResponseService::class)->columns($this->form('evaluation')));
    }

    public function test_limits_and_transaction_failure_do_not_leave_partial_responses(): void
    {
        try {
            app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv('Email,Name'.str_repeat(',Header', 200)."\n"));
            $this->fail('Wide upload accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('imports', 0);
        }
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv("Email,Name\none@example.test,Person One\ntwo@example.test,Person Two\n"));
        Submission::creating(function () {
            throw new RuntimeException('Simulated database write failure');
        });
        try {
            app(CsvImportService::class)->process($import, $import->column_map['map'], options: $this->importOptions());
            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database write failure', $exception->getMessage());
        } finally {
            Submission::flushEventListeners();
        }
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame('pending', $import->fresh()->status);
        Storage::disk('local')->assertExists('imports-pending/import-'.$import->id.'.csv');
    }

    public function test_a_thousand_response_rows_remain_paginated_and_keep_all_extra_values(): void
    {
        $lines = ['Email,Name,Course,Year Level,Optional'];
        for ($i = 1; $i <= 1000; $i++) {
            $lines[] = 'person'.$i.'@example.test,Participant '.$i.',Course '.$i.',0,';
        }
        $form = $this->form();
        $import = $this->import($form, $this->csv(implode("\n", $lines)."\n"));
        $this->assertSame(1000, $import->valid_rows);
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1000 && $rows->count() === 25);
        $this->get(route('admin.responses.index', [$this->webinar, 'page' => 40]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 25);
        $summary = app(FormResponseService::class)->summary($form->fresh(), app(FormResponseService::class)->columns($form->fresh()));
        $this->assertSame(1000, $summary['total']);
        $this->assertSame(1000, $summary['questions'][ImportedResponseSchema::extraKey('Year Level')]['answered']);
        $this->assertSame(0, $summary['scores']['count']);
    }

    public function test_checkbox_number_and_score_summaries_use_declared_types(): void
    {
        $form = $this->form();
        $checkbox = $form->fields()->create(['key' => 'topics', 'label' => 'Topics', 'field_type' => 'checkbox', 'options' => ['A', 'B', 'C']]);
        $number = $form->fields()->create(['key' => 'hours', 'label' => 'Hours', 'field_type' => 'number']);
        $import = $this->import($form->fresh(), $this->csv("Email,Name,Topics,Hours\none@example.test,Person One,\"A, B\",2\ntwo@example.test,Person Two,A,4\n"));
        $columns = app(FormResponseService::class)->columns($form->fresh());
        $summary = app(FormResponseService::class)->summary($form->fresh(), $columns);
        $this->assertSame(['A' => 2, 'B' => 1], $summary['questions']['field_'.$checkbox->id]['counts']);
        $this->assertSame(6.0, $summary['questions']['field_'.$number->id]['numbers']['sum']);
        $this->assertSame(0, $summary['scores']['count']);
        $this->assertSame(2, $import->valid_rows);
    }

    public function test_choice_answers_use_the_validated_value_and_keep_the_original_cell(): void
    {
        $form = $this->form('pretest');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Rating', 'field_type' => 'radio', 'options' => ['4', '5']]);
        $question = $form->questions()->create(['prompt' => 'Quiz answer', 'question_type' => 'multiple_choice', 'points' => 1]);
        $choice = $question->choices()->create(['label' => 'Correct answer', 'is_correct' => true]);
        $this->webinar->participants()->create(['email' => 'one@example.test', 'full_name' => 'Person One', 'verified_at' => now()]);
        $this->import($form->fresh(), $this->csv("Email,Rating,Quiz answer,Score\none@example.test, 5 , Correct answer ,1/1\n"));
        $submission = $form->submissions()->sole();
        $this->assertSame(['5'], $submission->imported_data['mapped']['field_'.$field->id]);
        $this->assertSame([$choice->id], $submission->answers()->where('question_id', $question->id)->sole()->value);
        $this->assertSame(' 5 ', $submission->imported_data['original'][1]['value']);
        $this->assertSame(' Correct answer ', $submission->imported_data['original'][2]['value']);
        $columns = app(FormResponseService::class)->columns($form->fresh());
        $summary = app(FormResponseService::class)->summary($form->fresh(), $columns);
        $this->assertSame(['5' => 1], $summary['questions']['field_'.$field->id]['counts']);
        $this->assertSame(['Correct answer' => 1], $summary['questions']['question_'.$question->id]['counts']);
    }

    public function test_checkbox_choices_with_commas_are_mapped_only_when_the_selection_is_unambiguous(): void
    {
        $form = $this->form();
        $field = $form->fields()->create(['key' => 'topics', 'label' => 'Topics', 'field_type' => 'checkbox',
            'options' => ['Research, teaching', 'Administration', 'A', 'B', 'A, B']]);
        $import = $this->import($form->fresh(), $this->csv("Email,Name,Topics\none@example.test,Person One,\"Research, teaching, Administration\"\ntwo@example.test,Person Two,\"A, B\"\n"));
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(1, $import->invalid_rows);
        $this->assertSame(['Research, teaching', 'Administration'], $form->submissions()->sole()->imported_data['mapped']['field_'.$field->id]);
        $this->assertStringContainsString('Ambiguous checkbox', $import->issues()->sole()->issue_message);
        $this->assertDatabaseCount('participants', 1);
        $columns = app(FormResponseService::class)->columns($form->fresh());
        $summary = app(FormResponseService::class)->summary($form->fresh(), $columns);
        $this->assertSame(['Research, teaching' => 1, 'Administration' => 1], $summary['questions']['field_'.$field->id]['counts']);
    }

    public function test_an_xlsx_response_sheet_can_have_blank_worksheets_before_and_after_it(): void
    {
        $form = $this->form();
        $import = $this->import($form, $this->xlsx([
            ['Email', 'Name', 'Optional'], ['one@example.test', 'Person One', ''], ['', '', ''], ['invalid email', 'Person Two', ''],
        ], blankSheets: true));
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame([3, 4], $import->issues()->orderBy('row_number')->pluck('row_number')->all());
    }

    public function test_held_rows_keep_their_definition_and_cannot_attach_to_changed_question_types(): void
    {
        $form = $this->form('evaluation');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Original rating', 'field_type' => 'radio', 'options' => ['4', '5']]);
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form->fresh(), $this->csv("Email,Name,Original rating\none@example.test,Person One, 5 \n"));
        $counts = $service->process($import, $import->column_map['map'], options: $this->importOptions([
            'required_columns' => [2], 'answer_map' => ['field_'.$field->id => 2],
        ]));
        $this->assertSame(1, $counts['unmatched']);
        $issue = $import->issues()->sole();
        $field->update(['field_type' => 'number']);
        try {
            $service->promote($issue, auth()->user(), 'Person One');
            $this->fail('A held answer was attached to a different question type.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mapped question changed', $exception->getMessage());
        }
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertSame(' 5 ', $issue->raw_data['Original rating']);
        $field->update(['field_type' => 'radio', 'label' => 'Updated rating wording']);
        $service->promote($issue->fresh(), auth()->user(), 'Person One');
        $submission = $form->submissions()->sole();
        $this->assertSame('Original rating', $submission->imported_data['schema']['field_'.$field->id]['label']);
        $this->assertSame(['5'], $submission->imported_data['mapped']['field_'.$field->id]);
    }

    public function test_historical_imports_with_original_cells_still_render_after_question_retirement(): void
    {
        $form = $this->form();
        $field = $form->fields()->create(['key' => 'course', 'label' => 'Course', 'field_type' => 'text']);
        $import = $this->import($form->fresh(), $this->csv("Email,Name,Course,Additional\none@example.test,Person One,Math,Keep original\n"));
        $columnMap = $import->column_map;
        unset($columnMap['response_schema']);
        $import->update(['column_map' => $columnMap]);
        $field->delete();
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('Course (retired)')->assertSee('Math')->assertSee('Keep original');
    }

    public function test_formula_injection_is_neutralized_in_imported_headers_as_well_as_answers(): void
    {
        $this->import($this->form(), $this->csv("Email,Name,=1+1\none@example.test,Person One,=2+2\n"));
        $csv = $this->get(route('admin.responses.export', $this->webinar))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'=2+2", $csv);
    }

    public function test_empty_excel_rows_preserve_the_source_row_numbers_for_errors(): void
    {
        $form = $this->form();
        $import = $this->import($form, $this->xlsx([
            ['Email', 'Name'], ['one@example.test', 'Person One'], ['', ''], ['invalid email', 'Person Two'],
        ]));
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(2, $import->invalid_rows);
        $this->assertSame([3, 4], $import->issues()->orderBy('row_number')->pluck('row_number')->all());
    }

    public function test_retirement_migration_preserves_existing_rows_and_retired_quiz_answers(): void
    {
        $form = $this->form('pretest');
        $question = $form->questions()->create(['prompt' => 'Historical quiz prompt', 'question_type' => 'multiple_choice', 'points' => 1]);
        $choice = $question->choices()->create(['label' => 'Historical choice', 'is_correct' => true]);
        $participant = $this->webinar->participants()->create(['email' => 'one@example.test', 'full_name' => 'Person One', 'verified_at' => now()]);
        $submission = $form->submissions()->create(['participant_id' => $participant->id, 'attempt_number' => 1, 'status' => 'submitted', 'score' => 1, 'maximum_score' => 1]);
        $submission->answers()->create(['question_id' => $question->id, 'value' => [$choice->id]]);
        $tables = ['participants', 'submissions', 'submission_answers', 'form_fields', 'questions', 'question_choices'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();
        $migration = require database_path('migrations/2026_10_21_000200_preserve_retired_response_questions.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $snapshot());
        $this->delete(route('admin.forms.questions.destroy', [$this->webinar, $form, $question]))->assertSessionHasNoErrors();
        $this->assertSame(0, $form->questions()->count());
        $this->assertSame(1, $submission->answers()->count());
        $this->get(route('admin.responses.show', [$this->webinar, $submission]))->assertOk()->assertSee('Historical quiz prompt (retired)')->assertSee('Historical choice');
    }
}
