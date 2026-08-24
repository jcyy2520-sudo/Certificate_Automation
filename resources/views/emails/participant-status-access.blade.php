<!doctype html>
<html lang="en">
<body style="margin:0;background:#f5f5f4;color:#0f172a;font-family:Arial,sans-serif">
    <div style="max-width:560px;margin:0 auto;padding:40px 24px">
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:32px">
            <h1 style="margin:0 0 16px;font-size:22px;line-height:1.35">Secure participant status access</h1>
            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#475569">
                Someone requested a private participant-status page using this email address. If that was you, confirm access below. The link is one-time and expires in {{ $expiresInMinutes }} minutes.
            </p>
            <p style="margin:0 0 24px">
                <a href="{{ $confirmationUrl }}" style="display:inline-block;border-radius:10px;background:#2563eb;color:#ffffff;padding:12px 20px;text-decoration:none;font-weight:600">View participant status</a>
            </p>
            <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b">
                If you did not request this, ignore this email. Do not forward the link.
            </p>
        </div>
    </div>
</body>
</html>
