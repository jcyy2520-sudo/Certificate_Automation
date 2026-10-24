<?php

namespace Tests\Unit;

use App\Support\ResponseTable;
use PHPUnit\Framework\TestCase;

class ResponseTableTest extends TestCase
{
    public function test_normal_tables_retain_twenty_five_rows_and_very_long_wide_tables_bound_repeated_labels(): void
    {
        $this->assertSame(25, ResponseTable::rowsPerPage([]));
        $this->assertSame(25, ResponseTable::rowsPerPage(array_fill(0, 30, ['label' => 'Ordinary question'])));
        $this->assertSame(1, ResponseTable::rowsPerPage(array_fill(0, 200, ['label' => str_repeat('a', 2000)])));
        $this->assertSame(2, ResponseTable::rowsPerPage(array_fill(0, 60, ['label' => str_repeat('a', 2000)])));
    }

    public function test_label_budget_accounts_for_html_expansion_without_changing_original_labels(): void
    {
        $columns = array_fill(0, 60, ['label' => str_repeat('&', 1000)]);
        $this->assertSame(1, ResponseTable::rowsPerPage($columns));
        $this->assertSame(str_repeat('&', 1000), $columns[0]['label']);
    }
}
