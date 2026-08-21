@php
    $layout = $certificate->template?->layout ?? [];
    $accent = $layout['accent'] ?? '#1d4ed8';
    $heading = $layout['heading'] ?? 'Certificate of Completion';
    $body = $layout['body'] ?? 'has successfully completed all requirements of';
    $signatoryName = $layout['signatory_name'] ?? null;
    $signatoryTitle = $layout['signatory_title'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->verification_code }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; color: #0f172a; }
        .sheet { position: relative; width: 100%; height: 560px; padding: 34px; box-sizing: border-box; }
        .frame { border: 2px solid {{ $accent }}; border-radius: 6px; height: 100%; box-sizing: border-box; padding: 30px 54px; text-align: center; }
        .rule { width: 90px; height: 4px; background-color: {{ $accent }}; margin: 0 auto 20px; }
        .eyebrow { font-size: 9px; letter-spacing: 4px; text-transform: uppercase; color: {{ $accent }}; margin: 0 0 6px; }
        .heading { font-size: 30px; font-weight: bold; margin: 0 0 22px; color: #0f172a; }
        .presented { font-size: 11px; color: #64748b; margin: 0 0 6px; }
        .recipient { font-size: 34px; font-weight: bold; margin: 0 0 6px; color: {{ $accent }}; }
        .recipient-rule { border-bottom: 1px solid #cbd5e1; width: 62%; margin: 0 auto 18px; }
        .body-text { font-size: 12px; color: #475569; margin: 0 0 6px; }
        .event { font-size: 18px; font-weight: bold; margin: 0 0 6px; }
        .date { font-size: 11px; color: #64748b; margin: 0; }
        .footer { position: absolute; left: 88px; right: 88px; bottom: 62px; }
        .footer td { font-size: 9px; color: #64748b; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #94a3b8; padding-top: 5px; }
        .sig-name { font-size: 11px; font-weight: bold; color: #0f172a; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #0f172a; }
        .qr { width: 74px; height: 74px; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="frame">
            <p class="eyebrow">{{ $certificate->webinar->title }}</p>
            <div class="rule"></div>
            <h1 class="heading">{{ $heading }}</h1>

            <p class="presented">This certificate is proudly presented to</p>
            <p class="recipient">{{ $certificate->recipient_name ?: 'Participant' }}</p>
            <div class="recipient-rule"></div>

            <p class="body-text">{{ $body }}</p>
            <p class="event">{{ $certificate->webinar->title }}</p>
            <p class="date">
                @if($certificate->webinar->starts_at)
                    held {{ $certificate->webinar->starts_at->format('F j, Y') }} ·
                @endif
                issued {{ $certificate->issued_at?->format('F j, Y') }}
            </p>
        </div>

        <table class="footer" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="38%">
                    @if($signatoryName)
                        <div class="sig-line">
                            <span class="sig-name">{{ $signatoryName }}</span><br>
                            {{ $signatoryTitle }}
                        </div>
                    @endif
                </td>
                <td width="24%" align="center">
                    @if(! empty($qrCode))
                        <img class="qr" src="{{ $qrCode }}" alt="Verification QR code">
                    @endif
                </td>
                <td width="38%" align="right">
                    Verify at {{ $verificationUrl }}<br>
                    <span class="code">{{ $certificate->verification_code }}</span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
