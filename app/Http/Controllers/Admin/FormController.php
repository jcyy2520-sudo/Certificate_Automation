<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Question;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Support\LocalDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FormController extends Controller
{
    public function edit(Webinar $webinar, Form $form): View
    {
        $this->assertBelongsTo($webinar, $form);
        // answers_count drives the "already answered" lock without a query per question.
        $form->load([
            'fields',
            'questions' => fn ($query) => $query->withCount('answers'),
            'questions.choices',
        ]);
        $form->setRelation('webinar', $webinar);

        return view('admin.forms.edit', compact('webinar', 'form'));
    }

    public function update(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'opens_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'closes_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:opens_at'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'show_score' => ['nullable', 'boolean'],
        ]);
        $data['opens_at'] = LocalDateTime::toUtc(
            $data['opens_at'] ?? null,
            $webinar->timezone,
            'opens_at',
        );
        $data['closes_at'] = LocalDateTime::toUtc(
            $data['closes_at'] ?? null,
            $webinar->timezone,
            'closes_at',
        );
        $data['show_score'] = $request->boolean('show_score');

        DB::transaction(function () use ($audit, $data, $form, $request, $webinar): void {
            $lockedForm = $this->lockedForm($webinar, $form);
            $lockedForm->update($data);
            $audit->record($request, 'form.updated', $lockedForm);
        });

        return back()->with('success', 'Form settings saved.');
    }

    /**
     * Open or close a form in one click, without re-submitting the whole
     * settings form. The database keeps its legacy status values internally,
     * while the organizer only has one explicit Open/Closed switch.
     */
    public function toggle(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse|JsonResponse
    {
        $this->assertBelongsTo($webinar, $form);

        $data = $request->validate([
            'is_open' => ['sometimes', 'boolean'],
            // Backward-compatible input for older bookmarks/tests; the UI no
            // longer renders or names this lifecycle field.
            'status' => ['required_without:is_open', Rule::in(['published', 'closed'])],
        ]);
        $status = $request->has('is_open')
            ? ($request->boolean('is_open') ? 'published' : 'closed')
            : $data['status'];

        $updatedForm = DB::transaction(function () use ($audit, $form, $request, $status, $webinar): Form {
            // Keep the same webinar -> form lock order used by public
            // submissions so an availability change is atomic with a response.
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $lockedForm = Form::query()
                ->whereKey($form->id)
                ->where('webinar_id', $lockedWebinar->id)
                ->lockForUpdate()
                ->firstOrFail();

            $formChanges = ['status' => $status];
            $webinarChanges = [];

            if ($status === 'published') {
                // "Open" means open now. A future opening time or an already
                // expired closing time must not contradict the manual switch.
                // A future closing deadline is preserved and will still close
                // the form automatically when that time arrives.
                if ($lockedForm->opens_at?->isFuture()) {
                    $formChanges['opens_at'] = null;
                }
                if ($lockedForm->closes_at?->isPast()) {
                    $formChanges['closes_at'] = null;
                }

                if ($lockedForm->type === 'registration') {
                    if ($lockedWebinar->registration_opens_at?->isFuture()) {
                        $webinarChanges['registration_opens_at'] = null;
                    }
                    if ($lockedWebinar->registration_closes_at?->isPast()) {
                        $webinarChanges['registration_closes_at'] = null;
                    }
                }
            }

            if ($webinarChanges !== []) {
                $lockedWebinar->update($webinarChanges);
            }
            $lockedForm->update($formChanges);
            $lockedForm->setRelation('webinar', $lockedWebinar);

            $audit->record($request, 'form.availability_changed', $lockedForm, [
                'open' => $status === 'published',
                'schedule_overridden' => count($formChanges) > 1 || $webinarChanges !== [],
            ]);

            return $lockedForm;
        });

        $acceptsResponses = $updatedForm->acceptsResponses();
        $message = $this->availabilityMessage($updatedForm, $updatedForm->webinar, $acceptsResponses);

        if ($request->expectsJson()) {
            return response()->json([
                'open' => $updatedForm->isOpen(),
                'accepts_responses' => $acceptsResponses,
                'state' => $acceptsResponses
                    ? 'Open — accepting responses now'
                    : ($updatedForm->isOpen() ? 'Open, but responses are currently blocked' : 'Closed — not accepting responses'),
                'message' => $message,
            ]);
        }

        return back()->with('success', $status === 'published'
            ? $message
            : 'Form closed. It no longer accepts new responses.');
    }

    private function availabilityMessage(Form $form, Webinar $webinar, bool $acceptsResponses): string
    {
        if ($acceptsResponses) {
            return 'Opened. This form is accepting responses now.';
        }

        if (! $form->isOpen()) {
            return 'Closed. This form is not accepting responses.';
        }

        if (! $webinar->isOpen()) {
            return 'The form is open, but the webinar is closed. Open the webinar in Webinar settings.';
        }

        return $form->closedReason();
    }

    /** Retire the current share link and mint a new one. Anyone holding the old URL loses access. */
    public function rotateLink(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);

        DB::transaction(function () use ($audit, $form, $request, $webinar): void {
            $lockedForm = $this->lockedForm($webinar, $form);
            $lockedForm->update([
                'public_token' => Form::newPublicToken(),
                'link_rotated_at' => now(),
            ]);
            $audit->record($request, 'form.link_rotated', $lockedForm);
        });

        return back()->with('success', 'A new share link was generated. The previous link no longer opens this form.');
    }

    public function storeField(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:180'],
            'field_type' => ['required', Rule::in(['text', 'email', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox'])],
            'help_text' => ['nullable', 'string', 'max:500'],
            'options_text' => ['nullable', 'string', 'max:3000'],
            'is_required' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($audit, $data, $form, $request, $webinar): void {
            $lockedForm = $this->lockedForm($webinar, $form);
            $field = $lockedForm->fields()->create([
                ...collect($data)->except('options_text', 'is_required')->all(),
                'key' => $this->uniqueFieldKey($lockedForm, $data['label']),
                'options' => $this->lines($data['options_text'] ?? '')->all(),
                'is_required' => $request->boolean('is_required'),
                'sort_order' => ($lockedForm->fields()->max('sort_order') ?? 0) + 1,
            ]);
            $audit->record($request, 'form_field.created', $field);
        });

        return back()->with('success', 'Field added.');
    }

    public function updateField(Request $request, Webinar $webinar, Form $form, FormField $field, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_unless($field->form_id === $form->id, 404);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:180'],
            'field_type' => ['required', Rule::in(['text', 'email', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox'])],
            'help_text' => ['nullable', 'string', 'max:500'],
            'options_text' => ['nullable', 'string', 'max:3000'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        // The key is generated once at creation and never shown to admins, so
        // editing a field only ever touches label/type/help/options — never
        // regenerates or exposes the key for retyping.
        DB::transaction(function () use ($audit, $data, $field, $form, $request, $webinar): void {
            $this->lockedForm($webinar, $form);
            $lockedField = FormField::query()
                ->whereKey($field->id)
                ->where('form_id', $form->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedField->update([
                ...collect($data)->except('options_text', 'is_required')->all(),
                'options' => $this->lines($data['options_text'] ?? '')->all(),
                'is_required' => $request->boolean('is_required'),
            ]);
            $audit->record($request, 'form_field.updated', $lockedField);
        });

        return back()->with('success', 'Field updated.');
    }

    public function updateQuestion(Request $request, Webinar $webinar, Form $form, Question $question, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_unless($question->form_id === $form->id, 404);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'question_type' => ['required', Rule::in(['multiple_choice', 'true_false', 'text'])],
            'points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'choices' => ['array', 'max:20'],
            'choices.*' => ['nullable', 'string', 'max:500'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        // A locked (answered) question's edit form has no choice inputs in it
        // at all, so a legitimate browser submit never sends "choices" or
        // "correct_choice". Only bother re-validating choices when this
        // request actually included them — which only happens for an
        // unlocked question, or a crafted request trying to bypass the lock.
        $touchesChoices = $request->has('choices') || $request->has('correct_choice');
        $rows = $touchesChoices ? $this->submittedChoiceRows($request) : collect();

        DB::transaction(function () use ($audit, $data, $form, $question, $request, $rows, $touchesChoices, $webinar): void {
            // Public submissions hold a shared lock on this same form row while
            // validating and writing answers. Taking the exclusive lock before
            // checking answer state makes the definition we inspect authoritative.
            $this->lockedForm($webinar, $form);
            $lockedQuestion = Question::query()
                ->whereKey($question->id)
                ->where('form_id', $form->id)
                ->lockForUpdate()
                ->firstOrFail();
            $answered = $lockedQuestion->answers()->exists();

            if ($answered && $data['question_type'] !== $lockedQuestion->question_type) {
                throw ValidationException::withMessages([
                    'question_type' => 'This question already has answers, so its answer type can no longer be changed.',
                ]);
            }

            if ($answered && $touchesChoices && $this->choicesChanged($lockedQuestion, $rows)) {
                throw ValidationException::withMessages([
                    'choices' => 'This question already has answers, so its choices can no longer be changed. Delete it and add a replacement instead.',
                ]);
            }

            if (! $answered && $data['question_type'] !== 'text') {
                if ($rows->count() < 2) {
                    throw ValidationException::withMessages(['choices' => 'Add at least two choices.']);
                }

                if (! $rows->contains('is_correct', true)) {
                    throw ValidationException::withMessages(['choices' => 'Select which choice is correct.']);
                }
            }

            $lockedQuestion->update([
                ...collect($data)->except('choices', 'is_required')->all(),
                'is_required' => $request->boolean('is_required'),
            ]);

            if ($answered) {
                $audit->record($request, 'question.updated', $lockedQuestion);

                return;
            }

            $lockedQuestion->choices()->delete();

            if ($data['question_type'] !== 'text') {
                $rows->values()->each(function (array $row, int $index) use ($lockedQuestion): void {
                    $lockedQuestion->choices()->create([
                        'label' => $row['label'],
                        'is_correct' => $row['is_correct'],
                        'sort_order' => $index + 1,
                    ]);
                });
            }

            $audit->record($request, 'question.updated', $lockedQuestion);
        });

        return back()->with('success', 'Question updated.');
    }

    /**
     * Turn the submitted `choices[i]` + `correct_choice` fields into
     * {label, is_correct} rows. Blank choice text is dropped (a stray empty
     * row from the editor), but correctness is still matched by the
     * original submitted index, not the post-filter position.
     *
     * @return Collection<int, array{label: string, is_correct: bool}>
     */
    private function submittedChoiceRows(Request $request): Collection
    {
        $correctIndex = (string) $request->input('correct_choice');

        return collect($request->input('choices', []))
            ->map(fn ($label, $index) => ['index' => (string) $index, 'label' => trim((string) $label)])
            ->filter(fn (array $row) => $row['label'] !== '')
            ->map(fn (array $row) => ['label' => $row['label'], 'is_correct' => $row['index'] === $correctIndex])
            ->values();
    }

    /** Whether the submitted choice list differs from what is stored. */
    private function choicesChanged(Question $question, Collection $rows): bool
    {
        $existing = $question->choices()->orderBy('sort_order')->get()
            ->map(fn ($choice) => ['label' => $choice->label, 'is_correct' => $choice->is_correct])
            ->values()->all();

        $submitted = $rows->map(fn (array $row) => ['label' => $row['label'], 'is_correct' => $row['is_correct']])->values()->all();

        return $existing !== $submitted;
    }

    /** Split a textarea into trimmed, non-empty lines. */
    private function lines(?string $value): Collection
    {
        return collect(preg_split('/\R/', (string) $value))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values();
    }

    public function destroyField(Request $request, Webinar $webinar, Form $form, FormField $field, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_unless($field->form_id === $form->id, 404);

        DB::transaction(function () use ($audit, $field, $form, $request, $webinar): void {
            $this->lockedForm($webinar, $form);
            $lockedField = FormField::query()
                ->whereKey($field->id)
                ->where('form_id', $form->id)
                ->lockForUpdate()
                ->firstOrFail();
            $audit->record($request, 'form_field.deleted', $lockedField, ['label' => $lockedField->label]);
            $lockedField->delete();
        });

        return back()->with('success', 'Field removed.');
    }

    public function storeQuestion(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_if($form->type === 'registration', 422, 'Questions are not supported on the registration form.');
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'question_type' => ['required', Rule::in(['multiple_choice', 'true_false', 'text'])],
            'points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'choices' => ['array', 'max:20'],
            'choices.*' => ['nullable', 'string', 'max:500'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $rows = $this->submittedChoiceRows($request);

        if ($data['question_type'] !== 'text' && $rows->count() < 2) {
            return back()->withErrors(['choices' => 'Add at least two choices.'])->withInput();
        }

        if ($data['question_type'] !== 'text' && ! $rows->contains('is_correct', true)) {
            return back()->withErrors(['choices' => 'Select which choice is correct.'])->withInput();
        }

        DB::transaction(function () use ($audit, $data, $form, $request, $rows, $webinar): void {
            $lockedForm = $this->lockedForm($webinar, $form);
            abort_if($lockedForm->type === 'registration', 422, 'Questions are not supported on the registration form.');

            $question = $lockedForm->questions()->create([
                ...collect($data)->except('choices', 'is_required')->all(),
                'is_required' => $request->boolean('is_required'),
                'sort_order' => ($lockedForm->questions()->max('sort_order') ?? 0) + 1,
            ]);

            if ($data['question_type'] !== 'text') {
                $rows->values()->each(function (array $row, int $index) use ($question): void {
                    $question->choices()->create([
                        'label' => $row['label'],
                        'is_correct' => $row['is_correct'],
                        'sort_order' => $index + 1,
                    ]);
                });
            }

            $audit->record($request, 'question.created', $question);
        });

        return back()->with('success', 'Question added.');
    }

    public function destroyQuestion(Request $request, Webinar $webinar, Form $form, Question $question, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_unless($question->form_id === $form->id, 404);

        DB::transaction(function () use ($audit, $form, $question, $request, $webinar): void {
            $this->lockedForm($webinar, $form);
            $lockedQuestion = Question::query()
                ->whereKey($question->id)
                ->where('form_id', $form->id)
                ->lockForUpdate()
                ->firstOrFail();
            $audit->record($request, 'question.deleted', $lockedQuestion, ['prompt' => $lockedQuestion->prompt]);
            $lockedQuestion->delete();
        });

        return back()->with('success', 'Question removed.');
    }

    private function assertBelongsTo(Webinar $webinar, Form $form): void
    {
        abort_unless($form->webinar_id === $webinar->id, 404);
    }

    /** Derive a stable internal key from the label so admins never have to type one. */
    private function uniqueFieldKey(Form $form, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $key = $base;
        $counter = 2;

        while ($form->fields()->where('key', $key)->exists()) {
            $key = $base.'_'.$counter++;
        }

        return $key;
    }

    /** Re-fetch the form row locked for the duration of the current transaction. */
    private function lockedForm(Webinar $webinar, Form $form): Form
    {
        return Form::query()
            ->whereKey($form->id)
            ->where('webinar_id', $webinar->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
