<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebinarLockController extends Controller
{
    public function update(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $request->validate(['locked' => ['required', 'boolean']]);
        $locked = $request->boolean('locked');

        DB::transaction(function () use ($request, $webinar, $audit, $locked): void {
            $current = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            if ($current->isAdministratorLocked() === $locked) {
                return;
            }
            $current->forceFill([
                'admin_locked_at' => $locked ? now() : null,
                'admin_locked_by' => $locked ? $request->user()->id : null,
            ])->save();
            $audit->record($request, $locked ? 'webinar.admin_locked' : 'webinar.admin_unlocked', $current, ['locked' => $locked]);
        }, attempts: 3);

        $message = $locked
            ? 'Webinar locked. Only administrators can manage it. Participant access and saved data are unchanged.'
            : 'Webinar unlocked. Existing management permissions are restored.';

        return $request->expectsJson()
            ? response()->json(['locked' => $locked, 'message' => $message])
            : redirect()->route('admin.webinars.index')->with('success', $message);
    }
}
