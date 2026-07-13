<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-member Financial Statement. Guards the data-truth fix (Benefits Received
 * counts only approved/disbursed claims) and the print polish (landscape so the
 * wide month grid is legible, readable base font).
 */
class MemberStatementTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
    }

    public function testBenefitsReceivedCountsApprovedOnly(): void
    {
        $this->assertStringContainsString(
            "FROM death_expenses WHERE member_id = ? AND status = 'approved'",
            $this->src,
            'Benefits Received / history must exclude pending or rejected claims.'
        );
    }

    public function testStatementPrintsLandscapeAndReadable(): void
    {
        $this->assertStringContainsString('@page { size: A4 landscape; }', $this->src);
        // the tiny 10px print fonts are gone
        $this->assertStringNotContainsString('font-size: 10px', $this->src);
        $this->assertStringNotContainsString('font-size: 7.5px', $this->src);
    }

    public function testGridFlowsOntoPageOne(): void
    {
        // the tall 2.2cm tfoot spacer that pushed the grid whole to page 2 is gone
        $this->assertStringNotContainsString('height: 2.2cm', $this->src);
        // the grid card may flow up onto page 1 instead of jumping to page 2
        $this->assertStringContainsString('.vk-grid-card { page-break-inside: auto', $this->src);
    }
}
