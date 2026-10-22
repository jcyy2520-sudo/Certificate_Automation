<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\ParticipantMessageBatch;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParticipantMessageController extends Controller
{
    public function index(Webinar $webinar, EligibilityService $eligibility)
    {
        abort_if($webinar->isUploadOnly(), 404);
        $participants = Participant::query()->whereIn('id', $eligibility->eligibleParticipantsQuery($webinar))
            ->whereNull('privacy_erased_at')->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')->paginate(50);

        return view('admin.webinars.messages', compact('webinar', 'participants'));
    }

    public function store(Request $request, Webinar $webinar, EligibilityService $eligibility, NotificationService $notifications, AuditService $audit)
    {
        $data = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'participants' => ['required', 'array', 'min:1', 'max:'.config('webinar.message_recipient_limit')],
            'participants.*' => ['required', 'string', 'distinct', 'max:26'],
            'subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $ids = $data['participants'];
        sort($ids, SORT_STRING);
        $digest = hash_hmac('sha256', json_encode([$ids, $data['subject'], $data['body']], JSON_THROW_ON_ERROR), config('app.key'));
        $count = DB::transaction(function () use ($request, $webinar, $data, $ids, $digest, $eligibility, $notifications): int {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $this->assertSendable($locked);
            $previous = ParticipantMessageBatch::query()->where('submission_key', $data['submission_key'])->first();
            if ($previous) {
                abort_unless($previous->webinar_id === $locked->id && $previous->created_by === $request->user()->id && hash_equals($previous->request_digest, $digest), 409, 'This submission key was already used for a different message. Reload the composer.');

                return $previous->recipient_count;
            }
            $participants = $locked->participants()->whereIn('public_id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($participants->count() !== count($ids)) {
                throw ValidationException::withMessages(['participants' => 'One or more selected participants are unavailable in this webinar.']);
            }
            foreach ($participants as $participant) {
                $this->assertRecipient($participant, $eligibility);
            }
            // A single submission never sends twice to the same normalized mailbox.
            $participants = $participants->unique(fn ($p) => Str::lower(trim($p->email)));
            $batch = ParticipantMessageBatch::query()->create([
                'webinar_id' => $locked->id, 'created_by' => $request->user()->id,
                'submission_key' => $data['submission_key'], 'request_digest' => $digest,
                'recipient_count' => $participants->count(),
            ]);
            foreach ($participants as $participant) {
                $delivery = $notifications->queue($locked, $participant, 'participant_message', $participant->email, $data['subject'],
                    view('emails.participant-message', ['messageBody' => $data['body'], 'webinar' => $locked])->render());
                $delivery->update(['participant_message_batch_id' => $batch->id]);
            }

            return $batch->recipient_count;
        }, attempts: 3);
        $audit->record($request, 'participant-message.queued', $webinar, ['recipient_count' => $count]);

        return redirect()->route('admin.webinars.email-logs.index', $webinar)->with('success', "Message queued for {$count} recipients. Repeated submission does not create additional deliveries.");
    }

    public function retry(Request $request, Webinar $webinar, string $delivery, EligibilityService $eligibility, NotificationService $notifications, AuditService $audit)
    {
        DB::transaction(function () use ($request, $webinar, $delivery, $eligibility, $notifications): void {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $this->assertSendable($locked);
            $original = $locked->emailDeliveries()->where('public_id', $delivery)->where('type', 'participant_message')->firstOrFail();
            $participant = $locked->participants()->whereKey($original->participant_id)->lockForUpdate()->firstOrFail();
            $original = EmailDelivery::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if (EmailDelivery::query()->where('retry_of_delivery_id', $original->id)->exists()) {
                return;
            }
            abort_unless($original->status === 'failed' && filled($original->payload['html'] ?? null), 409, 'Only a failed message with retained content can be retried.');
            if (str_starts_with((string) $original->last_error, 'Provider outcome unknown')) {
                $request->validate(['provider_reconciled' => ['accepted']]);
            }
            $this->assertRecipient($participant, $eligibility);
            abort_unless(hash_equals(Str::lower(trim($original->recipient_email ?? '')), Str::lower(trim($participant->email))), 409, 'The recipient address changed. Compose a new message.');
            $retry = $notifications->queue($locked, $participant, 'participant_message', $participant->email, $original->subject, $original->payload['html']);
            $retry->update(['participant_message_batch_id' => $original->participant_message_batch_id, 'retry_of_delivery_id' => $original->id]);
        }, attempts: 3);
        $audit->record($request, 'participant-message.retry', $webinar, ['delivery' => $delivery, 'provider_reconciled' => $request->boolean('provider_reconciled')]);

        return back()->with('success', 'Message retry queued. See the email logs for its result.');
    }

    private function assertSendable(Webinar $webinar): void
    {
        abort_if($webinar->isUploadOnly() || ! in_array($webinar->status, ['published', 'completed'], true)
            || $webinar->archived_at || $webinar->deletion_started_at || ! $webinar->retention_due_at?->isFuture(), 409, 'Messages require an open or completed webinar within its retention period.');
    }

    private function assertRecipient(Participant $participant, EligibilityService $eligibility): void
    {
        if ($participant->privacy_erased_at || ! filter_var($participant->email, FILTER_VALIDATE_EMAIL) || ! $eligibility->evaluate($participant)['eligible']) {
            throw ValidationException::withMessages(['participants' => 'Every recipient must still be eligible and have a valid email address. Refresh the list and select again.']);
        }
    }
}
