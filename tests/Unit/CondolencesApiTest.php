<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_death_expenses.php';

/**
 * Module 7 — Condolences (death_expenses).
 *
 * Built on includes/death_expense_access.php, fixed alongside this module
 * after the same shape of leak found in contributions: `death_expenses.view`
 * is a Member's own grant, not a group-wide one.
 */
final class CondolencesApiTest extends TestCase
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
            'id'                    => 4,
            'member_id'             => 2,
            'deceased_type'         => 'child',
            'deceased_id'           => 'child_0',
            'deceased_name'         => 'Asha Juma',
            'deceased_relationship' => 'Child',
            'amount'                => '122.00',
            'description'           => 'bb',
            'status'                => 'approved',
            'expense_date'          => '2026-06-24',
            'created_at'            => '2026-06-24 17:12:06',
            'reviewed_at'           => '2026-06-24 17:12:17',
            'approved_at'           => '2026-06-24 17:12:23',
            'member_name'           => 'Juma Hassan Mwakyusa',
        ];
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? 4 : 15),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['death_expenses' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1, 'review' => 1, 'approve' => 1]]
                : ['death_expenses' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0, 'review' => 0, 'approve' => 0]],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testDeceasedFieldsAreNestedRatherThanFlat(): void
    {
        $row = vk_api_death_row(self::raw());
        $this->assertSame([
            'type' => 'child', 'id' => 'child_0', 'name' => 'Asha Juma', 'relationship' => 'Child',
        ], $row['deceased']);
    }

    public function testIsSelfMarksTheReadersOwnRow(): void
    {
        $this->assertTrue(vk_api_death_row(self::raw(), 2)['is_self']);
        $this->assertFalse(vk_api_death_row(self::raw(), 3)['is_self']);
        $this->assertFalse(vk_api_death_row(self::raw(), 0)['is_self'], 'no member record means nothing is self');
    }

    public function testABlankDescriptionIsNullNotAnEmptyString(): void
    {
        $this->assertNull(vk_api_death_row(self::raw(['description' => '   ']))['description']);
        $this->assertNull(vk_api_death_row(self::raw(['deceased_relationship' => '']))['deceased']['relationship']);
    }

    public function testAmountIsANumberNotAString(): void
    {
        $this->assertSame(122.0, vk_api_death_row(self::raw())['amount']);
    }

    public function testDeceasedNameIsNeverNull(): void
    {
        // The one deceased field that must always render something, even for
        // an older row with no relationship or type recorded.
        $this->assertSame('Asha Juma', vk_api_death_row(self::raw())['deceased']['name']);
        $this->assertSame('', vk_api_death_row(self::raw(['deceased_name' => null]))['deceased']['name']);
    }

    // ── actions ─────────────────────────────────────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $a = vk_api_death_actions(self::auth(false), 'pending');
        $this->assertSame(['review' => false, 'approve' => false], $a);
    }

    public function testLeadershipMayReviewAPendingCase(): void
    {
        $a = vk_api_death_actions(self::auth(true), 'pending');
        $this->assertTrue($a['review']);
        $this->assertFalse($a['approve'], 'cannot approve before review');
    }

    public function testLeadershipMayApproveAReviewedCase(): void
    {
        $a = vk_api_death_actions(self::auth(true), 'reviewed');
        $this->assertFalse($a['review'], 'already past review');
        $this->assertTrue($a['approve']);
    }

    /**
     * 'rejected', 'inactive' and 'paid' are real column values but no code
     * path writes them — vk_api_death_transition() only ever moves pending ->
     * reviewed -> approved. Neither action should ever be offered on them.
     */
    public function testNoActionIsOfferedOnAnUnreachableStatus(): void
    {
        foreach (['rejected', 'inactive', 'paid', 'approved'] as $status) {
            $a = vk_api_death_actions(self::auth(true), $status);
            $this->assertFalse($a['review'], "review must not be offered on {$status}");
            $this->assertFalse($a['approve'], "approve must not be offered on {$status}");
        }
    }

    // ── leadership rule delegates to the shared, already-fixed rule ─────────

    public function testLeadershipDelegatesToTheSharedWebRule(): void
    {
        $this->assertStringContainsString(
            'vk_death_leader_from(',
            self::code('includes/api_death_expenses.php'),
            'The API must derive leadership from the same rule as the web, not its own copy — '
            . 'that drift is exactly how the contributions leak happened.'
        );
    }

    public function testAnAdminCountsAsLeadership(): void
    {
        $this->assertTrue(vk_api_death_is_leader(self::auth(false, true)));
        $this->assertFalse(vk_api_death_is_leader(self::auth(false)));
        $this->assertTrue(vk_api_death_is_leader(self::auth(true)));
    }

    public function testTheLeadershipTestIsEditNotView(): void
    {
        // A Member holds `view` in both fixtures; only the leader fixture holds
        // `edit`. If this ever tested `view`, both would pass.
        $this->assertFalse(vk_api_death_is_leader(self::auth(false)));
    }

    // ── group-wide endpoints require leadership ─────────────────────────────

    public function testTheGroupListRequiresLeadership(): void
    {
        $code = self::code('api/v1/condolences.php');
        $this->assertStringContainsString('vk_api_death_require_leader($auth)', $code);
    }

    public function testTheRefusalNamesTheMembersOwnEndpoint(): void
    {
        $this->assertStringContainsString(
            '/api/v1/my/condolences',
            self::code('includes/api_death_expenses.php')
        );
    }

    public function testTheGroupGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/condolences.php');
        $gate  = strpos($code, 'vk_api_death_require_leader(');
        $query = strpos($code, 'FROM death_expenses');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testRecordingIsLeadershipOnly(): void
    {
        $this->assertStringContainsString(
            "vk_api_death_require_leader(\$auth, 'record a condolence case')",
            self::code('api/v1/condolences_create.php')
        );
    }

    /**
     * The whole point of /my/condolences: it must scope to the caller's own
     * member id, taken from the token, not from anything the client sends.
     */
    public function testTheOwnEndpointScopesToTheCallersOwnMemberId(): void
    {
        $code = self::code('api/v1/my_condolences.php');

        $this->assertStringContainsString("\$where[]  = 'de.member_id = ?';", $code);
        $this->assertStringContainsString('$params[] = $own;', $code);
        $this->assertStringContainsString(
            'vk_api_member_id((int) $auth[\'user_id\'])',
            $code,
            'the member must come from the token'
        );
        $this->assertStringNotContainsString(
            "\$_GET['member_id']",
            $code,
            'there must be no member_id parameter here to tamper with'
        );
    }

    public function testAnAccountWithNoMemberRecordGetsNoOwnCondolences(): void
    {
        $this->assertStringContainsString('no_member_record', self::code('api/v1/my_condolences.php'));
    }

    public function testTheReportRequiresTheReportsPermissionNotDeathExpenses(): void
    {
        // Mirrors app/constant/reports/death_analysis.php's canView('vicoba_reports')
        // exactly — this report is gated separately from the module itself.
        $this->assertStringContainsString(
            "vk_api_require_permission(\$auth, 'view', 'vicoba_reports')",
            self::code('api/v1/reports_death-analysis.php')
        );
    }

    /**
     * A pending or reviewed case has not moved money yet — including it would
     * count assistance the group has not actually given as if it already had.
     */
    public function testTheReportOnlyIncludesPaidCases(): void
    {
        $this->assertStringContainsString(
            "WHERE d.status IN ('approved', 'paid')",
            self::code('api/v1/reports_death-analysis.php')
        );
    }

    // ── per-record endpoint checks ownership ────────────────────────────────

    public function testTheDetailEndpointReChecksOwnership(): void
    {
        $code = self::code('api/v1/condolences_detail.php');
        $this->assertStringContainsString(
            'vk_api_death_require_own_or_leader($auth, (int) $row[\'member_id\'], $own)',
            $code,
            'Guessing an id is trivial; the list endpoint having filtered is not enough.'
        );
    }

    public function testALeaderMaySeeAnyRecord(): void
    {
        $auth = self::auth(true);
        // Should not throw / exit for any member id when the caller is a leader.
        vk_api_death_require_own_or_leader($auth, 999, 0);
        $this->addToAssertionCount(1);
    }

    public function testANonLeaderReadingSomeoneElsesCaseIsRefused(): void
    {
        // own=5, the row belongs to member 2: not the same id, and not a
        // leader. Must refuse — and with 404, matching a genuine "not found"
        // rather than a 403 that would confirm the id exists.
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/404 not_found/');
        vk_api_death_require_own_or_leader(self::auth(false), 2, 5);
    }

    public function testANonLeaderReadingTheirOwnCaseIsAllowed(): void
    {
        // Must not throw.
        vk_api_death_require_own_or_leader(self::auth(false), 2, 2);
        $this->addToAssertionCount(1);
    }

    public function testAnAccountWithNoMemberRecordCannotReadAnyCase(): void
    {
        // ownMemberId 0 (an Admin-shaped account with no customers row) must
        // never coincide with a real member_id — 0 is not a valid member id to
        // match against, even if a row somehow had member_id 0.
        $this->expectException(Throwable::class);
        vk_api_death_require_own_or_leader(self::auth(false), 0, 0);
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function testThousandsSeparatorsAreAcceptedInAmount(): void
    {
        $this->assertSame(10000.0, vk_api_death_amount('10,000'));
    }

    /**
     * @testWith ["0"]
     *           ["-500"]
     *           ["a lot"]
     *
     * Separate cases rather than a loop with try/fail: $this->fail() itself
     * throws, so a catch(Throwable) around it swallows the failure and the
     * test passes no matter what the code does — the exact mistake made and
     * caught by mutation testing in the fines module.
     */
    public function testAZeroOrNegativeOrNonNumericAmountIsRefused(string $bad): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_amount/');
        vk_api_death_amount($bad);
    }

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_death_filters(['status' => 'disputed']);
    }

    /**
     * member_id and deceased_name are validated inline in the endpoint (not
     * pulled into a pure helper, unlike amount) — a plain create-form check,
     * not workflow logic, so a structural assertion is the right level here.
     */
    public function testMemberIdIsRequiredToRecordACase(): void
    {
        $code = self::code('api/v1/condolences_create.php');
        $this->assertStringContainsString("if (\$memberId <= 0) {", $code);
        $this->assertStringContainsString('member_required', $code);
    }

    public function testDeceasedNameIsRequiredToRecordACase(): void
    {
        $code = self::code('api/v1/condolences_create.php');
        $this->assertStringContainsString("if (\$deceasedName === '') {", $code);
        $this->assertStringContainsString('deceased_name_required', $code);
    }

    public function testAnUnparseableDateIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_death_filters(['date_from' => 'yesterday']);
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_death_filters([
            'status' => 'approved', 'date_from' => '2026-01-01', 'search' => 'Baraka',
        ], 'de');
        $this->assertCount(3, $where);
        $this->assertCount(5, $params, 'search binds three LIKEs');
        foreach ($where as $clause) {
            // The search clause is a compound "(a LIKE ? OR b LIKE ? OR c LIKE
            // ?)", so it ends in "?)" rather than a bare "?" — checked by
            // counting placeholders instead of the exact suffix.
            $this->assertGreaterThanOrEqual(1, substr_count($clause, '?'));
            $this->assertStringNotContainsString('Baraka', $clause);
        }
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_death_filters([]));
    }

    public function testTheAliasIsApplied(): void
    {
        [$where] = vk_api_death_filters(['status' => 'approved'], 'x');
        $this->assertSame(['x.status = ?'], $where);
    }

    // ── the fund-balance gate on approve ─────────────────────────────────────

    /**
     * Contributions never needs this: a contribution is money arriving. A
     * condolence payout is money leaving, and approving must not authorise
     * more than the group actually has.
     */
    /**
     * BEHAVIOURAL, not structural. vk_api_death_transition() runs inside a real
     * database transaction (SELECT ... FOR UPDATE, an UPDATE, two inserts),
     * which a source grep cannot prove correct — a mutation disabling the
     * actual `if` while leaving every string ('insufficient_funds',
     * 'getGroupFundBalance($pdo)') intact would pass a grep-based test outright.
     * That is why the guard was pulled out as pure logic: this calls it
     * directly, with no PDO at all.
     */
    public function testTheFundGuardRefusesWhenTheBalanceIsShort(): void
    {
        $this->assertFalse(vk_api_death_fund_sufficient(100.0, 100.01));
        $this->assertTrue(vk_api_death_fund_sufficient(100.0, 100.0), 'exactly enough must be allowed');
        $this->assertTrue(vk_api_death_fund_sufficient(100.0, 0.0));
    }

    public function testTheWorkflowGuardOnlyAllowsTheDeclaredFromStatuses(): void
    {
        $this->assertTrue(vk_api_death_can_transition('pending', ['pending']));
        $this->assertFalse(vk_api_death_can_transition('reviewed', ['pending']));
        $this->assertFalse(
            vk_api_death_can_transition('pending', ['reviewed']),
            'approve must not accept a still-pending case'
        );
    }

    /** The transition function must call the extracted guards, not re-inline them. */
    public function testTheTransitionFunctionUsesTheExtractedGuards(): void
    {
        $code = self::code('includes/api_death_expenses.php');
        $this->assertStringContainsString('vk_api_death_can_transition($from, $allowedFrom)', $code);
        $this->assertStringContainsString('vk_api_death_fund_sufficient($available, (float) $row[\'amount\'])', $code);
    }

    public function testTheFundCheckOnlyAppliesToApproveNotReview(): void
    {
        $code = self::code('includes/api_death_expenses.php');
        // The CALL site, not the declaration — 'function vk_api_death_fund_
        // sufficient(' appears earlier in the file and would otherwise make
        // this pass regardless of where the guard is actually wired in.
        $checkPos  = strpos($code, '!vk_api_death_fund_sufficient(');
        $ifApprove = strpos($code, "if (\$to === 'approved') {");
        $this->assertNotFalse($checkPos);
        $this->assertNotFalse($ifApprove);
        $this->assertGreaterThan($ifApprove, $checkPos, 'the fund check must sit inside the approve branch');
    }

    // ── the deceased-marking side effects mirror the web exactly ─────────────

    public function testApproveReusesTheSameHelperTheWebActionUses(): void
    {
        $code = self::code('api/v1/condolences_approve.php');
        $this->assertStringContainsString('markChildDeceasedJson(', $code);
        $this->assertStringNotContainsString(
            'function markChildDeceasedJson',
            $code,
            'must reuse helpers.php\'s function, not redeclare its own copy'
        );
    }

    public function testApproveHandlesAllFourDeceasedIdShapes(): void
    {
        $code = self::code('api/v1/condolences_approve.php');
        foreach (["'spouse'", "'father'", "'mother'", "'child_'"] as $needle) {
            $this->assertStringContainsString($needle, $code, "missing the {$needle} branch");
        }
        $this->assertStringContainsString("=== 'mwanachama'", $code, 'must also catch the legacy type label');
    }

    public function testApproveMarksTheMemberDormantNotJustDeceased(): void
    {
        // is_deceased alone would leave the member showing as active elsewhere.
        $code = self::code('api/v1/condolences_approve.php');
        $this->assertMatchesRegularExpression(
            "/is_active = 0.*is_deceased = 1.*status = 'dormant'/s",
            $code
        );
    }

    // ── routing ─────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/condolences'                 => 'condolences.php',
            'api/v1/my/condolences'               => 'my_condolences.php',
            'api/v1/condolences/5'                => 'condolences_detail.php',
            'api/v1/condolences/5/review'         => 'condolences_review.php',
            'api/v1/condolences/5/approve'        => 'condolences_approve.php',
            'api/v1/reports/death-analysis'       => 'reports_death-analysis.php',
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
            self::code('api/v1/condolences_create.php'),
            'The API has no session, so the audit user id must be passed explicitly.'
        );
        $this->assertMatchesRegularExpression(
            "/logActivity\(/",
            self::code('includes/api_death_expenses.php')
        );
    }

    public function testStatusChangesArePassedThroughTheSharedTransitionHelper(): void
    {
        foreach (['api/v1/condolences_review.php', 'api/v1/condolences_approve.php'] as $f) {
            $this->assertStringContainsString(
                'vk_api_death_transition(',
                self::code($f),
                "{$f} must not write to death_expenses directly."
            );
        }
    }
}
