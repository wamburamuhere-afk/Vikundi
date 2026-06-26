<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Finance PR-3: Contributions becomes the dedicated, filterable listing — the
 * recording UI (manual form + bulk/M-Koba modals) moved to the Transactions page.
 */
class ContributionsListingTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/manage_contributions.php');
    }

    public function testRecordingUiRemoved(): void
    {
        $this->assertStringNotContainsString('id="manualAddModal"', $this->src, 'record modal removed');
        $this->assertStringNotContainsString('manualAddForm', $this->src, 'record form removed');
        $this->assertStringNotContainsString('id="uploadReportModal"', $this->src, 'bulk modal removed');
        $this->assertStringNotContainsString('id="uploadMKobaModal"', $this->src, 'M-Koba modal removed');
    }

    public function testLinksToTransactionsForRecording(): void
    {
        $this->assertStringContainsString("getUrl('transactions')", $this->src);
    }

    public function testHasFilterControls(): void
    {
        foreach (['from', 'to', 'member', 'type', 'fstatus', 'account'] as $filter) {
            $this->assertStringContainsString('name="' . $filter . '"', $this->src, "filter $filter present");
        }
    }

    public function testApprovalQueueIncludesReviewedItems(): void
    {
        // The Approve button renders only for 'reviewed' rows, so the queue must
        // load them too — otherwise reviewed contributions get stranded.
        $this->assertStringContainsString("con.status IN ('pending', 'reviewed')", $this->src);
        $this->assertStringNotContainsString("WHERE con.status = 'pending'", $this->src);
    }

    public function testFilteredListIsParameterised(): void
    {
        // The list query is built with bound params, never string-interpolated input.
        $this->assertStringContainsString('$contribList', $this->src);
        $this->assertStringContainsString('$stmtList->execute($params)', $this->src);
        $this->assertStringContainsString('con.contribution_date >= ?', $this->src);
        // Filter values validated against allow-lists before use.
        $this->assertStringContainsString("['entrance', 'monthly', 'agm', 'fine', 'other']", $this->src);
        $this->assertStringContainsString("['pending', 'reviewed', 'approved', 'cancelled']", $this->src);
    }
}
