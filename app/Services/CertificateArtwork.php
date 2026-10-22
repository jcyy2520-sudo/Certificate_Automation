<?php

namespace App\Services;

use RuntimeException;

final class CertificateArtwork
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MAX_EDGE = 6000;

    public const MAX_PIXELS = 12000000;

    /** Inspect headers only; never allocate a full decoded bitmap to validate it. */
    public static function inspect(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('Certificate artwork must be at most 8 MB. Export a smaller PNG or JPG and upload it again.');
        }

        $size = @getimagesizefromstring($contents);
        if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $size[0] < 1 || $size[1] < 1) {
            throw new RuntimeException('Certificate artwork must be a valid PNG or JPG. Upload the design again.');
        }
        if ($size[0] > self::MAX_EDGE || $size[1] > self::MAX_EDGE || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Certificate artwork must be at most 6,000 pixels on either side and 12 megapixels in total. Export a smaller PNG or JPG and upload it again.');
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1], 'mime' => $size['mime']];
    }
}
