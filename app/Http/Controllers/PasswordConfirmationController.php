<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordConfirmationController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:1024', 'current_password'],
        ]);

        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $audit->record($request, 'administrator.password_confirmed', $request->user());

        return redirect()->intended(route('admin.dashboard'));
    }
}
