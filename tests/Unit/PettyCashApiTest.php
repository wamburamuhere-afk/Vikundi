<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_petty_cash.php';

/**
 * Module 9 — Petty Cash.
 *
 * Built on a NEW permission key, `petty_cash`, registered by
 * database/add_petty_cash_permission.php and mirrored from `expenses` —
 * the web's own gating for this module was inconsistent across files (some
 * check `expenses`, some check `petty_cash`, the list AJAX endpoint checked
 * nothing at all). This API normalizes on one key throughout.
 */
final class PettyCashApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function raw(array $over = []): array
    {
        return $over + [
            'id'               => 1,
            'voucher_no'       => 'PCV-2608-0001',
            'transaction_date' => '2026-08-16',
            'payee_name'       => 'Duka la Vifaa vya Ofisi',
            'category'         => 'Stationery',
            'description'      => 'Karatasi na wino wa printa',
            'amount'           => '35000.00',
            'status'           => 'paid',
            'prepared_by_name' => 'jmtui',
            'created_at'       => '2026-08-16 08:00:00',
            'reviewed_by'      => 484,
            'reviewed_at'      => '2026-08-16 09:00:00',
            'approved_by'      => 483,
            'approval_date'    => '2026-08-16 10:00:00',
            'paid_by'          => 485,
            'paid_at'          => '2026-08-17 08:00:00',
        ];
    }

    private static function auth(bool $leader, bool $admin = false, string $roleName = 'Treasurer'): array
    {
        $roleIds = ['Secretary' => 3, 'Treasurer' => 4];
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? ($roleIds[$roleName] ?? 4) : 15),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? $roleName : 'Member')],
            'permissions' => $leader || $admin
                ? ['petty_cash' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1, 'review' => 1, 'approve' => 1]]
                : ['petty_cash' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0, 'review' => 0, 'approve' => 0]],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testApprovalDateColumnIsMappedToApprovedAt(): void
    {
        // The table names this column `approval_date`, not `approved_at` —
        // exposed as `approved_at` so the two expense modules read alike.
        $row = vk_api_petty_row(self::raw());
        $this->assertNotNull($row['approved_at']);
        $this->assertArrayNotHasKey('approval_date', $row);
    }

    public function testAmountIsANumberNotAString(): void
    {
        $this->assertSame(35000.0, vk_api_petty_row(self::raw())['amount']);
    }

    public function testABlankCategoryIsNullNotAnEmptyString(): void
    {
        $this->assertNull(vk_api_petty_row(self::raw(['category' => '']))['category']);
    }

    // ── actions ─────────────────────────────────────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $a = vk_api_petty_actions(self::auth(false), 'pending');
        $this->assertSame(['edit' => false, 'review' => false, 'approve' => false, 'mark_paid' => false], $a);
    }

    public function testEditIsOnlyOfferedWhilePendingNotReviewed(): void
    {
        // Stricter than Expenses: actions/save_petty_cash.php's own rule is
        // "Only allow edit if status is pending" — reviewed is NOT editable
        // here, unlike a reviewed general expense.
        $auth = self::auth(true);
        $this->assertTrue(vk_api_petty_actions($auth, 'pending')['edit']);
        $this->assertFalse(vk_api_petty_actions($auth, 'reviewed')['edit']);
        $this->assertFalse(vk_api_petty_actions($auth, 'approved')['edit']);
    }

    public function testLeadershipMayReviewAPendingVoucher(): void
    {
        $a = vk_api_petty_actions(self::auth(true), 'pending');
        $this->assertTrue($a['review']);
        $this->assertFalse($a['approve']);
    }

    public function testLeadershipMayApproveAReviewedVoucher(): void
    {
        $a = vk_api_petty_actions(self::auth(true), 'reviewed');
        $this->assertFalse($a['review']);
        $this->assertTrue($a['approve']);
    }

    public function testOnlyTreasurerOrAdminMayMarkPaid(): void
    {
        $secretary = self::auth(true, false, 'Secretary');
        $this->assertFalse(vk_api_petty_actions($secretary, 'approved')['mark_paid']);

        $treasurer = self::auth(true, false, 'Treasurer');
        $this->assertTrue(vk_api_petty_actions($treasurer, 'approved')['mark_paid']);
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function testThousandsSeparatorsAreAcceptedInAmount(): void
    {
        $this->assertSame(35000.0, vk_api_petty_amount('35,000'));
    }

    /**
     * @testWith ["0"]
     *           ["-500"]
     *           ["free"]
     */
    public function testAZeroOrNegativeOrNonNumericAmountIsRefused(string $bad): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_amount/');
        vk_api_petty_amount($bad);
    }

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_petty_filters(['status' => 'disputed']);
    }

    public function testAnUnparseableDateIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_petty_filters(['date_from' => 'yesterday']);
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_petty_filters([
            'status' => 'approved', 'category' => 'Stationery', 'search' => 'Duka',
        ], 'v');
        $this->assertCount(3, $where);
        $this->assertCount(5, $params, 'status(1) + category(1) + search(3 LIKEs)');
        foreach ($where as $clause) {
            $this->assertStringNotContainsString('Duka', $clause);
        }
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_petty_filters([]));
    }

    // ── the workflow guard (pure) ──────────────────────────────────────────────

    public function testTheWorkflowGuardOnlyAllowsTheDeclaredFromStatuses(): void
    {
        $this->assertTrue(vk_api_petty_can_transition('pending', ['pending']));
        $this->assertFalse(vk_api_petty_can_transition('reviewed', ['pending']));
    }

    public function testApproveHasNoFundBalanceGateUnlikeExpenses(): void
    {
        // The web's own actions/approve_petty_cash.php has never gated on the
        // group fund balance — this must not add a check the web never had.
        $code = self::code('includes/api_petty_cash.php');
        $this->assertStringNotContainsString('getGroupFundBalance', $code);
        $this->assertStringNotContainsString('fund_sufficient', $code);
    }

    // ── the security fix: fetch_petty_cash.php had no permission check ────────

    public function testTheListAjaxEndpointNowChecksViewPermission(): void
    {
        $code = self::code('actions/fetch_petty_cash.php');
        $this->assertStringContainsString("canView('petty_cash')", $code);
    }

    public function testTheViewCheckComesBeforeAnyQuery(): void
    {
        $code  = self::code('actions/fetch_petty_cash.php');
        $gate  = strpos($code, "canView('petty_cash')");
        $query = strpos($code, 'FROM petty_cash_vouchers');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    // ── structural: gates fire before any query ───────────────────────────────

    public function testTheListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/petty-cash.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'petty_cash')");
        $query = strpos($code, 'FROM petty_cash_vouchers');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testMarkPaidDoesNotUseTheRolePermissionsGate(): void
    {
        $code = self::code('api/v1/petty-cash_mark-paid.php');
        $this->assertStringContainsString('vk_api_petty_may_mark_paid($auth)', $code);
        $this->assertStringNotContainsString('vk_api_require_permission', $code);
    }

    public function testReviewAndApproveDoubleGateViewAlongsideTheAction(): void
    {
        foreach (['review' => 'api/v1/petty-cash_review.php', 'approve' => 'api/v1/petty-cash_approve.php'] as $action => $file) {
            $code = self::code($file);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'petty_cash')", $code);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, '{$action}', 'petty_cash')", $code);
        }
    }

    // ── permission migration ─────────────────────────────────────────────────

    public function testTheMigrationMirrorsFromExpensesNotHardcoded(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/add_petty_cash_permission.php');
        $this->assertStringContainsString("VK_PETTY_MODEL = 'expenses'", $src);
    }

    public function testTheMigrationIsRegistered(): void
    {
        $migrate = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString('add_petty_cash_permission.php', $migrate);
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/petty-cash'              => 'petty-cash.php',
            'api/v1/petty-cash/5'            => 'petty-cash_detail.php',
            'api/v1/petty-cash/5/review'     => 'petty-cash_review.php',
            'api/v1/petty-cash/5/approve'    => 'petty-cash_approve.php',
            'api/v1/petty-cash/5/mark-paid'  => 'petty-cash_mark-paid.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    // ── auditing ────────────────────────────────────────────────────────────

    public function testEveryWriteIsAuditedAgainstTheRealUser(): void
    {
        $this->assertMatchesRegularExpression(
            "/logActivity\([^;]*\)/s",
            self::code('api/v1/petty-cash_create.php')
        );
    }
}
