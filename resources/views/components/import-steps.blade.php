@props(['active' => 1])
<nav class="mb-6" aria-label="Import progress">
    <ol class="flex flex-wrap gap-x-6 gap-y-3 text-sm">
        @foreach(['Upload', 'Confirm columns', 'Review results', 'Import'] as $label)
            <li class="flex items-center gap-2 {{ $loop->iteration === $active ? 'font-semibold text-slate-950' : 'text-slate-500' }}" @if($loop->iteration === $active) aria-current="step" @endif>
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full border {{ $loop->iteration <= $active ? 'border-accent-700 bg-accent-50 text-accent-800' : 'border-slate-200' }}" aria-hidden="true">{{ $loop->iteration }}</span>{{ $label }}
            </li>
        @endforeach
    </ol>
</nav>
