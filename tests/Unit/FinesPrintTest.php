<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fines printouts: a leadership fines register (dedicated print page, since the
 * list is a server-side DataTable) and a member's own-fines print. Source-guards
 * pin the routes, the gates, the branded header + shared footer, the filter
 * pass-through, and the print buttons.
 */
class FinesPrintTest extends TestCase
{
    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    public function testRegisterPageRoutedGatedAndBranded(): void
    {
        $this->assertStringContainsString("'fines_print'", $this->src('roots.php'));
        $p = $this->src('app/bms/customer/fines_print.php');
        $this->assertStringContainsString("requireViewPermission('manage_fines')", $p); // leadership only
        $this->assertStringContainsString('PrintHeader::render', $p);
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $p);
        // honours the member + status filters
        $this->assertStringContainsString('vk_fine_statuses', $p);
        $this->assertStringContainsString('f.customer_id = :mid', $p);
        // total prints once, not repeated per page
        $this->assertStringContainsString('tfoot { display: table-row-group', $p);
        // the date stays on one line (the long Reason column was making it wrap)
        $this->assertStringContainsString('<td class="text-nowrap"><?= $r[\'created_at\']', $p);
    }

    public function testManageFinesHasRegisterPrintButton(): void
    {
        $m = $this->src('app/bms/customer/manage_fines.php');
        $this->assertStringContainsString('printFinesRegister', $m);
        $this->assertStringContainsString("getUrl('fines_print')", $m);
        // it passes the current filters through
        $this->assertStringContainsString("member_id: $('#fMember').val()", $m);
        $this->assertStringContainsString("status: $('#fStatus').val()", $m);
    }

    public function testMyFinesHasOwnPrint(): void
    {
        $p = $this->src('app/bms/customer/my_fines.php');
        $this->assertStringContainsString('PrintHeader::render', $p);   // branded header
        $this->assertStringContainsString('window.print()', $p);        // print button
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $p);     // shared footer
        // the on-screen title card is not duplicated in print
        $this->assertStringContainsString('mb-4 d-print-none', $p);
        // summary chips stay 3-across even on phones (col-4, not stacking col-md-4)
        $this->assertStringContainsString('<div class="col-4">', $p);
        $this->assertStringNotContainsString('col-md-4', $p);
        // the table shows a single "Total owing" footer, printed once
        $this->assertStringContainsString('Total owing', $p);
        $this->assertStringContainsString('tfoot { display: table-row-group', $p);
        // the on-screen "confirmed by leadership" note is not printed
        $this->assertStringContainsString('mb-0 d-print-none"><i class="bi bi-info-circle', $p);
    }
}
