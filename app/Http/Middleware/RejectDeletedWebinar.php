<?php

namespace App\Http\Middleware;

use App\Models\Certificate;
use App\Models\Webinar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectDeletedWebinar
{
    /**
     * Prevent operations on webinars that are currently undergoing permanent deletion.
     *
     * Once deletion_started_at is set, the webinar is tombstoned: responses, files,
     * and database rows are in the process of irreversible erasure. All child mutations,
     * reviews, and workspace views must fail closed with HTTP 409 Conflict, except for
     * the deletion route itself (admin.webinars.destroy) which orchestrates the cleanup.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('admin.webinars.destroy')) {
            return $next($request);
        }

        $webinar = $request->route('webinar');
        if (is_numeric($webinar) || is_string($webinar)) {
            $webinar = Webinar::query()->find($webinar);
        }

        if (! $webinar instanceof Webinar && $request->route('certificate')) {
            $certificate = $request->route('certificate');
            if (is_numeric($certificate) || is_string($certificate)) {
                $certificate = Certificate::query()->find($certificate);
            }

            if ($certificate instanceof Certificate) {
                $webinar = $certificate->webinar;
            }
        }

        if ($webinar instanceof Webinar && $webinar->deletion_started_at !== null) {
            abort(409, 'This webinar is being permanently deleted.');
        }

        return $next($request);
    }
}
