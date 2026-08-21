<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IssueCertificateBatch;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    /**
     * The certificate studio: pick eligible participants on the left, review a
     * live in-browser preview of each generated certificate in the centre, and
     * issue to the selection from the right.
     */
    public function studio(Webinar $webinar, EligibilityService $eligibility): View
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first();

        $eligibleIds = $eligibility->eligibleParticipantsQuery($webinar)->pluck('participants.id');

        $participants = $webinar->participants()
            ->whereIn('participants.id', $eligibleIds)
            ->whereNull('privacy_erased_at')
            ->with(['certificates' => fn ($query) => $query
                ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'revoked_at'])])
            ->orderByRaw('full_name is null')
            ->orderBy('full_name')
            ->get(['id', 'public_id', 'webinar_id', 'full_name', 'email', 'organization', 'created_at', 'verified_at']);

        return view('admin.webinars.certificate-studio', [
            'webinar' => $webinar,
            'template' => $template,
            'participants' => $participants,
            'hasBackground' => (bool) $template?->background_path,
            'layout' => $template?->layout ?? [],
        ]);
    }

    /**
     * Issue certificates to an explicit selection of participants made in the
     * studio. Already-issued and no-longer-eligible participants are skipped.
     */
    public function issueSelected(Request $request, Webinar $webinar, CertificateService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:100'],
            'participants.*' => ['string', 'max:64'],
            // Optional per-participant name corrections, keyed by public id, so
            // a typo or a wrong name can be fixed at the moment of issuing.
            'names' => ['array'],
            'names.*' => ['nullable', 'string', 'max:120'],
        ], [
            'participants.required' => 'Select at least one participant to issue certificates to.',
            'participants.max' => 'Issue to at most 100 at a time here; use bulk issuance on the template page for larger runs.',
        ]);

        $participants = $webinar->participants()
            ->whereIn('public_id', $data['participants'])
            ->whereNull('privacy_erased_at')
            ->get();

        $names = $data['names'] ?? [];
        $issued = 0;
        $skipped = 0;

        foreach ($participants as $participant) {
            try {
                $service->issue($participant, null, $names[$participant->public_id] ?? null);
                $issued++;
            } catch (RuntimeException) {
                $skipped++;
            }
        }

        $audit->record($request, 'certificate.issued_selection', $webinar, [
            'requested' => count($data['participants']),
            'issued' => $issued,
            'skipped' => $skipped,
        ]);

        $message = $issued === 1 ? '1 certificate issued and its delivery queued.' : "{$issued} certificates issued and their deliveries queued.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already issued or no longer eligible).";
        }

        return redirect()->route('admin.certificates.studio', $webinar)
            ->with($issued > 0 ? 'success' : 'error', $issued > 0 ? $message : 'Nothing was issued — the selected participants already hold certificates or are no longer eligible.');
    }

    public function store(Request $request, Webinar $webinar, Participant $participant, CertificateService $service, AuditService $audit): RedirectResponse
    {
        abort_unless($participant->webinar_id === $webinar->id, 404);

        $data = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $certificate = $service->issue($participant, null, $data['recipient_name'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record($request, 'certificate.issued', $certificate);

        return back()->with('success', 'Certificate issued and its delivery was queued.');
    }

    /** Queue an issuance run for every eligible participant without a valid certificate. */
    public function batch(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first();

        if (! $template || blank($template->background_path)) {
            return back()->with('error', 'Upload a certificate design before issuing in bulk.');
        }

        $batch = CertificateBatch::query()->create([
            'webinar_id' => $webinar->id,
            'certificate_template_id' => $template->id,
            'created_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        IssueCertificateBatch::dispatch($batch->id);
        $audit->record($request, 'certificate_batch.queued', $batch);

        return back()->with('success', 'Bulk issuance queued. Progress appears below as the queue worker processes it.');
    }

    public function download(Request $request, Certificate $certificate, AuditService $audit): StreamedResponse
    {
        abort_unless($certificate->file_path, 404);
        abort_unless(
            hash_equals('certificates/'.$certificate->public_id.'.pdf', str_replace('\\', '/', $certificate->file_path)),
            404,
        );

        $disk = Storage::disk($certificate->storage_disk);
        abort_unless($disk->exists($certificate->file_path), 404);

        $audit->record($request, 'certificate.downloaded', $certificate);

        return $disk->download(
            $certificate->file_path,
            'certificate-'.$certificate->verification_code.'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function revoke(Request $request, Certificate $certificate, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $certificate->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $data['reason'],
            'issuance_key' => null,
        ]);
        $audit->record($request, 'certificate.revoked', $certificate, [
            'reason_fingerprint' => $audit->fingerprint($data['reason'], 'certificate-revocation-reason'),
        ]);

        return back()->with('success', 'Certificate revoked.');
    }
}
