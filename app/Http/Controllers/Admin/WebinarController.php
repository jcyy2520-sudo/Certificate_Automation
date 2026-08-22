<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateFileService;
use App\Services\EligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebinarController extends Controller
{
    public function index(Request $request): View
    {
        $webinars = Webinar::query()
            ->select(['id', 'title', 'description', 'status', 'starts_at', 'created_at'])
            ->withCount(['participants', 'certificates'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.webinars.index', compact('webinars'));
    }

    public function create(): View
    {
        return view('admin.webinars.form', [
            'webinar' => new Webinar,
            'timezoneOptions' => Webinar::timezoneOptions(),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['title']);
        $data['created_by'] = $request->user()->id;

        $webinar = DB::transaction(function () use ($data): Webinar {
            $webinar = Webinar::query()->create($data);

            collect([
                ['type' => 'registration', 'title' => 'Registration', 'status' => 'published'],
                ['type' => 'pretest', 'title' => 'Pre-assessment', 'status' => 'draft', 'show_score' => true],
                ['type' => 'posttest', 'title' => 'Post-assessment', 'status' => 'draft', 'show_score' => true],
                ['type' => 'evaluation', 'title' => 'Event evaluation', 'status' => 'draft'],
            ])->each(fn (array $form) => $webinar->forms()->create($form));

            EligibilityRule::query()->create([
                'webinar_id' => $webinar->id,
                'requirement' => 'registration',
                'is_required' => true,
            ]);

            CertificateTemplate::query()->create([
                'webinar_id' => $webinar->id,
                'name' => 'Classic certificate',
                'storage_disk' => config('webinar.certificate_disk'),
                'template_path' => 'generated/classic',
                'layout' => ['accent' => '#1d4ed8'],
                'is_active' => true,
            ]);

            return $webinar;
        });

        $audit->record($request, 'webinar.created', $webinar);

        return redirect()->route('admin.webinars.show', $webinar)->with('success', 'Webinar created with its default forms and certificate template.');
    }

    public function show(Webinar $webinar): View
    {
        $webinar->load([
            'forms' => fn ($query) => $query->select([
                'id', 'webinar_id', 'type', 'title', 'status', 'opens_at', 'closes_at',
            ]),
        ])->loadCount([
            'participants',
            'certificates',
            'certificates as issued_certificates_count' => fn ($query) => $query->whereNotNull('issued_at'),
        ]);
        $recentParticipants = $webinar->participants()
            ->select(['id', 'webinar_id', 'full_name', 'email', 'verified_at', 'created_at'])
            ->latest()
            ->limit(8)
            ->get();

        return view('admin.webinars.show', compact('webinar', 'recentParticipants'));
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

        $formStats = $forms->map(function ($form) use ($webinar): array {
            $submissions = Submission::query()->where('form_id', $form->id)->where('status', 'submitted');
            $respondents = (clone $submissions)->distinct()->count('participant_id');

            $averages = ['avg' => null, 'avgMax' => null];
            if (in_array($form->type, ['pretest', 'posttest'], true)) {
                $row = (clone $submissions)->whereNotNull('score')
                    ->selectRaw('AVG(score) as avg_score, AVG(maximum_score) as avg_max')
                    ->first();
                $averages = [
                    'avg' => $row?->avg_score !== null ? round((float) $row->avg_score, 1) : null,
                    'avgMax' => $row?->avg_max !== null ? round((float) $row->avg_max, 1) : null,
                ];
            }

            $base = max(1, $webinar->participants_count);

            return [
                'type' => $form->type,
                'title' => $form->title,
                'respondents' => $respondents,
                'rate' => (int) round($respondents / $base * 100),
                'avg' => $averages['avg'],
                'avgMax' => $averages['avgMax'],
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
            'timezoneOptions' => Webinar::timezoneOptions(),
        ]);
    }

    public function update(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        abort_if($webinar->deletion_started_at !== null, 409, 'This webinar is being permanently deleted.');

        $data = $this->validated($request);
        $this->assertRetentionDeadlineNotExtended($webinar, $data);
        $previousDeadline = $webinar->retention_due_at?->copy();

        $verificationChanges = array_key_exists('requires_verification', $data)
            && (bool) $data['requires_verification'] !== $webinar->requiresVerification();

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
        } else {
            $webinar->update($data);
        }

        $audit->record($request, 'webinar.updated', $webinar, [
            'retention_deadline_shortened' => $previousDeadline !== null
                && $webinar->retention_due_at?->lessThan($previousDeadline),
            'verification_mode_changed' => $verificationChanges,
            'requires_verification' => $webinar->requiresVerification(),
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
                ->update(['status' => 'cancelled', 'processing_at' => null]);

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

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['draft', 'published', 'completed'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                Rule::requiredIf(fn (): bool => in_array($request->input('status'), ['published', 'completed'], true)),
                'nullable', 'date', 'after_or_equal:starts_at',
            ],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after_or_equal:registration_opens_at'],
            'timezone' => ['required', 'timezone'],
            'data_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'requires_verification' => ['sometimes', 'boolean'],
        ]);

        foreach (['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at'] as $field) {
            if (blank($data[$field] ?? null)) {
                $data[$field] = null;

                continue;
            }

            $data[$field] = $this->localDateTimeToUtc(
                (string) $data[$field],
                (string) $data['timezone'],
                $field,
            );
        }

        return $data;
    }

    /**
     * Browser datetime-local values have no offset. Interpret them in the
     * event's declared IANA timezone, reject DST-normalized nonexistent times,
     * and persist one unambiguous UTC instant.
     */
    private function localDateTimeToUtc(string $value, string $timezone, string $field): CarbonImmutable
    {
        try {
            $local = CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid local date and time.']);
        }

        $submittedMinute = str_replace(' ', 'T', substr(trim($value), 0, 16));

        if ($local->format('Y-m-d\TH:i') !== $submittedMinute) {
            throw ValidationException::withMessages([
                $field => 'That local time does not exist in the selected timezone because of a clock change.',
            ]);
        }

        return $local->utc();
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
