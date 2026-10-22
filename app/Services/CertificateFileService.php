<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Webinar;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class CertificateFileService
{
    /**
     * Create a temporary ZIP archive containing all issued, non-revoked certificate PDFs.
     *
     * @return array{path: string, count: int}|null
     */
    public function createZipArchive(Webinar $webinar): ?array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'webinar_certs_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for certificate export.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to initialize zip archive.');
        }

        $count = 0;
        $sources = [];
        $closed = false;
        $ready = false;
        try {
            // addFromString retains every PDF until close(), even with chunked
            // model reads. File-backed sources keep PDF bytes out of PHP memory.
            $webinar->certificates()
                ->whereNull('revoked_at')
                ->where('status', 'issued')
                ->whereNotNull('file_path')
                ->chunkById(100, function ($certificates) use ($zip, &$count, &$sources): void {
                    foreach ($certificates as $certificate) {
                        $path = str_replace('\\', '/', (string) $certificate->file_path);
                        $expected = 'certificates/'.$certificate->public_id.'.pdf';

                        // Verify safe path structure
                        if (! hash_equals($expected, $path)) {
                            continue;
                        }

                        $disk = Storage::disk($certificate->storage_disk);
                        if (! $disk->exists($path)) {
                            continue;
                        }

                        $recipientName = trim(preg_replace('/[^\w\s\.-]/u', '', (string) ($certificate->recipient_name ?: 'Participant')) ?? '');
                        $recipientName = trim(preg_replace('/\s+/', '_', $recipientName) ?? '') ?: 'Participant';
                        $entryName = "{$recipientName}_{$certificate->verification_code}.pdf";

                        $source = $disk instanceof LocalFilesystemAdapter ? $disk->path($path) : $this->spoolPdf($disk, $path, $sources);
                        if ($source === null) {
                            continue;
                        }
                        if (! $zip->addFile($source, $entryName)) {
                            throw new RuntimeException('Unable to add a certificate to the ZIP archive.');
                        }
                        $count++;
                    }
                });

            $closed = true;
            if (! $zip->close()) {
                throw new RuntimeException('Unable to finish the certificate ZIP archive.');
            }
            if ($count === 0) {
                return null;
            }

            $ready = true;

            return ['path' => $tempFile, 'count' => $count];
        } finally {
            try {
                if (! $closed) {
                    $zip->close();
                }
            } finally {
                foreach ($sources as $source) {
                    @unlink($source);
                }
                if (! $ready) {
                    @unlink($tempFile);
                }
            }
        }
    }

    /** Remote PDFs need a private local source that survives until ZIP close. */
    private function spoolPdf(FilesystemAdapter $disk, string $path, array &$sources): ?string
    {
        $input = $disk->readStream($path);
        if (! is_resource($input)) {
            return null;
        }
        try {
            $source = tempnam(sys_get_temp_dir(), 'webinar_pdf_');
            if ($source === false) {
                throw new RuntimeException('Unable to prepare a temporary certificate export source.');
            }
            $sources[] = $source;
            $output = fopen($source, 'wb');
            if ($output === false) {
                throw new RuntimeException('Unable to open a temporary certificate export source.');
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('Unable to read a certificate for export.');
                }
            } finally {
                fclose($output);
            }

            return $source;
        } finally {
            fclose($input);
        }
    }

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
