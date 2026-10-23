<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\PendingSensitiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PasswordConfirmationController extends Controller
{
    public function show(Request $request, PendingSensitiveAction $pending): View|RedirectResponse
    {
        if (! config('security.require_sensitive_action_password_confirmation', true)) {
            $pending->forget($request);

            return redirect()->intended(route('admin.dashboard'));
        }

        return view('auth.confirm-password', [
            'pendingAction' => $pending->pending($request),
        ]);
    }

    public function store(
        Request $request,
        AuditService $audit,
        PendingSensitiveAction $pending,
    ): RedirectResponse|Response {
        if (! config('security.require_sensitive_action_password_confirmation', true)) {
            $pending->forget($request);

            return redirect()->intended(route('admin.dashboard'));
        }
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'max:1024', 'current_password'],
        ]);

        if ($validator->fails()) {
            $audit->record($request, 'administrator.password_confirmation_failed', $request->user());

            throw new ValidationException($validator);
        }

        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $audit->record($request, 'administrator.password_confirmed', $request->user());

        // The action the password confirmation interrupted is completed now,
        // in the administrator's own name. Without this the administrator
        // confirmed the password, returned to the same page, and nothing had
        // happened — which reads as a broken button.
        $replay = $pending->replay($request);
        if ($replay !== null) {
            return $replay;
        }

        return redirect()->intended(route('admin.dashboard'));
    }
}
