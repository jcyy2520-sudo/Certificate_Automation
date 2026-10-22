@php
    $editing = $account ?? null;
    $restore = old('_account_dialog') === $dialogId;
    $chosenRole = $restore ? old('role') : ($editing?->role ?? 'webinar_manager');
    $chosenIds = $restore ? (array) old('webinar_ids', []) : ($editing?->assignedWebinars->pluck('id')->all() ?? []);
@endphp
@if($restore && $errors->any())
<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">Review the following before continuing:</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<input type="hidden" name="_account_dialog" value="{{ $dialogId }}">
<label class="field-label">Role
<select class="field" name="role" data-account-role required>
    <option value="webinar_manager" @selected($chosenRole === 'webinar_manager')>Webinar Manager</option>
    <option value="user" @selected($chosenRole === 'user')>Regular User — view assigned webinar overview</option>
</select></label>
<fieldset class="rounded-xl border border-slate-200 p-4"><legend class="px-1 text-sm font-semibold">Assigned webinars</legend>
<p class="mb-3 text-xs text-slate-500">Managers require at least one assignment. Webinar ownership stays unchanged.</p>
<div class="max-h-48 space-y-2 overflow-y-auto">
@forelse($webinars as $assigned)
<label class="flex items-start gap-3 text-sm"><input class="mt-1" type="checkbox" name="webinar_ids[]" value="{{ $assigned->id }}" @checked(in_array($assigned->id, $chosenIds))><span>{{ $assigned->title }}</span></label>
@empty<p class="text-sm text-slate-500">Create a webinar first, then return to assign a manager.</p>@endforelse
</div></fieldset>
<fieldset class="space-y-3 rounded-xl border border-slate-200 p-4" data-account-manager-fields><legend class="px-1 text-sm font-semibold">Manager permissions</legend>
<p class="text-xs text-slate-500">Apply to all selected webinars. Certificate generation also queues email, so it requires both certificate and email permissions.</p>
@foreach(['manage_participants' => 'Manage participants, responses, forms and documents', 'manage_certificates' => 'Manage certificate templates, eligibility and certificates', 'send_emails' => 'Send participant messages and certificate emails'] as $permission => $permissionLabel)
<label class="flex items-start gap-3 text-sm"><input class="mt-1" type="checkbox" name="{{ $permission }}" value="1" @checked($restore ? old($permission) : ($editing?->assignedWebinars->contains(fn ($w) => (bool) $w->pivot->{$permission}) ?? false))><span>{{ $permissionLabel }}</span></label>
@endforeach
</fieldset>
<p class="text-sm text-slate-500">Confirm this access change using your own credentials.</p>
<label class="field-label">Your current password<input class="field" type="password" name="password" required autocomplete="current-password"></label>
<x-current-factor />
