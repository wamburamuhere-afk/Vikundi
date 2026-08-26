<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A member's savings are not group property.
 *
 * WHAT THIS CAUGHT. `manage_contributions.view` is granted to the Member role,
 * and correctly so — it is what lets a member open their own contributions.
 * Seven endpoints then read that same grant as permission to see EVERYONE:
 *
 *     if (!isAdmin() && !canView('manage_contributions')) { ...refuse... }
 *
 * Verified against the live demo site, signed in as an ordinary member
 * (`hmbwana1`, member 30):
 *
 *   - api/get_transactions.php returned all 333 group transactions
 *   - contribution_view?id=255 showed another member's TZS 50,000 contribution
 *   - contribution_statement?member_id=1 printed the chairperson's full statement
 *   - api/export_contributions_statement.php downloaded the group's savings
 *   - the Transactions recording hub opened
 *
 * Pre-existing, not introduced by the Module 4 permission migration: the Member
 * role's key count was 24 before that migration and 24 after, and the migration
 * only ever INSERTs — it cannot add a key without raising the count.
 *
 * THE DISTINCTION, now in one place:
 *
 *     group-wide data  -> LEADERSHIP (admin, or `edit`)
 *     a single record  -> OWNERSHIP  (it is yours), or leadership
 *
 * `edit` is the leadership test precisely because `view` is the grant a Member
 * holds. Testing `view` is what caused this.
 */
class ContributionAccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/contribution_access.php';
    }

    private static function code(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
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

    // — The rule itself ——————————————————————————————————————————————————

    public function testOnlyAdminOrEditIsLeadership(): void
    {
        $this->assertTrue(vk_contrib_leader_from(true, false),  'an admin is a leader');
        $this->assertTrue(vk_contrib_leader_from(false, true),  'edit is a leader');
        $this->assertTrue(vk_contrib_leader_from(true, true));
        $this->assertFalse(vk_contrib_leader_from(false, false), 'view alone is not leadership');
    }

    /**
     * The whole defect in one assertion: `view` is not an input to this function.
     * If it ever becomes one, a Member is a leader again.
     */
    public function testViewIsNotAnInputToTheLeadershipRule(): void
    {
        $code = self::code('includes/contribution_access.php');

        $this->assertStringNotContainsString(
            "canView('manage_contributions')",
            $code,
            'view must play no part in deciding leadership — it is the Member grant.'
        );
        $this->assertStringContainsString("canEdit('manage_contributions')", $code);
    }

    // — Group-wide endpoints must require leadership ——————————————————————

    /**
     * Every one of these returns data about people other than the caller, and
     * none of them can be narrowed to "just mine".
     */
    #[DataProvider('groupWideEndpoints')]
    public function testGroupWideEndpointsRequireLeadership(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_contrib_web_require_leader(',
            $code,
            "{$rel} serves the whole group and must require leadership."
        );
        $this->assertStringNotContainsString(
            "!canView('manage_contributions')",
            $code,
            "{$rel} must not gate on view — that is the grant a Member holds."
        );
    }

    public static function groupWideEndpoints(): array
    {
        return [
            'transactions list' => ['api/get_transactions.php'],
            'recording hub'     => ['app/bms/customer/transactions.php'],
        ];
    }

    // — Per-record endpoints must check ownership ——————————————————————————

    /**
     * Contribution ids are sequential, so an endpoint that loads by id and
     * renders without an ownership test is a walkable ledger.
     */
    #[DataProvider('perRecordEndpoints')]
    public function testPerRecordEndpointsCheckOwnership(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_contrib_web_require_own_or_leader(',
            $code,
            "{$rel} loads a row by id and must check who it belongs to."
        );
    }

    public static function perRecordEndpoints(): array
    {
        return [
            'view'  => ['app/bms/customer/contribution_view.php'],
            'print' => ['app/bms/customer/print_contribution.php'],
        ];
    }

    /** The check must come after the row is loaded — it needs the row's member. */
    #[DataProvider('perRecordEndpoints')]
    public function testTheOwnershipCheckUsesTheLoadedRowsMember(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            "vk_contrib_web_require_own_or_leader(\$pdo, (int) \$con['member_id'])",
            $code,
            "{$rel} must test the member the row actually belongs to."
        );
    }

    // — Statement + exports must be scoped, not blocked ————————————————————

    /**
     * These three share vk_statement_filters(), where member_id 0 means "the
     * whole group". Scoped rather than blocked on purpose: a member printing or
     * exporting their OWN statement is legitimate and is the same screen.
     */
    #[DataProvider('statementConsumers')]
    public function testStatementConsumersAreScopedToTheCaller(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_statement_apply_scope($pdo, $f)',
            $code,
            "{$rel} must pin a non-leader to their own member id."
        );

        // Order matters: scoping the filters after the query has been built does
        // nothing at all.
        $filters = strpos($code, 'vk_statement_filters($_GET)');
        $scope   = strpos($code, 'vk_statement_apply_scope($pdo, $f)');
        $where   = strpos($code, 'vk_statement_where($f');

        $this->assertNotFalse($where, "{$rel} must build its WHERE from the filters.");
        $this->assertGreaterThan($filters, $scope, 'scope must follow the filters');
        $this->assertLessThan($where, $scope, 'scope must precede the query');
    }

    public static function statementConsumers(): array
    {
        return [
            'printable statement' => ['app/bms/customer/contribution_statement.php'],
            'csv export'          => ['api/export_contributions_statement.php'],
            'mkoba export'        => ['api/export_contributions_statement_mkoba.php'],
        ];
    }

    /**
     * BEHAVIOURAL, because a source grep cannot tell the fix from the bug.
     *
     * A mutation that changed the assignment to
     *
     *     if ($f['member_id'] <= 0) { $f['member_id'] = $own; }
     *
     * passed every structural assertion here while restoring the exact hole:
     * a blank member_id gets filled, but an explicitly requested OTHER member is
     * left alone — so `?member_id=1` still prints the chairperson's statement.
     * The only way to catch that is to call the function.
     */
    #[DataProvider('scopeCases')]
    public function testANonLeaderIsAlwaysPinnedToTheirOwnMemberId(
        int $requested,
        int $expected
    ): void {
        require_once dirname(__DIR__, 2) . '/includes/contribution_statement.php';

        // A plain Member: holds view on manage_contributions, nothing more.
        $_SESSION['role_id']     = 15;
        $_SESSION['role']        = 'Member';
        $_SESSION['user_role']   = 'Member';
        $_SESSION['user_id']     = 77;
        $_SESSION['permissions'] = ['manage_contributions' => ['view' => true, 'edit' => false]];

        $GLOBALS['pdo'] = $pdo = self::pdoReturning(30); // their own customer_id

        try {
            $f = vk_statement_apply_scope($pdo, [
                'from' => '', 'to' => '', 'status' => '', 'member_id' => $requested,
            ]);

            $this->assertSame(
                $expected,
                $f['member_id'],
                "A member asking for member_id={$requested} must be pinned to 30."
            );
        } finally {
            unset(
                $_SESSION['role_id'], $_SESSION['role'], $_SESSION['user_role'],
                $_SESSION['user_id'], $_SESSION['permissions'], $GLOBALS['pdo']
            );
        }
    }

    public static function scopeCases(): array
    {
        return [
            'asked for nobody (the group)' => [0,  30],
            'asked for the chairperson'    => [1,  30],
            'asked for another member'     => [22, 30],
            'asked for themselves'         => [30, 30],
            'asked for a negative id'      => [-5, 30],
        ];
    }

    /** A leader's request is honoured unchanged, including 0 for the whole group. */
    public function testALeadersRequestIsNotRewritten(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/contribution_statement.php';

        $_SESSION['role_id']     = 4;            // Treasurer — not admin by id
        $_SESSION['role']        = 'Treasurer';
        $_SESSION['user_role']   = 'Treasurer';
        $_SESSION['user_id']     = 88;
        $_SESSION['permissions'] = ['manage_contributions' => ['view' => true, 'edit' => true]];

        $GLOBALS['pdo'] = $pdo = self::pdoReturning(3);

        try {
            foreach ([0, 1, 22] as $requested) {
                $f = vk_statement_apply_scope($pdo, [
                    'from' => '', 'to' => '', 'status' => '', 'member_id' => $requested,
                ]);
                $this->assertSame($requested, $f['member_id'], 'a leader may ask for anyone');
            }
        } finally {
            unset(
                $_SESSION['role_id'], $_SESSION['role'], $_SESSION['user_role'],
                $_SESSION['user_id'], $_SESSION['permissions'], $GLOBALS['pdo']
            );
        }
    }

    /**
     * Stands in for PDO: the scope helper only ever looks up one customer_id.
     *
     * Extends PDO without calling its constructor, because the helpers type-hint
     * PDO and this machine has only the mysql driver — sqlite is absent here and
     * in CI, so a real in-memory connection is not available. Nothing that would
     * touch a connection is called: prepare() is overridden and the statement it
     * returns is a plain object.
     */
    private static function pdoReturning(int $customerId): \PDO
    {
        return new class ($customerId) extends \PDO {
            public function __construct(private int $id)
            {
                // deliberately not calling parent::__construct() — no connection
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = [])
            {
                return new class ($this->id) {
                    public function __construct(private int $id)
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function fetchColumn(int $column = 0): int
                    {
                        return $this->id;
                    }
                };
            }
        };
    }

    /**
     * An account with no member record has no statement of its own, and must not
     * fall through with member_id 0 — which is the whole group.
     */
    public function testAnAccountWithNoMemberRecordIsRefusedNotDefaulted(): void
    {
        $code = self::code('includes/contribution_statement.php');

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$own\s*<=\s*0\s*\)\s*\{\s*http_response_code\(403\)/",
            $code,
            'member_id 0 means the whole group; it is not a safe fallback.'
        );
    }

    // — The two transports must not drift again ————————————————————————————

    public function testTheApiUsesTheSameLeadershipRuleAsTheWeb(): void
    {
        $code = self::code('includes/api_contributions.php');

        $this->assertStringContainsString(
            'vk_contrib_leader_from(',
            $code,
            'The API must derive leadership from the shared rule, not its own copy.'
        );
    }

    /**
     * manage_contributions.php is deliberately NOT gated on leadership: it holds
     * `view` and is correct, because it already branches on $is_leader and shows
     * a member only their own rows. Pinned so a well-meaning sweep does not lock
     * members out of their own contributions while "fixing" this class of bug.
     */
    public function testTheContributionsListStaysOpenToMembers(): void
    {
        $code = self::code('app/bms/customer/manage_contributions.php');

        $this->assertStringNotContainsString(
            'vk_contrib_web_require_leader(',
            $code,
            'A member must still reach their own contributions list.'
        );
        $this->assertStringContainsString(
            "\$is_leader   = isAdmin() || canEdit('manage_contributions');",
            $code,
            'It stays correct by scoping, which is what the rest now do too.'
        );
        $this->assertStringContainsString(
            'AND c.user_id = ?',
            $code,
            'The member branch must filter to the signed-in user.'
        );
    }
}
