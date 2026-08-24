<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IssueCertificateBatch;
use App\Jobs\IssueSelectedCertificate;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateFileService;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    public function studio(Request $request, Webinar $webinar, EligibilityService $eligibility): View
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first();

        $eligibleIds = $eligibility->eligibleParticipantsQuery($webinar)->pluck('participants.id');

        $requestedIds = collect($request->query('participants', []))
            ->filter(fn ($id) => is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id))
            ->unique()->take(200)->values();
        $participants = $webinar->participants()
            ->whereIn('participants.id', $eligibleIds)
            ->when($requestedIds->isNotEmpty(), fn ($query) => $query->whereIn('public_id', $requestedIds))
            ->whereNull('privacy_erased_at')
            ->with(['certificates' => fn ($query) => $query
                ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'sent_at', 'revoked_at', 'status', 'layout'])])
            ->orderByRaw('full_name is null')
            ->orderBy('full_name')
            ->get(['id', 'public_id', 'webinar_id', 'full_name', 'email', 'organization', 'created_at', 'verified_at']);

        // Map each eligible participant to one of the four plain statuses the
        // admin sees: ready (no certificate yet), sending (issued, email in
        // flight), sent (email delivered), or failed (delivery failed).
        $held = fn ($participant) => $participant->certificates
            ->sortByDesc('id')
            ->first(fn ($c) => ! $c->revoked_at && in_array($c->status, ['processing', 'issued', 'failed'], true));
        $deliveryMeta = $this->deliveryMetaByCertificate(
            $participants->map($held)->filter()->pluck('id'),
        );
        foreach ($participants as $participant) {
            $certificate = $held($participant);
            $participant->cert_status = match (true) {
                $certificate?->status === 'processing' => 'queued',
                $certificate?->status === 'failed' => 'failed',
                $certificate !== null => $deliveryMeta[$certificate->id]['status'] ?? ($certificate->sent_at ? 'sent' : 'queued'),
                blank($participant->email) => 'missing_email',
                default => 'ready',
            };
            $participant->certificate_record = $certificate;
            $participant->delivery_meta = $certificate ? ($deliveryMeta[$certificate->id] ?? []) : [];
            $participant->cert_status_detail = match (true) {
                $certificate?->status === 'processing' => 'Waiting for the certificate worker',
                $certificate?->status === 'failed' => 'Certificate generation failed',
                ($deliveryMeta[$certificate?->id]['status'] ?? null) === 'queued' => 'Certificate ready · waiting for the email worker',
                ($deliveryMeta[$certificate?->id]['status'] ?? null) === 'sending' => 'Sending to the email provider now',
                ($deliveryMeta[$certificate?->id]['status'] ?? null) === 'sent' => 'Accepted by the email provider',
                ($deliveryMeta[$certificate?->id]['status'] ?? null) === 'failed' => 'Email provider rejected the delivery',
                blank($participant->email) => 'Add an email address before sending',
                default => 'Ready to generate and email',
            };
        }

        // The "sent history" list: every certificate issued for this webinar,
        // newest first, with its recipient and current delivery status.
        $history = $webinar->certificates()
            ->whereNotNull('issued_at')
            ->with('participant:id,full_name,email')
            ->latest('issued_at')
            ->limit(25)
            ->get(['id', 'participant_id', 'webinar_id', 'recipient_name', 'verification_code', 'issued_at', 'revoked_at']);
        $historyStatus = $this->deliveryStatusByCertificate($history->pluck('id'));

        // If certificate emails have been waiting to go out for a while, the
        // background email sender is probably not running — warn the admin so
        // certificates don't sit silently in "Sending".
        $generationStalled = $webinar->certificates()
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->exists();
        $emailStalled = $webinar->certificates()
            ->whereHas('deliveries', fn ($query) => $query
                ->whereNull('sent_at')
                ->whereNull('failed_at')
                ->where('status', '!=', 'cancelled')
                ->where('scheduled_at', '<', now()->subMinutes(15)))
            ->exists();
        $emailProviderReady = config('webinar.email.provider') !== 'log'
            && (config('webinar.email.provider') !== 'brevo' || filled(config('services.brevo.key')));
        $pipelineWarning = match (true) {
            ! $emailProviderReady => 'Email delivery is in test mode. Certificates can be generated, but no message will leave the application until a transactional email provider is configured.',
            $generationStalled && $emailStalled => 'Certificate generation and email delivery are both waiting. Start the default queue worker and the emails queue worker.',
            $generationStalled => 'Certificate generation has been queued for more than 10 minutes. Start or restart the default queue worker.',
            $emailStalled => 'Generated certificates have been waiting for email delivery for more than 15 minutes. Start or restart the emails queue worker.',
            default => null,
        };

        return view('admin.webinars.certificate-studio', [
            'webinar' => $webinar,
            'template' => $template,
            'participants' => $participants,
            'hasBackground' => (bool) $template?->background_path,
            'layout' => $template?->layout ?? [],
            'fonts' => CertificateTemplate::FONTS,
            'history' => $history,
            'historyStatus' => $historyStatus,
            'pipelineWarning' => $pipelineWarning,
            'selectionScoped' => $requestedIds->isNotEmpty(),
            'backUrl' => route('admin.participants.index', $webinar),
            'statusUrl' => route('admin.certificates.studio.status', $webinar),
        ]);
    }

    /** Lightweight polling payload for the studio's live delivery tracker. */
    public function status(Webinar $webinar): JsonResponse
    {
        $participants = $webinar->participants()
            ->whereNull('privacy_erased_at')
            ->with(['certificates' => fn ($query) => $query
                ->whereNull('revoked_at')->whereIn('status', ['processing', 'issued', 'failed'])
                ->select(['id', 'participant_id', 'verification_code', 'status', 'issued_at', 'sent_at'])])
            ->get(['id', 'public_id', 'webinar_id']);
        $certificates = $participants->pluck('certificates')->flatten(1);
        $deliveryMeta = $this->deliveryMetaByCertificate($certificates->pluck('id'));

        return response()->json([
            'certificates' => $participants->map(function ($participant) use ($deliveryMeta) {
                $certificate = $participant->certificates->sortByDesc('id')->first();
                $meta = $certificate ? ($deliveryMeta[$certificate->id] ?? []) : [];

                $status = match (true) {
                    $certificate?->status === 'processing' => 'queued',
                    $certificate?->status === 'failed' => 'failed',
                    $certificate !== null => $meta['status'] ?? ($certificate->sent_at ? 'sent' : 'queued'),
                    default => 'ready',
                };

                return [
                    'participant_id' => $participant->public_id,
                    'certificate_number' => $certificate?->verification_code,
                    'status' => $status,
                    'status_label' => match ($status) {
                        'queued' => $certificate?->status === 'processing' ? 'Queued for generation' : 'Queued for email',
                        'sending' => 'Sending email',
                        'sent' => 'Accepted by provider',
                        'failed' => 'Delivery failed',
                        default => 'Ready to send',
                    },
                    'status_detail' => match ($status) {
                        'queued' => $certificate?->status === 'processing' ? 'Waiting for the certificate worker' : 'Certificate ready · waiting for the email worker',
                        'sending' => 'Sending to the email provider now',
                        'sent' => 'Accepted by the email provider',
                        'failed' => $meta['failed_reason'] ?? 'Certificate generation or email delivery failed',
                        default => 'Ready to generate and email',
                    },
                    'failed_reason' => $meta['failed_reason'] ?? null,
                    'retry_count' => $meta['retry_count'] ?? 0,
                    'failed_at' => $meta['failed_at'] ?? null,
                ];
            })->values(),
        ]);
    }

    /**
     * Send a certificate's email again — used to recover a delivery that failed.
     * The certificate already exists; this re-queues its stored PDF for delivery.
     */
    public function resend(
        Request $request,
        Certificate $certificate,
        NotificationService $notifications,
        AuditService $audit,
    ): RedirectResponse {
        $certificate->loadMissing(['participant', 'webinar']);

        abort_unless($certificate->issued_at !== null && $certificate->revoked_at === null, 422, 'This certificate cannot be resent.');
        $participant = $certificate->participant;
        abort_if($participant === null || blank($participant->email), 422, 'There is no email address on file for this participant.');
        abort_if($certificate->webinar?->deletion_started_at !== null, 409);

        $disk = Storage::disk((string) $certificate->storage_disk);
        abort_unless($certificate->file_path && $disk->exists($certificate->file_path), 404, 'The stored certificate file is missing.');
        $statusForm = $certificate->webinar->forms()->where('type', 'registration')->first(['public_token']);

        $notifications->queue(
            $certificate->webinar,
            $participant,
            'certificate',
            (string) $participant->email,
            'Your certificate for '.$certificate->webinar->title,
            view('emails.certificate-issued', [
                'participant' => $participant,
                'certificate' => $certificate,
                'verificationUrl' => route('certificates.verify', $certificate->verification_code),
                'statusUrl' => $statusForm
                    ? route('forms.public.status', $statusForm->public_token)
                    : null,
            ])->render(),
            $certificate,
            [['name' => 'certificate.pdf', 'content' => base64_encode($disk->get($certificate->file_path))]],
            $certificate->webinar->retention_due_at,
        );

        $audit->record($request, 'certificate.resent', $certificate);

        return back()->with('success', 'The certificate email was queued again. Watch its delivery status here.');
    }

    /**
     * Latest email-delivery status per certificate id, collapsed to the plain
     * words the admin sees. Certificates with no delivery row (recipient had no
     * email) are simply absent, and the caller treats those as already "sent".
     *
     * @param  Collection<int, int>  $certificateIds
     * @return array<int, string>
     */
    private function deliveryStatusByCertificate($certificateIds): array
    {
        return collect($this->deliveryMetaByCertificate($certificateIds))
            ->map(fn ($meta) => $meta['status'])->all();
    }

    /** @return array<int, array{status:string,failed_reason:?string,retry_count:int,failed_at:?string}> */
    private function deliveryMetaByCertificate($certificateIds): array
    {
        if ($certificateIds->isEmpty()) {
            return [];
        }

        return EmailDelivery::query()
            ->whereIn('certificate_id', $certificateIds)
            ->orderBy('id')
            ->get(['certificate_id', 'status', 'last_error', 'attempts', 'failed_at'])
            ->mapWithKeys(fn ($delivery) => [$delivery->certificate_id => [
                'status' => match ($delivery->status) {
                    'sent' => 'sent',
                    'failed', 'cancelled' => 'failed',
                    'processing' => 'sending',
                    default => 'queued',
                },
                'failed_reason' => $delivery->last_error,
                'retry_count' => (int) $delivery->attempts,
                'failed_at' => $delivery->failed_at?->toIso8601String(),
            ]])->all();
    }

    /**
     * Issue certificates to an explicit selection of participants made in the
     * studio. Already-issued and no-longer-eligible participants are skipped.
     */
    public function issueSelected(Request $request, Webinar $webinar, CertificateService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:200'],
            'participants.*' => ['string', 'max:64'],
            'preview_confirmed' => ['required', 'accepted'],
            'designs_json' => ['nullable', 'string', 'max:200000'],
        ], [
            'participants.required' => 'Select at least one participant to send a certificate to.',
            'participants.max' => 'Send to up to 200 at once here. For a larger group, use “Send to all ready”.',
        ]);

        $participants = $webinar->participants()
            ->whereIn('public_id', $data['participants'])
            ->whereNull('privacy_erased_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        $submittedDesigns = json_decode($data['designs_json'] ?? '{}', true);
        $submittedDesigns = is_array($submittedDesigns) ? $submittedDesigns : [];
        $issued = 0;
        $skipped = count($data['participants']) - $participants->count();

        foreach ($participants as $participant) {
            try {
                $design = $this->normalizeSubmittedDesign($submittedDesigns[$participant->public_id] ?? null);
                $certificate = $service->queue($participant, $design);
                if ($certificate->status === 'issued') {
                    $skipped++;

                    continue;
                }
                IssueSelectedCertificate::dispatch($participant->id);
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

        $message = $issued === 1
            ? '1 certificate was queued for generation. Delivery status will update here.'
            : "{$issued} certificates were queued for generation. Delivery status will update here.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already sent or no longer eligible).";
        }

        return redirect()->route('admin.certificates.studio', $webinar)
            ->with($issued > 0 ? 'success' : 'error', $issued > 0 ? $message : 'Nothing was sent — the selected participants were already sent a certificate or are no longer eligible.');
    }

    /** Keep client-authored visual settings inside the same bounds as the template editor. */
    private function normalizeSubmittedDesign(mixed $design): ?array
    {
        if (! is_array($design)) {
            return null;
        }
        $family = (string) ($design['name_font_family'] ?? '');
        $color = (string) ($design['accent'] ?? '');
        if (! isset(CertificateTemplate::FONTS[$family]) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return [
            'name_top' => round(max(0, min(100, (float) ($design['name_top'] ?? 62))), 2),
            'name_left' => round(max(0, min(100, (float) ($design['name_left'] ?? 50))), 2),
            'name_font_size' => round(max(12, min(160, (float) ($design['name_font_size'] ?? 42))), 1),
            'name_font_family' => $family,
            'accent' => strtolower($color),
            'name_font_weight' => ($design['name_font_weight'] ?? 'bold') === 'regular' ? 'regular' : 'bold',
            'name_font_style' => ($design['name_font_style'] ?? 'regular') === 'italic' ? 'italic' : 'regular',
            'name_text_align' => in_array($design['name_text_align'] ?? 'center', ['left', 'center', 'right'], true) ? ($design['name_text_align'] ?? 'center') : 'center',
        ];
    }

    public function store(Request $request, Webinar $webinar, Participant $participant, CertificateService $service, AuditService $audit): RedirectResponse
    {
        abort_unless($participant->webinar_id === $webinar->id, 404);

        try {
            $certificate = $service->issue($participant);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record($request, 'certificate.issued', $certificate);

        return back()->with('success', 'Certificate issued. It is being sent to the participant.');
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

    public function download(
        Request $request,
        Certificate $certificate,
        AuditService $audit,
        CertificateFileService $certificateFiles,
    ): StreamedResponse {
        $response = $certificateFiles->download($certificate);
        $audit->record($request, 'certificate.downloaded', $certificate);

        return $response;
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
