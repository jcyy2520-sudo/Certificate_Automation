<?php

namespace Tests\Unit;

use App\Support\CsvSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    #[Test]
    public function it_leaves_safe_cells_unchanged(): void
    {
        $this->assertSame('Normal Text', CsvSanitizer::safeCell('Normal Text'));
        $this->assertSame('john.doe@example.com', CsvSanitizer::safeCell('john.doe@example.com'));
        $this->assertSame('12345', CsvSanitizer::safeCell('12345'));
        $this->assertSame('A - B = C', CsvSanitizer::safeCell('A - B = C'));
        $this->assertSame('', CsvSanitizer::safeCell(''));
        $this->assertSame('', CsvSanitizer::safeCell(null));
    }

    #[Test]
    public function it_prefixes_formula_trigger_characters_with_single_quote(): void
    {
        $this->assertSame("'=1+1", CsvSanitizer::safeCell('=1+1'));
        $this->assertSame("'+SUM(A1:B2)", CsvSanitizer::safeCell('+SUM(A1:B2)'));
        $this->assertSame("'-10", CsvSanitizer::safeCell('-10'));
        $this->assertSame("'@mention", CsvSanitizer::safeCell('@mention'));
    }

    #[Test]
    public function it_neutralizes_formula_triggers_with_leading_whitespace_or_control_characters(): void
    {
        $this->assertSame("'  =cmd|'/C calc'!A0", CsvSanitizer::safeCell("  =cmd|'/C calc'!A0"));
        $this->assertSame("'\t+1+1", CsvSanitizer::safeCell("\t+1+1"));
        $this->assertSame("'\r\n-cmd", CsvSanitizer::safeCell("\r\n-cmd"));
        $this->assertSame("' \t @SUM(1,2)", CsvSanitizer::safeCell(" \t @SUM(1,2)"));
    }

    #[Test]
    public function it_strips_null_bytes_and_neutralizes_formulas(): void
    {
        $this->assertSame('Clean Text', CsvSanitizer::safeCell("Clean\0 Text"));
        $this->assertSame("'=1+1", CsvSanitizer::safeCell("\0=1+1"));
        $this->assertSame("'  =cmd", CsvSanitizer::safeCell("  \0=cmd"));
    }
}
