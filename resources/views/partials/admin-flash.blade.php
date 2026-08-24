{{-- Validation summary, shared by every admin layout. Success/error flash is
     rendered as a toast in partials/admin-ui instead of an inline banner. --}}
@if($errors->any())
    <div class="mb-7 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <p class="font-semibold">Please check the form.</p>
        <ul class="mt-1.5 space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
