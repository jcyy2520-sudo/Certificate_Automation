<?php

namespace App\Console\Commands;

use App\Services\CsvImportService;
use Illuminate\Console\Command;

class PruneStalePendingImports extends Command
{
    protected $signature = 'imports:prune-stale';

    protected $description = 'Cancel stale pending CSV imports and delete their temporary source files';

    public function handle(CsvImportService $imports): int
    {
        try {
            $count = $imports->pruneStalePending();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("{$count} stale pending import(s) cancelled.");

        return self::SUCCESS;
    }
}
