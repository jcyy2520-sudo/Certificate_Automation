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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'heading' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:300'],
            'signatory_name' => ['nullable', 'string', 'max:120'],
            'signatory_title' => ['nullable', 'string', 'max:120'],
            'background' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
            'remove_background' => ['nullable', 'boolean'],
            'name_top' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'name_font_size' => ['nullable', 'numeric', 'min:12', 'max:160'],
        ]);

        $template = $this->activeTemplate($webinar);
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
        } elseif ($request->boolean('remove_background') && $backgroundPath) {
            $disk->delete($backgroundPath);
            $backgroundPath = null;
        }

        $template->update([
            'name' => $data['name'],
            'background_path' => $backgroundPath,
            'layout' => [
                'accent' => $data['accent'],
                'heading' => $data['heading'],
                'body' => $data['body'],
                'signatory_name' => $data['signatory_name'] ?? null,
                'signatory_title' => $data['signatory_title'] ?? null,
                'name_top' => $data['name_top'] ?? ($template->layout['name_top'] ?? 62),
                'name_font_size' => $data['name_font_size'] ?? ($template->layout['name_font_size'] ?? 42),
            ],
        ]);

        $audit->record($request, 'certificate_template.updated', $template);

        return back()->with('success', 'Certificate design saved. New certificates use it immediately.');
    }

    /** Render the active design with sample data so the wording can be checked before issuing. */
    public function preview(Webinar $webinar, CertificateService $service): Response
    {
        $certificate = new Certificate([
            'verification_code' => 'CERT-PREVIEW0000000000',
            'recipient_name' => 'Sample Participant',
            'issued_at' => now(),
        ]);
        $certificate->setRelation('webinar', $webinar);
        $certificate->setRelation('template', $this->activeTemplate($webinar));

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
        ]);
    }

    /** Fetch the webinar's active template, creating a default one if it has none. */
    private function activeTemplate(Webinar $webinar): CertificateTemplate
    {
        return $webinar->certificateTemplates()->where('is_active', true)->first()
            ?? $webinar->certificateTemplates()->create([
                'name' => 'Classic certificate',
                'storage_disk' => config('webinar.certificate_disk'),
                'template_path' => 'generated/classic',
                'layout' => ['accent' => '#1d4ed8'],
                'is_active' => true,
            ]);
    }
}
