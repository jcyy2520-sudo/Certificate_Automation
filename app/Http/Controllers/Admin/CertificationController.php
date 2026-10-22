<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateArtwork;
use App\Services\CertificateService;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CertificationController extends Controller
{
    /** Requirements that can gate certificate issuance, in journey order. */
    public const REQUIREMENTS = ['registration', 'attendance', 'pretest', 'posttest', 'evaluation'];

    public function edit(Webinar $webinar): View
    {
        return view('admin.webinars.certification', [
            'webinar' => $webinar,
            'template' => $this->activeTemplate($webinar),
            'fonts' => CertificateTemplate::FONTS,
            'emailSender' => $this->emailSender(),
            'issuedCount' => $webinar->certificates()->whereNotNull('issued_at')->whereNull('revoked_at')->count(),
            'pendingCount' => $webinar->participants()
                ->whereNull('privacy_erased_at')
                ->whereDoesntHave('certificates', fn ($query) => $query->whereNull('revoked_at')->whereNotNull('issued_at'))
                ->count(),
        ]);
    }

    public function updateRules(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'rules' => ['array'],
            'rules.*.enabled' => ['nullable', 'boolean'],
            'rules.*.minimum_score' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        DB::transaction(function () use ($data, $webinar): void {
            Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
            foreach (self::REQUIREMENTS as $requirement) {
                $rule = $data['rules'][$requirement] ?? [];

                if (empty($rule['enabled'])) {
                    $webinar->eligibilityRules()->where('requirement', $requirement)->delete();

                    continue;
                }

                EligibilityRule::query()->updateOrCreate(
                    ['webinar_id' => $webinar->id, 'requirement' => $requirement],
                    [
                        'is_required' => true,
                        'minimum_score' => in_array($requirement, ['registration', 'attendance'], true)
                            ? null
                            : ($rule['minimum_score'] ?? null),
                    ],
                );
            }
        });

        $enabled = array_keys(array_filter($data['rules'] ?? [], fn ($rule) => ! empty($rule['enabled'])));
        $audit->record($request, 'eligibility_rules.updated', $webinar, ['requirements' => $enabled]);
        $destination = route('admin.responses.index', [$webinar, 'view' => 'settings']);

        if ($enabled === []) {
            return redirect()
                ->to($destination)
                ->with('warning', 'All requirements are disabled: every participant with an email is now automatically eligible. Re-enable the intended requirements before sending certificates.');
        }

        $affected = $webinar->participants()->whereNull('privacy_erased_at')->count();

        return redirect()
            ->to($destination)
            ->with('success', 'Certificate requirements saved ('.implode(', ', $enabled).'). '.$affected.' participant(s) will be re-evaluated against the new rule.');
    }

    public function updateTemplate(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        $data = $request->validate([
            // The finished certificate is uploaded whole; the system only places
            // the name on it. A one-page PDF is converted to PNG in the browser,
            // so the private server-side storage/rendering pipeline continues to
            // accept only verified raster images. An upload is required unless
            // one is already stored. Name styling is configured in the editor.
            'background' => [$template->background_path ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
        ], [
            'background.required' => 'Upload the finished certificate artwork (PNG, JPG, or a single-page PDF).',
            'background.image' => 'The server expected a converted PNG or JPG. Wait for PDF preparation to finish and try again.',
            'background.mimes' => 'The server expected a converted PNG or JPG. Wait for PDF preparation to finish and try again.',
        ]);

        $disk = Storage::disk($template->storage_disk);
        $backgroundPath = $template->background_path;
        $oldBackgroundPath = $backgroundPath;
        $layout = $template->layout ?? [];

        if ($request->hasFile('background')) {
            $file = $request->file('background');
            try {
                $dimensions = CertificateArtwork::inspect(file_get_contents($file->getRealPath()));
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages(['background' => $exception->getMessage()]);
            }
            $newBackgroundPath = $file->storeAs(
                'certificate-backgrounds',
                $template->id.'-'.now()->timestamp.'.'.$file->extension(),
                ['disk' => $template->storage_disk],
            );
            if (! $newBackgroundPath) {
                return back()->withInput()->withErrors(['background' => 'The certificate artwork could not be stored. The existing design was left unchanged.']);
            }
            $backgroundPath = $newBackgroundPath;

            // Remember the design's real pixel size so the preview and the issued
            // PDF are both rendered at its exact aspect ratio — never distorted.
            $layout['bg_w'] = $dimensions['width'];
            $layout['bg_h'] = $dimensions['height'];
        }

        try {
            $template->fill([
                'name' => mb_substr($template->automaticName(), 0, 255),
                'background_path' => $backgroundPath,
                // Preserve the name placement/font/colour set in the editor.
                'layout' => $layout,
            ])->save();
        } catch (\Throwable $exception) {
            if (isset($newBackgroundPath)) {
                $disk->delete($newBackgroundPath);
            }

            throw $exception;
        }

        // Only remove the previous private artwork after the replacement is
        // durable. A storage outage can no longer turn an otherwise valid
        // template into a template with no background at all.
        if (isset($newBackgroundPath) && $oldBackgroundPath && $oldBackgroundPath !== $backgroundPath) {
            $disk->delete($oldBackgroundPath);
        }

        $audit->record($request, 'certificate_template.updated', $template);

        return redirect()
            ->to(route('admin.certification.edit', $webinar).'#certificate-artwork')
            ->with('success', 'Certificate design saved. New certificates use it immediately.');
    }

    /**
     * Save the visual name placement made in the certificate editor: position,
     * font, size, and colour. This is cosmetic (it never changes the uploaded
     * image or who qualifies), so it stays out of the recent-password gate to
     * keep the editor fluid; it is still audited.
     */
    public function updateDesign(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        $data = $request->validate([
            'name_top' => ['required', 'numeric', 'min:0', 'max:100'],
            'name_left' => ['required', 'numeric', 'min:0', 'max:100'],
            'name_font_size' => ['required', 'numeric', 'min:12', 'max:160'],
            'name_font_family' => ['required', Rule::in(array_keys(CertificateTemplate::FONTS))],
            'accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'name_font_weight' => ['nullable', Rule::in(['regular', 'bold'])],
            'name_font_style' => ['nullable', Rule::in(['regular', 'italic'])],
            'name_text_align' => ['nullable', Rule::in(['left', 'center', 'right'])],
        ]);

        $template->fill([
            'name' => mb_substr($template->automaticName(), 0, 255),
            'layout' => array_merge($template->layout ?? [], [
                'name_top' => round((float) $data['name_top'], 2),
                'name_left' => round((float) $data['name_left'], 2),
                'name_font_size' => round((float) $data['name_font_size'], 1),
                'name_font_family' => $data['name_font_family'],
                'accent' => Str::lower($data['accent']),
                'name_font_weight' => $data['name_font_weight'] ?? 'bold',
                'name_font_style' => $data['name_font_style'] ?? 'regular',
                'name_text_align' => $data['name_text_align'] ?? 'center',
            ]),
        ])->save();

        $audit->record($request, 'certificate_template.design_updated', $template);

        // Do not use back() here. The protected background <img> request can
        // become Laravel's session previous URL after the settings page loads,
        // which would redirect the organizer into the browser's raw-image
        // viewer with no application navigation after saving. The studio's
        // "use for every recipient" action asks to come back to the studio.
        return redirect()
            ->to($request->boolean('studio')
                ? route('admin.certificates.studio', $webinar)
                : route('admin.certification.edit', $webinar).'#participant-name-style')
            ->with('success', 'Certificate design updated. New certificates use it immediately.');
    }

    /**
     * Render the uploaded design with a sample (or typed) name so the placement
     * can be checked before issuing. Participant names are resolved server-side;
     * ad hoc names travel only in a CSRF-protected POST body.
     */
    public function preview(Request $request, Webinar $webinar, CertificateService $service): Response|RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        if (blank($template->background_path)) {
            return redirect()
                ->route('admin.certification.edit', $webinar)
                ->with('error', 'Upload a certificate design first, then preview it.');
        }

        $request->validate(['participant' => ['nullable', 'string', 'max:64']]);
        $name = '';
        if ($request->filled('participant')) {
            $participant = $webinar->participants()->where('public_id', $request->input('participant'))->whereNull('privacy_erased_at')->firstOrFail();
            $name = (string) $participant->full_name;
        } elseif ($request->isMethod('post')) {
            $data = validator($request->request->all(), ['name' => ['nullable', 'string', 'max:180']])->validate();
            $name = trim((string) ($data['name'] ?? ''));
        }

        $certificate = new Certificate([
            'verification_code' => 'CERT-PREVIEW0000000000',
            'recipient_name' => $name !== '' ? $name : 'Sample Participant',
            'issued_at' => now(),
        ]);
        $certificate->setRelation('webinar', $webinar);
        $certificate->setRelation('template', $template);

        try {
            $contents = $service->render($certificate);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate-preview.pdf"',
        ]);
    }

    /** Stream the active template's uploaded background image, for the settings-page preview. */
    public function background(Webinar $webinar): Response
    {
        $template = $this->activeTemplate($webinar);
        abort_unless($template->background_path, 404);
        abort_unless(str_starts_with($template->background_path, 'certificate-backgrounds/'), 404);

        $disk = Storage::disk($template->storage_disk);
        abort_unless($disk->exists($template->background_path), 404);

        return response($disk->get($template->background_path), 200, [
            'Content-Type' => $disk->mimeType($template->background_path) ?: 'image/png',
            'Cache-Control' => 'private, no-store, max-age=0',
            // The stored file passed image validation, but never let a browser
            // re-sniff it into an active type.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Reading setup/preview never creates or rewrites a template. */
    private function activeTemplate(Webinar $webinar): CertificateTemplate
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first()
            ?? $webinar->certificateTemplates()->make([
                'storage_disk' => config('webinar.certificate_disk'),
                'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
                'is_active' => true,
            ]);
        $template->setRelation('webinar', $webinar);

        return $template;
    }

    public function updateEmail(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $data = $this->emailInput($request, $webinar);
        DB::transaction(function () use ($webinar, $data, $audit, $request): void {
            $locked = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $audit->record($request, 'certificate_email.updated', $locked);
        });

        return redirect()->to(route('admin.certification.edit', $webinar).'#email-configuration')->with('success', 'Email configuration saved. No certificates or emails were sent.');
    }

    /** Resolve unsaved composer text using synthetic recipient information only. */
    public function previewEmail(Request $request, Webinar $webinar): View
    {
        $sample = clone $webinar;
        if ($request->isMethod('post')) {
            $sample->fill($this->emailInput($request, $webinar));
        }
        [$participant, $certificate] = $this->sampleCertificate($sample);
        $sender = $this->emailSender();

        return view('emails.certificate-issued', [
            'participant' => $participant, 'certificate' => $certificate,
            'customMessage' => $sample->certificateEmailMessage($certificate->recipient_name),
            'subject' => $sample->certificateEmailSubject($certificate->recipient_name),
            'previewInfo' => [
                'sender' => $sender['name'].' <'.$sender['address'].'>',
                'participant' => $certificate->recipient_name,
                'attachment' => $certificate->template->background_path
                    ? 'certificate.pdf — sample PDF using this webinar’s saved design'
                    : 'certificate.pdf — upload a certificate design before testing the attachment',
            ],
            'statusUrl' => null,
        ]);
    }

    public function testEmail(Request $request, Webinar $webinar, CertificateService $certificates, NotificationService $notifications, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['test_key' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $data['test_key'] = Str::lower($data['test_key']);
        try {
            $queued = DB::transaction(function () use ($webinar, $request, $data, $certificates, $notifications, $audit): bool {
                $locked = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
                abort_if($locked->archived_at !== null || $locked->retention_due_at?->isPast(), 409, 'This webinar is archived or past its retention deadline.');
                if (EmailDelivery::query()->where('idempotency_key', $data['test_key'])->exists()) {
                    return false;
                }
                [$participant, $certificate] = $this->sampleCertificate($locked);
                $contents = $certificates->render($certificate);
                $expiry = now()->addMinutes(15);
                if ($locked->retention_due_at && $locked->retention_due_at->lt($expiry)) {
                    $expiry = $locked->retention_due_at;
                }
                $delivery = $notifications->queue(
                    $locked, null, 'certificate_test', $request->user()->email,
                    '[TEST] '.$locked->certificateEmailSubject($certificate->recipient_name),
                    view('emails.certificate-issued', [
                        'participant' => $participant, 'certificate' => $certificate,
                        'customMessage' => $locked->certificateEmailMessage($certificate->recipient_name),
                        'statusUrl' => null, 'isTest' => true,
                    ])->render(),
                    null, [['name' => 'certificate.pdf', 'content' => base64_encode($contents)]], $expiry, $data['test_key'],
                );
                $audit->record($request, 'certificate_email.test_queued', $delivery);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $queued = false;
        } catch (\RuntimeException $exception) {
            return redirect()->to(route('admin.certification.edit', $webinar).'#email-configuration')->with('error', $exception->getMessage());
        }

        return redirect()->to(route('admin.certification.edit', $webinar).'#email-configuration')->with($queued ? 'success' : 'warning', $queued
            ? 'One sample email was queued to your '.($request->user()->isAdministrator() ? 'administrator' : 'account').' email address. No participant certificate was issued. Check the email delivery log for pending, sent or failed status.'
            : 'This test request was already queued. It was not sent again. Check the email delivery log.');
    }

    private function emailInput(Request $request, Webinar $webinar): array
    {
        try {
            $data = $request->validate([
                'certificate_email_subject' => ['nullable', 'string', 'max:120', 'not_regex:/[\r\n]/'],
                'certificate_email_message' => ['nullable', 'string', 'max:2000'],
            ]);
            foreach (['certificate_email_subject', 'certificate_email_message'] as $field) {
                $data[$field] = filled($data[$field] ?? null) ? trim($data[$field]) : null;
                preg_match_all('/\{\{([^{}]+)\}\}/', (string) $data[$field], $matches);
                if (array_diff($matches[1], ['participant_name', 'webinar_title', 'certificate_type']) !== []) {
                    throw ValidationException::withMessages([$field => 'Use only {{participant_name}}, {{webinar_title}} or {{certificate_type}}.']);
                }
            }

            return $data;
        } catch (ValidationException $exception) {
            // A background image request can become the session's previous URL.
            // Validation must return to the composer, never to a raw image.
            throw $exception->redirectTo(route('admin.certification.edit', $webinar).'#email-configuration');
        }
    }

    /** No participant, certificate, file or delivery is persisted by sample creation. */
    private function sampleCertificate(Webinar $webinar): array
    {
        $participant = new Participant(['full_name' => 'Sample Participant']);
        $participant->setRelation('webinar', $webinar);
        $certificate = new Certificate(['verification_code' => 'SAMPLE — NOT VALID', 'recipient_name' => 'Sample Participant', 'issued_at' => now()]);
        $certificate->setRelation('webinar', $webinar);
        $certificate->setRelation('template', $this->activeTemplate($webinar));

        return [$participant, $certificate];
    }

    private function emailSender(): array
    {
        return config('webinar.email.provider') === 'brevo'
            ? ['name' => config('services.brevo.from_name'), 'address' => config('services.brevo.from_address')]
            : config('mail.from');
    }
}
