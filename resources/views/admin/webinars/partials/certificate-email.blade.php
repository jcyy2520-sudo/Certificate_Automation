<section id="email-configuration" class="panel mt-6 scroll-mt-6 p-5">
    <h2 class="section-title">Email Configuration</h2>
    <p class="mt-2 text-sm text-slate-500">Personalize the message sent with each participant’s certificate. Saving these settings does not generate or send certificates.</p>
    <form method="POST" action="{{ route('admin.certification.email', $webinar) }}" class="mt-5 grid gap-4">@csrf
        <div>
            <label class="field-label" for="certificate-email-subject">Email Subject</label>
            <input id="certificate-email-subject" class="field" name="certificate_email_subject" maxlength="120" value="{{ old('certificate_email_subject', $webinar->certificate_email_subject ?? \App\Models\Webinar::DEFAULT_CERTIFICATE_SUBJECT) }}">
            <x-field-error :error="$errors->first('certificate_email_subject')" />
        </div>
        <div>
            <label class="field-label" for="certificate-email-message">Email Message</label>
            <textarea id="certificate-email-message" class="field" name="certificate_email_message" rows="12" maxlength="2000">{{ old('certificate_email_message', $webinar->certificate_email_message ?? \App\Models\Webinar::DEFAULT_CERTIFICATE_MESSAGE) }}</textarea>
            <x-field-error :error="$errors->first('certificate_email_message')" />
        </div>
        <div class="text-sm leading-6 text-slate-500">
            <p>Available placeholders: <code>@{{participant_name}}</code>, <code>@{{webinar_title}}</code>, <code>@{{certificate_type}}</code>.</p>
            <p>Plain text and line breaks are preserved. Leave a field blank to use its professional default. Existing <code>{participant}</code> and <code>{webinar}</code> placeholders still work.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button class="button-primary">Save email configuration</button>
            <button class="button-secondary" formaction="{{ route('admin.certification.email-preview', $webinar) }}" formtarget="_blank" data-preview-submit>Preview Email</button>
        </div>
        <p class="text-xs text-slate-500">Preview opens in a new tab and resolves your current draft using Sample Participant. It saves nothing and sends nothing.</p>
    </form>

    <div class="mt-6 border-t border-slate-200 pt-5">
        <p class="text-sm"><strong>Sender:</strong> {{ $emailSender['name'] }} &lt;{{ $emailSender['address'] }}&gt;</p>
        @if(config('webinar.email.provider') === 'log')<p class="mt-2 text-sm text-amber-700">Email is in log mode. Test deliveries are recorded locally; no message leaves the application.</p>@endif
        <p class="mt-2 text-sm text-slate-500">Test uses the saved email configuration and certificate design. It sends a clearly marked sample PDF only to your administrator address: <strong>{{ auth()->user()->email }}</strong>. Save changes before testing.</p>
        <form method="POST" action="{{ route('admin.certification.email-test', $webinar) }}" class="mt-4" data-confirm="Queue one sample certificate email to {{ auth()->user()->email }}? It uses saved settings and does not issue a participant certificate." data-confirm-title="Send test email" data-confirm-action="Queue test email">@csrf
            <input type="hidden" name="test_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="confirmed" value="1">
            <button class="button-secondary" @disabled(! $template->background_path)>Send Test Email</button>
        </form>
        @unless($template->background_path)<p class="mt-2 text-sm text-slate-500">Upload a certificate design before testing the PDF attachment.</p>@endunless
        <a class="mt-4 inline-block text-sm font-medium text-accent-700 underline" href="{{ route('admin.webinars.email-logs.index', $webinar) }}">View pending, sent and failed deliveries</a>
    </div>
</section>
