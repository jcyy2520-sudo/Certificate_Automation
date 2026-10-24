<?php

namespace App\Support;

/** Bound repeated mobile labels while retaining every source column and row. */
final class ResponseTable
{
    public static function rowsPerPage(array $columns): int
    {
        $labelBytes = array_sum(array_map(fn ($column) => strlen(htmlspecialchars((string) $column['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), $columns));

        return max(1, min(25, intdiv(256 * 1024, max(1, $labelBytes))));
    }
}
