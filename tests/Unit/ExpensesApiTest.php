<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_expenses.php';

/**
 * Module 9 — Expenses (general_expenses side).
 *
 * The workflow has FOUR states, not three: pending -> reviewed -> approved ->
 * paid. 'paid' never gets an e-signature (actions/mark_expense_paid.php never
 * captures one) and is gated by canMarkPaid() (Treasurer/admin), not a
 * role_permissions grant like review/approve.
 */
final class ExpensesApiTest extends TestCase
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
            'id'           => 4,
            'expense_date' => '2026-08-14',
            'description'  => 'Karatasi na wino wa printa',
            'amount'       => '45000.00',
            'status'       => 'approved',
            'created_at'   => '2026-08-14 09:00:00',
            'created_by'   => 485,
            'reviewed_by'  => 484,
            'reviewed_at'  => '2026-08-15 10:00:00',
            'approved_by'  => 483,
            'approved_at'  => '2026-08-16 11:00:00',
            'paid_by'      => null,
            'paid_at'      => null,
            'member_id'    => null,
            'member_name'  => '',
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
                ? ['expenses' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1, 'review' => 1, 'approve' => 1]]
                // Member holds `view` live on demo/production — mirrored here, not the leak
                // shape: this key's list has never been misread as "my own", it has always
                // meant the whole group.
                : ['expenses' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0, 'review' => 0, 'approve' => 0]],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testAMemberChargeIsNestedRatherThanFlat(): void
    {
        $row = vk_api_expenses_row(self::raw(['member_id' => 30, 'member_name' => 'Hamisi Mbwana']));
        $this->assertSame(['id' => 30, 'name' => 'Hamisi Mbwana'], $row['member']);
    }

    public function testAWholeOrgExpenseHasNoMember(): void
    {
        $this->assertNull(vk_api_expenses_row(self::raw())['member']);
    }

    public function testAmountIsANumberNotAString(): void
    {
        $this->assertSame(45000.0, vk_api_expenses_row(self::raw())['amount']);
    }

    public function testPaidAtIsNullUntilActuallyPaid(): void
    {
        $this->assertNull(vk_api_expenses_row(self::raw())['paid_at']);
        $paid = vk_api_expenses_row(self::raw(['paid_at' => '2026-08-17 08:00:00']));
        $this->assertNotNull($paid['paid_at']);
    }

    // ── actions ─────────────────────────────────────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $a = vk_api_expenses_actions(self::auth(false), 'pending');
        $this->assertSame(['edit' => false, 'review' => false, 'approve' => false, 'mark_paid' => false], $a);
    }

    public function testLeadershipMayEditAPendingOrReviewedExpenseNotApprovedOrPaid(): void
    {
        $auth = self::auth(true);
        $this->assertTrue(vk_api_expenses_actions($auth, 'pending')['edit']);
        $this->assertTrue(vk_api_expenses_actions($auth, 'reviewed')['edit']);
        $this->assertFalse(vk_api_expenses_actions($auth, 'approved')['edit']);
        $this->assertFalse(vk_api_expenses_actions($auth, 'paid')['edit']);
    }

    public function testLeadershipMayReviewAPendingExpense(): void
    {
        $a = vk_api_expenses_actions(self::auth(true), 'pending');
        $this->assertTrue($a['review']);
        $this->assertFalse($a['approve']);
    }

    public function testLeadershipMayApproveAReviewedExpense(): void
    {
        $a = vk_api_expenses_actions(self::auth(true), 'reviewed');
        $this->assertFalse($a['review']);
        $this->assertTrue($a['approve']);
    }

    public function testOnlyTreasurerOrAdminMayMarkPaidEvenIfLeadership(): void
    {
        // Secretary/Chairperson hold 'expenses' review+approve but are NOT the
        // treasurer — mark_paid must still be false for them.
        $secretary = self::auth(true, false, 'Secretary');
        $this->assertFalse(vk_api_expenses_actions($secretary, 'approved')['mark_paid']);

        $treasurer = self::auth(true, false, 'Treasurer');
        $this->assertTrue(vk_api_expenses_actions($treasurer, 'approved')['mark_paid']);

        $admin = self::auth(false, true);
        $this->assertTrue(vk_api_expenses_actions($admin, 'approved')['mark_paid']);
    }

    public function testMarkPaidIsOnlyOfferedOnApproved(): void
    {
        $treasurer = self::auth(true, false, 'Treasurer');
        foreach (['pending', 'reviewed', 'paid', 'rejected'] as $status) {
            $this->assertFalse(
                vk_api_expenses_actions($treasurer, $status)['mark_paid'],
                "mark_paid must not be offered on {$status}"
            );
        }
    }

    // ── who may mark paid (pure) ──────────────────────────────────────────────

    public function testMarkPaidByRoleId(): void
    {
        $this->assertTrue(vk_api_expenses_may_mark_paid(['role_id' => 4, 'user' => []]));
        $this->assertFalse(vk_api_expenses_may_mark_paid(['role_id' => 3, 'user' => []]));
    }

    public function testMarkPaidByRoleNameFallback(): void
    {
        $this->assertTrue(vk_api_expenses_may_mark_paid(['role_id' => 99, 'user' => ['user_role' => 'Mweka Hazina']]));
        $this->assertFalse(vk_api_expenses_may_mark_paid(['role_id' => 99, 'user' => ['user_role' => 'Secretary']]));
    }

    public function testAdminAlwaysMayMarkPaid(): void
    {
        $this->assertTrue(vk_api_expenses_may_mark_paid(['role_id' => 1, 'user' => []]));
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function testThousandsSeparatorsAreAcceptedInAmount(): void
    {
        $this->assertSame(10000.0, vk_api_expenses_amount('10,000'));
    }

    /**
     * @testWith ["0"]
     *           ["-500"]
     *           ["a lot"]
     */
    public function testAZeroOrNegativeOrNonNumericAmountIsRefused(string $bad): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_amount/');
        vk_api_expenses_amount($bad);
    }

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_expenses_filters(['status' => 'disputed']);
    }

    public function testAnUnparseableDateIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_expenses_filters(['date_from' => 'yesterday']);
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_expenses_filters([
            'status' => 'approved', 'date_from' => '2026-01-01', 'search' => 'Karatasi',
        ], 'ge');
        $this->assertCount(3, $where);
        $this->assertCount(5, $params, 'search binds three LIKEs');
        foreach ($where as $clause) {
            $this->assertGreaterThanOrEqual(1, substr_count($clause, '?'));
            $this->assertStringNotContainsString('Karatasi', $clause);
        }
    }

    public function testScopeFiltersToWholeOrgOrMemberCharged(): void
    {
        [$whereGeneral] = vk_api_expenses_filters(['scope' => 'general'], 'ge');
        $this->assertSame(['ge.member_id IS NULL'], $whereGeneral);

        [$whereMember] = vk_api_expenses_filters(['scope' => 'member'], 'ge');
        $this->assertSame(['ge.member_id IS NOT NULL'], $whereMember);
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_expenses_filters([]));
    }

    // ── the workflow guard and fund gate (pure) ───────────────────────────────

    public function testTheWorkflowGuardOnlyAllowsTheDeclaredFromStatuses(): void
    {
        $this->assertTrue(vk_api_expenses_can_transition('pending', ['pending']));
        $this->assertFalse(vk_api_expenses_can_transition('reviewed', ['pending']));
        $this->assertFalse(vk_api_expenses_can_transition('pending', ['reviewed']));
    }

    public function testTheFundGuardRefusesWhenTheBalanceIsShort(): void
    {
        $this->assertFalse(vk_api_expenses_fund_sufficient(100.0, 100.01));
        $this->assertTrue(vk_api_expenses_fund_sufficient(100.0, 100.0));
        $this->assertTrue(vk_api_expenses_fund_sufficient(100.0, 0.0));
    }

    public function testTheTransitionFunctionUsesTheExtractedGuards(): void
    {
        $code = self::code('includes/api_expenses.php');
        $this->assertStringContainsString('vk_api_expenses_can_transition($from, $allowedFrom)', $code);
        $this->assertStringContainsString('vk_api_expenses_fund_sufficient($available, (float) $row[\'amount\'])', $code);
    }

    public function testTheFundCheckOnlyAppliesToApproveNotReview(): void
    {
        $code      = self::code('includes/api_expenses.php');
        $checkPos  = strpos($code, '!vk_api_expenses_fund_sufficient(');
        $ifApprove = strpos($code, "if (\$to === 'approved') {");
        $this->assertNotFalse($checkPos);
        $this->assertNotFalse($ifApprove);
        $this->assertGreaterThan($ifApprove, $checkPos, 'the fund check must sit inside the approve branch');
    }

    // ── the paid-edit fix ─────────────────────────────────────────────────────

    public function testEditIsRefusedOnApprovedOrPaidNotJustApproved(): void
    {
        $this->assertStringContainsString(
            "in_array(\$row['status'], ['approved', 'paid'], true)",
            self::code('api/v1/expenses_detail.php')
        );
    }

    public function testTheWebEditEndpointWasAlsoFixedToBlockPaid(): void
    {
        // api/update_general_expense.php used to check only `=== 'approved'`,
        // so a paid expense (money already gone) could still be edited.
        $code = self::code('api/update_general_expense.php');
        $this->assertStringContainsString("in_array(", $code);
        $this->assertStringContainsString("'approved', 'paid'", $code);
    }

    // ── structural: gates fire before any query, mark-paid uses its own gate ──

    public function testTheListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/expenses.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'expenses')");
        $query = strpos($code, 'FROM general_expenses');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testMarkPaidDoesNotUseTheRolePermissionsGate(): void
    {
        // Unlike every other action in this module, mark-paid is gated on
        // canMarkPaid()'s rule, not vk_api_can()/vk_api_require_permission().
        $code = self::code('api/v1/expenses_mark-paid.php');
        $this->assertStringContainsString('vk_api_expenses_may_mark_paid($auth)', $code);
        $this->assertStringNotContainsString('vk_api_require_permission', $code);
    }

    public function testReviewAndApproveDoubleGateViewAlongsideTheAction(): void
    {
        // core/permissions.php's canReview()/canApprove() are
        // isAdmin() || (canView() && review|approve) — the API must not be
        // the softer door.
        foreach (['review' => 'api/v1/expenses_review.php', 'approve' => 'api/v1/expenses_approve.php'] as $action => $file) {
            $code = self::code($file);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'expenses')", $code);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, '{$action}', 'expenses')", $code);
        }
    }

    public function testStatusChangesArePassedThroughTheSharedTransitionHelper(): void
    {
        foreach (['api/v1/expenses_review.php', 'api/v1/expenses_approve.php'] as $f) {
            $this->assertStringContainsString('vk_api_expenses_transition(', self::code($f));
        }
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/expenses'                    => 'expenses.php',
            'api/v1/expenses/5'                  => 'expenses_detail.php',
            'api/v1/expenses/5/review'            => 'expenses_review.php',
            'api/v1/expenses/5/approve'           => 'expenses_approve.php',
            'api/v1/expenses/5/mark-paid'         => 'expenses_mark-paid.php',
            'api/v1/reports/expense-report'       => 'reports_expense-report.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } elseif (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
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
            "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            self::code('api/v1/expenses_create.php'),
            'The API has no session, so the audit user id must be passed explicitly.'
        );
    }
}
