<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateApproval;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualCertificateRecipientController extends Controller
{
    public function store(Request $request, Webinar $webinar, EligibilityService $eligibility, AuditService $audit): JsonResponse
    {
        $data = $this->validatedIdentity($request);
        try {
            $participant = DB::transaction(function () use ($request, $webinar, $data, $eligibility, $audit): Participant {
                $this->lockWebinar($webinar);
                $participant = Participant::withTrashed()->where('webinar_id', $webinar->id)
                    ->where('email_normalized', $data['email'])->lockForUpdate()->first();
                $newIdentity = $participant === null;
                if ($participant) {
                    if ($participant->trashed() || $participant->privacy_erased_at) {
                        throw ValidationException::withMessages(['email' => 'This email belongs to an unavailable participant record. Review it before adding a recipient.']);
                    }
                    if ($this->nameKey($participant->full_name) !== $this->nameKey($data['full_name'])) {
                        throw ValidationException::withMessages(['full_name' => 'This email belongs to a participant with a different name. Review and correct the existing record first.']);
                    }
                    if ($eligibility->evaluate($participant)['eligible']) {
                        throw ValidationException::withMessages(['email' => 'This participant already appears in Certificate Studio through eligibility.']);
                    }
                    if ($eligibility->hasManualRecipient($participant)) {
                        throw ValidationException::withMessages(['email' => 'This recipient was already added manually to Certificate Studio.']);
                    }
                    if ($participant->certificates()->exists()) {
                        throw ValidationException::withMessages(['email' => 'This participant has certificate history. Use the existing correction or reissue workflow.']);
                    }
                } else {
                    $participant = $webinar->participants()->create($data);
                }

                $approval = CertificateApproval::query()->firstOrNew(['participant_id' => $participant->id]);
                if (! $approval->exists) {
                    $approval->fill(['approved_by' => $request->user()->id, 'approved_at' => now(),
                        'eligibility_snapshot' => ['evaluation' => $eligibility->evaluate($participant), 'rules' => $eligibility->ruleSnapshot($webinar)]]);
                }
                // Retain earlier approval evidence when authorizing an exception.
                $approval->fill(['source' => 'manual', 'removed_at' => null,
                    'manages_identity' => $newIdentity || $approval->manages_identity])->save();
                $audit->record($request, 'certificate.recipient_added', $participant, [
                    'webinar_id' => $webinar->id, 'source' => 'manual', 'authorization_id' => $approval->id,
                ]);

                return $participant;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to a participant in this webinar. Refresh the list and review the existing record.']);
        }

        return response()->json(['message' => 'Recipient added to Studio.', 'participant_id' => $participant->public_id], 201);
    }

    public function update(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): JsonResponse|RedirectResponse
    {
        $data = $this->validatedIdentity($request);
        try {
            DB::transaction(function () use ($request, $webinar, $participant, $data, $audit): void {
                $this->lockWebinar($webinar);
                $participant = $this->lockParticipant($webinar, $participant);
                $approval = $this->manualAuthorization($participant);
                if (! $approval->manages_identity || $participant->verified_at || $participant->email_verified_at
                    || $participant->checked_in_at || $participant->submissions()->exists()) {
                    throw ValidationException::withMessages(['full_name' => 'This identity is controlled by participant responses or eligibility. Review the participant record for corrections.']);
                }
                if ($participant->certificates()->exists()) {
                    throw ValidationException::withMessages(['full_name' => 'Certificate preparation has started. Use the explicit correction or reissue workflow.']);
                }
                if (Participant::withTrashed()->where('webinar_id', $webinar->id)->where('email_normalized', $data['email'])->whereKeyNot($participant->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already belongs to another participant in this webinar. Review that record; identities are never merged automatically.']);
                }
                $changed = array_keys(array_filter($data, fn ($value, $key) => $value !== $participant->$key, ARRAY_FILTER_USE_BOTH));
                $participant->fill($data)->save();
                $audit->record($request, 'certificate.recipient_updated', $participant, ['webinar_id' => $webinar->id, 'source' => 'manual', 'changed_fields' => $changed]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to another participant in this webinar.']);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Recipient updated.'])
            : redirect()->route('admin.certificates.studio', $webinar)->with('success', 'Recipient updated.');
    }

    public function destroy(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $webinar, $participant, $audit): void {
            $this->lockWebinar($webinar);
            $participant = $this->lockParticipant($webinar, $participant);
            $approval = $this->manualAuthorization($participant);
            // Removing authorization is safe even with historical certificates.
            // Active work must finish or be explicitly revoked first.
            if ($participant->certificates()->whereNull('revoked_at')->whereIn('status', ['processing', 'issued'])->exists()) {
                throw ValidationException::withMessages(['recipient' => 'This recipient has an active certificate. Use the explicit correction or reissue workflow.']);
            }
            $approval->update(['removed_at' => now()]);
            $audit->record($request, 'certificate.recipient_removed', $participant, ['webinar_id' => $webinar->id, 'source' => 'manual', 'authorization_id' => $approval->id]);
        }, attempts: 3);

        $message = 'Manual recipient removed. Certificate history is preserved; normally eligible participants remain available.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('admin.certificates.studio', $webinar)->with('success', $message);
    }

    private function validatedIdentity(Request $request): array
    {
        $request->merge([
            'full_name' => is_string($request->input('full_name')) ? trim($request->input('full_name')) : $request->input('full_name'),
            'email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email'),
        ]);

        return $request->validate(['full_name' => ['required', 'string', 'max:180', 'not_regex:/[\x00-\x1F\x7F]/u'], 'email' => ['required', 'email:rfc', 'max:255']]);
    }

    private function nameKey(?string $name): string
    {
        return Str::lower(Str::squish((string) $name));
    }

    private function lockWebinar(Webinar $webinar): void
    {
        $locked = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
        abort_if($locked->archived_at || $locked->retention_due_at?->isPast(), 409, 'This webinar is closed for recipient changes.');
    }

    private function lockParticipant(Webinar $webinar, Participant $participant): Participant
    {
        return $webinar->participants()->whereKey($participant->id)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
    }

    private function manualAuthorization(Participant $participant): CertificateApproval
    {
        $approval = $participant->certificateApproval()->where('source', 'manual')->whereNull('removed_at')->lockForUpdate()->first();
        abort_unless($approval, 422, 'Only an active manually added recipient can be managed here.');

        return $approval;
    }
}
