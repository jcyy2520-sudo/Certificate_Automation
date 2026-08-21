@props(['choices' => [], 'error' => null])
@php
    $choices = collect($choices)->values();
    $trueFalseCorrectIndex = $choices->search(fn ($choice) => $choice['is_correct'] ?? false);
    $trueFalseCorrectIndex = $trueFalseCorrectIndex === false ? 0 : $trueFalseCorrectIndex;
@endphp
<div data-choice-field>
    <div class="field-label" data-visible-for="multiple_choice">
        <span>Choices &mdash; pick the correct one</span>
        <div class="mt-2 space-y-2" data-choice-rows>
            @forelse($choices as $index => $choice)
                <div class="flex items-center gap-2" data-choice-row data-index="{{ $index }}">
                    <input type="radio" name="correct_choice" value="{{ $index }}" class="survey-radio size-5 shrink-0" data-choice-radio @checked($choice['is_correct'] ?? false)>
                    <input type="text" name="choices[{{ $index }}]" class="field mt-0 flex-1" placeholder="Choice text" value="{{ $choice['label'] ?? '' }}" data-choice-text>
                    <button type="button" class="shrink-0 rounded-md p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600" data-remove-choice title="Remove choice"><x-icon name="x" class="size-4" /></button>
                </div>
            @empty
                @for($i = 0; $i < 2; $i++)
                    <div class="flex items-center gap-2" data-choice-row data-index="{{ $i }}">
                        <input type="radio" name="correct_choice" value="{{ $i }}" class="survey-radio size-5 shrink-0" data-choice-radio>
                        <input type="text" name="choices[{{ $i }}]" class="field mt-0 flex-1" placeholder="Choice text" data-choice-text>
                        <button type="button" class="shrink-0 rounded-md p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600" data-remove-choice title="Remove choice"><x-icon name="x" class="size-4" /></button>
                    </div>
                @endfor
            @endforelse
        </div>
        <button type="button" class="mt-2 inline-flex items-center gap-1 text-[13px] font-medium text-accent-600 hover:underline" data-add-choice><x-icon name="plus" class="size-3.5" />Add choice</button>
    </div>

    <div class="field-label mt-1" data-visible-for="true_false">
        <span>Correct answer</span>
        <div class="mt-2 flex gap-2">
            <input type="hidden" name="choices[0]" value="True">
            <input type="hidden" name="choices[1]" value="False">
            <label class="chip w-auto cursor-pointer px-4 has-[:checked]:border-accent-600 has-[:checked]:bg-accent-50">
                <input type="radio" name="correct_choice" value="0" class="mr-1.5 size-4" @checked($trueFalseCorrectIndex === 0)> True
            </label>
            <label class="chip w-auto cursor-pointer px-4 has-[:checked]:border-accent-600 has-[:checked]:bg-accent-50">
                <input type="radio" name="correct_choice" value="1" class="mr-1.5 size-4" @checked($trueFalseCorrectIndex === 1)> False
            </label>
        </div>
    </div>

    <x-field-error :error="$error" />
</div>
