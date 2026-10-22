@extends('layouts.app')
@section('title', 'User Management')
@section('content')
<x-page-header title="User Management" subtitle="Review verified requests and manage account access." />
<nav aria-label="Account status" class="mb-6 flex flex-wrap gap-2">
@foreach(['pending_approval' => 'Pending Requests', 'approved' => 'Approved Users', 'rejected' => 'Rejected Requests', 'suspended' => 'Suspended Users'] as $key => $label)
<a class="{{ $status === $key ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.users.index', ['status' => $key]) }}" @if($status === $key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
<div class="panel overflow-x-auto"><table class="account-management-table w-full text-left text-sm" aria-label="Platform accounts">
<thead><tr class="border-b border-slate-200"><th scope="col" class="p-4">Account</th><th scope="col" class="p-4">Registered</th><th scope="col" class="p-4">Verification &amp; onboarding</th><th scope="col" class="p-4">Webinars</th><th scope="col" class="p-4">Actions</th></tr></thead>
<tbody>
@forelse($users as $user)
<tr class="border-b border-slate-100 align-top">
<td class="p-4"><p class="font-medium">{{ $user->name }}</p><p>{{ $user->email }}</p><p class="mt-1 text-xs text-slate-500">{{ $user->role === 'administrator' ? 'Platform administrator' : 'Regular user' }}</p></td>
<td class="p-4 whitespace-nowrap" data-label="Registered (Asia/Manila)">{{ $user->created_at->timezone('Asia/Manila')->format('M j, Y H:i') }}</td>
<td class="p-4" data-label="Verification & onboarding"><p>{{ $user->email_verified_at ? 'Email verified' : 'Legacy administrator' }}</p><p>{{ $user->password_setup_at ? 'Password set' : 'Password setup pending' }}</p>
@if($delivery = $deliveries->get($user->id))<p class="mt-2">Account email: {{ $delivery->statusLabel() }}</p>@if($delivery->last_error)<p class="mt-1 text-xs text-red-700 dark:text-red-300">{{ $delivery->last_error }}</p>@endif @endif</td>
<td class="p-4" data-label="Webinars"><a class="text-accent-600 hover:underline" href="{{ route('admin.webinars.index', ['owner' => $user->id]) }}">{{ $user->webinars_count }} {{ Str::plural('webinar', $user->webinars_count) }}</a></td>
<td class="p-4" data-label="Actions"><div class="flex flex-wrap gap-2">
@foreach(match($status) { 'pending_approval' => ['approve' => 'Approve', 'reject' => 'Reject'], 'approved' => ['resend' => $user->password_setup_at ? 'Send recovery link' : 'Resend setup invitation', 'suspend' => 'Suspend'], 'suspended' => ['reactivate' => 'Reactivate'], default => [] } as $action => $label)
<form novalidate method="POST" action="{{ route('admin.users.update', $user) }}" data-confirm="{{ $label }} for {{ $user->name }}? {{ $action === 'suspend' ? 'Access will stop immediately. Existing webinars and certificates will be preserved.' : 'This action is recorded.' }}" data-confirm-action="{{ $label }}">
@csrf <input type="hidden" name="action" value="{{ $action }}"><button class="button-secondary whitespace-nowrap">{{ $label }}</button>
</form>
@endforeach
</div></td></tr>
@empty<tr><td colspan="5" class="p-8 text-center text-slate-500">No accounts in this view. Only verified requests enter the review queue.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-6">{{ $users->links() }}</div>
@endsection
