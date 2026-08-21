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
use Illuminate\View\View;

class CertificationController extends Controller
{
    /** Requirements that can gate certificate issuance, in journey order. */
    public const REQUIREMENTS = ['registration', 'pretest', 'posttest', 'evaluation'];

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
            'batches' => $webinar->certificateBatches()
                ->select([
                    'id', 'webinar_id', 'created_by', 'status', 'total_count',
                    'completed_count', 'failed_count', 'created_at',
                ])
                ->with('creator:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
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
                        'minimum_score' => $requirement === 'registration' ? null : ($rule['minimum_score'] ?? null),
                    ],
                );
            }
        });

        $audit->record($request, 'eligibility_rules.updated', $webinar, ['requirements' => array_keys(array_filter($data['rules'] ?? [], fn ($rule) => ! empty($rule['enabled'])))]);

        return back()->with('success', 'Certificate requirements saved.');
    }

    public function updateTemplate(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $this->activeTemplate($webinar);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // The finished certificate is uploaded whole; the system only places
            // the name on it. An upload is required unless one is already stored.
            'background' => [$template->background_path ? 'nullable' : 'required', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
            'name_top' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'name_font_size' => ['nullable', 'numeric', 'min:12', 'max:160'],
        ], [
            'background.required' => 'Upload the finished certificate image (PNG or JPG).',
        ]);

        $disk = Storage::disk($template->storage_disk);
        $backgroundPath = $template->background_path;

        if ($request->hasFile('background')) {
            if ($backgroundPath) {
                $disk->delete($backgroundPath);
            }
            $backgroundPath = $request->file('background')->storeAs(
                'certificate-backgrounds',
                $template->id.'-'.now()->timestamp.'.'.$request->file('background')->extension(),
                ['disk' => $template->storage_disk],
            );
        }

        $template->update([
            'name' => $data['name'],
            'background_path' => $backgroundPath,
            'layout' => [
                'accent' => $data['accent'],
                'name_top' => $data['name_top'] ?? ($template->layout['name_top'] ?? 62),
                'name_font_size' => $data['name_font_size'] ?? ($template->layout['name_font_size'] ?? 42),
            ],
        ]);

        $audit->record($request, 'certificate_template.updated', $template);

        return back()->with('success', 'Certificate design saved. New certificates use it immediately.');
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
                'template_path' => 'uploaded',
                'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
                'is_active' => true,
            ]);
    }
}
