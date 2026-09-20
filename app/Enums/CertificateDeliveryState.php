<?php

namespace App\Enums;

use App\Models\Certificate;
use App\Models\EmailDelivery;

enum CertificateDeliveryState
{
    case Ready;
    case NotSent;
    case MissingEmail;
    case CertificateQueued;
    case EmailQueued;
    case Sending;
    case Sent;
    case CertificateFailed;
    case EmailFailed;

    public static function from(
        ?Certificate $c,
        ?EmailDelivery $d,
        bool $hasEmail = true,
        bool $eligible = true,
    ): self {
        return match (true) {
            $c === null && ! $hasEmail => self::MissingEmail,
            $c === null && ! $eligible => self::NotSent,
            $c === null => self::Ready,
            $c->status === 'processing' => self::CertificateQueued,
            $c->status === 'failed' => self::CertificateFailed,
            $d?->status === 'processing' => self::Sending,
            in_array($d?->status, ['failed', 'cancelled'], true) => self::EmailFailed,
            $d?->status === 'sent' || $c->sent_at !== null => self::Sent,
            $c->status === 'issued' => self::EmailQueued,
            default => self::Ready,
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::Ready => 'ready',
            self::NotSent => 'not_sent',
            self::MissingEmail => 'missing_email',
            self::CertificateQueued, self::EmailQueued => 'queued',
            self::Sending => 'sending',
            self::Sent => 'sent',
            self::CertificateFailed, self::EmailFailed => 'failed',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready to send',
            self::NotSent => 'Not sent',
            self::MissingEmail => 'Email needed',
            self::CertificateQueued => 'Queued for generation',
            self::EmailQueued => 'Queued for email',
            self::Sending => 'Sending email',
            self::Sent => 'Accepted by provider',
            self::CertificateFailed => 'Generation failed',
            self::EmailFailed => 'Delivery failed',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::Ready => 'Ready to generate and email',
            self::NotSent => '',
            self::MissingEmail => 'Add an email address before sending',
            self::CertificateQueued => 'Waiting for the certificate worker',
            self::EmailQueued => "Certificate ready \u{00B7} waiting for the email worker",
            self::Sending => 'Sending to the email provider now',
            self::Sent => 'Accepted by the email provider',
            self::CertificateFailed => 'Certificate generation failed',
            self::EmailFailed => 'Email provider rejected the delivery',
        };
    }
}
