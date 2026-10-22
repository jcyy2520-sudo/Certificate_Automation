<?php

namespace App\Http\Middleware;

use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
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
            if ($request->routeIs('admin.webinars.create', 'admin.webinars.store')) {
                abort_unless($request->user()->isAdministrator() || ($request->user()->role === User::USER_ROLE && $request->user()->account_source !== 'admin_invitation'), 403);
            }

            return $next($request);
        }
        // Run before any password prompt, controller, download or export.
        abort_unless(Gate::allows('manage', $webinar), 404);
        if (! $request->user()->isAdministrator()
            && ! ($request->user()->role === User::USER_ROLE && $request->user()->account_source !== 'admin_invitation' && (int) $webinar->created_by === (int) $request->user()->id)) {
            $policy = app(WebinarPolicy::class);
            // Membership grants viewing only. Explicit flags gate every mutation
            // and sensitive participant/certificate screen, export and download.
            $permission = match (true) {
                $request->routeIs('admin.webinars.messages.*', 'admin.webinars.email-logs.*', 'admin.certification.email-test', 'admin.certificates.resend') => 'send_emails',
                $request->routeIs('admin.certification.*', 'admin.certificates.*', 'admin.webinars.certificates.*') => 'manage_certificates',
                $request->routeIs('admin.participants.*', 'admin.responses.*', 'admin.forms.*', 'admin.webinars.imports.*', 'admin.webinars.import-issues.*', 'admin.webinars.reports', 'admin.webinars.documents.*', 'admin.webinars.eligibility.*') => 'manage_participants',
                default => null,
            };
            if ($permission) {
                abort_unless($policy->permission($request->user(), $webinar, $permission), 403);
            } elseif (! $request->isMethodSafe() || $request->routeIs('admin.webinars.edit')) {
                abort_unless($request->routeIs('admin.webinars.unlock.store'), 403);
            }
            if ($request->routeIs('admin.certificates.issue-selected', 'admin.certificates.store', 'admin.certificates.batch', 'admin.certificates.resend', 'admin.certification.email-test')) {
                abort_unless($policy->permission($request->user(), $webinar, 'manage_certificates'), 403);
                abort_unless($policy->permission($request->user(), $webinar, 'send_emails'), 403);
            }
            if ($request->routeIs('admin.webinars.eligibility.notify')) {
                abort_unless($policy->permission($request->user(), $webinar, 'send_emails'), 403);
            }
        }
        if ($webinar->isUploadOnly() && $request->routeIs('admin.forms.*', 'admin.certification.*', 'admin.certificates.*', 'admin.participants.*', 'admin.responses.*', 'admin.webinars.imports.*', 'admin.webinars.import-issues.*', 'admin.webinars.certificates.*', 'admin.webinars.eligibility.*')) {
            abort(404);
        }
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
