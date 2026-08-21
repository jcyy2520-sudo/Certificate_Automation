@extends('layouts.webinar')
@section('title', 'Certificates — '.$webinar->title)
@section('content')
@php
    $backgroundUrl = $hasBackground ? route('admin.certification.background', $webinar) : null;
    $issuable = $participants->reject(fn ($p) => $p->certificates->first(fn ($c) => $c->issued_at && ! $c->revoked_at));
@endphp

<x-page-header title="Certificate selection & preview"
               subtitle="Choose eligible participants, review each generated certificate, then issue and send.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $participants->count() }}</strong> eligible</span>
            <span class="text-slate-300">·</span>
            <span><strong class="font-semibold text-slate-900">{{ $issuable->count() }}</strong> awaiting a certificate</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar) }}"><x-icon name="external" class="size-4" />Preview blank PDF</a>
    </x-slot:actions>
</x-page-header>

@if(! $template)
    <div class="panel p-8 text-center">
        <p class="text-sm text-slate-600">No active certificate template yet.</p>
        <a class="button-primary mt-4" href="{{ route('admin.certification.edit', $webinar) }}">Set up the template</a>
    </div>
@elseif($participants->isEmpty())
    <div class="panel p-8 text-center">
        <x-icon name="award" class="mx-auto size-8 text-slate-300" />
        <p class="mt-3 text-sm font-medium text-slate-900">No eligible participants yet</p>
        <p class="mt-1 text-[13px] text-slate-500">Participants appear here once they meet the certificate requirements. Adjust requirements on the template page.</p>
        <a class="button-secondary mt-4" href="{{ route('admin.certification.edit', $webinar) }}">Template &amp; requirements</a>
    </div>
@else
<form method="POST" action="{{ route('admin.certificates.issue-selected', $webinar) }}"
      data-confirm="Issue certificates to the selected participants and email them their links?"
      data-studio>
    @csrf
    <div class="grid gap-4 lg:h-[calc(100vh-15rem)] lg:min-h-[540px] lg:grid-cols-[300px_minmax(0,1fr)_270px]">

        {{-- LEFT · selection list --}}
        <section class="panel flex min-h-0 flex-col overflow-hidden">
            <div class="shrink-0 border-b border-slate-100 px-4 py-3">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Participants</h2>
                    <span class="count" data-selected-count>0</span>
                </div>
                <label class="mt-2.5 flex cursor-pointer items-center gap-2 text-[13px] font-medium text-slate-600">
                    <input type="checkbox" class="survey-check size-[18px]" data-select-all>
                    Select all awaiting ({{ $issuable->count() }})
                </label>
            </div>
            <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                @foreach($participants as $participant)
                    @php $held = $participant->certificates->first(fn ($c) => $c->issued_at && ! $c->revoked_at); @endphp
                    <label class="flex cursor-pointer items-start gap-3 px-4 py-3 transition hover:bg-slate-50 has-[:checked]:bg-accent-50 has-[:disabled]:cursor-default has-[:disabled]:opacity-70 has-[:disabled]:hover:bg-white">
                        <input type="checkbox" name="participants[]" value="{{ $participant->public_id }}"
                               class="survey-check mt-0.5 size-[18px] shrink-0"
                               data-participant="{{ $participant->public_id }}"
                               @disabled($held)>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium text-slate-900">{{ $participant->full_name ?: 'Unnamed participant' }}</span>
                            <span class="block truncate text-[12px] text-slate-500">{{ $participant->email ?: 'No email on file' }}</span>
                            @if($held)
                                <span class="badge badge-green mt-1">issued</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        {{-- CENTER · scrollable certificate previews --}}
        <section class="panel flex min-h-0 flex-col overflow-hidden">
            <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="section-title">Certificate preview</h2>
                <span class="text-[12px] text-slate-500"><span data-selected-count>0</span> selected</span>
            </div>

            {{-- This is the only region that scrolls; the side panels stay put. --}}
            <div class="min-h-0 flex-1 space-y-6 overflow-y-auto bg-slate-100 p-5" data-preview-scroll>
                <div class="flex h-full flex-col items-center justify-center text-center" data-preview-empty>
                    <x-icon name="image" class="size-9 text-slate-300" />
                    <p class="mt-3 text-sm font-medium text-slate-600">Select participants to preview their certificates</p>
                    <p class="mt-1 max-w-xs text-[12px] text-slate-400">Each generated certificate appears here so you can check the name placement and formatting before sending.</p>
                </div>

                @foreach($participants as $participant)
                    <figure class="hidden overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm ring-1 ring-black/[0.02]"
                            data-preview="{{ $participant->public_id }}">
                        <x-certificate-preview
                            :webinar="$webinar"
                            :name="$participant->full_name"
                            :has-background="$hasBackground"
                            :layout="$layout"
                            :background-url="$backgroundUrl" />
                        <figcaption class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2.5">
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium text-slate-900">{{ $participant->full_name ?: 'Unnamed participant' }}</span>
                                <span class="block truncate text-[12px] text-slate-500">{{ $participant->email ?: 'No email on file' }}</span>
                            </span>
                            <span class="inline-flex shrink-0 items-center gap-1.5 text-[12px] font-medium text-emerald-700"><x-icon name="check-circle" class="size-4" />Ready</span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </section>

        {{-- RIGHT · actions --}}
        <aside class="flex min-h-0 flex-col gap-4 overflow-y-auto">
            <div class="panel p-5">
                <h2 class="section-title">Issue &amp; send</h2>
                <dl class="mt-4 space-y-2.5 text-[13px]">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Selected</dt>
                        <dd class="font-semibold text-slate-900 tabular-nums" data-selected-count>0</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Eligible total</dt>
                        <dd class="font-semibold text-slate-900 tabular-nums">{{ $participants->count() }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">Already issued</dt>
                        <dd class="font-semibold text-slate-900 tabular-nums">{{ $participants->count() - $issuable->count() }}</dd>
                    </div>
                </dl>

                <button class="button-primary mt-5 w-full" data-issue-button disabled>
                    <x-icon name="send" class="size-4" />Issue to <span data-selected-count>0</span>
                </button>
                <p class="mt-2.5 text-[12px] leading-5 text-slate-500">
                    Generates each certificate PDF and emails the participant a link where their email is on file. Participants who already hold a valid certificate are skipped.
                </p>
            </div>

            <div class="panel p-5">
                <h3 class="text-[13px] font-semibold text-slate-900">Template in use</h3>
                <p class="mt-1.5 text-[13px] text-slate-600">{{ $template->name }}</p>
                <p class="mt-1 inline-flex items-center gap-1.5 text-[12px] {{ $hasBackground ? 'text-emerald-700' : 'text-slate-500' }}">
                    <x-icon name="{{ $hasBackground ? 'image' : 'file-text' }}" class="size-4" />
                    {{ $hasBackground ? 'Uploaded design + auto-placed name' : 'Generated design' }}
                </p>
                <a class="mt-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.certification.edit', $webinar) }}">
                    <x-icon name="edit" class="size-4" />Edit template
                </a>
            </div>
        </aside>
    </div>
</form>

<script nonce="{{ $cspNonce }}">
    (function () {
        var form = document.querySelector('[data-studio]');
        if (!form) return;

        var checkboxes = Array.prototype.slice.call(form.querySelectorAll('input[name="participants[]"]'));
        var previews = {};
        form.querySelectorAll('[data-preview]').forEach(function (el) { previews[el.getAttribute('data-preview')] = el; });
        var scroll = form.querySelector('[data-preview-scroll]');
        var emptyState = form.querySelector('[data-preview-empty]');
        var issueButton = form.querySelector('[data-issue-button]');
        var counts = Array.prototype.slice.call(form.querySelectorAll('[data-selected-count]'));
        var selectAll = form.querySelector('[data-select-all]');

        function sync() {
            var selected = 0;
            checkboxes.forEach(function (box) {
                var card = previews[box.value];
                if (card) card.classList.toggle('hidden', !box.checked);
                if (box.checked) selected++;
            });
            counts.forEach(function (el) { el.textContent = selected; });
            if (emptyState) emptyState.classList.toggle('hidden', selected > 0);
            if (issueButton) issueButton.disabled = selected === 0;
        }

        form.addEventListener('change', function (event) {
            if (event.target.name === 'participants[]') sync();
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (box) {
                    if (!box.disabled) box.checked = selectAll.checked;
                });
                sync();
            });
        }

        // Don't submit an empty selection.
        form.addEventListener('submit', function (event) {
            if (!checkboxes.some(function (box) { return box.checked; })) event.preventDefault();
        });

        sync();
    })();
</script>
@endif
@endsection
