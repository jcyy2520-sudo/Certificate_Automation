@props(['value'])
@if(mb_strlen($value) > 180)
    <details class="response-value"><summary class="cursor-pointer"><span class="response-answer-preview">{{ Str::limit($value, 180) }}</span><span class="text-accent-700 underline">Read full answer</span></summary><p class="mt-2 whitespace-pre-wrap break-words">{{ $value }}</p></details>
@else
    <span class="whitespace-pre-wrap break-words">{{ $value === '' ? '—' : $value }}</span>
@endif
