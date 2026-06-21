<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for api/get_death_expenses.php
 *
 * Bug fixed: the listing/count queries used an INNER `JOIN customers` on
 * d.member_id = c.customer_id. A pending death_expense whose member no longer
 * exists in customers (e.g. member_id 20) was silently dropped, so the
 * dashboard "Funeral Supports" chip (which counts the raw death_expenses table)
 * showed 2 while the page listed only 1.
 *
 * Fix: use LEFT JOIN so every death_expenses row appears (with a graceful
 * member-name fallback), and make the search condition NULL-safe so orphan
 * rows still match an empty search. The page count then matches the chip.
 */
class DeathExpensesListQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/get_death_expenses.php');
    }

    // ── All customer joins must be LEFT JOIN (never a row-dropping INNER) ──────

    public function test_uses_left_join_customers(): void
    {
        $this->assertStringContainsString(
            'LEFT JOIN customers c ON d.member_id = c.customer_id',
            $this->src,
            'Customer join must be LEFT so orphan-member rows are not dropped'
        );
    }

    public function test_no_inner_join_customers_remains(): void
    {
        // Every "JOIN customers" occurrence must be preceded by LEFT.
        $innerJoins = preg_match_all('/(?<!LEFT\s)JOIN\s+customers\s+c\s+ON/i', $this->src);
        $this->assertSame(
            0,
            $innerJoins,
            'No bare INNER "JOIN customers" may remain — it silently hides pending rows'
        );
    }

    // ── Search must stay NULL-safe so orphan rows match an empty search ───────

    public function test_search_condition_is_null_safe(): void
    {
        $this->assertStringContainsString(
            "COALESCE(c.first_name,'') LIKE :q",
            $this->src,
            'Search must COALESCE NULL customer fields so LEFT-joined orphan rows still appear'
        );
    }

    // ── Member name must fall back gracefully for missing customers ───────────

    public function test_member_name_has_fallback(): void
    {
        $this->assertStringContainsString(
            "'Unknown Member'",
            $this->src,
            'member_name must fall back (deceased_name / Unknown Member) when the customer is gone'
        );
    }
}
