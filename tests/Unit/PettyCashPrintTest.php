<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Petty cash printouts. The list print must not leak the mobile pager or the
 * DataTables sort arrows, and an empty list should say so. The per-voucher Print
 * button already opens the dedicated voucher document (print_petty_cash.php) —
 * guarded here so that wiring stays intact.
 */
class PettyCashPrintTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/petty_cash.php');
    }

    public function testMobilePagerNeverPrints(): void
    {
        // d-md-none alone isn't honoured by print media (it leaked onto the sheet).
        $this->assertStringContainsString('d-md-none d-print-none justify-content-end', $this->src);
        $this->assertStringContainsString('#pettyCashPrevBtn, #pettyCashNextBtn, #pettyCashPageInfo { display: none', $this->src);
    }

    public function testSortArrowsHiddenInPrint(): void
    {
        $this->assertStringContainsString('.dt-column-order { display: none', $this->src);
        $this->assertStringContainsString('table.dataTable thead th.sorting:after', $this->src);
    }

    public function testEmptyListHasAMessage(): void
    {
        $this->assertStringContainsString('emptyTable:', $this->src);
    }

    public function testPerVoucherPrintOpensTheVoucherDocument(): void
    {
        // Reachability: the row action + detail view open the dedicated voucher
        // document, not the list print.
        $this->assertStringContainsString('onclick="printVoucher(${id})"', $this->src);
        $this->assertStringContainsString("getUrl('print_petty_cash') ?>?id=' + id", $this->src);
    }
}
