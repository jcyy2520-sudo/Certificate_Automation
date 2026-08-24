<?php

namespace App\Http\Controllers;

use App\Services\ParticipantMagicLinkService;
use App\Services\PublicFormResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class ParticipantAccessController extends Controller
{
    public function request(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantMagicLinkService $magicLinks,
    ): RedirectResponse {
        $form = $forms->resolve($token);
        abort_unless($form->webinar->requiresVerification(), 404);
        abort_unless($form->acceptsResponses(), 403, $form->closedReason());

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $email = Str::lower(trim($data['email']));
        $magicLinks->request($form, $email);

        // The email lives in the participant's own session (not a one-shot flash)
        // so the "check your inbox" screen — and its resend button — survive a
        // page refresh. It is cleared once they confirm, or when they start over.
        $request->session()->put('pf_verify_email', $email);

        // This is intentionally identical for every validly formatted address.
        // ?sent=1 keeps the success screen on refresh without exposing the email.
        return redirect()
            ->route('forms.public', ['token' => $form->public_token, 'sent' => 1])
            ->with('participant_access_requested', true);
    }

    public function confirm(string $token, PublicFormResolver $forms): View
    {
        $form = $forms->resolve($token);
        abort_unless($form->webinar->requiresVerification(), 404);
        abort_unless($form->acceptsResponses(), 403, $form->closedReason());

        return view('public.access-confirm', ['form' => $form]);
    }

    public function consume(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantMagicLinkService $magicLinks,
    ): RedirectResponse {
        $form = $forms->resolve($token);
        abort_unless($form->webinar->requiresVerification(), 404);
        abort_unless($form->acceptsResponses(), 403, $form->closedReason());

        // Do not use the validator for the secret: validation redirects can flash
        // submitted input into session storage. Every malformed/expired/replayed
        // credential receives the same result.
        $rawToken = $request->input('access_token');

        if (! is_string($rawToken) || ! $magicLinks->consume($request, $form, $rawToken)) {
            return redirect()
                ->route('forms.public', $form->public_token)
                ->with('participant_access_error', true);
        }

        // Verified — the stored email is no longer needed.
        $request->session()->forget('pf_verify_email');

        return redirect()->route('forms.public', $form->public_token);
    }
}
