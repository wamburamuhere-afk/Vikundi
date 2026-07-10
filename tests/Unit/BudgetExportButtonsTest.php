<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The budget page's Copy / Excel / PDF export buttons were removed (no clear
 * purpose, and other pages don't carry them) — only Print remains. The pdfmake
 * libraries (only used by the PDF export) go with them.
 */
class BudgetExportButtonsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/budget.php');
    }

    public function testCopyExcelPdfExportsRemoved(): void
    {
        $this->assertStringNotContainsString('copyHtml5', $this->src);
        $this->assertStringNotContainsString('excelHtml5', $this->src);
        $this->assertStringNotContainsString('pdfHtml5', $this->src);
    }

    public function testPdfmakeLibrariesRemoved(): void
    {
        // Only the (now-removed) PDF export needed these.
        $this->assertStringNotContainsString('pdfmake', $this->src);
        $this->assertStringNotContainsString('vfs_fonts', $this->src);
    }

    public function testStandaloneMobileExcelButtonRemoved(): void
    {
        $this->assertStringNotContainsString("title=\"Export Excel\"", $this->src);
    }

    public function testPrintIsKept(): void
    {
        $this->assertStringContainsString("extend: 'print'", $this->src);
        // and the per-budget document print stays reachable
        $this->assertStringContainsString("getUrl('print_budget')", $this->src);
    }
}
