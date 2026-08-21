<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CertificateFileService
{
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
