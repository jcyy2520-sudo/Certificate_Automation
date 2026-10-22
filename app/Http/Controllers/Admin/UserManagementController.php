<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AccountService;
use App\Services\AdminDeviceService;
use App\Services\AuditService;
use App\Services\SensitiveAccountVerification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'pending_verification', 'pending_approval', 'invited', 'active', 'approved', 'rejected', 'suspended', 'invitation_revoked'], true), 404);
        $query = User::query();
        match ($status) {
            'pending' => $query->whereIn('approval_status', ['pending_verification', 'pending_approval']),
            'invited' => $query->where('approval_status', 'approved')->whereNull('password_setup_at'),
            'active' => $query->where('approval_status', 'approved')->where('is_active', true)->whereNotNull('password_setup_at'),
            'suspended' => $query->whereIn('approval_status', ['suspended', 'invitation_revoked']),
            default => $query->where('approval_status', $status),
        };
        $users = $query->withCount('webinars')->with('assignedWebinars:id,title')->latest('id')->paginate(20)->withQueryString();
        $deliveries = EmailDelivery::query()->select(['id', 'account_user_id', 'status', 'last_error', 'type', 'provider', 'expires_at'])
            ->whereIn('id', EmailDelivery::query()->selectRaw('max(id)')->whereIn('account_user_id', $users->pluck('id'))->groupBy('account_user_id'))
            ->get()->keyBy('account_user_id');
        $counts = [
            'pending' => User::query()->whereIn('approval_status', ['pending_verification', 'pending_approval'])->count(),
            'invited' => User::query()->where('approval_status', 'approved')->whereNull('password_setup_at')->count(),
            'active' => User::query()->where('approval_status', 'approved')->where('is_active', true)->whereNotNull('password_setup_at')->count(),
            'suspended' => User::query()->whereIn('approval_status', ['suspended', 'invitation_revoked'])->count(),
            'rejected' => User::query()->where('approval_status', 'rejected')->count(),
        ];
        $webinars = Webinar::query()->whereNull('deletion_started_at')->orderBy('title')->get(['id', 'title']);

        return view('admin.users.index', compact('users', 'status', 'deliveries', 'counts', 'webinars'));
    }

    public function store(Request $request, AccountService $accounts, AuditService $audit)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email'))), 'webinar_ids' => $request->input('webinar_ids', [])]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email_normalized')],
            ...$this->accessRules(),
        ]);
        try {
            DB::transaction(function () use ($request, $data, $accounts, $audit): void {
                $this->lockWebinars($data['webinar_ids'] ?? []);
                $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($actor->isAdministrator(), 403);
                app(SensitiveAccountVerification::class)->verify($request, $actor);
                $user = new User;
                $user->forceFill([
                    'name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'],
                    'password' => Hash::make(Str::random(64)), 'is_active' => false,
                    'approval_status' => 'approved', 'approved_at' => now(), 'account_source' => 'admin_invitation',
                ])->save();
                $this->assign($user, $data, $actor);
                $accounts->invite($user, true);
                $audit->record($request, 'account.invited', $user, ['role' => $user->role, 'assigned_webinars' => $data['webinar_ids'] ?? []]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::query()->where('email_normalized', $data['email'])->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['email' => 'An account already exists for this email. Review that account instead of creating another.']);
        }

        return redirect()->route('admin.users.index', ['status' => 'invited'])->with('success', 'Account created. Activation email queued; review its delivery status below. The recipient chooses their own password.');
    }

    public function access(Request $request, User $user, AuditService $audit)
    {
        $request->merge(['webinar_ids' => $request->input('webinar_ids', [])]);
        $data = $request->validate($this->accessRules());
        DB::transaction(function () use ($request, $user, $data, $audit): void {
            $this->lockWebinars($data['webinar_ids'] ?? []);
            $locked = User::query()->whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $locked[$request->user()->id];
            $target = $locked[$user->id];
            abort_unless($actor->isAdministrator(), 403);
            abort_if($target->role === User::ADMINISTRATOR_ROLE || $target->id === $actor->id, 403, 'Administrator access is not editable here.');
            app(SensitiveAccountVerification::class)->verify($request, $actor);
            $before = ['role' => $target->role, 'assignments' => $target->assignedWebinars()->get()->mapWithKeys(fn ($w) => [$w->id => collect($w->pivot)->only(['manage_participants', 'manage_certificates', 'send_emails'])->all()])->all()];
            $target->forceFill(['role' => $data['role'], 'auth_version' => $target->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            $this->assign($target, $data, $actor);
            app(AdminDeviceService::class)->revokeAll($target);
            if ($target->approval_status === 'approved' && $target->password_setup_at === null) {
                app(AccountService::class)->invite($target, true);
            }
            $audit->record($request, 'account.access_updated', $target, ['before' => $before, 'after' => ['role' => $target->role, 'assigned_webinars' => $data['webinar_ids'] ?? [], 'permissions' => $this->permissions($data)]]);
        }, attempts: 3);

        return back()->with('success', 'Account access updated. Removed assignments stop access immediately; queued work rechecks permissions.');
    }

    public function update(Request $request, User $user, AccountService $accounts, AuditService $audit)
    {
        $data = $request->validate(['action' => ['required', 'in:approve,reject,suspend,reactivate,resend,revoke,reinvite']]);
        abort_if($user->role === User::ADMINISTRATOR_ROLE, 403, 'Administrator credentials and access are managed separately.');
        DB::transaction(function () use ($request, $user, $accounts, $audit, $data): void {
            if ($data['action'] === 'resend') {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->approval_status === 'approved' && ($locked->is_active || $locked->password_setup_at === null), 409);
                $accounts->invite($locked);
            } else {
                $accounts->transition($user, $data['action']);
            }
            $audit->record($request, 'account.'.$data['action'], $user->fresh(), ['previous_status' => $user->approval_status, 'updated_status' => $user->fresh()->approval_status]);
        });

        return redirect()->route('admin.users.index', ['status' => $user->fresh()->approval_status])->with('success', $data['action'] === 'resend'
            ? 'Invitation requested. Repeated requests are limited; review the delivery status below. Provider acceptance does not guarantee inbox delivery.'
            : 'Account updated. Existing webinars and records are preserved. Review email progress below.');
    }

    private function accessRules(): array
    {
        return [
            'role' => ['required', Rule::in([User::USER_ROLE, User::MANAGER_ROLE])],
            'webinar_ids' => ['present', 'array', 'max:50', 'required_if:role,webinar_manager'],
            'webinar_ids.*' => ['required', 'integer', 'distinct', Rule::exists('webinars', 'id')->whereNull('deletion_started_at')],
            'manage_participants' => ['nullable', 'boolean'], 'manage_certificates' => ['nullable', 'boolean'], 'send_emails' => ['nullable', 'boolean'],
        ];
    }

    private function lockWebinars(array $ids): void
    {
        $found = Webinar::query()->whereIn('id', $ids)->whereNull('deletion_started_at')->orderBy('id')->lockForUpdate()->get(['id']);
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['webinar_ids' => 'A selected webinar is no longer available. Refresh and review assignments.']);
        }
    }

    private function permissions(array $data): array
    {
        return collect(['manage_participants', 'manage_certificates', 'send_emails'])->mapWithKeys(fn ($key) => [$key => $data['role'] === User::MANAGER_ROLE && (bool) ($data[$key] ?? false)])->all();
    }

    private function assign(User $user, array $data, User $actor): void
    {
        $permissions = $this->permissions($data) + ['assigned_by' => $actor->id];
        $user->assignedWebinars()->sync(collect($data['webinar_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => $permissions])->all());
    }
}
