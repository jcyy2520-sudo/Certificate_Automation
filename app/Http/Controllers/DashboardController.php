<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = request()->user();
        $visibleIds = fn () => Webinar::query()->visibleTo($user)->select('id');
        // Four scalar subqueries keep the exact real-time totals while paying for
        // one database round trip instead of four on every dashboard visit.
        $stats = (array) DB::query()
            ->selectSub(Webinar::query()->visibleTo($user)->selectRaw('count(*)')->whereNull('archived_at'), 'webinars')
            ->selectSub(Participant::query()->whereIn('webinar_id', $visibleIds())->selectRaw('count(*)'), 'participants')
            ->selectSub(Certificate::query()->whereIn('webinar_id', $visibleIds())->selectRaw('count(*)')->whereNotNull('issued_at'), 'certificates')
            ->selectSub(EmailDelivery::query()->when(! $user->isAdministrator(), fn ($q) => $q->whereIn('webinar_id', $visibleIds()))->selectRaw('count(*)')->where('status', 'sent'), 'emails')
            ->first();

        return view('admin.dashboard', [
            'stats' => $stats,
            'webinars' => Webinar::query()
                ->visibleTo($user)
                ->select(['id', 'title', 'status'])
                ->withCount(['participants', 'certificates'])
                ->latest('id')
                ->limit(6)
                ->get(),
            // Delivery payloads can contain full HTML messages and base64 PDF
            // attachments. The dashboard only needs these four small fields.
            'deliveries' => EmailDelivery::query()
                ->when(! $user->isAdministrator(), fn ($q) => $q->whereIn('webinar_id', $visibleIds()))
                ->select(['id', 'subject', 'recipient_email', 'status', 'provider', 'attempts'])
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
