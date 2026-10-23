<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? 'Certificate email' }}</title>
</head>
<body style="margin:0;padding:24px;background-color:#ffffff;color:#000000;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">
    <div style="max-width:640px;">
        @if(isset($previewInfo))
            <p style="margin:0 0 24px;">
                <strong>Email preview — no email sent</strong><br>
                Subject: {{ $subject }}<br>Sender: {{ $previewInfo['sender'] }}<br>
                Participant: {{ $previewInfo['participant'] }}<br>Attachment: {{ $previewInfo['attachment'] }}<br>
                Close this tab to return to Certificate Setup.
            </p>
        @endif
        @if($isTest ?? false)<p style="margin:0 0 24px;">TEST EMAIL — sample certificate only. No participant certificate was issued.</p>@endif
        <p style="margin:0;" data-certificate-message>{!! nl2br(e($customMessage ?? $certificate->webinar->certificateEmailMessage($certificate->recipient_name))) !!}</p>
    </div>
</body>
</html>
