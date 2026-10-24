@extends('layouts.webinar')
@section('title', 'Original evaluation row — '.$webinar->title)
@section('content')
<x-page-header :title="'Original source row '.$response->row_number" :subtitle="'Unattributed evaluation · Import #'.$import->id">
    <x-slot:actions><a class="button-secondary" href="{{ route('admin.unattributed.index', [$webinar, 'import' => $import->id]) }}">Back to responses</a></x-slot:actions>
</x-page-header>
<p class="mb-5 text-sm text-slate-600">These are the original source labels and values in column order. This row has no participant or completion record.</p>
<section class="panel p-4">
    <h2 class="mb-4 text-base font-semibold">Original cells</h2>
    <dl class="space-y-5">@foreach($cells as $cell)<div class="min-w-0"><dt class="whitespace-pre-wrap break-words text-sm font-semibold">{{ $cell['label'] }}</dt><dd class="mt-1"><x-response-value :value="$cell['value']" />@if($cell['value'] === '')<span class="text-sm text-slate-500">Empty cell</span>@endif</dd></div>@endforeach</dl>
</section>
@endsection
