<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateFileService
{
    /** Return a private download response after validating the stored path. */
    public function download(Certificate $certificate): StreamedResponse
    {
        abort_unless($certificate->file_path, 404);

        $path = str_replace('\\', '/', $certificate->file_path);
        $expected = 'certificates/'.$certificate->public_id.'.pdf';
        abort_unless(hash_equals($expected, $path), 404);

        $disk = Storage::disk($certificate->storage_disk);
        abort_unless($disk->exists($path), 404);

        return $disk->download(
            $path,
            'certificate-'.$certificate->verification_code.'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Delete private PDFs represented by certificate rows.
     *
     * @param  iterable<Certificate>  $certificates
     */
    public function delete(iterable $certificates): void
    {
        foreach ($certificates as $certificate) {
            if (blank($certificate->file_path)) {
                continue;
            }

            $path = str_replace('\\', '/', $certificate->file_path);
            $expected = 'certificates/'.$certificate->public_id.'.pdf';

            // A compromised or malformed database row must not turn a privacy
            // cleanup into an arbitrary-file deletion primitive.
            if (! hash_equals($expected, $path)) {
                throw new RuntimeException('Refusing to delete an unexpected certificate file path.');
            }

            $disk = Storage::disk($certificate->storage_disk);

            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('A private certificate file could not be deleted.');
            }
        }
    }
}
