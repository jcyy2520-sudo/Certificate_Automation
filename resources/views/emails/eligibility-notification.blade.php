<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Your participation record</title></head><body style="font-family:Arial,sans-serif;color:#182334;line-height:1.6">
<div style="max-width:580px;margin:auto;padding:24px">
    <h1 style="font-size:22px">Your participation record</h1>
    <p>Hello {{ $participant->full_name }},</p>
    <p>Thank you for participating in <strong>{{ $webinar->title }}</strong>.</p>
    <p>Your current certificate eligibility status is <strong>{{ $summary['label'] }}</strong>. {{ $summary['explanation'] }}</p>
    @if($summary['missing'])<p>Requirements to check: {{ implode(', ', $summary['missing']) }}.</p>@endif
    @if($summary['final_reason'])<p>{{ $summary['final_reason'] }}</p>@endif
    <p><a href="{{ $personalUrl }}" style="color:#1d4ed8">View your private eligibility record</a></p>
    <p>The personal link expires in 24 hours and can be used once to open a private session. Please do not forward it. Your page shows available submission records, certificate processing status and a Request Record Review option.</p>
    <p>If something appears incorrect, submit a short explanation through that page. Missing or unmatched records are not automatically treated as proof that you did not submit. A review request does not automatically change eligibility.</p>
    <p>No certificate is attached to this notification.</p>
</div></body></html>
