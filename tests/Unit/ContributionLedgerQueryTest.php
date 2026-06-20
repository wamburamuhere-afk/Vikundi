<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for api/get_contribution_ledger.php
 *
 * Bug fixed: the customer query used `customers.status = 'active'` which matched
 * only 6 customers with no contributions, instead of joining with the users table
 * where 15 members have u.status = '' (empty) and 1 has u.status = 'active'.
 * Fix: JOIN users and filter u.status IN ('active', '') so all 16 registered
 * members appear in the grid with their real contribution data.
 */
class ContributionLedgerQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/get_contribution_ledger.php');
    }

    // ── Customer query must JOIN users table ──────────────────────────────────

    public function test_query_joins_users_table(): void
    {
        $this->assertStringContainsString(
            'JOIN users u ON c.user_id = u.user_id',
            $this->src,
            'Customer query must JOIN users so we filter on users.status, not customers.status'
        );
    }

    public function test_query_uses_user_status_not_customer_status(): void
    {
        $this->assertStringContainsString(
            "u.status IN ('active', '')",
            $this->src,
            'Must filter on users.status to include members with empty status'
        );
    }

    public function test_query_excludes_deceased_members(): void
    {
        $this->assertStringContainsString(
            'c.is_deceased = 0',
            $this->src,
            'Must exclude deceased customers from the grid'
        );
    }

    // ── Old broken filter must NOT be present ─────────────────────────────────

    public function test_old_unqualified_status_filter_removed(): void
    {
        $this->assertStringNotContainsString(
            "FROM customers WHERE status = 'active'",
            $this->src,
            'Old unqualified customers.status filter must be gone — it returned wrong members'
        );
    }

    public function test_old_where_clause_string_removed(): void
    {
        $this->assertStringNotContainsString(
            '"status = \'active\'"',
            $this->src,
            'Old $where = "status = \'active\'" must not exist'
        );
    }

    // ── Contribution status filter is broad enough to catch all valid records ──

    public function test_contribution_query_includes_approved_status(): void
    {
        $this->assertStringContainsString(
            "status IN ('confirmed', 'approved', '')",
            $this->src,
            'Contribution SUM must include approved and empty-status rows, not just confirmed'
        );
    }

    public function test_contribution_query_does_not_use_confirmed_only(): void
    {
        $this->assertStringNotContainsString(
            "status = 'confirmed'",
            $this->src,
            'status = confirmed alone would return zero rows since no records have that status'
        );
    }

    // ── DataTables LIMIT uses safe integer interpolation ─────────────────────

    public function test_limit_uses_integer_interpolation_not_pdo_binding(): void
    {
        $this->assertStringNotContainsString(
            'LIMIT ?, ?',
            $this->src,
            'LIMIT must not use PDO ? binding — MySQL rejects quoted string values for LIMIT'
        );
    }
}
