@extends('public.layout')
@section('title', $form->title)
@section('content')
@php
    // Highlight the final word of the title, as in the reference design.
    $titleWords = preg_split('/\s+/', trim($form->title)) ?: [$form->title];
    $lastWord = array_pop($titleWords);
    $leadWords = implode(' ', $titleWords);
    $totalPrompts = $form->fields->count() + $form->questions->count();
@endphp

<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">
        @if($leadWords !== ''){{ $leadWords }} @endif<span class="title-mark">{{ $lastWord }}</span>
    </h1>

    @if($form->description)
        <p class="mx-auto mt-4 max-w-md whitespace-pre-line text-[13px] leading-5 text-slate-600">{{ $form->description }}</p>
    @endif

    @if($totalPrompts > 0)
        <p class="mx-auto mt-4 max-w-md text-[13px] leading-5 text-slate-600">
            This form consists of {{ $totalPrompts }} {{ Str::plural('question', $totalPrompts) }}. Thank you for completing it!
        </p>
    @endif

    @if($form->closes_at)
        <p class="mt-2 text-[12px] text-slate-400">Closes {{ $form->closes_at->format('F j, Y \a\t g:i A') }}</p>
    @endif
</header>

<div class="stepper mt-9 h-4 justify-between" data-stepper>
    <span class="stepper-track"></span>
    <span class="stepper-fill" data-stepper-fill style="width:0"></span>
    <span class="stepper-dot stepper-dot-current" data-stepper-dot></span>
    <span class="stepper-dot" data-stepper-dot></span>
    <span class="stepper-dot" data-stepper-dot></span>
</div>

@if($errors->any())
    <div class="mt-8 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-[13px] text-red-900">
        <strong class="font-semibold">Please check your answers.</strong>
        <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<form class="mt-10" method="POST" action="{{ route('forms.public.submit', $form->public_token) }}" data-survey>@csrf

    <fieldset class="space-y-5">
        <legend class="survey-question mb-5">About you</legend>

        <label class="block">
            <span class="text-[15px] text-slate-600">Full name <span class="text-red-600">*</span></span>
            <input class="survey-input @error('full_name') border-red-400 @enderror" name="full_name" value="{{ old('full_name', $participant?->full_name) }}" required autocomplete="name" data-answerable>
            @error('full_name')<span class="mt-2 block text-[13px] text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block">
            <span class="text-[15px] text-slate-600">Email address <span class="text-red-600">*</span></span>
            <input class="survey-input @error('email') border-red-400 @enderror" type="email" name="email" value="{{ old('email', $participant?->email) }}" required autocomplete="email" @readonly($participant) data-answerable>
            <span class="mt-2 block text-[12px] text-slate-400">@if($participant)Verified for this secure session.@else Links this response to your other responses for this event.@endif</span>
            @error('email')<span class="mt-1 block text-[13px] text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block">
            <span class="text-[15px] text-slate-600">Organization <span class="text-slate-400">(optional)</span></span>
            <input class="survey-input" name="organization" value="{{ old('organization', $participant?->organization) }}" autocomplete="organization">
        </label>
    </fieldset>

    <div class="mt-12 space-y-11">
        @php $number = 0; @endphp

        @foreach($form->fields as $field)
            @php $number++; $name = 'fields['.$field->id.']'; $key = 'fields.'.$field->id; @endphp
            <section>
                <p class="survey-question">
                    <span class="survey-number">{{ $number }}.</span>
                    {{ $field->label }}@if($field->is_required)<span class="text-red-600"> *</span>@endif
                </p>
                @if($field->help_text)<p class="mt-1 pl-6 text-[13px] text-slate-500">{{ $field->help_text }}</p>@endif

                @if($field->field_type === 'radio' || $field->field_type === 'select')
                    <div class="mt-4 space-y-1 pl-6">
                        @foreach($field->options ?? [] as $option)
                            <label class="survey-option">
                                <input class="survey-radio" type="radio" name="{{ $name }}" value="{{ $option }}" @checked(old($key) === $option) data-answerable>
                                <span class="survey-option-label">{{ $option }}</span>
                            </label>
                        @endforeach
                    </div>
                @elseif($field->field_type === 'checkbox')
                    <div class="mt-4 pl-6">
                        <label class="survey-option">
                            <input class="survey-check" type="checkbox" name="{{ $name }}" value="1" @checked(old($key)) data-answerable>
                            <span class="survey-option-label">I agree</span>
                        </label>
                    </div>
                @elseif($field->field_type === 'textarea')
                    <div class="pl-6">
                        <textarea class="survey-input @error($key) border-red-400 @enderror" name="{{ $name }}" rows="4" data-answerable>{{ old($key) }}</textarea>
                    </div>
                @else
                    <div class="pl-6">
                        <input class="survey-input @error($key) border-red-400 @enderror" type="{{ in_array($field->field_type, ['email', 'number', 'date']) ? $field->field_type : 'text' }}" name="{{ $name }}" value="{{ old($key) }}" data-answerable>
                    </div>
                @endif

                @error($key)<p class="mt-2 pl-6 text-[13px] text-red-600">{{ $message }}</p>@enderror
            </section>
        @endforeach

        @foreach($form->questions as $question)
            @php $number++; $name = 'questions['.$question->id.']'; $key = 'questions.'.$question->id; @endphp
            <section>
                <p class="survey-question">
                    <span class="survey-number">{{ $number }}.</span>
                    {{ $question->prompt }}@if($question->is_required)<span class="text-red-600"> *</span>@endif
                </p>

                @if($question->question_type === 'text')
                    <div class="pl-6">
                        <textarea class="survey-input @error($key) border-red-400 @enderror" name="{{ $name }}" rows="3" data-answerable>{{ old($key) }}</textarea>
                    </div>
                @else
                    <div class="mt-4 space-y-1 pl-6">
                        @foreach($question->choices as $choice)
                            <label class="survey-option">
                                <input class="survey-radio" type="radio" name="{{ $name }}" value="{{ $choice->id }}" @checked((int) old($key) === $choice->id) data-answerable>
                                <span class="survey-option-label">{{ $choice->label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                @error($key)<p class="mt-2 pl-6 text-[13px] text-red-600">{{ $message }}</p>@enderror
            </section>
        @endforeach
    </div>

    <div class="mt-12 rounded-xl border border-slate-200 bg-white px-5 py-4">
        <label class="survey-option items-start">
            <input class="survey-check mt-0.5" type="checkbox" name="privacy_acknowledged" value="1" @checked(old('privacy_acknowledged')) required data-answerable>
            <span class="text-[13px] leading-5 text-slate-600">
                I understand that my contact information and responses are used to administer this webinar, assess completion, and issue certificates. Participant data is scheduled for erasure {{ $form->webinar->data_retention_days }} {{ Str::plural('day', $form->webinar->data_retention_days) }} after the webinar ends. A certificate's random verification code, event, issue date, and validity may remain without my name or contact details.
            </span>
        </label>
        @error('privacy_acknowledged')<p class="mt-2 pl-6 text-[13px] text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="mt-14 flex flex-col items-center gap-4">
        <button class="button-primary w-full sm:w-auto sm:min-w-50">Submit</button>
        <p class="text-[12px] text-slate-400">{{ $form->max_attempts === 1 ? 'One response per email address' : $form->max_attempts.' responses allowed per email address' }}</p>
    </div>
</form>

<script nonce="{{ $cspNonce }}">
    // Drives the stepper from how much of the form is filled in.
    (function () {
        var form = document.querySelector('[data-survey]');
        var stepper = document.querySelector('[data-stepper]');
        if (!form || !stepper) return;

        var fill = stepper.querySelector('[data-stepper-fill]');
        var dots = Array.prototype.slice.call(stepper.querySelectorAll('[data-stepper-dot]'));
        var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-answerable]'));
        if (!fill || dots.length < 2 || inputs.length === 0) return;

        // Group radios by name so a question counts once, not once per choice.
        var groups = [];
        var seen = {};
        inputs.forEach(function (input) {
            if (input.type === 'radio') {
                if (seen[input.name]) return;
                seen[input.name] = true;
                groups.push(form.querySelectorAll('input[name="' + CSS.escape(input.name) + '"]'));
            } else {
                groups.push([input]);
            }
        });

        function answered(group) {
            return Array.prototype.some.call(group, function (input) {
                if (input.type === 'radio' || input.type === 'checkbox') return input.checked;
                return input.value.trim() !== '';
            });
        }

        function centre(dot) {
            return dot.offsetLeft + dot.offsetWidth / 2;
        }

        function render() {
            var done = groups.filter(answered).length;
            var progress = done / groups.length;

            var start = centre(dots[0]);
            var end = centre(dots[dots.length - 1]);
            fill.style.width = (start + (end - start) * progress) + 'px';

            dots.forEach(function (dot, index) {
                var threshold = index / (dots.length - 1);
                var reached = progress >= threshold - 0.0001;
                var leading = reached && (index === dots.length - 1 || progress < (index + 1) / (dots.length - 1));

                dot.classList.toggle('stepper-dot-done', reached && !leading);
                dot.classList.toggle('stepper-dot-current', leading);
            });
        }

        form.addEventListener('input', render);
        form.addEventListener('change', render);
        window.addEventListener('resize', render);
        render();
    })();
</script>
@endsection
