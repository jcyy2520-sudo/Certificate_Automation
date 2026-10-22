<?php

namespace App\Http\Controllers;

use App\Models\EligibilityReview;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityReviewFiles;
use App\Services\ParticipantEligibilityService;
use App\Services\ParticipantStatusLinkService;
use App\Services\PublicFormResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ParticipantReviewController extends Controller
{
    public function store(Request $request, string $token, PublicFormResolver $forms, ParticipantStatusLinkService $links, ParticipantEligibilityService $eligibility, EligibilityReviewFiles $files, AuditService $audit)
    {
        $form = $forms->resolve($token);
        $participant = $links->participant($request, $form->webinar);
        abort_unless($participant, 403, 'Open your secure participant link before requesting a review.');
        $data = $request->validate([
            'submission_key' => ['required', 'uuid'], 'explanation' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:2048', 'mimes:pdf,png,jpg,jpeg', 'extensions:pdf,png,jpg,jpeg'],
        ]);
        $file = $request->file('evidence');
        $extension = $file ? $files->validate($file) : null;
        $review = null;
        $new = false;
        DB::transaction(function () use ($request, $data, $file, $extension, $participant, $eligibility, $audit, &$review, &$new): void {
            $webinar = Webinar::query()->whereKey($participant->webinar_id)->lockForUpdate()->firstOrFail();
            abort_if($webinar->archived_at || $webinar->deletion_started_at || ! $webinar->retention_due_at?->isFuture(), 409);
            $locked = Participant::query()->whereKey($participant->id)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->status_access_version === (int) $participant->status_access_version, 403);
            $review = $locked->eligibilityReviews()->where(fn ($q) => $q->where('status', 'pending')->orWhere('submission_key', $data['submission_key']))->first();
            if ($review) {
                return;
            }
            abort_if($locked->eligibilityReviews()->where('created_at', '>', now()->subDay())->count() >= 3, 429, 'Please wait before requesting another review.');
            $disk = (string) config('webinar.document_disk');
            abort_if($file && ($disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public'), 503);
            $review = EligibilityReview::query()->create([
                'webinar_id' => $webinar->id, 'participant_id' => $locked->id, 'submission_key' => $data['submission_key'],
                'open_key' => 'participant:'.$locked->id, 'explanation' => trim($data['explanation']),
                'evidence_fingerprint' => $eligibility->summary($locked)['fingerprint'],
                'storage_disk' => $file ? $disk : null, 'extension' => $extension, 'file_size' => $file?->getSize(),
                'evidence_status' => $file ? 'pending' : null,
                'file_path' => $file ? 'eligibility-evidence/'.$webinar->id.'/'.Str::uuid().'.'.$extension : null,
            ]);
            $new = true;
            $audit->record($request, 'eligibility.review_requested', $review, ['participant_id' => $participant->id]);
        }, attempts: 3);
        // Commit the private-file inventory before I/O. A killed request or
        // storage/commit failure cannot leave an untracked evidence object.
        $attachmentFailed = false;
        if ($new && $file) {
            try {
                DB::transaction(function () use ($participant, $review, $file): void {
                    $webinar = Webinar::query()->whereKey($participant->webinar_id)->lockForUpdate()->firstOrFail();
                    $p = Participant::query()->whereKey($participant->id)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
                    abort_if($webinar->archived_at || $webinar->deletion_started_at || ! $webinar->retention_due_at?->isFuture(), 409);
                    $r = $p->eligibilityReviews()->whereKey($review->id)->lockForUpdate()->firstOrFail();
                    abort_unless($r->status === 'pending' && $r->evidence_status === 'pending' && $r->file_path, 409);
                    if (! Storage::disk($r->storage_disk)->putFileAs(dirname($r->file_path), $file, basename($r->file_path), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Private evidence storage unavailable.');
                    }
                    $r->update(['evidence_status' => 'stored']);
                }, attempts: 1);
            } catch (\Throwable) {
                $attachmentFailed = true;
                // Preserve inventory even if storage is unavailable. Privacy
                // cleanup can retry; the UI never claims the evidence arrived.
                EligibilityReview::query()->whereKey($review->id)->where('evidence_status', 'pending')->update(['evidence_status' => 'failed']);
            }
        }

        return redirect()->route('forms.public.status.view', $token)->with('success', $attachmentFailed
            ? 'Your review request is saved, but the supporting file could not be confirmed. The organizer can see this upload failure. Your eligibility has not changed.'
            : 'Your review request is saved. The organizer will check the records; submitting a request does not change eligibility.');
    }

    public function evidence(Request $request, string $token, string $reviewPublicId, PublicFormResolver $forms, ParticipantStatusLinkService $links, EligibilityReviewFiles $files)
    {
        $form = $forms->resolve($token);
        $participant = $links->participant($request, $form->webinar);
        abort_unless($participant, 403);
        $review = $participant->eligibilityReviews()->where('public_id', $reviewPublicId)->firstOrFail();

        return $files->download($review);
    }
}
