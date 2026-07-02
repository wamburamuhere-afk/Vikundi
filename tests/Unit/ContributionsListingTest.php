<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contributions page: recording lives on the Transactions page; the page shows
 * the Contribution Analysis Grid as the single table (the old itemised list was
 * removed per the chairman's request), with a date-range statement on demand.
 * (Grid data + statement behaviour are covered by ContributionGridTest.)
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

    public function testApprovalQueueIncludesReviewedItems(): void
    {
        // The Approve button renders only for 'reviewed' rows, so the queue must
        // load them too — otherwise reviewed contributions get stranded.
        $this->assertStringContainsString("con.status IN ('pending', 'reviewed')", $this->src);
        $this->assertStringNotContainsString("WHERE con.status = 'pending'", $this->src);
    }

    public function testItemisedListRemoved(): void
    {
        // The old always-on list (and its filtered query) is gone; the grid is
        // the single table and the date-range statement covers ad-hoc filtering.
        $this->assertStringNotContainsString('$contribList', $this->src);
        $this->assertStringNotContainsString('Contributions List', $this->src);
        $this->assertStringContainsString("getUrl('contribution_statement')", $this->src);
    }
}
