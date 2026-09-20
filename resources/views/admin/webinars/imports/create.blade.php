@extends('layouts.webinar')
@section('title', 'Import responses — '.$webinar->title)
@section('content')
<x-page-header title="CSV imports" :crumbs="[]" :subtitle="'Upload an exported Google Forms response file for '.($webinar->title).'.'">
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.webinars.imports.index', $webinar) }}"><x-icon name="arrow-left" class="size-4" />Back to imports</a>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('admin.webinars.imports.preview', $webinar) }}" enctype="multipart/form-data" class="max-w-2xl">
    @csrf
    <section class="panel divide-y divide-slate-100">
        <div class="px-5 py-5">
            <label class="field-label">Which form are these responses for?</label>
            <select name="form_id" class="field mt-1 max-w-md {{ $errors->has('form_id') ? 'field-invalid' : '' }}" required>
                @foreach($forms as $form)
                    <option value="{{ $form->id }}" @selected(old('form_id') == $form->id)>{{ $form->title }} ({{ $form->type }})</option>
                @endforeach
            </select>
            <x-field-error :error="$errors->first('form_id')" />
            @error('file')
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[12px] leading-5 text-red-900">{{ $message }}</div>
            @enderror
            <label class="mt-4 flex items-start gap-2 text-[12px] text-slate-600">
                <input type="checkbox" name="force" value="1" class="mt-0.5 size-4 rounded border-slate-300 text-accent-700">
                <span>Import even if this exact file was uploaded before.</span>
            </label>
        </div>

        <div class="px-5 py-5">
            <label class="field-label">Response file (.csv, up to 4 MB)</label>
            <input type="file" name="file" accept=".csv,text/csv,text/plain" required
                   class="field mt-1 {{ $errors->has('file') ? 'field-invalid' : '' }}">
            <p class="mt-2 text-[12px] leading-5 text-slate-500">Export from Google Forms with Responses → Link to Sheets → File → Download → Comma-separated values (.csv). The file is parsed and then discarded — it is never kept.</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600">
            <span class="flex items-start gap-2">
                <x-icon name="info" class="mt-px size-4 shrink-0 text-slate-400" />
                <span>Rows attach to participants by email address only — never by name. On the next screen you confirm which column holds what, so changed Google Forms headings are not a problem. Rows that cannot be attached cleanly are held for your review instead of being guessed at or dropped.</span>
            </span>
        </div>
    </section>

    <div class="mt-6 flex items-center gap-3">
        <button class="button-primary"><x-icon name="files" class="size-4" />Continue to column mapping</button>
        <a class="button-secondary" href="{{ route('admin.webinars.imports.index', $webinar) }}">Cancel</a>
    </div>
</form>
@endsection
