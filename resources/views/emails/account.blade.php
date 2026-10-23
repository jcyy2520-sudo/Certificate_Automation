<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="font-family:Arial,sans-serif;color:#172033;line-height:1.6;max-width:580px;margin:32px auto;padding:20px">
<p>{{ config('branding.name') }}</p><h1 style="font-size:22px">{{ $subject }}</h1>
<p>Hello {{ $user->name }},</p><p>{{ $message }}</p>
@if($url)<p><a href="{{ $url }}" style="display:inline-block;background:#172033;color:#ffffff;padding:12px 20px;border-radius:6px">{{ $label }}</a></p>
<p>This link expires. If it is no longer available, request a new link from the sign-in page.</p>@endif
<p>Account notifications are separate from webinar participation and certificate delivery.</p>
</body></html>
