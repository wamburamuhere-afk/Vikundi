<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Contribution Ledger grid had two problems:
 *   1. the "‹ / ›" period navigator clamped prev at block 0 (max(0, $block-1)),
 *      so it could never reach earlier months — and since the imported data is
 *      dated February (before the March start = block 0), the grid opened empty
 *      and the ‹ arrow "did nothing";
 *   2. the 327-member matrix rendered with no pagination.
 *
 * Now: the default block is the one holding the MOST RECENT contribution (opens on
 * real data), prev/next are bounded to [earliest data … current month] and disabled
 * at the edges, the month columns are computed sign-safely for negative blocks, and
 * the grid is a paginated DataTable whose search reuses the existing box.
 */
class ContributionGridNavTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/manage_contributions.php');
    }

    public function testPrevIsNoLongerClampedAtZero(): void
    {
        $this->assertStringNotContainsString('?block=<?= max(0, $block - 1) ?>', $this->src);
        // Prev is now bounded by the earliest-data block instead.
        $this->assertStringContainsString('max($min_block, $block - 1)', $this->src);
        $this->assertStringContainsString('min($max_block, $block + 1)', $this->src);
    }

    public function testBoundsComputedFromRealContributionRange(): void
    {
        $this->assertStringContainsString('MIN(contribution_date)', $this->src);
        $this->assertStringContainsString('MAX(contribution_date)', $this->src);
        $this->assertStringContainsString('$min_block', $this->src);
        $this->assertStringContainsString('$max_block', $this->src);
        $this->assertStringContainsString('$block_of', $this->src);
    }

    public function testDefaultOpensOnLatestDataBlock(): void
    {
        // No ?block= => land on the block holding the latest contribution (mx).
        $this->assertStringContainsString("isset(\$_GET['block'])", $this->src);
        $this->assertStringContainsString('$block_of($cRange[\'mx\'])', $this->src);
        // And the requested/derived block is clamped into the reachable range.
        $this->assertStringContainsString('$block = max($min_block, min($max_block, $block));', $this->src);
    }

    public function testColumnMonthsAreSignSafeForNegativeBlocks(): void
    {
        // "$idx months" works for negative idx; the old "+$idx months" produced
        // "+-4 months" and broke once prev could go back.
        $this->assertStringContainsString('strtotime("$idx months", strtotime($start_date))', $this->src);
        $this->assertStringNotContainsString('$start_date . " +$idx months"', $this->src);
    }

    public function testGridIsAPaginatedDataTableReusingTheSearchBox(): void
    {
        $this->assertStringContainsString('id="contribGrid"', $this->src);
        $this->assertStringContainsString("\$grid = \$('#contribGrid')", $this->src);
        $this->assertStringContainsString('paging: true', $this->src);
        // The existing member search box drives the DataTable, not a second box.
        $this->assertStringContainsString("gridTable.search(\$(this).val()).draw()", $this->src);
        // Never initialise on the empty "no members" colspan placeholder.
        $this->assertStringContainsString("tr.vk-grid-row').length", $this->src);
    }
}
