<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending_approval');
        abort_unless(in_array($status, ['pending_approval', 'approved', 'rejected', 'suspended'], true), 404);
        $users = User::query()->where('approval_status', $status)->withCount('webinars')->latest('id')->paginate(20)->withQueryString();
        $deliveries = EmailDelivery::query()->select(['id', 'account_user_id', 'status', 'last_error', 'type', 'provider'])
            ->whereIn('account_user_id', $users->pluck('id'))->latest('id')->get()
            ->unique('account_user_id')->keyBy('account_user_id');

        return view('admin.users.index', compact('users', 'status', 'deliveries'));
    }

    public function update(Request $request, User $user, AccountService $accounts, AuditService $audit)
    {
        $data = $request->validate(['action' => ['required', 'in:approve,reject,suspend,reactivate,resend']]);
        DB::transaction(function () use ($request, $user, $accounts, $audit, $data): void {
            if ($data['action'] === 'resend') {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->approval_status === 'approved' && $locked->is_active, 409);
                $accounts->invite($locked);
            } else {
                $accounts->transition($user, $data['action']);
            }
            $audit->record($request, 'account.'.$data['action'], $user);
        });

        $nextStatus = $user->fresh()->approval_status;

        return redirect()->route('admin.users.index', ['status' => $nextStatus])->with('success', $data['action'] === 'resend'
            ? 'Password link requested. Recent requests are limited; review invitation status below.'
            : 'Account updated. Review invitation status below for email progress.');
    }
}
