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

        return view('admin.email-log', [
            'webinar' => $webinar,
            'logUrl' => $currentUrl,
            'deliveries' => $query
                ->select(['id', 'webinar_id', 'type', 'recipient_email', 'subject', 'status', 'attempts', 'provider_message_id', 'last_error', 'scheduled_at', 'sent_at', 'failed_at', 'created_at'])
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
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
