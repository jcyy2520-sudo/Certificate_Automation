<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

class QrCodeService
{
    /**
     * Render a PNG data URI for embedding in HTML or PDF output.
     *
     * Returns null when the runtime cannot produce an image (for example when the
     * GD extension is unavailable) so certificate issuance never depends on it.
     */
    public function dataUri(string $data, int $size = 220): ?string
    {
        try {
            $result = (new Builder(
                writer: new PngWriter,
                data: $data,
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: $size,
                margin: 4,
            ))->build();

            return $result->getDataUri();
        } catch (Throwable $exception) {
            // MFA QR input contains the TOTP secret. Exception messages from a
            // renderer are untrusted and may echo that input, so record only
            // non-secret diagnostics and a keyed correlation fingerprint.
            Log::warning('QR code generation failed.', [
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'context_fingerprint' => hash_hmac('sha256', $data, (string) config('app.key')),
            ]);

            return null;
        }
    }
}
