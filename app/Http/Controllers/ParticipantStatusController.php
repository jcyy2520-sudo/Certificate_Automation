<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\AuditService;
use App\Services\CertificateFileService;
use App\Services\EligibilityService;
use App\Services\ParticipantStatusLinkService;
use App\Services\PublicFormResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ParticipantStatusController extends Controller
{
    public function entry(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantStatusLinkService $statusLinks,
    ): View|RedirectResponse {
        $form = $forms->resolve($token);

        if ($statusLinks->participant($request, $form->webinar)) {
            return redirect()->route('forms.public.status.view', $form->public_token);
        }

        return view('public.status-access', ['form' => $form]);
    }

    public function request(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantStatusLinkService $statusLinks,
    ): RedirectResponse {
        $form = $forms->resolve($token);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $email = Str::lower(trim($data['email']));
        $statusLinks->request($form->webinar, $email);

        // Kept in the participant's own session so the success screen and its
        // resend button survive a refresh; cleared once they confirm.
        $request->session()->put('pf_status_email', $email);

        // This response never reveals whether the address has a participant row.
        return redirect()
            ->route('forms.public.status', ['token' => $form->public_token, 'sent' => 1])
            ->with('participant_status_requested', true);
    }

    public function confirm(string $token, PublicFormResolver $forms): View
    {
        $form = $forms->resolve($token);

        return view('public.status-confirm', ['form' => $form]);
    }

    public function consume(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantStatusLinkService $statusLinks,
    ): RedirectResponse {
        $form = $forms->resolve($token);
        $rawToken = $request->input('access_token');

        if (! is_string($rawToken) || ! $statusLinks->consume($request, $form->webinar, $rawToken)) {
            return redirect()
                ->route('forms.public.status', $form->public_token)
                ->with('participant_status_error', true);
        }

        $request->session()->forget('pf_status_email');

        return redirect()->route('forms.public.status.view', $form->public_token);
    }

    public function show(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantStatusLinkService $statusLinks,
        EligibilityService $eligibility,
    ): View|RedirectResponse {
        $form = $forms->resolve($token);
        $participant = $statusLinks->participant($request, $form->webinar);

        if (! $participant) {
            return redirect()
                ->route('forms.public.status', $form->public_token)
                ->with('participant_status_error', true);
        }

        $participant->load([
            'submissions.form:id,webinar_id,type,title',
            'certificates' => fn ($query) => $query
                ->where('status', 'issued')
                ->whereNotNull('issued_at')
                ->whereNull('revoked_at')
                ->select([
                    'id', 'public_id', 'participant_id', 'webinar_id', 'verification_code',
                    'status', 'issued_at', 'revoked_at', 'privacy_erased_at',
                ]),
        ]);
        $evaluation = $eligibility->evaluate($participant);
        $certificate = $participant->certificates->first(
            fn (Certificate $certificate): bool => $certificate->isPubliclyValid(),
        );

        return view('public.status', [
            'form' => $form,
            'participant' => $participant,
            'evaluation' => $evaluation,
            'certificate' => $certificate,
        ]);
    }

    public function download(
        Request $request,
        string $token,
        string $certificatePublicId,
        PublicFormResolver $forms,
        ParticipantStatusLinkService $statusLinks,
        CertificateFileService $certificateFiles,
        AuditService $audit,
    ): StreamedResponse|RedirectResponse {
        $form = $forms->resolve($token);
        $participant = $statusLinks->participant($request, $form->webinar);

        if (! $participant) {
            return redirect()
                ->route('forms.public.status', $form->public_token)
                ->with('participant_status_error', true);
        }

        $certificate = Certificate::query()
            ->where('public_id', $certificatePublicId)
            ->where('webinar_id', $form->webinar_id)
            ->where('participant_id', $participant->id)
            ->firstOrFail();
        abort_unless($certificate->isPubliclyValid(), 404);

        $response = $certificateFiles->download($certificate);
        $audit->record($request, 'certificate.downloaded', $certificate);

        return $response;
    }
}
