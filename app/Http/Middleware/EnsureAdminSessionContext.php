<?php

namespace App\Http\Middleware;

use App\Services\AdminDeviceService;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAdminSessionContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (config('security.require_admin_two_factor') && $user?->isAdministrator()
            && $user->two_factor_confirmed_at && ! $request->routeIs('admin.logout')
            && ! app(AdminDeviceService::class)->sessionMatches($request, $user)) {
            app(AdminDeviceService::class)->revokeAll($user);
            app(AuditService::class)->record($request, 'administrator.context_changed', $user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('warning', 'Please sign in again with your password and authenticator. Your sign-in context changed or needs verification.');
        }

        return $next($request);
    }
}
