<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EligibilityReview;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
use App\Services\AuditService;
use App\Services\CertificateFileService;
use App\Services\EligibilityReviewFiles;
use App\Services\EligibilityService;
use App\Services\ParticipantPrivacyService;
use App\Support\LocalDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebinarController extends Controller
{
    public function index(Request $request): View
    {
        $webinars = Webinar::query()
            ->visibleTo($request->user())
            ->select(['id', 'created_by', 'title', 'description', 'status', 'starts_at', 'created_at', 'admin_locked_at'])
            ->with('creator:id,name')
            ->when($request->filled('search'), fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ['%'.Str::lower(mb_substr((string) $request->query('search'), 0, 180)).'%']))
            ->when($request->user()->isAdministrator() && $request->filled('owner'), fn ($q) => $q->where('created_by', $request->integer('owner')))
            ->withCount(['participants', 'certificates'])
            ->when($request->filled('availability'), function ($query) use ($request): void {
                match ($request->string('availability')->value()) {
                    'open' => $query->where('status', 'published'),
                    'closed' => $query->whereIn('status', ['draft', 'completed']),
                    'archived' => $query->where('status', 'archived'),
                    default => null,
                };
            })
            // Keep old bookmarked filters working even though lifecycle status
            // is no longer exposed in the interface.
            ->when(! $request->filled('availability') && $request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $owners = $request->user()->isAdministrator() ? User::query()->whereHas('webinars')->orderBy('name')->get(['id', 'name']) : collect();

        return view('admin.webinars.index', compact('webinars', 'owners'));
    }

    public function create(): View
    {
        return view('admin.webinars.form', [
            'webinar' => new Webinar,
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['created_by'] = $request->user()->id;
        $protection = $request->validate([
            'password_protected' => ['sometimes', 'boolean'],
            'webinar_password' => ['exclude_unless:password_protected,1', 'required', 'string', 'min:15', 'max:1024', 'confirmed'],
        ]);
        if ($request->boolean('password_protected')) {
            $data['management_password'] = Hash::make($protection['webinar_password']);
            $data['management_lock_version'] = (string) Str::uuid();
        }

        $webinar = DB::transaction(function () use ($data): Webinar {
            $webinar = Webinar::query()->create($data);

            if ($webinar->isUploadOnly()) {
                return $webinar;
            }

            collect([
                ['type' => 'registration', 'title' => 'Registration', 'status' => 'published'],
                ['type' => 'pretest', 'title' => 'Pre-assessment', 'status' => 'draft', 'show_score' => true],
                ['type' => 'posttest', 'title' => 'Post-assessment', 'status' => 'draft', 'show_score' => true],
                ['type' => 'evaluation', 'title' => 'Event evaluation', 'status' => 'draft'],
            ])->each(fn (array $form) => $webinar->forms()->create($form));

            collect(['pretest', 'posttest', 'evaluation'])->each(function (string $requirement) use ($webinar): void {
                EligibilityRule::query()->create([
                    'webinar_id' => $webinar->id,
                    'requirement' => $requirement,
                    'is_required' => true,
                ]);
            });

            CertificateTemplate::query()->create([
                'webinar_id' => $webinar->id,
                'name' => mb_substr($webinar->title.' — Certificate of Participation', 0, 255),
                'storage_disk' => config('webinar.certificate_disk'),
                'layout' => ['accent' => '#1d4ed8'],
                'is_active' => true,
            ]);

            return $webinar;
        });

        $audit->record($request, 'webinar.created', $webinar);

        if ($webinar->isUploadOnly()) {
            return redirect()->route('admin.webinars.show', $webinar)->with('success', 'Webinar created.');
        }

        return redirect()->route('admin.webinars.show', $webinar)->with('success', 'Webinar created. Open the webinar and registration form when you are ready to accept participants.');
    }

    public function show(Webinar $webinar, EligibilityService $eligibility): View
    {
        $user = request()->user();
        if (! $user->isAdministrator() && ! ($user->canCreateWebinars() && (int) $webinar->created_by === (int) $user->id)) {
            $policy = app(WebinarPolicy::class);

            return view('admin.webinars.assigned-overview', [
                'webinar' => $webinar,
                'canParticipants' => $policy->permission($user, $webinar, 'manage_participants'),
                'canCertificates' => $policy->permission($user, $webinar, 'manage_certificates'),
                'canSend' => $policy->permission($user, $webinar, 'send_emails'),
            ]);
        }
        if ($webinar->isUploadOnly()) {
            return view('admin.webinars.documents', ['webinar' => $webinar, 'documents' => $webinar->documents()->latest()->paginate(20)]);
        }
        $webinar->load([
            'forms' => fn ($query) => $query->select([
                'id', 'webinar_id', 'type', 'title', 'status', 'opens_at', 'closes_at', 'public_token',
            ]),
        ])->loadCount([
            'participants',
            'certificates',
            'certificates as issued_certificates_count' => fn ($query) => $query->whereNotNull('issued_at'),
        ]);

        // Bind the parent so each form's acceptsResponses()/shareUrl() resolves
        // without an extra query per row.
        $webinar->forms->each->setRelation('webinar', $webinar);
        $recentParticipants = $webinar->participants()
            ->select(['id', 'webinar_id', 'full_name', 'email', 'verified_at', 'created_at'])
            ->latest()
            ->limit(8)
            ->get();

        $hasCertificateDesign = $webinar->certificateTemplates()
            ->where('is_active', true)
            ->whereNotNull('background_path')
            ->exists();

        // Release readiness is deliberately derived live rather than stored as
        // a checkbox. Imports, requirements, names, templates, and delivery
        // state can all change after an administrator last looked at the page.
        $requiredRequirements = $webinar->eligibilityRules()
            ->where('is_required', true)
            ->pluck('requirement')
            ->sort()
            ->values();
        $defaultCertificateRequirements = collect(['evaluation', 'posttest', 'pretest']);
        $usesDefaultCertificateRequirements = $requiredRequirements->values()->all()
            === $defaultCertificateRequirements->values()->all();
        $unresolvedImportIssues = $webinar->importIssues()->whereNull('resolved_at')->count();
        $activeBatch = $webinar->certificateBatches()
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        $eligibleParticipants = $eligibility->eligibleParticipantsQuery($webinar)
            ->whereNull('participants.privacy_erased_at');
        $eligibleCount = (clone $eligibleParticipants)->count();
        $readyRecipientCount = (clone $eligibleParticipants)
            ->whereNotNull('participants.email')
            ->where('participants.email', '!=', '')
            ->whereDoesntHave('certificates', fn ($query) => $query
                ->whereNull('revoked_at')
                ->whereNotNull('issued_at'))
            ->count();
        $missingEmailCount = max(0, $eligibleCount - (clone $eligibleParticipants)
            ->whereNotNull('participants.email')
            ->where('participants.email', '!=', '')
            ->count());
        $emailProviderReady = (config('webinar.email.certificate_provider') ?? config('webinar.email.provider')) !== 'log'
            && ((config('webinar.email.certificate_provider') ?? config('webinar.email.provider')) !== 'brevo' || filled(config('services.brevo.key')));
        $readiness = [
            'uses_default_requirements' => $usesDefaultCertificateRequirements,
            'required_requirements' => $requiredRequirements,
            'has_certificate_design' => $hasCertificateDesign,
            'unresolved_import_issues' => $unresolvedImportIssues,
            'eligible_count' => $eligibleCount,
            'ready_recipient_count' => $readyRecipientCount,
            'missing_email_count' => $missingEmailCount,
            'email_provider_ready' => $emailProviderReady,
            'email_provider' => (config('webinar.email.certificate_provider') ?? config('webinar.email.provider')),
            'active_batch' => $activeBatch,
        ];

        return view('admin.webinars.show', compact('webinar', 'recentParticipants', 'hasCertificateDesign', 'readiness'));
    }

    /**
     * Reports and analytics for a single webinar: the registration-to-certificate
     * funnel, response counts per stage, and assessment score averages.
     */
    public function reports(Webinar $webinar, EligibilityService $eligibility): View
    {
        $webinar->load(['forms' => fn ($query) => $query->select(['id', 'webinar_id', 'type', 'title'])])
            ->loadCount('participants');

        $verifiedCount = $webinar->participants()->whereNotNull('verified_at')->count();
        $order = ['registration', 'pretest', 'posttest', 'evaluation'];
        $forms = $webinar->forms->sortBy(fn ($form) => array_search($form->type, $order, true) === false ? 99 : array_search($form->type, $order, true))->values();

        // One grouped read for every form. The maximum-score average must still
        // exclude attempts without a score, exactly as the previous query did.
        $submissionStats = Submission::query()->whereIn('form_id', $forms->pluck('id'))
            ->where('status', 'submitted')->groupBy('form_id')
            ->selectRaw('form_id, COUNT(DISTINCT participant_id) as respondents, AVG(score) as avg_score, AVG(CASE WHEN score IS NOT NULL THEN maximum_score END) as avg_max')
            ->get()->keyBy('form_id');

        $formStats = $forms->map(function ($form) use ($webinar, $submissionStats): array {
            $row = $submissionStats->get($form->id);
            $respondents = (int) ($row?->respondents ?? 0);
            $scored = in_array($form->type, ['pretest', 'posttest'], true);

            $base = max(1, $webinar->participants_count);

            return [
                'type' => $form->type,
                'title' => $form->title,
                'respondents' => $respondents,
                'rate' => (int) round($respondents / $base * 100),
                'avg' => $scored && $row?->avg_score !== null ? round((float) $row->avg_score, 1) : null,
                'avgMax' => $scored && $row?->avg_max !== null ? round((float) $row->avg_max, 1) : null,
            ];
        });

        return view('admin.webinars.reports', [
            'webinar' => $webinar,
            'verifiedCount' => $verifiedCount,
            'formStats' => $formStats,
            'eligibleCount' => $eligibility->eligibleParticipantsQuery($webinar)->count(),
            'issuedCount' => $webinar->certificates()->whereNotNull('issued_at')->whereNull('revoked_at')->count(),
            'revokedCount' => $webinar->certificates()->whereNotNull('revoked_at')->count(),
        ]);
    }

    public function edit(Webinar $webinar): View
    {
        return view('admin.webinars.form', [
            'webinar' => $webinar,
        ]);
    }

    public function update(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        abort_if($webinar->deletion_started_at !== null, 409, 'This webinar is being permanently deleted.');

        $data = $this->validated($request, $webinar);
        $this->assertRetentionDeadlineNotExtended($webinar, $data);
        $previousDeadline = $webinar->retention_due_at?->copy();

        $verificationChanges = array_key_exists('requires_verification', $data)
            && (bool) $data['requires_verification'] !== $webinar->requiresVerification();
        $capacityChanges = array_key_exists('registration_capacity', $data)
            && $data['registration_capacity'] !== $webinar->registration_capacity;

        if ($verificationChanges) {
            DB::transaction(function () use (&$webinar, $data): void {
                $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();

                // Public submissions hold a compatible shared lock on their
                // form. Locking every form makes this mode switch atomic with
                // all in-flight submissions without serializing submitters.
                Form::query()
                    ->where('webinar_id', $lockedWebinar->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                $lockedWebinar->update($data);
                $webinar = $lockedWebinar;
            }, attempts: 3);
        } elseif ($capacityChanges) {
            DB::transaction(function () use (&$webinar, $data): void {
                // Capacity-limited registration submissions lock this same row
                // exclusively, so changing the limit is atomic with their count.
                $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                $lockedWebinar->update($data);
                $webinar = $lockedWebinar;
            }, attempts: 3);
        } else {
            $webinar->update($data);
        }

        $audit->record($request, 'webinar.updated', $webinar, [
            'retention_deadline_shortened' => $previousDeadline !== null
                && $webinar->retention_due_at?->lessThan($previousDeadline),
            'verification_mode_changed' => $verificationChanges,
            'requires_verification' => $webinar->requiresVerification(),
            'registration_capacity_changed' => $capacityChanges,
        ]);

        return redirect()->route('admin.webinars.show', $webinar)->with('success', 'Webinar settings updated.');
    }

    public function archive(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        abort_if($webinar->deletion_started_at !== null, 409, 'This webinar is being permanently deleted.');

        $webinar->update(['status' => 'archived', 'archived_at' => now()]);
        $audit->record($request, 'webinar.archived', $webinar);

        return redirect()->route('admin.webinars.index')->with('success', 'Webinar archived.');
    }

    /**
     * Permanently delete a webinar and everything under it.
     *
     * Deleting cascades to its certificates, which would break the public
     * verification links already handed out, so the exact title must be typed to
     * confirm and the certificate count is surfaced on the way in.
     */
    public function destroy(
        Request $request,
        Webinar $webinar,
        AuditService $audit,
        CertificateFileService $certificateFiles,
        ParticipantPrivacyService $privacy,
    ): RedirectResponse {
        $request->validate(
            ['confirm' => ['required', 'string']],
            ['confirm.required' => 'Type the webinar title to confirm deletion.'],
        );

        if ($request->string('confirm')->trim()->value() !== $webinar->title) {
            return back()->withErrors(['confirm' => 'That did not match the webinar title, so nothing was deleted.']);
        }

        // Publish an irreversible tombstone before looking at the file inventory.
        // Issuance and delivery workers lock/re-read this row and must refuse new
        // work once this marker is visible, closing the orphan-file race.
        $auditMetadata = DB::transaction(function () use ($audit, $request, $webinar): array {
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $newlyStarted = $lockedWebinar->deletion_started_at === null;

            if ($newlyStarted) {
                $lockedWebinar->deletion_started_at = now();
                $lockedWebinar->status = 'archived';
                $lockedWebinar->archived_at ??= now();
                $lockedWebinar->save();
            }

            CertificateBatch::query()
                ->where('webinar_id', $lockedWebinar->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled', 'completed_at' => now()]);

            EmailDelivery::query()
                ->where('webinar_id', $lockedWebinar->id)
                ->whereNotIn('status', ['sent', 'cancelled'])
                ->update([
                    'status' => 'cancelled',
                    'processing_at' => null,
                    'active_certificate_delivery_key' => null,
                ]);

            // Deliveries intentionally survive relational deletion for operations
            // counts. Remove all message content as soon as deletion begins so a
            // retry cannot retain or reconstruct participant data.
            EmailDelivery::query()->where('webinar_id', $lockedWebinar->id)->update([
                'recipient_email' => null,
                'subject' => null,
                'payload' => null,
                'provider_message_id' => null,
                'last_error' => null,
            ]);

            $metadata = [
                'webinar_id' => $lockedWebinar->id,
                'participants' => $lockedWebinar->participants()->withTrashed()->count(),
                'certificates' => $lockedWebinar->certificates()->whereNotNull('issued_at')->count(),
            ];

            if ($newlyStarted) {
                $audit->record($request, 'webinar.deletion_started', $lockedWebinar, $metadata);
            }

            return $metadata;
        });

        $certificateFiles->delete(
            $webinar->certificates()->select(['id', 'public_id', 'storage_disk', 'file_path'])->cursor(),
        );
        $privacy->eraseWebinarImports($webinar);
        foreach (EligibilityReview::query()->where('webinar_id', $webinar->id)->cursor() as $review) {
            app(EligibilityReviewFiles::class)->delete($review);
        }
        // Keep retired/replaced uploads in the inventory until the authorized
        // permanent webinar deletion. Fail closed before relational deletion.
        foreach ($webinar->documents()->withTrashed()->cursor() as $document) {
            $prefix = 'webinar-documents/'.$webinar->id.'/';
            if (! preg_match('#^'.preg_quote($prefix, '#').'[a-f0-9-]{36}\.(pdf|docx|png|jpg|jpeg)$#D', $document->file_path)) {
                throw new \RuntimeException('Refusing to delete an unexpected document path.');
            }
            $disk = Storage::disk($document->storage_disk);
            if ($disk->exists($document->file_path) && ! $disk->delete($document->file_path)) {
                throw new \RuntimeException('A private document could not be deleted. Retry webinar deletion.');
            }
        }

        DB::transaction(function () use ($webinar): void {
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->first();

            if ($lockedWebinar === null) {
                return;
            }

            abort_unless($lockedWebinar->deletion_started_at !== null, 409, 'Webinar deletion was not safely started.');
            $lockedWebinar->delete();
        });

        $audit->record($request, 'webinar.deleted', null, $auditMetadata);

        return redirect()->route('admin.webinars.index')->with('success', 'Webinar deleted along with its forms, responses, and certificates.');
    }

    private function validated(Request $request, ?Webinar $webinar = null): array
    {
        $mode = $webinar?->creation_mode ?? $request->input('creation_mode', 'standard');
        $request->validate(['creation_mode' => ['sometimes', Rule::in(['standard', 'uploads'])]]);
        if ($mode === 'uploads') {
            $data = $request->validate(['title' => ['required', 'string', 'max:180']]);

            return $data + ($webinar ? [] : ['creation_mode' => 'uploads', 'status' => 'draft', 'timezone' => config('app.timezone'), 'data_retention_days' => Webinar::defaultRetentionDays()]);
        }
        $resolvedStatus = $this->resolvedStatus($request, $webinar);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Open/Closed is the only lifecycle control shown to organizers.
            // `status` remains accepted for imports and older clients while the
            // database's draft/completed distinction stays an internal detail.
            'is_open' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'completed'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                Rule::requiredIf(fn (): bool => in_array($resolvedStatus, ['published', 'completed'], true)),
                'nullable', 'date', 'after_or_equal:starts_at',
            ],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after_or_equal:registration_opens_at'],
            'registration_capacity' => ['nullable', 'integer', 'min:1'],
            // The timezone picker was removed from the UI: schedule times are
            // interpreted in the application's single timezone. A value is still
            // accepted (tests and imports may supply one) but is never required.
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'data_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'requires_verification' => ['sometimes', 'boolean'],
            'certificate_email_subject' => ['nullable', 'string', 'max:120'],
            'certificate_email_message' => ['nullable', 'string', 'max:2000'],
        ]);

        unset($data['is_open']);
        $data['status'] = $resolvedStatus;

        // Blank overrides mean "use the built-in wording", not an empty subject.
        foreach (['certificate_email_subject', 'certificate_email_message'] as $optionalText) {
            if (array_key_exists($optionalText, $data)) {
                $data[$optionalText] = filled($data[$optionalText]) ? trim((string) $data[$optionalText]) : null;
            }
        }

        $data['timezone'] = filled($data['timezone'] ?? null)
            ? $data['timezone']
            : config('app.timezone');

        foreach (['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = null;

                continue;
            }

            $data[$field] = LocalDateTime::toUtc(
                (string) $data[$field],
                (string) $data['timezone'],
                $field,
            );
        }

        $data['registration_capacity'] = filled($data['registration_capacity'] ?? null)
            ? (int) $data['registration_capacity']
            : null;

        return $data;
    }

    /**
     * Translate the public Open/Closed switch into the legacy lifecycle values.
     * A webinar closed after being open becomes completed so certificates and
     * participant status links still work; a webinar not opened yet stays draft.
     */
    private function resolvedStatus(Request $request, ?Webinar $webinar): string
    {
        if (! $request->has('is_open')) {
            return (string) ($request->input('status') ?: $webinar?->status ?: 'draft');
        }

        if ($webinar?->status === 'archived') {
            return 'archived';
        }

        if ($request->boolean('is_open')) {
            return 'published';
        }

        return in_array($webinar?->status, ['published', 'completed'], true)
            ? 'completed'
            : 'draft';
    }

    /**
     * A normal settings edit can shorten a committed privacy deadline, but it
     * cannot remove or postpone it. Extending retention needs a separate,
     * explicitly authorized and audited governance workflow; none exists here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertRetentionDeadlineNotExtended(Webinar $webinar, array $data): void
    {
        if ($webinar->retention_due_at === null) {
            return;
        }

        if (blank($data['ends_at'] ?? null)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The event end time cannot be cleared after its privacy deadline is committed.',
            ]);
        }

        $endsAt = $data['ends_at'] instanceof \DateTimeInterface
            ? CarbonImmutable::instance($data['ends_at'])
            : CarbonImmutable::parse((string) $data['ends_at']);
        $proposedDeadline = $endsAt
            ->addDays((int) $data['data_retention_days']);

        if ($proposedDeadline->greaterThan($webinar->retention_due_at)) {
            throw ValidationException::withMessages([
                'data_retention_days' => 'This change would extend the committed privacy deadline and was refused.',
            ]);
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $counter = 2;

        while (Webinar::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
