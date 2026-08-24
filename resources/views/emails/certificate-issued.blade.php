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

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
        <tr>
            <td style="background-color:#1d4ed8;border-radius:12px;">
                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">Verify this certificate</a>
            </td>
        </tr>
    </table>

    @if($statusUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="border:1px solid #1d4ed8;border-radius:12px;">
                    <a href="{{ $statusUrl }}" style="display:inline-block;padding:13px 27px;color:#1d4ed8;font-size:15px;font-weight:600;text-decoration:none;">View your progress and certificate</a>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:0;font-size:13px;line-height:20px;color:#64748b;">
        Anyone can confirm this certificate is genuine at the address above. The public page shows only the event, issue date, and validity — never your contact details or assessment results.
    </p>
@endsection
