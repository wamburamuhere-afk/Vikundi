<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module 4: contributions — the ledger, the approval workflow, and a member's
 * own standing.
 *
 * TWO PROPERTIES MATTER HERE and neither is "does it return rows".
 *
 * 1. WHO SEES WHOSE MONEY. A savings group's roster is legitimately shared;
 *    each member's savings balance is not. The web settles this in one line
 *    ($is_leader = isAdmin() || canEdit('manage_contributions')) and the API
 *    must settle it the same way — by OVERWRITING the requested member id, never
 *    by trusting a client to omit it.
 *
 * 2. THE WORKFLOW CANNOT BE SKIPPED. pending -> reviewed -> approved exists
 *    because an approved contribution counts toward the member's savings and the
 *    group's total. A path from pending straight to approved is a path to moving
 *    the group's books without the second signature the group's own rule
 *    requires. actions/update_contribution.php had exactly that hole — it wrote
 *    any posted status behind a single edit check — and it is closed here.
 *
 * The rules live in includes/api_contributions.php, which requires no database,
 * so they are exercised for real below rather than grepped. The endpoint files
 * cannot be executed without a live request and are checked structurally — but
 * only for the properties that would be genuine holes if they regressed.
 */
class ContributionsApiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/api_contributions.php';
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Source with comments stripped, string literals intact.
     *
     * Every file in this module explains itself at length — naming the
     * permissions it checks, the statuses it allows, the helper it delegates to.
     * Asserting against the raw text therefore passes on the PROSE: it has
     * happened four times in this codebase that deleting a call left the
     * docblock mention behind and the test stayed green. Assertions about code
     * must see only code.
     */
    private static function code(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path, "{$rel} is missing.");

        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $out .= $t[1];
                continue;
            }
            $out .= $t;
        }
        return $out;
    }

    /** @param array<string,bool> $contribPerms */
    private static function auth(int $roleId, array $contribPerms = []): array
    {
        return [
            'user_id'     => 99,
            'role_id'     => $roleId,
            'permissions' => ['manage_contributions' => $contribPerms + [
                'view' => false, 'create' => false, 'edit' => false,
                'delete' => false, 'review' => false, 'approve' => false,
            ]],
        ];
    }

    // — Who is a leader ——————————————————————————————————————————————————

    /**
     * The test is `edit`, not `view`. After the permission migration a Member
     * holds view on manage_contributions — that is what lets them open their own
     * contributions at all — so gating the group-wide ledger on view would hand
     * every member the whole group's savings.
     */
    public function testViewAloneDoesNotMakeSomeoneALeader(): void
    {
        $member = self::auth(13, ['view' => true]);

        $this->assertFalse(
            vk_api_contrib_is_leader($member),
            'A Member holds view on manage_contributions; view must not widen the ledger.'
        );
    }

    public function testEditMakesSomeoneALeader(): void
    {
        $this->assertTrue(vk_api_contrib_is_leader(self::auth(4, ['view' => true, 'edit' => true])));
    }

    public function testAnAdminRoleIdIsALeaderWithNoGrantsAtAll(): void
    {
        // role_id 1/2/12 bypass the map entirely (vk_api_is_admin). A deployment
        // whose role_permissions rows are empty must still let the admin work.
        foreach ([1, 2, 12] as $roleId) {
            $this->assertTrue(
                vk_api_contrib_is_leader(self::auth($roleId)),
                "role_id {$roleId} must be treated as a leader."
            );
        }
    }

    // — What the app may offer ————————————————————————————————————————————

    /**
     * The action flags are sent with every row so the app never re-derives the
     * workflow. An app offering a button the server would refuse is the same
     * class of bug /auth/me was just repaired for: one inconsistency that every
     * consumer has to special-case.
     */
    public function testReviewIsOfferedOnlyOnAPendingRow(): void
    {
        $officer = self::auth(3, ['view' => true, 'review' => true, 'approve' => true, 'edit' => true]);

        $this->assertTrue(vk_api_contrib_actions($officer, 'pending')['review']);
        $this->assertFalse(vk_api_contrib_actions($officer, 'reviewed')['review']);
        $this->assertFalse(vk_api_contrib_actions($officer, 'approved')['review']);
    }

    public function testApproveIsOfferedOnlyOnAReviewedRow(): void
    {
        $officer = self::auth(3, ['view' => true, 'review' => true, 'approve' => true, 'edit' => true]);

        $this->assertFalse(
            vk_api_contrib_actions($officer, 'pending')['approve'],
            'Approving a pending row would skip review entirely.'
        );
        $this->assertTrue(vk_api_contrib_actions($officer, 'reviewed')['approve']);
        $this->assertFalse(vk_api_contrib_actions($officer, 'approved')['approve']);
    }

    /** Approved money is in the members' statements; it is not undone by a flag. */
    public function testApprovedRowsCannotBeCancelled(): void
    {
        $officer = self::auth(3, ['view' => true, 'edit' => true]);

        $this->assertTrue(vk_api_contrib_actions($officer, 'pending')['cancel']);
        $this->assertTrue(vk_api_contrib_actions($officer, 'reviewed')['cancel']);
        $this->assertFalse(vk_api_contrib_actions($officer, 'approved')['cancel']);
        $this->assertFalse(vk_api_contrib_actions($officer, 'cancelled')['cancel']);
    }

    public function testAMemberIsOfferedNoWorkflowActions(): void
    {
        $member = self::auth(13, ['view' => true]);

        foreach (['pending', 'reviewed', 'approved'] as $status) {
            $this->assertSame(
                ['review' => false, 'approve' => false, 'cancel' => false],
                vk_api_contrib_actions($member, $status),
                "A Member must be offered nothing on a {$status} row."
            );
        }
    }

    /**
     * core/permissions.php's canReview() is isAdmin() || (canView() && review).
     * A role granted review but not view is refused on the web; the API must not
     * be the softer door.
     */
    public function testReviewWithoutViewIsNotOffered(): void
    {
        $odd = self::auth(9, ['review' => true, 'approve' => true]); // no view

        $this->assertFalse(vk_api_contrib_actions($odd, 'pending')['review']);
        $this->assertFalse(vk_api_contrib_actions($odd, 'reviewed')['approve']);
    }

    // — The row on the wire ———————————————————————————————————————————————

    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'contribution_id'   => 7,
            'member_id'         => 3,
            'customer_name'     => 'Asha Mhando',
            'first_name'        => 'Asha',
            'last_name'         => 'Mhando',
            'amount'            => '25000.00',
            'contribution_type' => 'monthly',
            'status'            => 'approved',
            'contribution_date' => '2026-08-01',
            'description'       => null,
            'receipt_number'    => null,
            'account'           => 'Cash',
            'evidence_path'     => null,
            'mkoba_trans_id'    => null,
            'created_at'        => '2026-08-01 10:00:00',
            'reviewed_at'       => null,
            'approved_at'       => null,
        ];
    }

    /**
     * Whether a row is savings is NOT obvious from its fields: cancelled money
     * and 'agm'/'fine' rows are excluded by contribution_standing.php. A member
     * adding up this list on the phone would otherwise get a different total
     * from their own statement, and the first thing anyone does with two
     * statements is check the totals match.
     */
    #[DataProvider('savingsCases')]
    public function testCountsTowardSavingsMatchesTheStatementRules(
        string $status,
        string $type,
        bool $expected
    ): void {
        $row = vk_api_contrib_row(self::row(['status' => $status, 'contribution_type' => $type]));

        $this->assertSame($expected, $row['counts_toward_savings'], "{$status}/{$type}");
    }

    public static function savingsCases(): array
    {
        return [
            'approved monthly counts'   => ['approved',  'monthly',  true],
            'approved entrance counts'  => ['approved',  'entrance', true],
            'approved other counts'     => ['approved',  'other',    true],
            'approved fine excluded'    => ['approved',  'fine',     false],
            'approved agm excluded'     => ['approved',  'agm',      false],
            'pending never counts'      => ['pending',   'monthly',  false],
            'reviewed never counts'     => ['reviewed',  'monthly',  false],
            'cancelled never counts'    => ['cancelled', 'monthly',  false],
        ];
    }

    /** Money carried in from M-Koba is an opening balance, not a fresh payment. */
    public function testOpeningMoneyIsFlaggedTheSameWayTheStatementFlagsIt(): void
    {
        $byAccount = vk_api_contrib_row(self::row(['account' => 'M-Koba']));
        $byTransId = vk_api_contrib_row(self::row(['mkoba_trans_id' => '3800000000123']));
        $plain     = vk_api_contrib_row(self::row());

        $this->assertTrue($byAccount['is_opening']);
        $this->assertTrue($byTransId['is_opening']);
        $this->assertFalse($plain['is_opening']);
    }

    public function testAmountIsANumberNotAString(): void
    {
        // MySQL returns decimal(15,2) as a string. Left alone it reaches Dart as
        // a String and every arithmetic operation on the phone throws.
        $row = vk_api_contrib_row(self::row(['amount' => '25000.00']));

        $this->assertIsFloat($row['amount']);
        $this->assertSame(25000.0, $row['amount']);
    }

    public function testMemberNameFallsBackToTheStructuredNameWhenCustomerNameIsBlank(): void
    {
        $row = vk_api_contrib_row(self::row(['customer_name' => '']));

        $this->assertSame('Asha Mhando', $row['member_name']);
    }

    // — Scoping, as written into the endpoints ————————————————————————————

    /**
     * The list must PIN a non-leader to their own id rather than filter on what
     * the client sent. Reading the requested member_id and trusting it is the
     * whole vulnerability.
     */
    public function testTheListDelegatesScopingToTheSharedRule(): void
    {
        $code = self::code('api/v1/contributions.php');

        $this->assertMatchesRegularExpression(
            '/\$scope\s*=\s*vk_api_contrib_scope\(\s*\$auth\s*,/',
            $code,
            'The list must resolve scope through vk_api_contrib_scope().'
        );
        $this->assertStringContainsString(
            "\$where[]  = 'co.member_id = ?';",
            $code,
            'The resolved member id must be applied as a SQL filter.'
        );
        $this->assertStringNotContainsString(
            "\$params[] = (int) (\$_GET['member_id']",
            $code,
            'The requested member id must never reach the query directly.'
        );
    }

    /**
     * vk_api_contrib_scope() must refuse an account with no member record rather
     * than fall through with member_id 0 — which, with no WHERE clause, is the
     * entire group's ledger.
     */
    public function testScopeRefusesANonLeaderWithNoMemberRecord(): void
    {
        $code = self::code('includes/api_contributions.php');

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$own\s*<=\s*0\s*\)\s*\{\s*vk_api_error\(/",
            $code,
            'A non-leader with no member record must be refused, not defaulted to 0.'
        );
    }

    /**
     * Ids are sequential, so a member walking /contributions/1..n would read the
     * whole group unless detail re-checks ownership on the row it loaded.
     */
    public function testDetailRechecksOwnershipOnTheLoadedRow(): void
    {
        $code = self::code('api/v1/contributions_detail.php');

        $this->assertMatchesRegularExpression(
            "/!\\\$scope\['is_leader'\]\s*&&\s*\(int\)\s*\\\$row\['member_id'\]\s*!==\s*\\\$scope\['own_member_id'\]/",
            $code,
            'Detail must compare the loaded row against the caller, not trust the id.'
        );
        $this->assertStringContainsString(
            "vk_api_error(404, 'not_found'",
            $code,
            'A row belonging to someone else must 404, not 403 — a 403 confirms it exists.'
        );
    }

    /** The group summary is entirely about other people; it cannot be narrowed. */
    public function testTheGroupSummaryIsLeadershipOnly(): void
    {
        $code = self::code('api/v1/contributions_summary.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!vk_api_contrib_is_leader\(\s*\$auth\s*\)\s*\)/',
            $code
        );
        $this->assertStringContainsString("vk_api_error(\n        403,", $code);
    }

    // — The workflow, as written into the transition runner ————————————————

    /**
     * Two officers tapping Approve at once would otherwise both read 'reviewed',
     * both pass the guard and both write — two approval signatures for one
     * contribution.
     */
    public function testTheTransitionLocksTheRowItIsAbout(): void
    {
        $code = self::code('includes/api_contributions.php');

        $this->assertStringContainsString(
            'SELECT status FROM contributions WHERE contribution_id = ? FOR UPDATE',
            $code,
            'The status must be read FOR UPDATE inside the transaction.'
        );
        $this->assertStringContainsString('$pdo->beginTransaction();', $code);
    }

    public function testTheTransitionRefusesAStatusItWasNotStartedFrom(): void
    {
        $code = self::code('includes/api_contributions.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!in_array\(\s*\(string\)\s*\$from\s*,\s*\$allowedFrom\s*,\s*true\s*\)\s*\)/',
            $code,
            'The FROM status must be checked against the allowed set.'
        );
        $this->assertStringContainsString("'invalid_status_transition'", $code);
    }

    #[DataProvider('transitionEndpoints')]
    public function testEachTransitionDeclaresTheStatusItMayStartFrom(
        string $rel,
        string $allowedFrom
    ): void {
        $code = self::code($rel);

        $this->assertStringContainsString(
            $allowedFrom,
            $code,
            "{$rel} must name the statuses it may transition from."
        );
    }

    public static function transitionEndpoints(): array
    {
        return [
            'review'  => ['api/v1/contributions_review.php',  "['pending']"],
            'approve' => ['api/v1/contributions_approve.php', "['reviewed']"],
            'cancel'  => ['api/v1/contributions_cancel.php',  "['pending', 'reviewed']"],
        ];
    }

    /**
     * Caught by mutation: deleting the review requirement from
     * contributions_review.php left every test green, because only the approve
     * endpoint had its permissions pinned. Each transition asserts its own.
     */
    #[DataProvider('transitionPermissions')]
    public function testEachTransitionRequiresItsOwnPermission(
        string $rel,
        string $action
    ): void {
        $code = self::code($rel);

        $this->assertStringContainsString(
            "vk_api_require_permission(\$auth, '{$action}', 'manage_contributions');",
            $code,
            "{$rel} must require the '{$action}' permission."
        );
    }

    public static function transitionPermissions(): array
    {
        return [
            'review needs review'   => ['api/v1/contributions_review.php',  'review'],
            'review needs view'     => ['api/v1/contributions_review.php',  'view'],
            'approve needs approve' => ['api/v1/contributions_approve.php', 'approve'],
            'approve needs view'    => ['api/v1/contributions_approve.php', 'view'],
            'cancel needs edit'     => ['api/v1/contributions_cancel.php',  'edit'],
        ];
    }

    public function testApprovingRequiresTheApprovePermissionNotMerelyEdit(): void
    {
        $code = self::code('api/v1/contributions_approve.php');

        $this->assertStringContainsString(
            "vk_api_require_permission(\$auth, 'approve', 'manage_contributions');",
            $code
        );
        $this->assertStringContainsString(
            "vk_api_require_permission(\$auth, 'view', 'manage_contributions');",
            $code,
            'canReview()/canApprove() also require view on the web; the API must match.'
        );
    }

    // — Creating ——————————————————————————————————————————————————————————

    /**
     * A client that could post status=approved would move the group's books on
     * its own. The status is a literal in the INSERT, never a bound parameter.
     */
    public function testANewContributionIsAlwaysPending(): void
    {
        $code = self::code('api/v1/contributions_create.php');

        $this->assertStringContainsString(
            "VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, CURRENT_TIMESTAMP)",
            $code,
            'The status must be a literal in the INSERT.'
        );
        $this->assertStringNotContainsString(
            "\$body['status']",
            $code,
            'The request must not be able to name the status.'
        );
    }

    /**
     * actions/process_contribution.php's rule, carried over: submitting your own
     * needs no grant; create is what permits filing against someone else. The
     * member id is OVERWRITTEN for anyone without it, not validated.
     */
    public function testANonPrivilegedCallerIsPinnedToTheirOwnMemberId(): void
    {
        $code = self::code('api/v1/contributions_create.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$mayFileForOthers\s*\)\s*\{\s*\$memberId\s*=\s*\$ownMemberId;/',
            $code,
            'Without the create grant the member id must be overwritten, not checked.'
        );
        $this->assertStringContainsString(
            "\$mayFileForOthers = vk_api_can(\$auth, 'create', 'manage_contributions');",
            $code
        );
    }

    /**
     * The web handler built the stored filename from the client's own extension
     * and moved it into a web-served directory: receipt.php landed as
     * receipt.php. Both doors must use the validating helper.
     */
    #[DataProvider('uploadHandlers')]
    public function testEvidenceUploadsAreValidated(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_api_store_upload(',
            $code,
            "{$rel} must store evidence through the validating helper."
        );
        $this->assertStringNotContainsString(
            'PATHINFO_EXTENSION',
            $code,
            "{$rel} must not derive a stored filename from the client's own string."
        );
        $this->assertStringNotContainsString(
            'move_uploaded_file(',
            $code,
            "{$rel} must not move an upload itself."
        );
    }

    public static function uploadHandlers(): array
    {
        return [
            'mobile api' => ['api/v1/contributions_create.php'],
            'web action' => ['actions/process_contribution.php'],
        ];
    }

    // — The web hole this change closes ———————————————————————————————————

    /**
     * actions/update_contribution.php took $_POST['status'] and wrote it into
     * the row behind a single canEdit() check. Anyone with edit could post
     * status=approved: approving without the approve permission, skipping review,
     * with no signature and no approver recorded.
     */
    public function testTheWebUpdateEndpointCanOnlyCancel(): void
    {
        $code = self::code('actions/update_contribution.php');

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$status\s*!==\s*'cancelled'\s*\)/",
            $code,
            'The endpoint must accept exactly one target status.'
        );
        $this->assertStringNotContainsString(
            'SET status = ? ',
            $code,
            'The status must no longer be a bound parameter taken from the request.'
        );
        $this->assertStringContainsString(
            'SET status = "cancelled"',
            $code,
            'The written status must be a literal.'
        );
    }

    public function testTheWebUpdateEndpointRefusesToUndoApprovedMoney(): void
    {
        $code = self::code('actions/update_contribution.php');

        $this->assertStringContainsString(
            "in_array((string) \$from, ['pending', 'reviewed'], true)",
            $code,
            'Approved money is in every statement; it cannot be cancelled by a flag.'
        );
        $this->assertStringContainsString('FOR UPDATE', $code);
    }

    // — The permission the code has always referenced ——————————————————————

    /**
     * requireViewPermission('manage_contributions') has gated the contributions
     * page since it was written, but no row for that key existed in
     * `permissions`. Every such check therefore resolved false for anyone not
     * caught by the isAdmin() name bypass — so the module was reachable by role
     * NAME only, and a Member could not see their own contributions at all.
     */
    public function testTheContributionsPermissionIsRegisteredByAMigration(): void
    {
        $code = self::code('database/add_contributions_permission.php');

        $this->assertStringContainsString("'manage_contributions'", $code);
        $this->assertStringContainsString('INSERT INTO permissions', $code);
        $this->assertStringContainsString('SELECT permission_id FROM permissions WHERE page_key = ?', $code);
    }

    /**
     * The grants are copied from an existing key rather than hardcoded. Role ids
     * differ between installs (Member is 15 on the live database, 13 on a fresh
     * schema), and each deployment has already curated who may touch expenses —
     * the same shape of record with the same workflow.
     */
    public function testTheGrantsAreMirroredFromAnExistingKeyNotHardcoded(): void
    {
        $code = self::code('database/add_contributions_permission.php');

        $this->assertStringContainsString('VK_CONTRIB_MODEL', $code);
        $this->assertMatchesRegularExpression(
            '/SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve\s+FROM role_permissions WHERE permission_id = \?/',
            $code,
            'The grants must be read from the model key, not written as literals.'
        );
    }

    /** Re-running a migration must never overwrite an administrator's choice. */
    public function testTheMigrationLeavesExistingGrantsAlone(): void
    {
        $code = self::code('database/add_contributions_permission.php');

        // The `continue` is the half that matters. Counting a grant as kept and
        // then falling through to the INSERT anyway would overwrite it while
        // reporting that it had not been touched — which is exactly what this
        // assertion missed until a mutation removed the continue and stayed
        // green.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\(int\)\s*\$has->fetchColumn\(\)\s*>\s*0\s*\)\s*\{\s*\$kept\+\+;[^}]*continue;\s*\}/',
            $code,
            'An existing grant must be counted AND skipped, never rewritten.'
        );
    }

    public function testTheMigrationIsRegisteredSoDeploysApplyIt(): void
    {
        $code = self::code('database/migrate.php');

        $this->assertStringContainsString("'add_contributions_permission.php'", $code);

        // It must run BEFORE the role seeder, which only seeds a role that has no
        // rows at all — a key registered afterwards would never reach the
        // officers on an existing deployment.
        $this->assertLessThan(
            strpos($code, "'seed_vicoba_roles.php'"),
            strpos($code, "'add_contributions_permission.php'"),
            'The permission must exist before roles are seeded.'
        );
    }

    // — Every endpoint is gated ————————————————————————————————————————————

    #[DataProvider('allEndpoints')]
    public function testEveryContributionEndpointRequiresAToken(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_api_require_auth()',
            $code,
            "{$rel} must require a valid access token."
        );
        $this->assertStringContainsString(
            'vk_api_require_method(',
            $code,
            "{$rel} must declare the methods it accepts."
        );
    }

    public static function allEndpoints(): array
    {
        return [
            ['api/v1/contributions.php'],
            ['api/v1/contributions_create.php'],
            ['api/v1/contributions_detail.php'],
            ['api/v1/contributions_review.php'],
            ['api/v1/contributions_approve.php'],
            ['api/v1/contributions_cancel.php'],
            ['api/v1/contributions_summary.php'],
            ['api/v1/contributions_standing.php'],
        ];
    }

    /**
     * Every money figure on this screen must come from
     * includes/contribution_standing.php. Four screens each re-deriving the same
     * sum is why the same bug kept reappearing — savings reading 0, false
     * year-long deficits, members wrongly marked dormant.
     */
    public function testStandingIsDerivedFromTheSharedModuleNotRecomputed(): void
    {
        $code = self::code('api/v1/contributions_standing.php');

        foreach (['cs_member_schedule(', 'cs_calendar_grid(', 'cs_arrears_from_grid(',
                  'cs_year_summary(', 'cs_expected_to_date(', 'cs_standing('] as $fn) {
            $this->assertStringContainsString($fn, $code, "standing must use {$fn}.");
        }

        $this->assertStringNotContainsString(
            'SUM(amount)',
            $code,
            'Standing must not compute its own totals.'
        );
    }

    /**
     * The group total must be cs_group_savings_total() — the same figure the
     * dashboard KPI and Group Reports already show. A hand-rolled SUM here would
     * be a fourth answer to a question that already has one.
     */
    public function testTheSummaryUsesTheSharedGroupTotal(): void
    {
        $code = self::code('api/v1/contributions_summary.php');

        $this->assertStringContainsString('cs_group_savings_total($pdo)', $code);
        $this->assertStringContainsString('cs_group_standing($pdo)', $code);
    }

    /**
     * A KNOWN DIVERGENCE, pinned here so it cannot be half-fixed.
     *
     * The two standing figures are anchored differently inside
     * includes/contribution_standing.php:
     *
     *   cs_group_standing()   anchors at the member's FIRST CONTRIBUTION DATE
     *   cs_member_schedule()  anchors at max(group start, customers.created_at)
     *
     * For a member whose contributions predate their customer record — every
     * member imported from M-Koba — the two produce different "expected"
     * figures, and can therefore reach opposite conclusions about the same
     * person. Observed on real data: member 3, expected 400,000 and "behind" by
     * the group pass, expected 150,000 and "ahead" by their own statement.
     *
     * This is pre-existing and shows on the WEB today — the Group Reports and a
     * member's printed statement already disagree. The API inherits it because
     * both endpoints correctly call the shared module rather than re-deriving.
     *
     * It is NOT fixed here on purpose. Changing an anchor moves every savings
     * figure on the dashboard, the ledger, the reports and the printed
     * statements, so it needs its own change, its own verification, and the
     * treasurer confirming which anchor the group actually means. The same
     * judgement the module already applies to cs_statement_filter_sql()'s
     * documented divergence.
     *
     * What this test protects: whoever fixes it must fix BOTH sides. Changing
     * one endpoint to use the other's helper would make the phone agree with
     * itself while disagreeing with the printed statement — which is worse,
     * because it looks correct.
     */
    public function testTheTwoStandingFiguresComeFromTheirOwnDocumentedHelpers(): void
    {
        $standing = self::code('api/v1/contributions_standing.php');
        $summary  = self::code('api/v1/contributions_summary.php');

        // The member screen must stay on the member-statement helpers.
        $this->assertStringContainsString('cs_member_schedule($pdo, $memberId)', $standing);
        $this->assertStringNotContainsString('cs_group_standing(', $standing);

        // The leadership screen must stay on the one-pass group helper.
        $this->assertStringContainsString('cs_group_standing($pdo)', $summary);
        $this->assertStringNotContainsString('cs_member_schedule(', $summary);
    }

    /** One pass over every member, not one query per member. */
    public function testTheSummaryDoesNotWalkMembersOneAtATime(): void
    {
        $code = self::code('api/v1/contributions_summary.php');

        $this->assertStringNotContainsString(
            'cs_member_schedule(',
            $code,
            'Per-member schedules would be two queries each — 600 round trips on a group of 300.'
        );
        $this->assertSame(
            1,
            substr_count($code, 'cs_group_standing($pdo)'),
            'cs_group_standing() must be called once, not once per figure.'
        );
    }
}
