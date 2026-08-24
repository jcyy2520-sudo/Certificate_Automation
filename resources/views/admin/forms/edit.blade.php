@extends('layouts.webinar')
@section('title', $form->title)
@section('content')
@php
    // Every row on this page (the settings form, each "add" panel, each
    // existing field/question) posts a hidden `_row` marker identifying
    // itself. Because old()/$errors are shared across the whole request,
    // that marker is the only way to know *which* of the many forms here
    // just failed, so only that row restores typed input, shows its error,
    // and reopens automatically.
    $failedRow = old('_row');
    // Pre/post-assessments are quizzes — they only make sense as scored
    // questions, not profile-style fields. Registration collects details
    // about the person; the evaluation survey can use either.
    $hasFields = ! in_array($form->type, ['pretest', 'posttest'], true);
    $hasQuestions = $form->type !== 'registration';
    $showTwoColumns = $hasFields && $hasQuestions;
    $settingsOpen = $errors->hasAny(['title', 'description', 'closes_at', 'max_attempts', 'show_score']);
    $addFieldOpen = $failedRow === 'new-field';
    $addQuestionOpen = $failedRow === 'new-question';

    // Plain-language names for the technical field_type/question_type values.
    $fieldTypeLabels = [
        'text' => 'Short text', 'email' => 'Email address', 'number' => 'Number', 'date' => 'Date',
        'textarea' => 'Long text', 'select' => 'Dropdown list', 'radio' => 'Multiple choice (pick one)', 'checkbox' => 'Checkboxes (pick multiple)',
    ];
    $questionTypeLabels = [
        'multiple_choice' => 'Multiple choice', 'true_false' => 'True / false', 'text' => 'Written answer',
    ];
@endphp

@php
    $noun = $form->type === 'registration' ? 'Registration' : 'Form';
    $availabilityLabel = match ($form->type) {
        'registration' => 'Registration',
        'pretest' => 'Pre-test',
        'posttest' => 'Post-test',
        'evaluation' => 'Evaluation',
        default => 'Form',
    };
    $isOpen = $form->isOpen();
    $isLive = $form->acceptsResponses();
    // When the form is set open but responses still aren't accepted, explain why
    // and point to exactly where to fix it.
    $blockedByWebinar = $isOpen && ! $isLive && ! $webinar->isOpen();
@endphp

<x-page-header :title="$form->title"
               :crumbs="[($form->type === 'registration' ? 'Participants' : 'Tests') => route('admin.webinars.show', $webinar)]">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500">
            <span class="uppercase tracking-wide">{{ $form->type }}</span>
        </div>
    </x-slot:meta>
</x-page-header>

<section class="panel mb-6 p-5" data-availability-panel>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h2 class="section-title">Availability</h2>
            <p class="mt-1 flex items-center gap-2 text-[13px]">
                <span class="inline-flex items-center gap-1.5 font-medium {{ $isLive ? 'text-emerald-700' : 'text-slate-500' }}" data-availability-state>
                    <span class="size-1.5 rounded-full {{ $isLive ? 'bg-emerald-500' : 'bg-slate-400' }}" data-availability-dot></span>
                    <span data-availability-state-text>{{ $isLive ? 'Open — accepting responses now' : ($isOpen ? 'Open, but responses are currently blocked' : 'Closed — not accepting responses') }}</span>
                </span>
            </p>
        </div>

        {{-- One-click manual switch. Deadlines remain an independent boundary. --}}
        <form method="POST" action="{{ route('admin.forms.toggle', [$webinar, $form]) }}" class="shrink-0" data-availability-form>@csrf
            <input type="hidden" name="is_open" value="0">
            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2.5">
                <span class="text-right">
                    <span class="block text-[13px] font-medium text-slate-900">{{ $availabilityLabel }}</span>
                    <span class="block text-[12px] {{ $isOpen ? 'text-emerald-700' : 'text-slate-500' }}" data-availability-label>{{ $isOpen ? 'Open' : 'Closed' }}</span>
                </span>
                <span class="switch">
                    <input type="checkbox" name="is_open" value="1" role="switch" aria-label="Open {{ Str::lower($availabilityLabel) }}" data-availability-switch @checked($isOpen)>
                </span>
            </label>
            <p class="mt-1 hidden max-w-64 text-right text-[12px] text-red-600" role="alert" data-availability-error></p>
        </form>
    </div>

        <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900 {{ $isLive ? 'hidden' : '' }}" data-availability-notice>
            <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
            <span data-availability-message>
                @if($blockedByWebinar)
                    This {{ Str::lower($noun) }} is switched on, but the webinar itself is closed, so nobody can respond yet. Open the webinar in <a class="font-medium underline" href="{{ route('admin.webinars.edit', $webinar) }}">Webinar settings</a>.
                @elseif(! $isOpen)
                    Turn on the <strong>{{ $availabilityLabel }}</strong> switch above to start accepting responses.
                @else
                    {{ $form->closedReason() }} Check the closing deadline in Settings below.
                @endif
            </span>
        </div>
</section>

<section class="panel mb-6 p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="section-title flex items-center gap-2"><x-icon name="link" class="size-[18px] text-slate-400" />Share link</h2>
            <p class="mt-1 text-[13px] text-slate-500">Post this anywhere. It opens this form and nothing else.</p>
        </div>
        <a class="button-secondary shrink-0" target="_blank" rel="noopener" href="{{ $form->shareUrl() }}"><x-icon name="external" class="size-4" />Open</a>
    </div>

    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
        <input id="share-link" class="field mt-0 flex-1 font-mono text-[13px]" value="{{ $form->shareUrl() }}" readonly data-select-on-click>
        <button type="button" class="button-secondary shrink-0" data-copy-target="#share-link" data-copy-label="Copy link" data-copy-success="Link copied"><x-icon name="copy" class="size-4" />Copy link</button>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
        <p class="text-[12px] text-slate-500">
            @if($form->link_rotated_at)
                Link last changed {{ $form->link_rotated_at->format('M j, Y g:i A') }}.
            @else
                Anyone with this link can respond.
            @endif
        </p>
        <form method="POST" action="{{ route('admin.forms.rotate-link', [$webinar, $form]) }}" data-confirm="Generate a new link? The current link stops working for everyone who has it.">@csrf
            <button class="text-[13px] font-medium text-red-600 hover:underline">Generate a new link</button>
        </form>
    </div>
</section>

<section class="panel mb-6 overflow-hidden">
    <details class="group" @if($settingsOpen) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
            <div class="min-w-0">
                <h2 class="section-title">Settings</h2>
                <p class="mt-0.5 truncate text-[13px] text-slate-500 group-open:hidden">Max {{ $form->max_attempts }} response(s) per email{{ $form->show_score ? ' · shows score' : '' }} · closing deadline optional</p>
            </div>
            <span class="shrink-0 text-[13px] font-medium text-accent-600"><span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span></span>
        </summary>
        <form method="POST" action="{{ route('admin.forms.update', [$webinar, $form]) }}" class="border-t border-slate-100 p-5">@csrf @method('PUT')
            <input type="hidden" name="_row" value="settings">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field-label sm:col-span-2">Title
                    <input class="field {{ $errors->has('title') ? 'field-invalid' : '' }}" name="title" value="{{ old('title', $form->title) }}" required>
                    <x-field-error :error="$errors->first('title')" />
                </label>
                <label class="field-label sm:col-span-2">Description
                    <textarea class="field {{ $errors->has('description') ? 'field-invalid' : '' }}" name="description" rows="3">{{ old('description', $form->description) }}</textarea>
                    <x-field-error :error="$errors->first('description')" />
                </label>
                <label class="field-label sm:col-span-2">Maximum responses per email
                    <input class="field {{ $errors->has('max_attempts') ? 'field-invalid' : '' }}" type="number" min="1" max="20" name="max_attempts" value="{{ old('max_attempts', $form->max_attempts) }}" required>
                    <x-field-error :error="$errors->first('max_attempts')" />
                </label>
                <label class="field-label sm:col-span-2">Closing deadline <span class="font-normal text-slate-400">optional</span>
                    <input class="field {{ $errors->has('closes_at') ? 'field-invalid' : '' }}" type="datetime-local" name="closes_at" value="{{ old('closes_at', $form->closes_at?->copy()->setTimezone($webinar->timezone)->format('Y-m-d\TH:i')) }}">
                    <x-field-error :error="$errors->first('closes_at')" />
                </label>
                <p class="text-[12px] text-slate-500 sm:col-span-2">The Open/Closed switch controls when responses start. If you set a deadline, the form closes automatically at that time; switching it on again after the deadline opens it manually.</p>
                <label class="flex items-center gap-2.5 text-[13px] text-slate-700 sm:col-span-2"><input class="survey-check size-5" type="checkbox" name="show_score" value="1" @checked(old('show_score', $form->show_score))> Show score after submitting</label>
            </div>
            <button class="button-primary mt-4">Save settings</button>
        </form>
    </details>
</section>

<div class="grid gap-6 {{ $showTwoColumns ? 'xl:grid-cols-2' : '' }}">
    @if($hasFields)
    <section class="panel overflow-hidden self-start">
        <details class="group" @if($addFieldOpen) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 hover:bg-slate-50">
                <span class="section-title">Fields <span class="count">{{ $form->fields->count() }}</span></span>
                <span class="button-secondary h-8 px-3 text-[13px]"><x-icon name="plus" class="size-4" /><span class="group-open:hidden">Add field</span><span class="hidden group-open:inline">Close</span></span>
            </summary>
            <form class="border-b border-slate-100 p-5" method="POST" action="{{ route('admin.forms.fields.store', [$webinar, $form]) }}">@csrf
                <input type="hidden" name="_row" value="new-field">
                <p class="mb-3 text-[13px] text-slate-500">A field collects one piece of information from every participant, like their name or job title.</p>
                <div class="grid gap-4">
                    <label class="field-label">What should this ask for?
                        <input class="field {{ $addFieldOpen && $errors->has('label') ? 'field-invalid' : '' }}" name="label" placeholder="Job title" value="{{ $addFieldOpen ? old('label') : '' }}" required>
                        <x-field-error :error="$addFieldOpen ? $errors->first('label') : null" />
                    </label>
                    <label class="field-label">Answer type
                        <select class="field" name="field_type" data-toggles-visibility>
                            @foreach($fieldTypeLabels as $type => $typeLabel)
                                <option value="{{ $type }}" @selected($addFieldOpen ? old('field_type') === $type : $type === 'text')>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field-label">Help text <span class="font-normal text-slate-400">optional</span><input class="field" name="help_text" placeholder="Shown under the question to clarify what you need" value="{{ $addFieldOpen ? old('help_text') : '' }}"></label>
                    <label class="field-label" data-visible-for="select,radio,checkbox">List the choices <span class="font-normal text-slate-400">one per line</span>
                        <textarea class="field font-mono text-[13px] {{ $addFieldOpen && $errors->has('options_text') ? 'field-invalid' : '' }}" name="options_text" rows="3" placeholder="Nurse&#10;Doctor&#10;Admin">{{ $addFieldOpen ? old('options_text') : '' }}</textarea>
                        <x-field-error :error="$addFieldOpen ? $errors->first('options_text') : null" />
                    </label>
                    <label class="flex items-center gap-2.5 text-[13px] text-slate-700"><input class="survey-check size-5" type="checkbox" name="is_required" value="1" @checked($addFieldOpen ? old('is_required') : true)> Participants must answer this</label>
                    <button class="button-secondary mt-1 justify-self-start">Add field</button>
                </div>
            </form>
        </details>

        <div class="divide-y divide-slate-100">
            @forelse($form->fields as $field)
                @php
                    $rowFailed = $failedRow === 'field-'.$field->id;
                    $rowErrors = $rowFailed ? $errors : null;
                @endphp
                <details class="group px-5 py-3.5 {{ $rowFailed ? 'bg-red-50/60' : '' }}" @if($rowFailed) open @endif>
                    <summary class="flex list-none cursor-pointer items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ $field->label }} @if($field->is_required)<span class="text-red-500">*</span>@endif</p>
                            <p class="mt-0.5 text-[12px] text-slate-500">{{ $fieldTypeLabels[$field->field_type] ?? ucfirst($field->field_type) }}</p>
                        </div>
                        <span class="shrink-0 text-[13px] font-medium text-accent-600 group-open:hidden">Edit</span>
                        <span class="hidden shrink-0 text-[13px] font-medium text-slate-500 group-open:inline">Close</span>
                    </summary>

                    <form class="mt-4 grid gap-3 border-t border-slate-100 pt-4" method="POST" action="{{ route('admin.forms.fields.update', [$webinar, $form, $field]) }}">@csrf @method('PUT')
                        <input type="hidden" name="_row" value="field-{{ $field->id }}">
                        <label class="field-label">What should this ask for?
                            <input class="field {{ $rowErrors?->has('label') ? 'field-invalid' : '' }}" name="label" value="{{ $rowFailed ? old('label') : $field->label }}" required>
                            <x-field-error :error="$rowErrors?->first('label')" />
                        </label>
                        <label class="field-label">Answer type
                            <select class="field" name="field_type" data-toggles-visibility>
                                @foreach($fieldTypeLabels as $type => $typeLabel)
                                    <option value="{{ $type }}" @selected($rowFailed ? old('field_type') === $type : $field->field_type === $type)>{{ $typeLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field-label">Help text <span class="font-normal text-slate-400">optional</span><input class="field" name="help_text" value="{{ $rowFailed ? old('help_text') : $field->help_text }}"></label>
                        <label class="field-label" data-visible-for="select,radio,checkbox">List the choices <span class="font-normal text-slate-400">one per line</span>
                            <textarea class="field font-mono text-[13px] {{ $rowErrors?->has('options_text') ? 'field-invalid' : '' }}" name="options_text" rows="3">{{ $rowFailed ? old('options_text') : collect($field->options ?? [])->implode(PHP_EOL) }}</textarea>
                            <x-field-error :error="$rowErrors?->first('options_text')" />
                        </label>
                        <label class="flex items-center gap-2.5 text-[13px] text-slate-700"><input class="survey-check size-5" type="checkbox" name="is_required" value="1" @checked($rowFailed ? old('is_required') : $field->is_required)> Participants must answer this</label>
                        <button class="button-primary justify-self-start">Save field</button>
                    </form>

                    <form class="mt-3 border-t border-slate-100 pt-3" method="POST" action="{{ route('admin.forms.fields.destroy', [$webinar, $form, $field]) }}" data-confirm="Remove this field and every answer given to it?">@csrf @method('DELETE')
                        <button class="text-[13px] font-medium text-red-600 hover:underline">Delete this field</button>
                    </form>
                    </details>
            @empty
                <p class="px-5 py-10 text-center text-sm text-slate-500">No fields yet.</p>
            @endforelse
        </div>
    </section>
    @endif

    @if($hasQuestions)
        <section class="panel overflow-hidden self-start" data-questions>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                <span class="section-title">Questions <span class="count">{{ $form->questions->count() }}</span></span>
                <span class="segmented" data-question-tabs role="tablist">
                    <label class="segmented-option"><input type="radio" name="qtab" value="builder" checked><x-icon name="edit" class="size-4" />Builder</label>
                    <label class="segmented-option"><input type="radio" name="qtab" value="all"><x-icon name="file-text" class="size-4" />All questions</label>
                </span>
            </div>

            <div data-qpanel="builder">
            <details class="group" @if($addQuestionOpen) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 hover:bg-slate-50">
                    <span class="text-[13px] font-medium text-slate-700">Add a question</span>
                    <span class="button-secondary h-8 px-3 text-[13px]"><x-icon name="plus" class="size-4" /><span class="group-open:hidden">Add question</span><span class="hidden group-open:inline">Close</span></span>
                </summary>
                <form class="border-b border-slate-100 p-5" method="POST" action="{{ route('admin.forms.questions.store', [$webinar, $form]) }}">@csrf
                    <input type="hidden" name="_row" value="new-question">
                    <p class="mb-3 text-[13px] text-slate-500">A question tests what a participant learned, and can be scored.</p>
                    <div class="grid gap-4">
                        <label class="field-label">Question
                            <textarea class="field {{ $addQuestionOpen && $errors->has('prompt') ? 'field-invalid' : '' }}" name="prompt" rows="3" placeholder="What is the first step in the BLS algorithm?" required>{{ $addQuestionOpen ? old('prompt') : '' }}</textarea>
                            <x-field-error :error="$addQuestionOpen ? $errors->first('prompt') : null" />
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="field-label">Answer type
                                <select class="field" name="question_type" data-toggles-visibility>
                                    @foreach($questionTypeLabels as $value => $text)
                                        <option value="{{ $value }}" @selected($addQuestionOpen && old('question_type') === $value)>{{ $text }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-label">Points
                                <input class="field {{ $addQuestionOpen && $errors->has('points') ? 'field-invalid' : '' }}" type="number" step="0.5" min="0" name="points" value="{{ $addQuestionOpen ? old('points', 1) : 1 }}" required>
                                <x-field-error :error="$addQuestionOpen ? $errors->first('points') : null" />
                            </label>
                        </div>
                        <x-choice-editor
                            :choices="$addQuestionOpen && old('choices') ? collect(old('choices'))->map(fn ($label, $i) => ['label' => $label, 'is_correct' => (string) $i === (string) old('correct_choice')])->all() : []"
                            :error="$addQuestionOpen ? $errors->first('choices') : null" />
                        <label class="field-label">Explanation <span class="font-normal text-slate-400">optional, shown after answering</span><input class="field" name="explanation" value="{{ $addQuestionOpen ? old('explanation') : '' }}"></label>
                        <label class="flex items-center gap-2.5 text-[13px] text-slate-700"><input class="survey-check size-5" type="checkbox" name="is_required" value="1" @checked($addQuestionOpen ? old('is_required') : true)> Participants must answer this</label>
                        <button class="button-secondary mt-1 justify-self-start">Add question</button>
                    </div>
                </form>
            </details>

            <div class="divide-y divide-slate-100">
                @forelse($form->questions as $question)
                    @php
                        $locked = $question->answers_count > 0;
                        $rowFailed = $failedRow === 'question-'.$question->id;
                        $rowErrors = $rowFailed ? $errors : null;
                    @endphp
                    <details class="group px-5 py-4 {{ $rowFailed ? 'bg-red-50/60' : '' }}" @if($rowFailed) open @endif>
                        <summary class="flex list-none cursor-pointer items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium"><span class="text-slate-400 tabular-nums">{{ $loop->iteration }}.</span> {{ $question->prompt }}</p>
                                <p class="mt-1 text-[12px] text-slate-500">{{ str_replace('_', ' ', $question->question_type) }} · {{ (float) $question->points }} points @if($locked) · answered @endif</p>
                            </div>
                            <span class="shrink-0 text-[13px] font-medium text-accent-600 group-open:hidden">Edit</span>
                            <span class="hidden shrink-0 text-[13px] font-medium text-slate-500 group-open:inline">Close</span>
                        </summary>

                        @if($question->choices->isNotEmpty())
                            <ul class="mt-3 space-y-1 text-[13px]">
                                @foreach($question->choices as $choice)
                                    <li class="flex items-center gap-2 {{ $choice->is_correct ? 'font-medium text-emerald-700' : 'text-slate-500' }}">
                                        <span class="grid size-4 shrink-0 place-items-center rounded-full border {{ $choice->is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300' }}">
                                            @if($choice->is_correct)<x-icon name="check" class="size-2.5" stroke-width="3" />@endif
                                        </span>
                                        {{ $choice->label }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <form class="mt-4 grid gap-3 border-t border-slate-100 pt-4" method="POST" action="{{ route('admin.forms.questions.update', [$webinar, $form, $question]) }}">@csrf @method('PUT')
                            <input type="hidden" name="_row" value="question-{{ $question->id }}">
                            <label class="field-label">Question
                                <textarea class="field {{ $rowErrors?->has('prompt') ? 'field-invalid' : '' }}" name="prompt" rows="3" required>{{ $rowFailed ? old('prompt') : $question->prompt }}</textarea>
                                <x-field-error :error="$rowErrors?->first('prompt')" />
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="field-label">Answer type
                                    <select class="field" name="question_type" data-toggles-visibility @disabled($locked)>
                                        @foreach($questionTypeLabels as $value => $text)
                                            <option value="{{ $value }}" @selected($rowFailed ? old('question_type') === $value : $question->question_type === $value)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                    @if($locked)<input type="hidden" name="question_type" value="{{ $question->question_type }}">@endif
                                </label>
                                <label class="field-label">Points
                                    <input class="field {{ $rowErrors?->has('points') ? 'field-invalid' : '' }}" type="number" step="0.5" min="0" name="points" value="{{ $rowFailed ? old('points') : (float) $question->points }}" required>
                                    <x-field-error :error="$rowErrors?->first('points')" />
                                </label>
                            </div>
                            @if($locked)
                                <p class="text-[12px] text-slate-500">This question has been answered, so its choices and type are locked. Wording and points can still be corrected.</p>
                            @else
                                <x-choice-editor
                                    :choices="$rowFailed && old('choices') ? collect(old('choices'))->map(fn ($label, $i) => ['label' => $label, 'is_correct' => (string) $i === (string) old('correct_choice')])->all() : $question->choices->map(fn ($choice) => ['label' => $choice->label, 'is_correct' => $choice->is_correct])->all()"
                                    :error="$rowErrors?->first('choices')" />
                            @endif
                            <label class="field-label">Explanation <span class="font-normal text-slate-400">optional, shown after answering</span><input class="field" name="explanation" value="{{ $rowFailed ? old('explanation') : $question->explanation }}"></label>
                            <label class="flex items-center gap-2.5 text-[13px] text-slate-700"><input class="survey-check size-5" type="checkbox" name="is_required" value="1" @checked($rowFailed ? old('is_required') : $question->is_required)> Participants must answer this</label>
                            <button class="button-primary justify-self-start">Save question</button>
                        </form>

                        <form class="mt-3 border-t border-slate-100 pt-3" method="POST" action="{{ route('admin.forms.questions.destroy', [$webinar, $form, $question]) }}" data-confirm="Remove this question and every answer given to it?">@csrf @method('DELETE')
                            <button class="text-[13px] font-medium text-red-600 hover:underline">Delete this question</button>
                        </form>
                    </details>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-slate-500">No questions yet.</p>
                @endforelse
            </div>
            </div>{{-- /builder panel --}}

            {{-- Compact, read-only overview of every question so the whole test
                 can be scanned without opening each editor. --}}
            <div data-qpanel="all" class="hidden">
                @forelse($form->questions as $question)
                    <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-3 last:border-0">
                        <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md bg-slate-100 text-[12px] font-semibold tabular-nums text-slate-500">{{ $loop->iteration }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13px] font-medium text-slate-900">{{ $question->prompt }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[12px] text-slate-500">
                                <span>{{ $questionTypeLabels[$question->question_type] ?? str_replace('_', ' ', $question->question_type) }}</span>
                                <span class="text-slate-300">·</span>
                                <span class="tabular-nums">{{ (float) $question->points }} {{ Str::plural('point', (float) $question->points) }}</span>
                                @if(! $question->is_required)<span class="text-slate-300">·</span><span>optional</span>@endif
                                @php $correct = $question->choices->firstWhere('is_correct', true); @endphp
                                @if($correct)
                                    <span class="text-slate-300">·</span>
                                    <span class="inline-flex items-center gap-1 font-medium text-emerald-700"><x-icon name="check" class="size-3.5" />{{ $correct->label }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-slate-500">No questions yet. Add them in the Builder tab.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>

@if($hasQuestions)
<script nonce="{{ $cspNonce }}">
    (function () {
        var root = document.querySelector('[data-questions]');
        if (!root) return;
        var tabs = root.querySelectorAll('[data-question-tabs] input[name="qtab"]');
        var panels = root.querySelectorAll('[data-qpanel]');
        function sync() {
            var active = 'builder';
            tabs.forEach(function (t) { if (t.checked) active = t.value; });
            panels.forEach(function (p) { p.classList.toggle('hidden', p.getAttribute('data-qpanel') !== active); });
        }
        tabs.forEach(function (t) { t.addEventListener('change', sync); });
        sync();
    })();
</script>
@endif
@endsection
