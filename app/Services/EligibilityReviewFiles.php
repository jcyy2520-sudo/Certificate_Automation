<?php

namespace App\Services;

use App\Models\EligibilityReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EligibilityReviewFiles
{
    public function validate(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $expected = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        if (! isset($expected[$extension]) || $expected[$extension] !== $mime || $file->getSize() > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['evidence' => 'Upload a PDF, PNG or JPEG up to 2 MB with matching file content.']);
        }
        if ($extension !== 'pdf') {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || max($dimensions[0], $dimensions[1]) > 6000 || $dimensions[0] * $dimensions[1] > 12000000) {
                throw ValidationException::withMessages(['evidence' => 'Use an image up to 6,000 pixels per edge and 12 megapixels.']);
            }
        }

        return $extension;
    }

    public function delete(EligibilityReview $review): void
    {
        if (! $review->file_path) {
            return;
        }
        $prefix = 'eligibility-evidence/'.$review->webinar_id.'/';
        if (! preg_match('#^'.preg_quote($prefix, '#').'[a-f0-9-]{36}\.(pdf|png|jpg|jpeg)$#D', $review->file_path)) {
            throw new \RuntimeException('Unexpected eligibility evidence path.');
        }
        $disk = Storage::disk($review->storage_disk);
        if ($disk->exists($review->file_path) && ! $disk->delete($review->file_path)) {
            throw new \RuntimeException('Private evidence cleanup failed; its inventory is retained for retry.');
        }
    }

    public function download(EligibilityReview $review)
    {
        abort_unless($review->file_path && $review->evidence_status === 'stored', 404);

        return Storage::disk($review->storage_disk)->download($review->file_path, 'review-evidence-'.$review->public_id.'.'.$review->extension, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'private, no-store',
        ]);
    }
}
