<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_reports.php';

/**
 * Module 15 — Reports & Statements.
 *
 * Every figure is delegated to includes/contribution_standing.php (already
 * extensively unit-tested — ContributionStandingTest, GroupStatementTest,
 * TransactionStatementTest, StatementUsesStandingModuleTest, etc.) and
 * includes/finance.php. This file tests only what Module 15 itself adds: the
 * as_of parser, the leader/ownership resolution mirrored from the web pages,
 * the JSON row shaping, and the permission-gate structure of each endpoint —
 * verified individually per file, not assumed consistent, given the
 * Documents/Voting incidents.
 */
final class ReportsApiTest extends TestCase
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

    private static function auth(bool $leader, bool $admin = false, int $userId = 1): array
    {
        return [
            'user_id' => $userId,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'permissions' => $leader || $admin
                ? ['manage_contributions' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1], 'vicoba_reports' => ['view' => 1]]
                : ['manage_contributions' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0], 'vicoba_reports' => ['view' => 1]],
        ];
    }

    private static function fakePdoNeverCalled(): PDO
    {
        return new class extends PDO {
            public function __construct() {}
        };
    }

    // ── as_of parsing ──────────────────────────────────────────────────────────

    public function testAValidYearMonthResolvesToTheFirstOfThatMonth(): void
    {
        $asOf = vk_api_reports_as_of('2026-08');
        $this->assertSame('2026-08-01', $asOf->format('Y-m-d'));
    }

    public function testAMissingAsOfDefaultsToToday(): void
    {
        $asOf = vk_api_reports_as_of(null);
        $this->assertSame((new DateTime('today'))->format('Y-m-d'), $asOf->format('Y-m-d'));
    }

    public function testAMalformedAsOfDefaultsToTodayRatherThanErroring(): void
    {
        $asOf = vk_api_reports_as_of('not-a-date');
        $this->assertSame((new DateTime('today'))->format('Y-m-d'), $asOf->format('Y-m-d'));
    }

    // ── leader test — the EXACT one member_statement.php/member_transactions.php use ──

    public function testAdminIsALeader(): void
    {
        $this->assertTrue(vk_api_reports_is_leader(self::auth(false, true)));
    }

    public function testCreateOnManageContributionsIsALeader(): void
    {
        $this->assertTrue(vk_api_reports_is_leader(self::auth(true)));
    }

    public function testAPlainMemberIsNotALeader(): void
    {
        $this->assertFalse(vk_api_reports_is_leader(self::auth(false)));
    }

    // ── ownership resolution — mirrors the web's ?id override rule exactly ──────

    public function testALeaderRequestingASpecificIdGetsThatIdWithoutTouchingTheDatabase(): void
    {
        // No DB call needed for this branch — proves the leader+id path never
        // has to resolve the caller's own record first.
        $id = vk_api_reports_resolve_member_id(self::fakePdoNeverCalled(), self::auth(true), 42);
        $this->assertSame(42, $id);
    }

    public function testANonLeaderIsAlwaysForcedToTheirOwnRecordRegardlessOfRequestedId(): void
    {
        // A non-leader always resolves through vk_api_member_id() (a real DB
        // call) — cannot be proven with the never-called stub, so this only
        // proves the fake-PDO branch is NOT taken for a non-leader by
        // asserting the leader branch's early return is skipped structurally.
        $code = self::code('includes/api_reports.php');
        $branch = strpos($code, 'if (vk_api_reports_is_leader($auth) && $requestedId > 0)');
        $fallback = strpos($code, 'return vk_api_member_id((int) $auth[\'user_id\']);');
        $this->assertNotFalse($branch);
        $this->assertNotFalse($fallback);
        $this->assertLessThan($fallback, $branch);
    }

    public function testALeaderWithNoRequestedIdFallsThroughToOwnRecord(): void
    {
        // requestedId=0 must NOT short-circuit even for a leader.
        $code = self::code('includes/api_reports.php');
        $this->assertStringContainsString('$requestedId > 0', $code);
    }

    // ── member details shaping ─────────────────────────────────────────────────

    private static function memberRaw(array $over = []): array
    {
        return $over + [
            'customer_id'          => 7,
            'first_name'           => 'Amina',
            'middle_name'          => '',
            'last_name'            => 'Hando',
            'registration_number'  => 'REG-007',
            'nida_number'          => '199001012222000111',
            'phone'                => '0712345678',
            'mobile'               => null,
            'dob'                  => '1990-01-01',
            'created_at'           => '2024-03-01',
            'ward'                 => 'Kinondoni',
            'district'             => 'Kinondoni',
            'state'                => 'Dar es Salaam',
            'marital_status'       => 'Married',
            'spouse_deceased'      => 0,
            'children_data'        => '[{"is_deceased":false},{"is_deceased":true}]',
            'initial_savings'      => 50000,
        ];
    }

    public function testMemberNameSkipsABlankMiddleNameCleanly(): void
    {
        $row = vk_api_reports_member_details(self::memberRaw());
        $this->assertSame('Amina Hando', $row['name']);
    }

    public function testDependantsCountsOnlyLivingChildrenPlusAnActiveSpouse(): void
    {
        $row = vk_api_reports_member_details(self::memberRaw());
        $this->assertSame(['total' => 2, 'children' => 1, 'spouse' => 1], $row['dependants']);
    }

    public function testADeceasedSpouseIsNotCountedAsADependant(): void
    {
        $row = vk_api_reports_member_details(self::memberRaw(['spouse_deceased' => 1]));
        $this->assertSame(0, $row['dependants']['spouse']);
    }

    public function testBlankRegistrationAndNidaAreNullNotEmptyString(): void
    {
        $row = vk_api_reports_member_details(self::memberRaw(['registration_number' => '', 'nida_number' => null]));
        $this->assertNull($row['registration_number']);
        $this->assertNull($row['nida_number']);
    }

    public function testResidenceJoinsWardDistrictState(): void
    {
        $row = vk_api_reports_member_details(self::memberRaw());
        $this->assertSame('Kinondoni, Kinondoni, Dar es Salaam', $row['residence']);
    }

    // ── condolence row shaping ─────────────────────────────────────────────────

    public function testCondolenceRowFallsBackToDeceasedTypeWhenRelationshipIsBlank(): void
    {
        $row = vk_api_reports_condolence_row([
            'expense_date' => '2026-01-10', 'deceased_name' => 'John Doe',
            'deceased_relationship' => '', 'deceased_type' => 'parent', 'amount' => 50000,
        ]);
        $this->assertSame('parent', $row['relationship']);
    }

    // ── structural: permission gates, verified per-file, not assumed ────────────

    public function testMemberStatementHasNoPermissionKeyGate(): void
    {
        // Mirrors the web exactly: only session/JWT auth, no vk_api_require_permission().
        $code = self::code('api/v1/member-statement_detail.php');
        $this->assertStringContainsString('vk_api_require_auth', $code);
        $this->assertStringNotContainsString('vk_api_require_permission', $code);
    }

    public function testMemberTransactionsHasNoPermissionKeyGate(): void
    {
        $code = self::code('api/v1/member-transactions_detail.php');
        $this->assertStringContainsString('vk_api_require_auth', $code);
        $this->assertStringNotContainsString('vk_api_require_permission', $code);
    }

    public function testGroupStatementEndpointsGateOnVicobaReportsNotAStricterKey(): void
    {
        // Deliberately mirrors the web's actual gate, not todo.md's "leadership
        // only" annotation — see includes/api_reports.php's header.
        foreach (['api/v1/group-statement_contributions.php', 'api/v1/group-statement_transactions.php'] as $file) {
            $code = self::code($file);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'vicoba_reports')", $code);
        }
    }

    public function testReportsEndpointsGateOnVicobaReports(): void
    {
        foreach (['api/v1/reports_vicoba.php', 'api/v1/reports_customer-analysis.php'] as $file) {
            $code = self::code($file);
            $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'vicoba_reports')", $code);
        }
    }

    public function testGroupStatementGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/group-statement_contributions.php');
        $gate  = strpos($code, "vk_api_require_permission");
        $query = strpos($code, 'vk_api_group_statement');
        $this->assertLessThan($query, $gate);
    }

    // ── the available-fund deviation, and the customer-analysis fix ────────────

    public function testVicobaReportUsesTheCanonicalFundBalanceHelper(): void
    {
        // Deliberately NOT the web page's own total_savings-minus-total_expenses
        // inline arithmetic — see includes/api_reports.php's header.
        $code = self::code('includes/api_reports.php');
        $this->assertStringContainsString('getGroupFundBalance($pdo)', $code);
    }

    public function testCustomerAnalysisFiltersByRoleIdNotTheLegacyUserRoleString(): void
    {
        $code = self::code('includes/api_reports.php');
        $this->assertStringContainsString('role_id NOT IN (1,2,12)', $code);
        $this->assertStringNotContainsString("user_role != 'Admin'", $code);
    }

    public function testTheWebPageWasFixedToMatchNotJustTheApi(): void
    {
        // customer_analysis.php's own member-counting filter, fixed in the same
        // change rather than left to drift from this module's new endpoint.
        $code = self::code('app/constant/reports/customer_analysis.php');
        $this->assertStringContainsString('role_id NOT IN (1,2,12)', $code);
        $this->assertStringNotContainsString("user_role != 'Admin'", $code);
    }

    // ── routing ────────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/member-statement/7'          => 'member-statement_detail.php',
            'api/v1/member-transactions/7'       => 'member-transactions_detail.php',
            'api/v1/group-statement/contributions' => 'group-statement_contributions.php',
            'api/v1/group-statement/transactions'  => 'group-statement_transactions.php',
            'api/v1/reports/vicoba'                => 'reports_vicoba.php',
            'api/v1/reports/customer-analysis'     => 'reports_customer-analysis.php',
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
}
