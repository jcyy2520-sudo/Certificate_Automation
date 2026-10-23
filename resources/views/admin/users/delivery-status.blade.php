@php
    $awaitingActivation = $user->approval_status === 'approved' && $user->password_setup_at === null;
    $expiredPending = $delivery && $delivery->expires_at?->isPast() && in_array($delivery->status, ['pending', 'processing'], true);
@endphp
@if($delivery)
    <p class="mt-2">Email: {{ $expiredPending ? ((int) $delivery->attempts === 0 ? 'Expired before sending' : 'Expired') : $delivery->statusLabel() }}</p>
    <p class="mt-1 text-xs text-slate-500">{{ (int) $delivery->attempts }} {{ Str::plural('attempt', (int) $delivery->attempts) }}@if($delivery->sent_at) · {{ $delivery->provider === 'log' ? 'Captured' : 'Accepted' }} {{ $delivery->sent_at->timezone('Asia/Manila')->format('M j, H:i') }}@endif</p>
    @if($awaitingActivation && $delivery->expires_at)
        <p class="mt-1 text-xs text-slate-500">{{ $delivery->expires_at->isPast() ? 'Invitation expired — resolve any delivery issue below, then choose Resend Invitation for a fresh link.' : 'Link expires '.$delivery->expires_at->timezone('Asia/Manila')->format('M j, H:i') }}</p>
    @endif
    @if(!$expiredPending && $delivery->status === 'pending' && $delivery->scheduled_at?->isFuture())
        <p class="mt-1 text-xs text-slate-500">Next attempt {{ $delivery->scheduled_at->timezone('Asia/Manila')->format('M j, H:i') }}. A working email worker and outbox relay are required.</p>
    @elseif(!$expiredPending && $awaitingActivation && $delivery->status === 'pending' && (int) $delivery->attempts === 0 && $delivery->created_at?->lt(now()->subMinutes(2)))
        <p role="status" class="mt-1 text-xs text-amber-700 dark:text-amber-300">Activation email has not been attempted. Check the {{ config('accounts.email_queue') }} email worker, outbox relay and provider allowance before resending.</p>
    @endif
    @if($delivery->last_error)
        <p class="mt-1 text-xs text-red-700 dark:text-red-300">{{ $delivery->last_error }}</p>
    @endif
@endif
@if($awaitingActivation && (!$delivery || $delivery->type !== 'account_setup'))
    <p role="status" class="mt-2 text-xs text-amber-700 dark:text-amber-300">Activation email has not been queued. Choose Resend Invitation to create a fresh activation link.</p>
@endif
