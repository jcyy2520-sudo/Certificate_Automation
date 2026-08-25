@extends('emails.layout')
@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:30px;font-weight:700;">Your certificate is ready</h1>

    <p style="margin:0 0 16px;">Congratulations {{ $certificate->recipient_name ?: $participant->full_name }},</p>

    <p style="margin:0 0 24px;">You have completed every requirement for <strong>{{ $participant->webinar->title }}</strong>. Your certificate is attached to this message as a PDF.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
        <tr>
            <td style="padding:20px 24px;">
                <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#64748b;">Verification code</p>
                <p style="margin:0;font-size:18px;font-weight:700;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $certificate->verification_code }}</p>
                <p style="margin:8px 0 0;font-size:13px;line-height:20px;color:#64748b;">Issued {{ $certificate->issued_at?->format('F j, Y') }}</p>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 24px;font-size:14px;line-height:22px;">
        Keep the verification code above. Quote it if you ever need us to confirm
        this certificate or reissue it.
    </p>

    @php($supportEmail = config('webinar.support_email'))
    <p style="margin:0;font-size:13px;line-height:20px;color:#64748b;">
        Something wrong with your name or certificate? Reply to this message
        @if($supportEmail)
            or write to <a href="mailto:{{ $supportEmail }}" style="color:#1d4ed8;">{{ $supportEmail }}</a>
        @endif
        and we will sort it out.
    </p>
@endsection
