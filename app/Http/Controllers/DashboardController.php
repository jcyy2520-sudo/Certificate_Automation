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
        // Four scalar subqueries keep the exact real-time totals while paying for
        // one database round trip instead of four on every dashboard visit.
        $stats = (array) DB::query()
            ->selectSub(Webinar::query()->selectRaw('count(*)')->whereNull('archived_at'), 'webinars')
            ->selectSub(Participant::query()->selectRaw('count(*)'), 'participants')
            ->selectSub(Certificate::query()->selectRaw('count(*)')->whereNotNull('issued_at'), 'certificates')
            ->selectSub(EmailDelivery::query()->selectRaw('count(*)')->where('status', 'sent'), 'emails')
            ->first();

        return view('admin.dashboard', [
            'stats' => $stats,
            'webinars' => Webinar::query()
                ->select(['id', 'title', 'status'])
                ->withCount(['participants', 'certificates'])
                ->latest('id')
                ->limit(6)
                ->get(),
            // Delivery payloads can contain full HTML messages and base64 PDF
            // attachments. The dashboard only needs these four small fields.
            'deliveries' => EmailDelivery::query()
                ->select(['id', 'subject', 'recipient_email', 'status'])
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
