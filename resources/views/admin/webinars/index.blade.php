@extends('layouts.app')
@section('title', 'Webinars')
@section('content')

<x-page-header title="Webinars" :crumbs="['Dashboard' => route('admin.dashboard')]">
    <x-slot:actions>
        <a class="button-primary" href="{{ route('admin.webinars.create') }}"><x-icon name="plus" class="size-4" />New webinar</a>
    </x-slot:actions>
</x-page-header>

<form method="GET" class="mb-6 flex flex-wrap items-center gap-3">
    <label class="chip w-56">
        <x-icon name="filter" class="size-[18px] text-slate-400" />
        <select name="status" data-auto-submit>
            <option value="">All statuses</option>
            @foreach(['draft', 'published', 'completed', 'archived'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </label>
    <span class="text-[13px] text-slate-500 tabular-nums">{{ $webinars->total() }} total</span>
</form>

<div class="panel overflow-hidden">
    <div class="divide-y divide-slate-100">
        @forelse($webinars as $webinar)
            <a href="{{ route('admin.webinars.show', $webinar) }}" class="flex flex-col gap-3 px-5 py-4 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5">
                        <p class="truncate text-sm font-medium">{{ $webinar->title }}</p>
                        <span class="badge shrink-0 {{ $webinar->status === 'published' ? 'badge-green' : 'badge-slate' }}">{{ $webinar->status }}</span>
                    </div>
                    <p class="mt-1 truncate text-[13px] text-slate-500">{{ $webinar->description ?: 'No description yet.' }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-6 text-[13px] text-slate-500 tabular-nums">
                    <span><strong class="font-semibold text-slate-900">{{ $webinar->participants_count }}</strong> participants</span>
                    <span><strong class="font-semibold text-slate-900">{{ $webinar->certificates_count }}</strong> certificates</span>
                    <span class="w-24 text-right">{{ $webinar->starts_at?->format('M j, Y') ?? 'No date' }}</span>
                </div>
            </a>
        @empty
            <div class="px-5 py-16 text-center">
                @if(request('status'))
                    <p class="text-sm text-slate-500">No {{ request('status') }} webinars.</p>
                    <a class="mt-3 inline-block text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.webinars.index') }}">Clear the filter</a>
                @else
                    <p class="text-sm text-slate-500">No webinars yet.</p>
                    <a class="button-primary mt-5" href="{{ route('admin.webinars.create') }}"><x-icon name="plus" class="size-4" />Create the first webinar</a>
                @endif
            </div>
        @endforelse
    </div>
</div>

<div class="mt-6">{{ $webinars->links() }}</div>
@endsection
