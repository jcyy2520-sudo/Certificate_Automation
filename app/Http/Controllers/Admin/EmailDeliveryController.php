<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use App\Models\Webinar;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\View\View;

/**
 * Read-only delivery log over the transactional email outbox. Recipient
 * addresses are masked to the same first-character-plus-domain form used on
 * the participant access screen, so the log stays useful for spotting failures
 * without putting a wall of personal addresses on one page.
 */
class EmailDeliveryController extends Controller
{
    public function index(): View
    {
        return $this->renderLog(
            EmailDelivery::query()->with('webinar:id,title'),
            route('admin.email-logs.index'),
        );
    }

    public function webinar(Webinar $webinar): View
    {
        return $this->renderLog(
            $webinar->emailDeliveries(),
            route('admin.webinars.email-logs.index', $webinar),
            $webinar,
        );
    }

    private function renderLog(Builder $query, string $currentUrl, ?Webinar $webinar = null): View
    {
        $status = request()->string('status')->value();
        if (in_array($status, ['pending', 'processing', 'sent', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $deliveries = $query
            ->with('certificate:id,verification_code')
            ->select(['id', 'public_id', 'webinar_id', 'certificate_id', 'type', 'provider', 'recipient_email', 'subject', 'status', 'attempts', 'provider_message_id', 'last_error', 'scheduled_at', 'sent_at', 'failed_at', 'created_at'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // First delivery per certificate is the original; later rows are manual
        // resends with fresh idempotency keys. Precompute so the view stays N+1-free.
        $certificateIds = $deliveries->getCollection()->pluck('certificate_id')->filter()->unique()->values();
        $originalIds = $certificateIds->isEmpty() ? collect() : EmailDelivery::query()
            ->whereIn('certificate_id', $certificateIds)
            ->groupBy('certificate_id')
            ->selectRaw('certificate_id, min(id) as original_id')
            ->pluck('original_id', 'certificate_id');

        return view('admin.email-log', [
            'webinar' => $webinar,
            'logUrl' => $currentUrl,
            'deliveries' => $deliveries,
            'originalIds' => $originalIds,
        ]);
    }

    /** maría.dela.cruz@example.com → m•••@example.com */
    public static function maskRecipient(?string $email): string
    {
        if (blank($email)) {
            return '—';
        }

        $parts = explode('@', trim($email), 2);

        return ($parts[0][0] ?? '•').'•••@'.($parts[1] ?? 'unknown');
    }
}
