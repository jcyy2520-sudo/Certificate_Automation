{{-- Shared in-app UI: toast viewport and the confirmation modal that replace
     every browser alert()/confirm() dialog. Included once per admin layout. --}}

{{-- Toast viewport. Session flash is rendered here as the first toast so a
     success/error survives a normal redirect; the page script animates it in,
     lets it be dismissed, and auto-hides success messages. --}}
<div class="toast-viewport" data-toast-viewport aria-live="polite" aria-atomic="false">
    @if(session('success') || session('error'))
        @php $isSuccess = (bool) session('success'); @endphp
        <div class="toast {{ $isSuccess ? 'toast-success' : 'toast-error' }}" role="status"
             data-toast @if($isSuccess) data-toast-autohide="6000" @endif>
            <span class="toast-icon"><x-icon :name="$isSuccess ? 'check-circle' : 'alert'" class="size-5" /></span>
            <div class="toast-body">
                <p class="toast-title">{{ $isSuccess ? 'Done' : 'Something needs your attention' }}</p>
                <p class="mt-0.5 text-slate-600">{{ session('success') ?: session('error') }}</p>
            </div>
            <button type="button" class="toast-close" data-toast-close aria-label="Dismiss"><x-icon name="x" class="size-4" /></button>
        </div>
    @endif
</div>

{{-- Confirmation modal. A form marked data-confirm="..." opens this instead of
     window.confirm; confirming re-submits the form. --}}
<div class="modal-overlay hidden" data-confirm-modal role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title" hidden>
    <div class="modal-card" data-confirm-card>
        <div class="flex items-start gap-4">
            <span class="modal-icon bg-red-50 text-red-600" data-confirm-icon>
                <x-icon name="alert" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-semibold text-slate-900" id="confirm-modal-title" data-confirm-title>Please confirm</h2>
                <p class="mt-1.5 text-[13px] leading-5 text-slate-600" data-confirm-message></p>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="button-secondary sm:min-w-24" data-confirm-cancel>Cancel</button>
            <button type="button" class="button-primary sm:min-w-24" data-confirm-accept>Confirm</button>
        </div>
    </div>
</div>
