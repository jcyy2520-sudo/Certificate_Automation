<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return $this->renderTrail($request);
    }

    public function webinar(Request $request, Webinar $webinar)
    {
        abort_unless($request->user()->isAdministrator() || $request->user()->role === User::MANAGER_ROLE, 403);
        Gate::authorize('manage', $webinar);
        abort_if($request->filled('webinar') && (string) $request->input('webinar') !== (string) $webinar->id, 404);

        return $this->renderTrail($request, $webinar);
    }

    private function renderTrail(Request $request, ?Webinar $webinar = null)
    {
        $data = $request->validate([
            'actor' => ['nullable', 'integer', 'min:1'], 'action' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]*$/'],
            'webinar' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outcome' => ['nullable', 'in:success,failure'],
        ]);
        $logs = AuditLog::query()->with('user:id,name')
            ->when($webinar, fn ($q) => $q->where('webinar_id', $webinar->id))
            ->when($data['actor'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('actor_user_id', $id)->orWhere(fn ($q) => $q->whereNull('actor_user_id')->where('user_id', $id))))
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', '%'.$action.'%'))
            ->when($data['webinar'] ?? null, fn ($q, $id) => $q->where('webinar_id', $id))
            ->when($data['outcome'] ?? null, fn ($q, $outcome) => $q->where('outcome', $outcome))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->where('created_at', '<', CarbonImmutable::parse($date, 'Asia/Manila')->addDay()->startOfDay()->utc()))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs, 'filters' => $data, 'webinar' => $webinar,
            'trailUrl' => $webinar ? route('admin.webinars.audit', $webinar) : route('admin.audit.index'),
            'actors' => User::query()->when($webinar, fn ($q) => $q->whereIn('id', AuditLog::query()
                ->where('webinar_id', $webinar->id)->selectRaw('coalesce(actor_user_id, user_id)')))->orderBy('name')->get(['id', 'name']),
            'webinars' => $webinar ? collect([$webinar]) : Webinar::query()->orderBy('title')->get(['id', 'title']),
        ]);
    }
}
