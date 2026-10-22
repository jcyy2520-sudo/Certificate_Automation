@extends('layouts.app')
@section('title', 'User Management')
@section('content')
<x-page-header title="User Management" subtitle="Review requests, invite groupmates and manage assigned webinar access." />
<div class="mb-6 flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-slate-500">You control system access. Groupmates use their own account and password.</p><button type="button" class="button-primary" data-account-dialog-open="account-create">Create Account</button></div>
@unless(app(\App\Services\AccountEmailReadiness::class)->ready())
<div role="status" class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">Account email delivery is unavailable. Requests remain saved, but invitations require a working email provider. Local captures do not reach an inbox.</div>
@endunless
<nav aria-label="Account status" class="mb-6 flex flex-wrap gap-2">
@foreach(['pending' => 'Pending Requests', 'invited' => 'Pending Invitations', 'active' => 'Active Accounts', 'suspended' => 'Disabled Accounts', 'rejected' => 'Rejected Requests'] as $key => $label)
<a class="{{ $status === $key ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.users.index', ['status' => $key]) }}" @if($status === $key) aria-current="page" @endif>{{ $label }} ({{ $counts[$key] }})</a>
@endforeach
</nav>
<div class="panel overflow-x-auto"><table class="account-management-table w-full text-left text-sm" aria-label="Platform accounts">
<thead><tr class="border-b border-slate-200"><th scope="col" class="p-4">Account</th><th scope="col" class="p-4">Created</th><th scope="col" class="p-4">Status &amp; invitation</th><th scope="col" class="p-4">Webinar access</th><th scope="col" class="p-4">Actions</th></tr></thead><tbody>
@forelse($users as $user)
<tr class="border-b border-slate-100 align-top">
<td class="p-4"><p class="font-medium">{{ $user->name }}</p><p class="break-all">{{ $user->email }}</p><p class="mt-1 text-xs text-slate-500">{{ match($user->role) { 'administrator' => 'System Administrator', 'webinar_manager' => 'Webinar Manager', default => 'Regular User' } }}</p></td>
<td class="p-4 whitespace-nowrap" data-label="Created (Asia/Manila)">{{ $user->created_at->timezone('Asia/Manila')->format('M j, Y H:i') }}</td>
<td class="p-4" data-label="Status & invitation"><p class="font-medium">{{ $user->accountStatusLabel() }}</p><p class="mt-1 text-xs text-slate-500">{{ $user->email_verified_at ? 'Email verified' : 'Email verified during activation' }}</p>
@if($user->role === 'administrator')<p class="mt-1 text-xs">{{ $user->two_factor_confirmed_at ? 'Personal authenticator enrolled' : 'Authenticator enrollment required' }}</p>@endif
@if($delivery = $deliveries->get($user->id))<p class="mt-2">Email: {{ $delivery->statusLabel() }}</p>@if(!$user->password_setup_at && $delivery->expires_at)<p class="mt-1 text-xs text-slate-500">{{ $delivery->expires_at->isPast() ? 'Invitation expired — resend to issue a new link.' : 'Link expires '.$delivery->expires_at->timezone('Asia/Manila')->format('M j, H:i') }}</p>@endif @if($delivery->last_error)<p class="mt-1 text-xs text-red-700 dark:text-red-300">{{ $delivery->last_error }}</p>@endif @endif</td>
<td class="p-4" data-label="Webinar access">
@if($user->role === 'administrator')<p>All webinars and system management</p>@else
<ul class="space-y-1">@forelse($user->assignedWebinars as $assigned)<li>{{ $assigned->title }}</li>@empty<li class="text-slate-500">No assigned webinars</li>@endforelse</ul>
@if($user->webinars_count)<a class="mt-2 inline-block text-accent-600 hover:underline" href="{{ route('admin.webinars.index', ['owner' => $user->id]) }}">{{ $user->webinars_count }} owned {{ Str::plural('webinar', $user->webinars_count) }}</a>@endif
@endif</td>
<td class="p-4" data-label="Actions"><div class="flex flex-wrap gap-2">
@if($user->role !== 'administrator')
@foreach(match($user->approval_status) { 'pending_verification', 'pending_approval' => ['approve' => 'Approve', 'reject' => 'Reject'], 'approved' => $user->password_setup_at ? ['resend' => 'Send recovery link', 'suspend' => 'Disable Account'] : ['resend' => 'Resend Invitation', 'revoke' => 'Revoke Invitation'], 'suspended' => ['reactivate' => 'Reactivate'], 'invitation_revoked' => ['reinvite' => 'Send new invitation'], default => [] } as $action => $label)
<form novalidate method="POST" action="{{ route('admin.users.update', $user) }}" data-confirm="{{ $label }} for {{ $user->name }}? {{ in_array($action, ['suspend', 'revoke']) ? 'Account access or the unused invitation will stop. Existing webinars and records are preserved.' : 'This action is recorded in the audit trail.' }}" data-confirm-action="{{ $label }}">
@csrf <input type="hidden" name="action" value="{{ $action }}"><button class="button-secondary whitespace-nowrap">{{ $label }}</button></form>
@endforeach
<button type="button" class="button-secondary" data-account-dialog-open="account-access-{{ $user->id }}">Edit Access</button>
@endif
<a class="button-secondary" href="{{ route('admin.audit.index', ['actor' => $user->id]) }}">View Activity</a>
</div></td></tr>
@empty<tr><td colspan="5" class="p-8 text-center text-slate-500">No accounts in this view. New public requests appear in Pending Requests immediately.</td></tr>@endforelse
</tbody></table></div>
<div class="mt-6">{{ $users->links() }}</div>
<dialog id="account-create" class="response-dialog panel p-6" data-account-dialog aria-labelledby="account-create-title">
<h2 id="account-create-title" class="section-title">Create Account</h2><p class="mt-2 text-sm text-slate-500">Invite a groupmate. They verify their email and choose their own password. This does not grant system administrator access.</p>
<form novalidate method="POST" action="{{ route('admin.users.store') }}" class="mt-5 grid gap-4">@csrf
<label class="field-label">Full Name<input class="field" name="name" required maxlength="255" autocomplete="name" value="{{ old('_account_dialog') === 'account-create' ? old('name') : '' }}"></label>
<label class="field-label">Email Address<input class="field" type="email" name="email" required maxlength="254" autocomplete="email" value="{{ old('_account_dialog') === 'account-create' ? old('email') : '' }}"></label>
@include('admin.users.access-fields', ['account' => null, 'dialogId' => 'account-create'])
<div class="flex justify-end gap-2"><button class="button-secondary" type="button" data-account-dialog-close>Cancel</button><button class="button-primary">Create and invite</button></div>
</form></dialog>
@foreach($users->where('role', '!=', 'administrator') as $account)
<dialog id="account-access-{{ $account->id }}" class="response-dialog panel p-6" data-account-dialog aria-labelledby="account-access-title-{{ $account->id }}">
<h2 class="section-title" id="account-access-title-{{ $account->id }}">Edit Access</h2><p class="mt-2 text-sm text-slate-500">{{ $account->name }} · {{ $account->email }}</p>
<form novalidate method="POST" action="{{ route('admin.users.access', $account) }}" class="mt-5 grid gap-4">@csrf @method('PUT')
@include('admin.users.access-fields', ['dialogId' => 'account-access-'.$account->id])
<div class="flex justify-end gap-2"><button class="button-secondary" type="button" data-account-dialog-close>Cancel</button><button class="button-primary">Save access</button></div>
</form></dialog>
@endforeach
@if($errors->any() && old('_account_dialog'))<span hidden data-account-reopen="{{ old('_account_dialog') }}"></span>@endif
@endsection
