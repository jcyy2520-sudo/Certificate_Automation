<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountEmailReadiness;
use App\Services\AccountService;
use App\Services\AdminDeviceService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function register()
    {
        return view('auth.account-request');
    }

    public function store(Request $request, AccountService $accounts, AuditService $audit)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:254']]);
        $user = $accounts->request($data['name'], $data['email']);
        $audit->record($request, $user->wasRecentlyCreated ? 'account.requested' : 'account.request_repeated', $user);

        return redirect()->route('account.status')->with('success', 'Your request has been received for administrator review. If approved, an activation email will let you verify your address and choose your password. Repeated requests do not create duplicate accounts.');
    }

    public function status()
    {
        return view('auth.account-status');
    }

    public function verify(Request $request, User $user, AccountService $accounts)
    {
        // Email clients and scanners may GET the page. Only the explicit POST
        // consumes the fragment token; GET contains no secret in access logs.
        return view('auth.account-verify', compact('user'));
    }

    public function consume(Request $request, User $user, AccountService $accounts)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $accounts->verify($user, $data['token']);

        return redirect()->route('account.status')->with('success', 'Email verified. Your request is now pending administrator approval. We will email you when it has been reviewed.');
    }

    public function forgot()
    {
        return view('auth.forgot-password');
    }

    public function sendReset(Request $request, AccountService $accounts)
    {
        $email = Str::lower(trim((string) $request->input('email')));
        validator(['email' => $email], ['email' => ['required', 'email', 'max:254']])->validate();
        if (! app(AccountEmailReadiness::class)->canQueue()) {
            return back()->with('warning', 'Account email delivery is temporarily unavailable. Please try again later.');
        }
        DB::transaction(function () use ($email, $accounts): void {
            $user = User::query()->where('email_normalized', $email)->lockForUpdate()->first();
            if ($user && $user->approval_status === 'approved' && ($user->is_active || $user->password_setup_at === null)) {
                $accounts->invite($user);
            }
        });

        return back()->with('success', 'If this account is approved and available, we will email a password link. Check your inbox and spam folder.');
    }

    public function reset(Request $request)
    {
        return view('auth.reset-password', ['setup' => $request->boolean('setup')]);
    }

    public function updatePassword(Request $request, AccountService $accounts)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'string', 'max:1024', PasswordRule::min(15)],
        ]);
        if ($validator->fails()) {
            return redirect()->to(route('password.reset').'#'.http_build_query([
                'token' => (string) $request->input('token'), 'email' => (string) $request->input('email'),
            ]))->withErrors($validator);
        }
        $data = $validator->validated();
        $data['email'] = Str::lower(trim($data['email']));
        $status = DB::transaction(function () use ($data, $accounts) {
            $user = User::query()->where('email_normalized', $data['email'])->lockForUpdate()->first();
            if (! $user || $user->approval_status !== 'approved' || (! $user->is_active && $user->password_setup_at !== null)) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset($data, function (User $user, string $password) use ($accounts): void {
                $activation = $user->password_setup_at === null;
                $user->forceFill(['password' => Hash::make($password), 'is_active' => true, 'email_verified_at' => $user->email_verified_at ?? now(), 'password_setup_at' => now(), 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
                $accounts->cancelPending($user);
                app(AdminDeviceService::class)->revokeAll($user);
                app(AuditService::class)->record(request(), $activation ? 'account.activated' : 'account.password_reset', $user, actor: $user);
            });
        }, attempts: 3);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This password link is unavailable or expired. Request a new link.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Your password has been saved. You can now sign in.');
    }

    public function profile()
    {
        return view('auth.profile');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->update($data);

        return back()->with('success', 'Profile updated.');
    }

    public function changePassword(Request $request, AccountService $accounts, AuditService $audit)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'confirmed', 'max:1024', 'different:current_password', PasswordRule::min(15)],
        ]);
        DB::transaction(function () use ($request, $data, $accounts, $audit): void {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $user->canAccessPlatform() || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
            }
            $user->forceFill(['password' => Hash::make($data['password']), 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            Password::deleteToken($user);
            $accounts->cancelPending($user);
            app(AdminDeviceService::class)->revokeAll($user);
            $audit->record($request, 'account.password_changed', $user);
        }, attempts: 3);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Password changed. Sign in again with your new password. Other sessions will require sign-in again.');
    }
}
