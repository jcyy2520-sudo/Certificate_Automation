{{-- Session flash and validation summary, shared by every admin layout. --}}
@if(session('success') || session('error'))
    <div class="mb-7 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm {{ session('success') ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-900' }}">
        <x-icon :name="session('success') ? 'check' : 'alert'" class="mt-0.5 size-[18px] shrink-0" />
        <span>{{ session('success') ?: session('error') }}</span>
    </div>
@endif

@if($errors->any())
    <div class="mb-7 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <p class="font-semibold">Please check the form.</p>
        <ul class="mt-1.5 space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
