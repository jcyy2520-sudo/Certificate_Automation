@extends('layouts.webinar')
@section('title', 'Import responses — '.$webinar->title)
@section('content')
<x-page-header title="Import Responses" :crumbs="[]" :subtitle="'Upload an exported Google Forms response file for '.($webinar->title).'.'">
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.responses.index', [$webinar, 'type' => $selectedType]) }}"><x-icon name="arrow-left" class="size-4" />Back to responses</a>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('admin.webinars.imports.preview', $webinar) }}" enctype="multipart/form-data" class="max-w-2xl">
    @csrf
    <section class="panel divide-y divide-slate-100">
        <div class="px-5 py-5">
            <label class="field-label" for="response-form">Which form are these responses for?</label>
            <select id="response-form" name="form_id" class="field mt-1 max-w-md {{ $errors->has('form_id') ? 'field-invalid' : '' }}" required>
                @foreach($forms as $form)
                    <option value="{{ $form->id }}" @selected(old('form_id', $forms->firstWhere('type', $selectedType)?->id) == $form->id)>{{ $form->title }} ({{ $form->type }})</option>
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
            <label class="field-label" for="response-file">Response file (.csv or .xlsx, up to 4 MB)</label>
            <input id="response-file" type="file" name="file" accept=".csv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                   class="field mt-1 {{ $errors->has('file') ? 'field-invalid' : '' }}">
            <p class="mt-2 text-[12px] leading-5 text-slate-500">Upload a Google Forms export, UTF-8 comma-separated CSV, or Excel workbook containing one response sheet. Up to 5,000 response rows, 200 columns and 300,000 cells. The file is analyzed before import. Keep your original; the private temporary copy is deleted after import.</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600">
            <span class="flex items-start gap-2">
                <x-icon name="info" class="mt-px size-4 shrink-0 text-slate-400" />
                <span>Import registration first, then assessment and evaluation files. Participants match by email only. Confirm the suggested columns and run the dry check before importing. Ambiguous columns require your choice; names and email ownership cannot be inferred from the file.</span>
            </span>
        </div>
    </section>

    <div class="mt-6 flex items-center gap-3">
        <button class="button-primary"><x-icon name="files" class="size-4" />Continue to column mapping</button>
        <a class="button-secondary" href="{{ route('admin.webinars.imports.index', $webinar) }}">Cancel</a>
    </div>
</form>
@endsection
