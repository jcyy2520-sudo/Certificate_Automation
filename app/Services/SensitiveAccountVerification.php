<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SensitiveAccountVerification
{
    /** Caller holds the user row lock through the sensitive operation. */
    public function verify(Request $request, User $user): void
    {
        $request->validate([
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/', 'required_without:recovery_code', 'prohibits:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{5}-[A-Za-z0-9]{5}$/', 'required_without:code', 'prohibits:code'],
        ]);
        if (! Hash::check($request->string('password')->toString(), $user->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'The current password is incorrect.']);
        }
        $factors = app(TwoFactorService::class);
        $valid = $request->filled('code')
            ? $factors->consumeTotp($user, $request->string('code')->toString())
            : $factors->consumeRecoveryCode($user, $request->string('recovery_code')->toString());
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'Enter a current authenticator code or an unused recovery code. Wait for a new code if you just used one.']);
        }
        if ($request->filled('recovery_code')) {
            app(AdminDeviceService::class)->revokeAll($user);
        }
    }
}
