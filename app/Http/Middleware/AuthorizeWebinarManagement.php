<?php

namespace App\Http\Middleware;

use App\Models\Certificate;
use App\Models\Webinar;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeWebinarManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        $webinar = $request->route('webinar');
        if ($webinar !== null && ! $webinar instanceof Webinar) {
            $webinar = Webinar::query()->findOrFail($webinar);
        }
        if (! $webinar && ($certificate = $request->route('certificate'))) {
            $certificate = $certificate instanceof Certificate ? $certificate : Certificate::query()->findOrFail($certificate);
            $webinar = $certificate->webinar;
            abort_unless($webinar, 404);
        }
        if (! $webinar) {
            return $next($request);
        }
        // Run before any password prompt, controller, download or export.
        abort_unless(Gate::allows('manage', $webinar), 404);
        if ($request->user()->isAdministrator()) {
            if ((int) $webinar->created_by !== (int) $request->user()->id || $webinar->management_password) {
                $grant = $request->session()->get('administrative_webinars.'.$webinar->id);
                if (! is_int($grant) || $grant < now()->timestamp) {
                    if (! $request->isMethod('GET')) {
                        abort(403, 'Open this webinar through administrator access first.');
                    }

                    return redirect()->route('admin.webinars.administrative-access', $webinar);
                }
                app(AuditService::class)->record($request, 'administrator.webinar_access', $webinar, [
                    'operation' => $request->route()->getName(), 'method' => $request->method(),
                ]);
            }

            return $next($request);
        }
        if ($webinar->management_password && ! $request->routeIs('admin.webinars.unlock', 'admin.webinars.unlock.store')) {
            $grant = $request->session()->get('webinar_unlocks.'.$webinar->id, []);
            if (($grant['version'] ?? null) !== $webinar->management_lock_version
                || ($grant['until'] ?? 0) < now()->timestamp) {
                if ($request->expectsJson()) {
                    abort(423, 'Unlock this webinar to continue.');
                }

                return redirect()->route('admin.webinars.unlock', $webinar);
            }
        }

        return $next($request);
    }
}
