<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Group Financial Ledger printout. The table is 23+ columns wide (12 monthly
 * columns plus totals/targets), so the browser Print must go landscape and
 * compact, repeat its header per page, and print every member (not just the
 * current DataTables page). Source-guards pin those behaviours.
 */
class LedgerPrintTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/financial_ledger.php');
    }

    public function testPrintsLandscapeAndCompact(): void
    {
        $this->assertStringContainsString('@page { size: A4 landscape; }', $this->src);
        $this->assertStringContainsString('#ledgerTable { font-size: 0.6rem', $this->src);
        // numeric columns stay on one line; only the name wraps
        $this->assertStringContainsString('white-space: nowrap !important', $this->src);
        $this->assertStringContainsString('#ledgerTable td:nth-child(2), #ledgerTable th:nth-child(2) { white-space: normal', $this->src);
    }

    public function testHeaderRepeatsPerPage(): void
    {
        $this->assertStringContainsString('#ledgerTable thead { display: table-header-group', $this->src);
    }

    public function testPrintsFullRegisterNotOnePage(): void
    {
        $this->assertStringContainsString("addEventListener('beforeprint'", $this->src);
        $this->assertStringContainsString('window.ledgerTable.page.len(-1).draw()', $this->src);
        $this->assertStringContainsString("addEventListener('afterprint'", $this->src);
    }
}
