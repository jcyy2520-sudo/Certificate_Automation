<?php

namespace App\Http\Controllers;

use App\Models\Webinar;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WebinarAccessController extends Controller
{
    public function unlock(Webinar $webinar)
    {
        return view('auth.webinar-unlock', compact('webinar'));
    }

    public function store(Request $request, Webinar $webinar)
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);
        if (! $webinar->management_password || ! Hash::check($data['password'], $webinar->management_password)) {
            return back()->withErrors(['password' => 'The webinar password is incorrect.']);
        }
        $request->session()->put('webinar_unlocks.'.$webinar->id, [
            'version' => $webinar->management_lock_version,
            'until' => now()->addMinutes(max(5, config('accounts.unlock_minutes')))->timestamp,
        ]);

        return redirect()->route('admin.webinars.show', $webinar);
    }

    public function protection(Request $request, Webinar $webinar, AuditService $audit)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'password' => ['exclude_unless:enabled,1', 'required', 'string', 'min:15', 'max:1024', 'confirmed'],
        ]);
        DB::transaction(function () use ($request, $webinar, $data, $audit): void {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'management_password' => $request->boolean('enabled') ? Hash::make($data['password']) : null,
                'management_lock_version' => (string) Str::uuid(),
            ])->save();
            $audit->record($request, 'webinar.protection_changed', $locked, ['enabled' => $request->boolean('enabled')]);
        });
        $request->session()->forget('webinar_unlocks.'.$webinar->id);

        return redirect()->route('admin.webinars.show', $webinar)->with('success', 'Webinar password protection updated. Previous unlocks have expired.');
    }

    public function administrative(Webinar $webinar)
    {
        return view('auth.administrative-access', compact('webinar'));
    }

    public function authorizeAccess(Request $request, Webinar $webinar, AuditService $audit)
    {
        $request->validate(['confirmed' => ['accepted']]);
        $request->session()->put('administrative_webinars.'.$webinar->id, now()->addMinutes(60)->timestamp);
        $audit->record($request, 'administrator.webinar_authorized', $webinar);

        return redirect()->route('admin.webinars.show', $webinar);
    }
}
