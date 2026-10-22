<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * A sensitive action that the password-confirmation step-up interrupted.
 *
 * The administrator pressed a button; the application asked for the password
 * before letting the action through, and then dropped the action itself. The
 * administrator confirmed the password, landed back on the same page, and
 * nothing had happened — with nothing on screen saying so. Every gated form
 * read as broken, and the workflow that depends on one (record an eligibility
 * override, then send the certificate) was impossible to complete.
 *
 * The action is therefore remembered in the administrator's own session and
 * replayed once, immediately, after the password is proven. Only a body that
 * is small, free of uploads and same-origin is remembered, and it is consumed
 * the first time it is used.
 */
class PendingSensitiveAction
{
    public const SESSION_KEY = 'auth.pending_sensitive_action';

    /** Marks a request as the replay of an action, so it is not charged twice. */
    public const REPLAY_ATTRIBUTE = 'password_confirmation_replay';

    /** The largest remembered body. Anything larger is not worth replaying. */
    private const MAX_BODY_BYTES = 65536;

    /** How long a remembered action stays replayable. */
    private const MAX_AGE_SECONDS = 900;

    /**
     * What the administrator was trying to do, per route. The confirmation
     * screen names the action so nobody wonders what the password is for.
     */
    private const LABELS = [
        'admin.users.update' => 'update account access',
        'admin.webinars.administrative-access.store' => 'authorize administrator webinar access',
        'admin.webinars.protection' => 'update webinar password protection',
        'admin.certification.email' => 'save certificate email configuration',
        'admin.certification.email-test' => 'queue a sample certificate email to your administrator address',
        'admin.participants.override' => 'record an eligibility override',
        'admin.participants.override.destroy' => 'remove an eligibility override',
        'admin.participants.store' => 'add a participant',
        'admin.participants.name' => 'correct a participant name',
        'admin.participants.merge' => 'merge two participant records',
        'admin.participants.destroy' => 'permanently delete a participant',
        'admin.certificates.issue-selected' => 'queue certificates for sending',
        'admin.certificates.manual.update' => 'correct a pending manual certificate recipient',
        'admin.certificates.manual.destroy' => 'remove a manual certificate exception',
        'admin.certificates.store' => 'issue a certificate',
        'admin.certificates.resend' => 'resend a certificate email',
        'admin.certificates.revoke' => 'revoke a certificate',
        'admin.certificates.batch' => 'send certificates to everyone who is ready',
        'admin.certification.rules' => 'change the certificate requirements',
        'admin.certification.template' => 'replace the certificate design',
        'admin.imports.preview' => 'preview an import file',
        'admin.imports.dry-run' => 'dry-run an import',
        'admin.imports.store' => 'commit an import',
        'admin.imports.cancel' => 'cancel an import',
        'admin.import-issues.promote' => 'promote a held import row',
        'admin.import-issues.replace' => 'replace a held import row',
        'admin.import-issues.dismiss' => 'dismiss a held import row',
        'admin.webinars.archive' => 'archive a webinar',
        'admin.webinars.destroy' => 'delete a webinar',
        'admin.forms.rotate-link' => 'rotate a form link',
    ];

    /**
     * Remember the action a password confirmation interrupted.
     *
     * @return bool whether the action can be replayed later
     */
    public function remember(Request $request): bool
    {
        // Never retain a newly chosen password in a replayable session body.
        if ($request->hasAny(['password', 'password_confirmation', 'webinar_password', 'webinar_password_confirmation'])) {
            $this->forget($request);

            return false;
        }
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        // An upload cannot be replayed from a session: the file itself is gone
        // by the time the password is confirmed.
        if ($request->allFiles() !== []) {
            return false;
        }

        $body = $request->except(['_token']);
        $encoded = json_encode($body);
        if ($encoded === false || strlen($encoded) > self::MAX_BODY_BYTES) {
            return false;
        }

        $request->session()->put(self::SESSION_KEY, [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'body' => $body,
            'referer' => (string) $request->headers->get('referer'),
            'label' => $this->label($request),
            'at' => now()->timestamp,
        ]);

        return true;
    }

    /**
     * The remembered action, if it is still replayable.
     *
     * @return array{method:string,url:string,body:array,referer:string,label:string,at:int}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! isset($pending['url'], $pending['method'], $pending['at'])) {
            $this->forget($request);

            return null;
        }

        if (now()->timestamp - (int) $pending['at'] > self::MAX_AGE_SECONDS) {
            $this->forget($request);

            return null;
        }

        // The remembered address must still point at this application.
        if (parse_url((string) $pending['url'], PHP_URL_HOST) !== $request->getHost()) {
            $this->forget($request);

            return null;
        }

        return $pending;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Perform the remembered action exactly once, in the administrator's own
     * name, now that the password has been proven.
     */
    public function replay(Request $request): ?Response
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return null;
        }

        // Consumed before the action runs: a remembered action is never
        // replayed twice, whatever the action itself does.
        $this->forget($request);
        $request->session()->forget('url.intended');

        $session = $request->session();
        $replay = Request::create(
            (string) $pending['url'],
            (string) $pending['method'],
            (array) ($pending['body'] ?? []),
            [],
            [],
            array_filter([
                'HTTP_REFERER' => (string) ($pending['referer'] ?? ''),
                // The confirmation is the proof of intent; the request carries
                // the current session's token so the replay is not a forgery.
                'HTTP_X_CSRF_TOKEN' => $session->token(),
            ]),
        );

        // The replay is the action the administrator already submitted, so it
        // must not be charged a second time against the sensitive-action rate
        // limit.
        $replay->attributes->set(self::REPLAY_ATTRIBUTE, true);

        // The replayed request has to be the current request for the duration
        // of the action: the framework resolves the signed-in administrator
        // from it, and the URL generator builds "back" links from it. The
        // administrator's own request is restored afterwards.
        $container = app();
        $original = $container->bound('request') ? $container->make('request') : null;
        $container->instance('request', $replay);

        try {
            $response = app(Router::class)->dispatch($replay);
        } finally {
            if ($original instanceof Request) {
                $container->instance('request', $original);
            }
        }

        $this->restoreFlash($session);

        return $response;
    }

    /**
     * The outer request saves the session once more when it leaves the
     * controller, which would age the replay's message out of existence.
     * Carry the flash keys back into the "new" bag so the next request — the
     * page the administrator is sent to — still sees them.
     */
    private function restoreFlash(Store $session): void
    {
        $old = (array) $session->get('_flash.old', []);
        $new = (array) $session->get('_flash.new', []);

        $session->put('_flash.old', []);
        $session->put('_flash.new', array_values(array_unique(array_merge($new, $old))));
    }

    /** A plain-language description of what the interrupted action does. */
    private function label(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && isset(self::LABELS[$name])
            ? self::LABELS[$name]
            : 'complete the action you started';
    }
}
