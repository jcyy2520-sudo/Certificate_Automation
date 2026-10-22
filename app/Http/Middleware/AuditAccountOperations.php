<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuditAccountOperations
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->failure($request, method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500));
            throw $exception;
        }
        if (! $request->isMethodSafe() && ! $request->attributes->get('audit_recorded')) {
            $failed = $response->getStatusCode() >= 400 || $request->session()->get('errors')?->any();
            app(AuditService::class)->record($request, $failed ? 'operation.failed' : 'operation.completed',
                metadata: ['operation' => $request->route()?->getName(), 'http_status' => $response->getStatusCode()]);
        }

        return $response;
    }

    private function failure(Request $request, int $status): void
    {
        // No request bodies, validation values, credentials or exception text.
        app(AuditService::class)->record($request, 'operation.failed',
            metadata: ['operation' => $request->route()?->getName(), 'http_status' => $status], outcome: 'failure');
    }
}
