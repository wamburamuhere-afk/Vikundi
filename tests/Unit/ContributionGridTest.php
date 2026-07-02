<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contribution grid + date-range statement. Pure tests cover cell status, the
 * block caption (same-year + cross-year), the collection rate and the statement
 * WHERE builder; source-guards pin that the List was removed, the grid uses real
 * per-month sums, and the statement page + CSV export exist.
 */
class ContributionGridTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_grid_helpers.php';
        require_once __DIR__ . '/../../includes/contribution_statement.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure: grid helpers -------------------------------------------------

    public function testCellStatus(): void
    {
        $this->assertSame('full', vk_contribution_cell_status(20000, 20000));
        $this->assertSame('full', vk_contribution_cell_status(25000, 20000));
        $this->assertSame('partial', vk_contribution_cell_status(10000, 20000));
        $this->assertSame('none', vk_contribution_cell_status(0, 20000));
        $this->assertSame('full', vk_contribution_cell_status(5000, 0)); // no target -> any payment full
    }

    public function testBlockLabelSameYear(): void
    {
        $cols = [
            ['month_label' => 'Mar', 'year' => '2026'],
            ['month_label' => 'Apr', 'year' => '2026'],
            ['month_label' => 'May', 'year' => '2026'],
            ['month_label' => 'Jun', 'year' => '2026'],
        ];
        $this->assertSame('Mar – Jun 2026', vk_grid_block_label($cols));
    }

    public function testBlockLabelCrossYear(): void
    {
        $cols = [
            ['month_label' => 'Nov', 'year' => '2026'],
            ['month_label' => 'Dec', 'year' => '2026'],
            ['month_label' => 'Jan', 'year' => '2027'],
            ['month_label' => 'Feb', 'year' => '2027'],
        ];
        $this->assertSame('Nov 2026 – Feb 2027', vk_grid_block_label($cols));
    }

    public function testCollectionRate(): void
    {
        $this->assertSame(50, vk_collection_rate(50000, 100000));
        $this->assertSame(0, vk_collection_rate(0, 0));
    }

    // --- pure: statement filter/where --------------------------------------

    public function testStatementFiltersNormalise(): void
    {
        $f = vk_statement_filters(['from' => '2026-03-01', 'to' => 'bad-date', 'member_id' => '7', 'status' => 'approved']);
        $this->assertSame('2026-03-01', $f['from']);
        $this->assertSame('', $f['to']);           // invalid date dropped
        $this->assertSame(7, $f['member_id']);
        $this->assertSame('approved', $f['status']);
    }

    public function testStatementWhereBuildsParams(): void
    {
        $f = vk_statement_filters(['from' => '2026-03-01', 'to' => '2026-06-30', 'member_id' => '7', 'status' => 'approved']);
        $params = [];
        $where = vk_statement_where($f, $params);
        $this->assertStringContainsString('con.contribution_date >= ?', $where);
        $this->assertStringContainsString('con.member_id = ?', $where);
        $this->assertSame(['2026-03-01', '2026-06-30', 7, 'approved'], $params);
    }

    // --- wiring (source guards) --------------------------------------------

    public function testListRemovedGridUsesRealData(): void
    {
        $p = $this->src('app/bms/customer/manage_contributions.php');
        $this->assertStringNotContainsString('Contributions List', $p);           // list gone
        $this->assertStringContainsString("DATE_FORMAT(contribution_date, '%Y-%m')", $p); // real per-month sums
        $this->assertStringContainsString('vk_contribution_cell_status', $p);
        $this->assertStringContainsString('vk-sticky-col', $p);                    // sticky member column
    }

    public function testStatementPageAndExportExist(): void
    {
        $this->assertStringContainsString("'contribution_statement'", $this->src('roots.php'));
        $page = $this->src('app/bms/customer/contribution_statement.php');
        $this->assertStringContainsString('vk_statement_where', $page);
        $exp = $this->src('api/export_contributions_statement.php');
        $this->assertStringContainsString("text/csv", $exp);
        $this->assertStringContainsString('\xEF\xBB\xBF', $exp);                    // Excel BOM (source literal)
        $this->assertStringContainsString("canView('manage_contributions')", $exp); // leadership-gated
    }
}
