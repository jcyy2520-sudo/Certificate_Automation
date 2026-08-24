<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Webinar;
use App\Services\ParticipantMagicLinkService;
use App\Services\PublicFormResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The only participant-facing surface in the application.
 *
 * A form's unguessable link opens that form and nothing else: there is no
 * navigation, no event listing, no account, and no way to reach any other
 * record from here.
 */
class PublicFormController extends Controller
{
    public const PRIVACY_NOTICE_VERSION = '2026-08-20';

    public const REGISTRATION_REQUIRED_MESSAGE = 'This email address is not registered for this webinar. Please use the same email address you used during registration.';

    public function show(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantMagicLinkService $magicLinks,
    ): View {
        $form = $forms->resolve($token);

        if (! $form->acceptsResponses()) {
            return view('public.closed', ['form' => $form]);
        }

        $participant = null;
        if ($form->webinar->requiresVerification()) {
            $participant = $magicLinks->participant($request, $form);

            if (! $participant) {
                return view('public.access', ['form' => $form]);
            }

            if ($form->type !== 'registration' && ! $participant->verified_at) {
                return view('public.registration-required', [
                    'form' => $form,
                    'message' => self::REGISTRATION_REQUIRED_MESSAGE,
                ]);
            }
        }

        $this->loadDefinition($form, includeCorrectAnswers: false);

        return view('public.form', ['form' => $form, 'participant' => $participant]);
    }

    public function submit(
        Request $request,
        string $token,
        PublicFormResolver $forms,
        ParticipantMagicLinkService $magicLinks,
    ): RedirectResponse {
        $form = $forms->resolve($token);

        abort_unless($form->acceptsResponses(), 403, $form->closedReason());

        $sessionParticipant = null;
        if ($form->webinar->requiresVerification()) {
            $sessionParticipant = $magicLinks->participant($request, $form);

            if (! $sessionParticipant) {
                return redirect()
                    ->route('forms.public', $form->public_token)
                    ->with('participant_access_error', true);
            }

            if ($form->type !== 'registration' && ! $sessionParticipant->verified_at) {
                return redirect()->route('forms.public', $form->public_token);
            }
        }

        $this->loadDefinition($form, includeCorrectAnswers: true);
        try {
            $initialData = $request->validate($this->rules($form), $this->messages($form));
        } catch (ValidationException $exception) {
            $this->retainSafeInput($request, $form);

            throw $exception;
        }

        $initialEmail = Str::lower(trim($initialData['email']));

        if ($sessionParticipant
            && ! hash_equals(Str::lower(trim((string) $sessionParticipant->email)), $initialEmail)) {
            $this->retainSafeInput($request, $form);

            throw ValidationException::withMessages([
                'email' => 'Use the email address verified for this session.',
            ]);
        }

        $sessionParticipantId = $sessionParticipant?->id;
        $capacityLockRequired = $form->type === 'registration'
            && $form->webinar->registration_capacity !== null;

        try {
            do {
                $retryWithCapacityLock = false;
                $submission = DB::transaction(function () use (
                    $form,
                    $request,
                    $sessionParticipantId,
                    $capacityLockRequired,
                    &$retryWithCapacityLock,
                ): ?Submission {
                    // Lock in the same webinar -> form order as the access-link
                    // and administrative paths. Unlimited webinars retain the
                    // compatible shared lock; a configured registration cap
                    // serializes only submissions which can consume a place.
                    $webinarQuery = Webinar::query()->whereKey($form->webinar_id);
                    $lockedWebinar = $capacityLockRequired
                        ? $webinarQuery->lockForUpdate()->firstOrFail()
                        : $webinarQuery->sharedLock()->firstOrFail();
                    $lockedForm = Form::query()
                        ->whereKey($form->id)
                        ->where('webinar_id', $lockedWebinar->id)
                        ->sharedLock()
                        ->firstOrFail();
                    $lockedForm->setRelation('webinar', $lockedWebinar);

                    // Capacity may have been enabled after this request resolved its
                    // public form. Release the shared lock and retry once with the
                    // exclusive webinar lock before reading or changing the count.
                    if ($lockedForm->type === 'registration'
                        && $lockedWebinar->registration_capacity !== null
                        && ! $capacityLockRequired) {
                        $retryWithCapacityLock = true;

                        return null;
                    }

                    abort_unless($lockedForm->acceptsResponses(), 403, $lockedForm->closedReason());

                    // Recheck the security mode at the same transaction boundary as
                    // the write. Turning verification on while an OFF-mode page is
                    // open must never let that stale page submit without ownership.
                    if ($lockedWebinar->requiresVerification() && ! $sessionParticipantId) {
                        return null;
                    }

                    // The page may have been open while an administrator changed a
                    // required field, answer choice, or scoring rule. Reload and
                    // validate the authoritative definition only after holding the
                    // same form lock used by every administrative definition edit.
                    $this->loadDefinition($lockedForm, includeCorrectAnswers: true);
                    $data = Validator::make(
                        $request->all(),
                        $this->rules($lockedForm),
                        $this->messages($lockedForm),
                    )->validate();
                    $email = Str::lower(trim($data['email']));
                    $data['full_name'] = trim($data['full_name']);
                    $data['organization'] = filled($data['organization'] ?? null)
                        ? trim($data['organization'])
                        : null;

                    $participant = $sessionParticipantId
                        ? Participant::query()
                            ->whereKey($sessionParticipantId)
                            ->where('webinar_id', $lockedForm->webinar_id)
                            ->whereNull('privacy_erased_at')
                            ->whereNotNull('email_verified_at')
                            ->lockForUpdate()
                            ->first()
                        : $this->participantFor($lockedForm, $email, $data);

                    // Repeat the identity check while the participant row is locked.
                    // A privacy erasure or administrative email correction racing the
                    // request must not let a stale session submit as the old identity.
                    if ($sessionParticipantId && $participant
                        && ! hash_equals(Str::lower(trim((string) $participant->email)), $email)) {
                        $participant = null;
                    }

                    if ($lockedForm->type !== 'registration' && $participant && ! $participant->verified_at) {
                        throw ValidationException::withMessages([
                            'email' => self::REGISTRATION_REQUIRED_MESSAGE,
                        ]);
                    }

                    // Erased/deleted records and exhausted attempts deliberately receive
                    // the same thank-you response as a successful submission. A public
                    // form must not be usable as an email-participation oracle.
                    if (! $participant) {
                        return null;
                    }

                    $used = Submission::query()
                        ->where('form_id', $lockedForm->id)
                        ->where('participant_id', $participant->id)
                        ->count();

                    if ($used >= $lockedForm->max_attempts) {
                        return null;
                    }

                    // A later public form cannot rewrite identity information already
                    // collected for an email address. Verified identity changes belong
                    // on an authenticated administrative path.
                    if (blank($participant->full_name)) {
                        $participant->full_name = $data['full_name'];
                    }
                    if (blank($participant->organization) && filled($data['organization'])) {
                        $participant->organization = $data['organization'];
                    }
                    $participant->last_access_at = now();

                    // Completing the registration form is what marks a participant verified.
                    if ($lockedForm->type === 'registration' && ! $participant->verified_at) {
                        $participant->verified_at = now();
                    }

                    $participant->save();

                    $submission = Submission::query()->create([
                        'form_id' => $lockedForm->id,
                        'participant_id' => $participant->id,
                        'attempt_number' => $used + 1,
                        'status' => 'submitted',
                        'submitted_at' => now(),
                        'metadata' => [
                            'privacy_notice_version' => self::PRIVACY_NOTICE_VERSION,
                            'privacy_acknowledged_at' => now()->toIso8601String(),
                        ],
                    ]);

                    $answers = [];
                    $timestamp = now();

                    foreach ($lockedForm->fields as $field) {
                        $value = $data['fields'][$field->id] ?? null;
                        $answers[] = [
                            'submission_id' => $submission->id,
                            'form_field_id' => $field->id,
                            'question_id' => null,
                            // Bulk inserts bypass Eloquent casts, so encrypt explicitly.
                            'value' => Crypt::encryptString(json_encode([$value], JSON_THROW_ON_ERROR)),
                            'is_correct' => null,
                            'awarded_points' => null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }

                    $score = 0.0;
                    $maximum = 0.0;

                    foreach ($lockedForm->questions as $question) {
                        $value = $data['questions'][$question->id] ?? null;
                        $maximum += (float) $question->points;
                        $choice = $question->choices->firstWhere('id', (int) $value);
                        $isCorrect = $question->question_type === 'text' ? null : (bool) $choice?->is_correct;
                        $awarded = $isCorrect ? (float) $question->points : 0.0;
                        $score += $awarded;

                        $answers[] = [
                            'submission_id' => $submission->id,
                            'form_field_id' => null,
                            'question_id' => $question->id,
                            'value' => Crypt::encryptString(json_encode([$value], JSON_THROW_ON_ERROR)),
                            'is_correct' => $isCorrect,
                            'awarded_points' => $question->question_type === 'text' ? null : $awarded,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }

                    if ($answers !== []) {
                        SubmissionAnswer::query()->insert($answers);
                    }

                    $submission->update([
                        'score' => $lockedForm->questions->isEmpty() ? null : $score,
                        'maximum_score' => $lockedForm->questions->isEmpty() ? null : $maximum,
                    ]);

                    return $submission;
                }, attempts: 3);

                $capacityLockRequired = $capacityLockRequired || $retryWithCapacityLock;
            } while ($retryWithCapacityLock);
        } catch (ValidationException $exception) {
            $this->retainSafeInput($request, $form);

            throw $exception;
        } catch (UniqueConstraintViolationException) {
            // A database uniqueness constraint is the final guard if two workers
            // race on a database without effective row-level locks.
            $submission = null;
        }

        $redirect = redirect()->route('forms.public.thanks', $form->public_token);

        return $submission ? $redirect->with('submission_id', $submission->id) : $redirect;
    }

    public function thanks(Request $request, string $token, PublicFormResolver $forms): View
    {
        $form = $forms->resolve($token);
        $submission = Submission::query()
            ->where('form_id', $form->id)
            ->find($request->session()->get('submission_id'));

        // Refreshing the thank-you page must not resurface someone else's result.
        $request->session()->forget('submission_id');

        return view('public.thanks', [
            'form' => $form,
            'score' => $form->show_score && $submission?->score !== null ? $submission : null,
        ]);
    }

    /** Find the registered participant, creating one only during registration. */
    private function participantFor(Form $form, string $email, array $data): ?Participant
    {
        $participant = Participant::withTrashed()
            ->where('webinar_id', $form->webinar_id)
            ->where('email_normalized', $email)
            ->lockForUpdate()
            ->first();

        if (! $participant) {
            if ($form->type !== 'registration') {
                throw ValidationException::withMessages([
                    'email' => self::REGISTRATION_REQUIRED_MESSAGE,
                ]);
            }

            $participant = Participant::withTrashed()->firstOrCreate(
                ['webinar_id' => $form->webinar_id, 'email_normalized' => $email],
                [
                    'email' => $email,
                    'full_name' => $data['full_name'],
                    'organization' => $data['organization'] ?? null,
                ],
            );

            // firstOrCreate handles a concurrent unique-key collision; acquire the
            // row lock after either creation path before enforcing attempt limits.
            $participant = Participant::withTrashed()->lockForUpdate()->findOrFail($participant->id);
        }

        if ($participant->privacy_erased_at || $participant->trashed()) {
            return null;
        }

        if ($form->type !== 'registration' && ! $participant->verified_at) {
            throw ValidationException::withMessages([
                'email' => self::REGISTRATION_REQUIRED_MESSAGE,
            ]);
        }

        return $participant;
    }

    /** @return array<string, mixed> */
    private function rules(Form $form): array
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'organization' => ['nullable', 'string', 'max:180'],
            'privacy_acknowledged' => ['required', 'accepted'],
            'fields' => $this->answerContainerRules($form->fields->pluck('id')->all()),
            'questions' => $this->answerContainerRules($form->questions->pluck('id')->all()),
        ];

        foreach ($form->fields as $field) {
            $rules['fields.'.$field->id] = array_values(array_filter([
                $field->is_required ? 'required' : 'nullable',
                in_array($field->field_type, ['text', 'textarea', 'email', 'date', 'select', 'radio'], true) ? 'string' : null,
                $field->field_type === 'email' ? 'email:rfc' : null,
                $field->field_type === 'number' ? 'numeric' : null,
                $field->field_type === 'number' ? 'regex:/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,4})?$/' : null,
                $field->field_type === 'date' ? 'date_format:Y-m-d' : null,
                $field->field_type === 'checkbox' ? ($field->is_required ? 'accepted' : 'boolean') : null,
                in_array($field->field_type, ['select', 'radio'], true) && filled($field->options)
                    ? Rule::in($field->options)
                    : null,
                in_array($field->field_type, ['text', 'textarea'], true) ? 'max:2000' : null,
                $field->field_type === 'email' ? 'max:255' : null,
                in_array($field->field_type, ['select', 'radio'], true) ? 'max:1000' : null,
            ]));
        }

        foreach ($form->questions as $question) {
            $rules['questions.'.$question->id] = array_values(array_filter([
                $question->is_required ? 'required' : 'nullable',
                $question->question_type === 'text'
                    ? 'string'
                    : 'integer',
                $question->question_type === 'text'
                    ? 'max:2000'
                    : Rule::in($question->choices->pluck('id')->all()),
            ]));
        }

        return $rules;
    }

    /**
     * Reject keys that are not part of the current locked definition. An empty
     * definition prohibits the container entirely instead of accepting arbitrary
     * nested attacker-controlled data.
     *
     * @param  array<int, int|string>  $allowedIds
     * @return array<int, mixed>
     */
    private function answerContainerRules(array $allowedIds): array
    {
        if ($allowedIds === []) {
            return ['prohibited'];
        }

        return ['sometimes', Rule::array(array_map('strval', $allowedIds))];
    }

    /** @return array<string, string> */
    private function messages(Form $form): array
    {
        $messages = [
            'full_name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'privacy_acknowledged.accepted' => 'Please acknowledge the privacy notice before submitting.',
        ];

        foreach ($form->fields as $field) {
            $messages['fields.'.$field->id.'.required'] = $field->label.' is required.';
            $messages['fields.'.$field->id.'.accepted'] = $field->label.' must be accepted.';
            $messages['fields.'.$field->id.'.in'] = 'Choose one of the listed options for '.$field->label.'.';
        }

        foreach ($form->questions as $index => $question) {
            $messages['questions.'.$question->id.'.required'] = 'Question '.($index + 1).' is required.';
            $messages['questions.'.$question->id.'.in'] = 'Choose one of the listed answers for question '.($index + 1).'.';
        }

        return $messages;
    }

    /**
     * Keep only opaque, non-identifying answers for Laravel's old-input flash.
     * Free text and participant identity never enter the session.
     */
    private function retainSafeInput(Request $request, Form $form): void
    {
        $safe = [];

        foreach ($form->fields as $field) {
            if (in_array($field->field_type, ['text', 'textarea', 'email'], true)) {
                continue;
            }

            $value = $request->input('fields.'.$field->id);
            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            $valid = match ($field->field_type) {
                'select', 'radio' => in_array($value, $field->options ?? [], true),
                'checkbox' => in_array($value, ['0', '1'], true),
                'number' => preg_match('/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,4})?$/', $value) === 1,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                default => false,
            };

            if ($valid) {
                $safe['fields'][$field->id] = $value;
            }
        }

        foreach ($form->questions->where('question_type', '!=', 'text') as $question) {
            $value = $request->input('questions.'.$question->id);
            if (is_scalar($value) && $question->choices->contains('id', (int) $value)) {
                $safe['questions'][$question->id] = (int) $value;
            }
        }

        if ((string) $request->input('privacy_acknowledged') === '1') {
            $safe['privacy_acknowledged'] = '1';
        }

        $request->request->replace($safe);
    }

    /** Load only the columns needed to render and validate a public form. */
    private function loadDefinition(Form $form, bool $includeCorrectAnswers): void
    {
        $choiceColumns = ['id', 'question_id', 'label', 'sort_order'];
        if ($includeCorrectAnswers) {
            $choiceColumns[] = 'is_correct';
        }

        $form->load([
            'fields' => fn ($query) => $query->select([
                'id', 'form_id', 'label', 'field_type', 'help_text', 'options', 'is_required', 'sort_order',
            ]),
            'questions' => fn ($query) => $query->select([
                'id', 'form_id', 'question_type', 'prompt', 'points', 'is_required', 'sort_order',
            ]),
            'questions.choices' => fn ($query) => $query->select($choiceColumns),
        ]);
    }
}
