<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ImportSourceFile
{
    /** Keep the durable import inventory until private-file deletion succeeds. */
    public static function delete(int $importId): void
    {
        $disk = Storage::disk('local');
        $path = 'imports-pending/import-'.$importId.'.csv';
        try {
            if (! $disk->exists($path)) {
                return;
            }
            if (! $disk->delete($path) || $disk->exists($path)) {
                throw new RuntimeException('Private import source cleanup failed. The import record was retained for retry.');
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('Private import source cleanup failed. The import record was retained for retry.', previous: $exception);
        }
    }
}
