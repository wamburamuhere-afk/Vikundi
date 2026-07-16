<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Follow-up security sweep after the expense-endpoint fix (PR #287): the same
 * "ungated mutating endpoint" gap existed on more expense/transaction endpoints
 * and on bulk contribution upload, and a member could file a contribution against
 * another member. Each is now gated. (Fines and death-expense/payout endpoints
 * were already correctly gated.)
 */
class MoneyEndpointSweepTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testExpenseAndTransactionEndpointsGatedAndCsrf(): void
    {
        $endpoints = [
            'api/account/delete_expense.php'        => "requirePermissionJson('delete', 'expenses')",
            'api/account/update_expense.php'        => "requirePermissionJson('edit', 'expenses')",
            'api/account/update_expense_status.php' => "requirePermissionJson('edit', 'expenses')",
            'api/update_general_expense.php'        => "requirePermissionJson('edit', 'expenses')",
            'api/account/update_transaction.php'    => "requirePermissionJson('edit', 'journals')",
        ];
        foreach ($endpoints as $file => $gate) {
            $src = $this->src($file);
            $this->assertStringContainsString($gate, $src, "$file must gate on permission");
            $this->assertStringContainsString('require_csrf.php', $src, "$file must require CSRF");
        }
        // the one that had no login check at all now requires one
        $this->assertStringContainsString('require_auth.php', $this->src('api/update_general_expense.php'));
    }

    public function testBulkContributionUploadIsLeadershipOnly(): void
    {
        $src = $this->src('actions/upload_contributions.php');
        $this->assertStringContainsString("requirePermissionJson('create', 'manage_contributions')", $src);
        $this->assertStringContainsString('require_csrf.php', $src);
    }

    public function testContributionSubmitCannotBeFiledAgainstAnotherMember(): void
    {
        // A non-leadership user may only submit their OWN contribution — member_id
        // is forced to their own customer id, so they can't file against others.
        $src = $this->src('actions/process_contribution.php');
        $this->assertStringContainsString("canCreate('manage_contributions')", $src);
        $this->assertStringContainsString('vk_member_customer_id($pdo', $src);
    }
}
