<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Members listing DataTable integrity. When a column is added to the members
 * table the DataTable "columns" config and any column-index lookups must move in
 * step, or DataTables throws "Incorrect column count" (tn/18) and the status
 * filter silently targets the wrong column. These guards would have caught the
 * regression the Reg. No. column introduced.
 */
class MembersListingDataTableTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/customers.php');
    }

    private function membersTheadThCount(): int
    {
        // Content between the members table's opening <thead ...> and </thead>.
        if (!preg_match('/id="membersTable".*?<thead[^>]*>(.*?)<\/thead>/s', $this->src, $m)) {
            $this->fail('members thead not found');
        }
        return substr_count($m[1], '<th');
    }

    private function dataTableColumnCount(): int
    {
        if (!preg_match('/"columns":\s*\[(.*?)\]/s', $this->src, $m)) {
            $this->fail('DataTable columns config not found');
        }
        return substr_count($m[1], '"orderable"');
    }

    public function testColumnConfigMatchesTheadCount(): void
    {
        $th = $this->membersTheadThCount();
        $this->assertSame(9, $th, 'members thead should have 9 columns');
        $this->assertSame(
            $th,
            $this->dataTableColumnCount(),
            'DataTable "columns" entries must match the number of <th> — mismatch is DataTables tn/18'
        );
    }

    public function testStatusFilterTargetsTheStatusColumn(): void
    {
        // Status sits at index 7 after the Reg. No. column was inserted at 3.
        $this->assertStringContainsString('table.column(7).search', $this->src);
        $this->assertStringNotContainsString('table.column(6).search', $this->src);
    }

    public function testPrintBlankSpaceResetIsGlobal(): void
    {
        // The blank band at the top of in-page printouts came from a screen-only
        // top margin on the content wrapper leaking into print; header.php's print
        // block must zero it (one fix for every in-page printout).
        $h = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertStringContainsString('.container-fluid.px-4.mt-3, .container.mt-4', $h);
    }
}
