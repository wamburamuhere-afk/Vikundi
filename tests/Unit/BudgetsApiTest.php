<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_budgets.php';

/**
 * Module 10 — Budgets.
 *
 * Three-stage workflow — pending -> reviewed -> approved, or
 * pending|reviewed -> rejected — one shorter than Expenses/Petty Cash: a
 * budget has no 'paid' state, and its approve has no fund-balance gate.
 *
 * Built on a brand-new, leadership-only `budget` permission key
 * (database/add_budget_permission.php) — the web's own gating for this
 * module was the most inconsistent found yet: four of seven action files had
 * no permission check at all, and one (update_budget_status.php) let any
 * authenticated user approve any budget directly, bypassing review entirely.
 */
final class BudgetsApiTest extends TestCase
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
            'budget_id'           => 4,
            'budget_name'         => 'Q3 Office Supplies',
            'budget_year'         => 2026,
            'budget_month'        => 9,
            'allocated_amount'    => '150000.00',
            'actual_amount'       => '0.00',
            'variance'            => '150000.00',
            'variance_percentage' => '100.00',
            'status'              => 'approved',
            'notes'               => null,
            'created_at'          => '2026-09-01 09:00:00',
            'created_by'          => 485,
            'reviewed_by'         => 484,
            'reviewed_at'         => '2026-09-02 10:00:00',
            'approved_by'         => 483,
            'approved_at'         => '2026-09-03 11:00:00',
        ];
    }

    private static function itemRaw(array $over = []): array
    {
        return $over + [
            'item_id' => 1, 'description' => 'Printer paper', 'units' => 'reams',
            'qty' => '10.00', 'price_per_item' => '15000.00', 'total_amount' => '150000.00',
        ];
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['budget' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1, 'review' => 1, 'approve' => 1]]
                // Budget is leadership-only from the start — unlike Expenses/
                // Petty Cash, Member holds NOTHING here, not even view.
                : ['budget' => ['view' => 0, 'create' => 0, 'edit' => 0, 'delete' => 0, 'review' => 0, 'approve' => 0]],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testAmountsAreNumbersNotStrings(): void
    {
        $row = vk_api_budgets_row(self::raw());
        $this->assertSame(150000.0, $row['allocated_amount']);
        $this->assertSame(150000.0, $row['variance']);
        $this->assertSame(100.0, $row['variance_percentage']);
    }

    public function testCategoryIdIsNeverExposed(): void
    {
        // Deliberately not part of the row shape at all — see
        // includes/api_budgets.php's own note on why.
        $this->assertArrayNotHasKey('category_id', vk_api_budgets_row(self::raw()));
        $this->assertArrayNotHasKey('category_name', vk_api_budgets_row(self::raw()));
    }

    public function testItemsAreOmittedFromTheListShapeByDefault(): void
    {
        $this->assertArrayNotHasKey('items', vk_api_budgets_row(self::raw()));
    }

    public function testItemsAreIncludedWhenPassedForTheDetailShape(): void
    {
        $row = vk_api_budgets_row(self::raw(), [self::itemRaw()]);
        $this->assertCount(1, $row['items']);
        $this->assertSame(10.0, $row['items'][0]['qty']);
        $this->assertSame(15000.0, $row['items'][0]['price_per_item']);
        $this->assertSame(150000.0, $row['items'][0]['total_amount']);
    }

    // ── actions ─────────────────────────────────────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $a = vk_api_budgets_actions(self::auth(false), 'pending');
        $this->assertSame(
            ['edit' => false, 'delete' => false, 'review' => false, 'approve' => false, 'reject' => false],
            $a
        );
    }

    public function testLeadershipMayEditWhileNotApproved(): void
    {
        $auth = self::auth(true);
        $this->assertTrue(vk_api_budgets_actions($auth, 'pending')['edit']);
        $this->assertTrue(vk_api_budgets_actions($auth, 'reviewed')['edit']);
        $this->assertTrue(vk_api_budgets_actions($auth, 'rejected')['edit']);
        $this->assertFalse(vk_api_budgets_actions($auth, 'approved')['edit']);
    }

    public function testAdminMayEditEvenApproved(): void
    {
        $this->assertTrue(vk_api_budgets_actions(self::auth(false, true), 'approved')['edit']);
    }

    public function testLeadershipMayReviewAPendingBudget(): void
    {
        $a = vk_api_budgets_actions(self::auth(true), 'pending');
        $this->assertTrue($a['review']);
        $this->assertFalse($a['approve']);
    }

    public function testLeadershipMayApproveAReviewedBudget(): void
    {
        $a = vk_api_budgets_actions(self::auth(true), 'reviewed');
        $this->assertFalse($a['review']);
        $this->assertTrue($a['approve']);
    }

    public function testRejectIsOfferedOnPendingOrReviewedNotApproved(): void
    {
        $auth = self::auth(true);
        $this->assertTrue(vk_api_budgets_actions($auth, 'pending')['reject']);
        $this->assertTrue(vk_api_budgets_actions($auth, 'reviewed')['reject']);
        $this->assertFalse(vk_api_budgets_actions($auth, 'approved')['reject']);
    }

    public function testNoMarkPaidActionExists(): void
    {
        // Budgets have no 'paid' state at all — confirm the actions map never
        // grows a mark_paid key the way Expenses/Petty Cash's does.
        $this->assertArrayNotHasKey('mark_paid', vk_api_budgets_actions(self::auth(true), 'approved'));
    }

    // ── item parsing & validation ──────────────────────────────────────────

    public function testABlankDescriptionLineIsSilentlySkipped(): void
    {
        // Matches api/account/add_budget.php's own loop exactly.
        $items = vk_api_budgets_parse_items([
            ['description' => '', 'qty' => 1, 'price_per_item' => 100],
            ['description' => 'Real item', 'qty' => 2, 'price_per_item' => 50],
        ]);
        $this->assertCount(1, $items);
        $this->assertSame('Real item', $items[0]['description']);
    }

    public function testNoItemsIsAllowed(): void
    {
        $this->assertSame([], vk_api_budgets_parse_items(null));
        $this->assertSame([], vk_api_budgets_parse_items([]));
    }

    public function testANonArrayItemsPayloadIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_items/');
        vk_api_budgets_parse_items('not an array');
    }

    public function testANegativeQuantityIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_amount/');
        vk_api_budgets_parse_items([['description' => 'x', 'qty' => -1, 'price_per_item' => 10]]);
    }

    public function testZeroIsAllowedInAnItemAmount(): void
    {
        // Unlike a payment amount elsewhere in the API, zero is a legitimate
        // placeholder line item.
        $items = vk_api_budgets_parse_items([['description' => 'TBD', 'qty' => 0, 'price_per_item' => 0]]);
        $this->assertSame(0.0, $items[0]['qty']);
    }

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_budgets_filters(['status' => 'disputed']);
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_budgets_filters([]));
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_budgets_filters(['status' => 'approved', 'year' => 2026, 'search' => 'Office'], 'b');
        $this->assertCount(3, $where);
        $this->assertSame(['approved', 2026, '%Office%'], $params);
        foreach ($where as $clause) {
            $this->assertStringNotContainsString('Office', $clause);
        }
    }

    // ── the workflow guard (pure) ──────────────────────────────────────────

    public function testTheWorkflowGuardOnlyAllowsTheDeclaredFromStatuses(): void
    {
        $this->assertTrue(vk_api_budgets_can_transition('pending', ['pending']));
        $this->assertFalse(vk_api_budgets_can_transition('reviewed', ['pending']));
        $this->assertTrue(vk_api_budgets_can_transition('reviewed', ['pending', 'reviewed']));
    }

    public function testNoFundBalanceGateExistsAnywhereInThisModule(): void
    {
        $code = self::code('includes/api_budgets.php');
        $this->assertStringNotContainsString('getGroupFundBalance', $code);
        $this->assertStringNotContainsString('fund_sufficient', $code);
    }

    // ── structural: gates fire before any query ───────────────────────────

    public function testTheListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/budgets.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'budget')");
        $query = strpos($code, 'FROM budgets');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testReviewApproveDoubleGateViewAlongsideTheAction(): void
    {
        foreach (['review' => 'api/v1/budgets_review.php', 'approve' => 'api/v1/budgets_approve.php'] as $action => $file) {
            $code = self::code($file);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'budget')", $code);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, '{$action}', 'budget')", $code);
        }
    }

    public function testRejectAcceptsEitherReviewOrApprovePermission(): void
    {
        $code = self::code('api/v1/budgets_reject.php');
        $this->assertStringContainsString("vk_api_can(\$auth, 'review', 'budget')", $code);
        $this->assertStringContainsString("vk_api_can(\$auth, 'approve', 'budget')", $code);
    }

    public function testStatusChangesArePassedThroughTheSharedTransitionHelper(): void
    {
        foreach (['api/v1/budgets_review.php', 'api/v1/budgets_approve.php', 'api/v1/budgets_reject.php'] as $f) {
            $this->assertStringContainsString('vk_api_budgets_transition(', self::code($f));
        }
    }

    public function testEditUsesTheCoreWorkflowHelper(): void
    {
        // canEditDocument() existed, unused, before this module — confirm the
        // API actually wires it in rather than re-deriving the rule.
        $this->assertStringContainsString('canEditDocument(', self::code('api/v1/budgets_detail.php'));
    }

    // ── the web-side security fixes ─────────────────────────────────────────

    public function testTheWorkflowBypassEndpointNoLongerAcceptsApproved(): void
    {
        $code = self::code('api/account/update_budget_status.php');
        $this->assertStringContainsString("['rejected']", $code);
        $this->assertStringNotContainsString("'pending', 'approved', 'rejected'", $code);
    }

    public function testEveryFormerlyUngatedWebEndpointNowChecksAPermission(): void
    {
        $expect = [
            'api/account/add_budget.php'           => "canCreate('budget')",
            'api/account/edit_budget.php'          => "canEdit('budget')",
            'api/account/delete_budget.php'        => "canDelete('budget')",
            'api/account/get_budget.php'           => "canView('budget')",
            'api/account/update_budget_status.php' => "canReview('budget')",
            'api/account/update_budget.php'        => "canEdit('budget')",
        ];
        foreach ($expect as $file => $needle) {
            $this->assertStringContainsString($needle, self::code($file), "{$file} is missing its permission check");
        }
    }

    public function testTheUnauthenticatedAjaxHoleInBudgetPageIsClosed(): void
    {
        $code = self::code('app/constant/accounts/budget.php');
        $gate  = strpos($code, "!isAuthenticated() || !canView('budget')");
        $query = strpos($code, 'FROM budgets WHERE budget_id');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/budgets'               => 'budgets.php',
            'api/v1/budgets/5'             => 'budgets_detail.php',
            'api/v1/budgets/5/review'      => 'budgets_review.php',
            'api/v1/budgets/5/approve'     => 'budgets_approve.php',
            'api/v1/budgets/5/reject'      => 'budgets_reject.php',
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
            "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            self::code('api/v1/budgets_create.php')
        );
    }
}
