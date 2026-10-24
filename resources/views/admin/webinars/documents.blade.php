@extends('layouts.webinar')
@php
    $isWorkspace = request()->routeIs('admin.webinars.show');
    $documentContext = old('_document');
    $addErrors = ! $documentContext && $errors->any();
@endphp
@section('title', $isWorkspace ? $webinar->title : 'Uploaded forms and documents')
@section('content')
<x-page-header :title="$isWorkspace ? $webinar->title : 'Uploaded forms and documents'" subtitle="Keep your webinar's forms and supporting files together in one private workspace.">
    <x-slot:meta><div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500"><span class="badge badge-slate">Document workspace</span><span>{{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}</span></div></x-slot:meta>
    @if(auth()->user()->isAdministrator() || (auth()->user()->canCreateWebinars() && (int) $webinar->created_by === (int) auth()->id()))
        <x-slot:actions><a class="button-secondary" href="{{ route('admin.webinars.edit', $webinar) }}"><x-icon name="settings" class="size-4" />Webinar settings</a></x-slot:actions>
    @endif
</x-page-header>

<details class="panel document-upload mb-7" @if($addErrors) open @endif>
    <summary class="document-upload-trigger"><span class="flex items-center gap-3"><span class="document-upload-icon"><x-icon name="plus" class="size-5" /></span><span><span class="block font-medium">Add a document</span><span class="mt-1 block text-xs font-normal text-slate-500">Excel workbooks, forms and supporting files</span></span></span><x-icon name="chevron-down" class="size-4 shrink-0 text-slate-400" /></summary>
    <form novalidate method="POST" enctype="multipart/form-data" action="{{ route('admin.webinars.documents.store', $webinar) }}" class="document-upload-form grid max-w-2xl gap-4">
        @csrf
        <div><label for="new-document-title" class="field-label">Document title</label><input id="new-document-title" class="field" name="title" value="{{ $documentContext ? '' : old('title') }}" maxlength="180" required aria-invalid="{{ $addErrors && $errors->has('title') ? 'true' : 'false' }}" aria-describedby="new-document-title-error"><div id="new-document-title-error"><x-field-error :error="$addErrors ? $errors->first('title') : null" /></div></div>
        <div>
            <label for="new-document" class="field-label">File</label>
            <input id="new-document" type="file" name="file" accept=".xlsx,.pdf,.docx,.png,.jpg,.jpeg" required aria-invalid="{{ $addErrors && $errors->has('file') ? 'true' : 'false' }}" aria-describedby="document-help document-error">
            <p id="document-help" class="mt-3 text-xs leading-5 text-slate-500">Excel (.xlsx), PDF, DOCX, PNG or JPG. Up to 8 MB. For older .xls workbooks, save a copy as .xlsx first.</p>
            <div id="document-error"><x-field-error :error="$addErrors ? $errors->first('file') : null" /></div>
        </div>
        <p class="text-xs leading-5 text-slate-500">Files stay private and download as attachments. Uploading a document does not publish a participant form or import its responses.</p>
        <button class="button-primary justify-self-start"><x-icon name="upload" class="size-4" />Upload document</button>
    </form>
</details>

<section aria-labelledby="documents-heading">
    <div class="mb-4 flex items-center justify-between gap-3"><h2 id="documents-heading" class="section-title">Documents</h2><span class="text-xs tabular-nums text-slate-500">{{ $documents->total() }} {{ Str::plural('file', $documents->total()) }}</span></div>
    <div class="grid gap-4 xl:grid-cols-2">
    @forelse($documents as $document)
        @php($failedReplacement = $documentContext === $document->public_id && $errors->any())
        <article class="panel document-card">
            <div class="flex items-start gap-3"><span class="document-type">{{ strtoupper($document->extension) }}</span><div class="min-w-0"><h3 class="font-medium break-words">{{ $document->title }}</h3><p class="mt-1 text-xs text-slate-500">{{ number_format($document->size / 1024) }} KB <span aria-hidden="true">·</span> Version {{ $document->version }}</p></div></div>
            <a class="button-secondary mt-5" href="{{ route('admin.webinars.documents.download', [$webinar, $document]) }}" download><x-icon name="download" class="size-4" />Download {{ $document->title }}</a>
            <details class="document-replace mt-5" @if($failedReplacement) open @endif>
                <summary class="text-sm font-medium text-slate-500">Replace document</summary>
                <form novalidate method="POST" enctype="multipart/form-data" action="{{ route('admin.webinars.documents.update', [$webinar, $document]) }}" class="mt-4 grid gap-3" data-confirm="Replace this document? The previous file will be retained privately until webinar deletion." data-confirm-action="Replace document">
                    @csrf @method('PUT')
                    <input type="hidden" name="version" value="{{ $document->version }}">
                    <input type="hidden" name="_document" value="{{ $document->public_id }}">
                    <label class="field-label" for="document-title-{{ $document->public_id }}">Document title</label>
                    <input class="field" id="document-title-{{ $document->public_id }}" name="title" value="{{ $failedReplacement ? old('title', $document->title) : $document->title }}" maxlength="180" required aria-invalid="{{ $failedReplacement && $errors->has('title') ? 'true' : 'false' }}" aria-describedby="document-title-error-{{ $document->public_id }}">
                    <div id="document-title-error-{{ $document->public_id }}"><x-field-error :error="$failedReplacement ? $errors->first('title') : null" /></div>
                    <label class="field-label" for="document-file-{{ $document->public_id }}">Replacement file</label>
                    <input id="document-file-{{ $document->public_id }}" type="file" name="file" accept=".xlsx,.pdf,.docx,.png,.jpg,.jpeg" aria-invalid="{{ $failedReplacement && $errors->has('file') ? 'true' : 'false' }}" aria-describedby="document-file-help-{{ $document->public_id }} document-file-error-{{ $document->public_id }}" required>
                    <p id="document-file-help-{{ $document->public_id }}" class="text-xs text-slate-500">Excel (.xlsx), PDF, DOCX, PNG or JPG. Up to 8 MB.</p>
                    <div id="document-file-error-{{ $document->public_id }}"><x-field-error :error="$failedReplacement ? $errors->first('file') : null" /></div>
                    @if($failedReplacement)<x-field-error :error="$errors->first('version')" />@endif
                    <button class="button-secondary justify-self-start">Replace document</button>
                </form>
            </details>
            <form novalidate method="POST" action="{{ route('admin.webinars.documents.destroy', [$webinar, $document]) }}" class="mt-4" data-confirm="Remove this document from the webinar? Its file stays private until webinar deletion." data-confirm-action="Remove document">
                @csrf @method('DELETE')
                <button class="button-danger">Remove document</button>
            </form>
        </article>
    @empty
        <div class="panel document-empty xl:col-span-2"><span class="document-upload-icon mx-auto"><x-icon name="folder" class="size-6" /></span><h3 class="mt-4">Your webinar is ready</h3><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Add forms or supporting files whenever you need them. You can return to this workspace at any time.</p></div>
    @endforelse
    </div>
</section>
<div class="mt-6">{{ $documents->links() }}</div>
@endsection
