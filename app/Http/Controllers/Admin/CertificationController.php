<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CertificationController extends Controller
{
    /** Requirements that can gate certificate issuance, in journey order. */
    public const REQUIREMENTS = ['registration', 'attendance', 'pretest', 'posttest', 'evaluation'];

    public function edit(Webinar $webinar): View
    {
        $webinar->load([
            'forms' => fn ($query) => $query->select(['id', 'webinar_id', 'type', 'title', 'status']),
            'eligibilityRules' => fn ($query) => $query
                ->select(['id', 'webinar_id', 'requirement', 'is_required', 'minimum_score']),
        ]);

        return view('admin.webinars.certification', [
            'webinar' => $webinar,
            'rules' => $webinar->eligibilityRules->keyBy('requirement'),
            'template' => $this->activeTemplate($webinar),
            'fonts' => CertificateTemplate::FONTS,
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

        $audit->record($request, 'eligibility_rules.updated', $webinar, ['requirements' => array_keys(array_filter($data['rules'] ?? [], fn ($rule) => ! empty($rule['enabled'])))]);

        return redirect()
            ->to(route('admin.certification.edit', $webinar).'#certificate-requirements')
            ->with('success', 'Certificate requirements saved.');
    }

    public function updateTemplate(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // The finished certificate is uploaded whole; the system only places
            // the name on it. An upload is required unless one is already stored.
            // The name's position, font, size, and colour are set visually in the
            // certificate editor, not here.
            'background' => [$template->background_path ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
        ], [
            'background.required' => 'Upload the finished certificate image (PNG or JPG).',
        ]);

        $disk = Storage::disk($template->storage_disk);
        $backgroundPath = $template->background_path;
        $layout = $template->layout ?? [];

        if ($request->hasFile('background')) {
            if ($backgroundPath) {
                $disk->delete($backgroundPath);
            }
            $file = $request->file('background');
            $backgroundPath = $file->storeAs(
                'certificate-backgrounds',
                $template->id.'-'.now()->timestamp.'.'.$file->extension(),
                ['disk' => $template->storage_disk],
            );

            // Remember the design's real pixel size so the preview and the issued
            // PDF are both rendered at its exact aspect ratio — never distorted.
            $dimensions = @getimagesize($file->getRealPath());
            if ($dimensions !== false && $dimensions[0] > 0 && $dimensions[1] > 0) {
                $layout['bg_w'] = (int) $dimensions[0];
                $layout['bg_h'] = (int) $dimensions[1];
            }
        }

        $template->update([
            'name' => $data['name'],
            'background_path' => $backgroundPath,
            // Preserve the name placement/font/colour set in the editor.
            'layout' => $layout,
        ]);

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

        $template->update([
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
        ]);

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
     * can be checked before issuing. A `name` query lets the organizer preview
     * the exact spelling they intend to print.
     */
    public function preview(Request $request, Webinar $webinar, CertificateService $service): Response|RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        if (blank($template->background_path)) {
            return redirect()
                ->route('admin.certification.edit', $webinar)
                ->with('error', 'Upload a certificate design first, then preview it.');
        }

        $name = trim((string) $request->query('name', ''));

        $certificate = new Certificate([
            'verification_code' => 'CERT-PREVIEW0000000000',
            'recipient_name' => $name !== '' ? Str::limit($name, 120, '') : 'Sample Participant',
            'issued_at' => now(),
        ]);
        $certificate->setRelation('webinar', $webinar);
        $certificate->setRelation('template', $template);

        return response($service->render($certificate), 200, [
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

    /** Fetch the webinar's active template, creating a default one if it has none. */
    private function activeTemplate(Webinar $webinar): CertificateTemplate
    {
        return $webinar->certificateTemplates()->where('is_active', true)->first()
            ?? $webinar->certificateTemplates()->create([
                'name' => 'Certificate',
                'storage_disk' => config('webinar.certificate_disk'),
                'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
                'is_active' => true,
            ]);
    }
}
