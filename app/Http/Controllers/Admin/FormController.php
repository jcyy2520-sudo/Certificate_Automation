<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Question;
use App\Models\Webinar;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
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

        return view('admin.forms.edit', compact('webinar', 'form'));
    }

    public function update(Request $request, Webinar $webinar, Form $form, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
            'opens_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'closes_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after_or_equal:opens_at'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'show_score' => ['nullable', 'boolean'],
        ]);
        $data['opens_at'] = $this->localDateTimeToUtc($data['opens_at'] ?? null, $webinar->timezone);
        $data['closes_at'] = $this->localDateTimeToUtc($data['closes_at'] ?? null, $webinar->timezone);
        $data['show_score'] = $request->boolean('show_score');

        DB::transaction(function () use ($audit, $data, $form, $request, $webinar): void {
            $lockedForm = $this->lockedForm($webinar, $form);
            $lockedForm->update($data);
            $audit->record($request, 'form.updated', $lockedForm);
        });

        return back()->with('success', 'Form settings saved.');
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

        $answered = $question->answers()->exists();

        // A locked (answered) question's edit form has no choice inputs in it
        // at all, so a legitimate browser submit never sends "choices" or
        // "correct_choice". Only bother re-validating choices when this
        // request actually included them — which only happens for an
        // unlocked question, or a crafted request trying to bypass the lock.
        $touchesChoices = $request->has('choices') || $request->has('correct_choice');
        $rows = $touchesChoices ? $this->submittedChoiceRows($request) : collect();

        if ($answered && $touchesChoices && $this->choicesChanged($question, $rows)) {
            return back()
                ->withInput()
                ->withErrors(['choices' => 'This question already has answers, so its choices can no longer be changed. Delete it and add a replacement instead.']);
        }

        if (! $answered && $data['question_type'] !== 'text') {
            if ($rows->count() < 2) {
                return back()->withInput()->withErrors(['choices' => 'Add at least two choices.']);
            }

            if (! $rows->contains('is_correct', true)) {
                return back()->withInput()->withErrors(['choices' => 'Select which choice is correct.']);
            }
        }

        DB::transaction(function () use ($question, $data, $request, $rows, $answered): void {
            $question->update([
                ...collect($data)->except('choices', 'is_required')->all(),
                'is_required' => $request->boolean('is_required'),
            ]);

            if ($answered) {
                return;
            }

            $question->choices()->delete();

            if ($data['question_type'] === 'text') {
                return;
            }

            $rows->values()->each(function (array $row, int $index) use ($question): void {
                $question->choices()->create([
                    'label' => $row['label'],
                    'is_correct' => $row['is_correct'],
                    'sort_order' => $index + 1,
                ]);
            });
        });

        $audit->record($request, 'question.updated', $question);

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
        $audit->record($request, 'form_field.deleted', $field, ['label' => $field->label]);
        $field->delete();

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

        $question = $form->questions()->create([
            ...collect($data)->except('choices', 'is_required')->all(),
            'is_required' => $request->boolean('is_required'),
            'sort_order' => ($form->questions()->max('sort_order') ?? 0) + 1,
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

        return back()->with('success', 'Question added.');
    }

    public function destroyQuestion(Request $request, Webinar $webinar, Form $form, Question $question, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $form);
        abort_unless($question->form_id === $form->id, 404);
        $audit->record($request, 'question.deleted', $question, ['prompt' => $question->prompt]);
        $question->delete();

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
