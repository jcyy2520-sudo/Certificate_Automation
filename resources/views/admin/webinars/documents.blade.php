@extends('layouts.webinar')
@section('title', 'Uploaded forms and documents')
@section('content')
<x-page-header title="Uploaded forms and documents" subtitle="Private files for this webinar. Downloaded files remain protected by webinar ownership and access checks." />
<form novalidate method="POST" enctype="multipart/form-data" action="{{ route('admin.webinars.documents.store', $webinar) }}" class="panel mb-6 grid max-w-2xl gap-4 p-5">
    @csrf
    <h2 class="section-title">Add a document</h2>
    <x-account-field name="title" label="Document title" :value="old('title')" maxlength="180" />
    <label for="new-document" class="field-label">File</label>
    <input id="new-document" type="file" name="file" accept=".pdf,.docx,.png,.jpg,.jpeg" required aria-describedby="document-help document-error">
    <p id="document-help" class="text-sm text-slate-500">PDF, DOCX, PNG or JPEG. Maximum 8 MB. Files are downloaded as attachments; this screen does not publish a public form or collect responses.</p>
    <div id="document-error"><x-field-error :error="$errors->first('file')" /></div>
    <button class="button-primary justify-self-start">Upload document</button>
</form>
<div class="grid gap-4">
@forelse($documents as $document)
    <article class="panel p-5">
        <h2 class="section-title">{{ $document->title }}</h2>
        <p class="mt-2 text-sm text-slate-500">{{ strtoupper($document->extension) }} · {{ number_format($document->size / 1024) }} KB · Version {{ $document->version }}</p>
        <a class="button-secondary mt-3" href="{{ route('admin.webinars.documents.download', [$webinar, $document]) }}" download>Download {{ $document->title }}</a>
        <details class="mt-4">
            <summary class="cursor-pointer text-sm">Replace document</summary>
            <form novalidate method="POST" enctype="multipart/form-data" action="{{ route('admin.webinars.documents.update', [$webinar, $document]) }}" class="mt-4 grid max-w-2xl gap-3" data-confirm="Replace this document? The previous file will be retained privately until webinar deletion." data-confirm-action="Replace document">
                @csrf @method('PUT')
                <input type="hidden" name="version" value="{{ $document->version }}">
                <label class="field-label" for="document-title-{{ $document->public_id }}">Document title</label>
                <input class="field" id="document-title-{{ $document->public_id }}" name="title" value="{{ $document->title }}" maxlength="180" required>
                <label class="field-label" for="document-file-{{ $document->public_id }}">Replacement file</label>
                <input id="document-file-{{ $document->public_id }}" type="file" name="file" accept=".pdf,.docx,.png,.jpg,.jpeg" required>
                <button class="button-secondary justify-self-start">Replace document</button>
            </form>
        </details>
        <form novalidate method="POST" action="{{ route('admin.webinars.documents.destroy', [$webinar, $document]) }}" class="mt-4" data-confirm="Remove this document from the webinar? Its file stays private until webinar deletion." data-confirm-action="Remove document">
            @csrf @method('DELETE')
            <button class="button-danger">Remove document</button>
        </form>
    </article>
@empty
    <p class="panel p-6 text-sm text-slate-500">No documents yet. Use Add a document above to upload your first form.</p>
@endforelse
</div>
<div class="mt-6">{{ $documents->links() }}</div>
@endsection
