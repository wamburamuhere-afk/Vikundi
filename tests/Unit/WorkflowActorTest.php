<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The approval trail must name the person who approved, not the database user.
 *
 * WHAT THIS CAUGHT. workflowActorSnapshot() opened with:
 *
 *     global $pdo, $username, $user_role;
 *     $name = !empty($username) ? $username : '';
 *
 * reading $username on the assumption it is the value header.php:34 sets from
 * the users table. But includes/config.php:7 ALSO declares `$username` — for the
 * PDO connection — and config.php is included by every endpoint. On any path
 * that does not go through header.php (every AJAX endpoint under api/, every
 * handler under actions/, and the entire mobile API) the global still held the
 * DATABASE user, and !empty() accepted it.
 *
 * So workflow_signatures recorded the database account as the approver. Every
 * signature row in the development database read "vikundi"; on the server it
 * would read the production DB user. The three-approval rule the group's books
 * rest on was recording, at each step, a name that identifies nobody — and it
 * rendered perfectly normally in the UI, which is why it survived.
 *
 * Found by driving the live endpoints, not by the suite: every test passed
 * throughout. The lesson keeps repeating — a green suite is not the bar.
 */
class WorkflowActorTest extends TestCase
{
    private static function code(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path);

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

    /**
     * A stand-in for PDO that records the SQL it was handed and returns one row.
     *
     * Deliberately not SQLite: neither this machine nor CI has the driver (CI
     * installs pdo_mysql only), and a test that silently skips is a test that
     * does not exist. The function under test only ever calls prepare/execute/
     * fetch, so a stub exercises it completely — and it lets the query itself be
     * asserted, which a real database cannot do.
     *
     * @param array<string,mixed>|false $row
     */
    private static function fakePdo(array|false $row, ?string &$sql = null, ?array &$bound = null): object
    {
        return new class ($row, $sql, $bound) {
            public function __construct(
                private array|false $row,
                private ?string &$sql,
                private ?array &$bound
            ) {
            }

            public function prepare(string $query): object
            {
                $this->sql = $query;
                return new class ($this->row, $this->bound) {
                    public function __construct(private array|false $row, private ?array &$bound)
                    {
                    }

                    public function execute(array $params = []): bool
                    {
                        $this->bound = $params;
                        return true;
                    }

                    public function fetch(int $mode = 0): array|false
                    {
                        return $this->row;
                    }
                };
            }
        };
    }

    public function testTheActorIsTheSessionUserNotTheAmbientGlobal(): void
    {
        require_once dirname(__DIR__, 2) . '/core/workflow.php';

        $sql = null;
        $bound = null;

        // Exactly what includes/config.php leaves lying around.
        $GLOBALS['username']  = 'user_vikundi';
        $GLOBALS['user_role'] = null;
        $GLOBALS['pdo']       = self::fakePdo([
            'full_name' => 'Juma Hassan Mwakyusa',
            'username'  => 'jmwakyusa',
            'role_name' => 'Treasurer',
        ], $sql, $bound);
        $_SESSION['user_id'] = 7;

        try {
            $actor = workflowActorSnapshot();

            $this->assertSame(
                'Juma Hassan Mwakyusa',
                $actor['name'],
                'The signature must name the officer, not the database user.'
            );
            $this->assertSame('Treasurer', $actor['role']);
            $this->assertNotSame('user_vikundi', $actor['name']);

            // The identity must come from the session, not from anywhere else.
            $this->assertSame([7], $bound, 'The lookup must be keyed on the session user id.');
        } finally {
            unset($GLOBALS['username'], $GLOBALS['user_role'], $GLOBALS['pdo'], $_SESSION['user_id']);
        }
    }

    /**
     * A user whose role row has gone must still be named. The old inner JOIN
     * returned nothing here and fell through to the global, so an orphaned role
     * turned the approver into the database user.
     */
    public function testAUserWithNoMatchingRoleRowIsStillNamed(): void
    {
        require_once dirname(__DIR__, 2) . '/core/workflow.php';

        $GLOBALS['username'] = 'user_vikundi';
        $GLOBALS['pdo']      = self::fakePdo([
            'full_name' => '',
            'username'  => 'orphan',
            'role_name' => null,
        ]);
        $_SESSION['user_id'] = 8;

        try {
            $actor = workflowActorSnapshot();

            $this->assertSame('orphan', $actor['name'], 'Falls back to the username, never the global.');
            $this->assertSame('Member', $actor['role']);
        } finally {
            unset($GLOBALS['username'], $GLOBALS['pdo'], $_SESSION['user_id']);
        }
    }

    /** A user id with no row at all is nobody — not the database account. */
    public function testAMissingUserRowIsNotAttributedToTheDatabaseUser(): void
    {
        require_once dirname(__DIR__, 2) . '/core/workflow.php';

        $GLOBALS['username'] = 'user_vikundi';
        $GLOBALS['pdo']      = self::fakePdo(false);
        $_SESSION['user_id'] = 4242;

        try {
            $this->assertSame(['name' => 'System', 'role' => 'System'], workflowActorSnapshot());
        } finally {
            unset($GLOBALS['username'], $GLOBALS['pdo'], $_SESSION['user_id']);
        }
    }

    public function testAnUnauthenticatedCallIsNotAttributedToAnyone(): void
    {
        require_once dirname(__DIR__, 2) . '/core/workflow.php';

        $GLOBALS['username'] = 'user_vikundi';
        unset($_SESSION['user_id'], $GLOBALS['pdo']);

        try {
            $this->assertSame(
                ['name' => 'System', 'role' => 'System'],
                workflowActorSnapshot(),
                'With no session there is no person; the DB user is not a stand-in.'
            );
        } finally {
            unset($GLOBALS['username']);
        }
    }

    /**
     * The structural half. The globals must be GONE, not merely reordered — a
     * fallback to them restores the bug the moment the lookup misses.
     */
    public function testTheConnectionGlobalsAreNoLongerRead(): void
    {
        $code = self::code('core/workflow.php');

        $this->assertStringNotContainsString('global $pdo, $username', $code,
            'workflowActorSnapshot() must not read $username — config.php sets it too.');
        $this->assertStringNotContainsString('$user_role', $code);
        $this->assertStringContainsString("\$_SESSION['user_id']", $code);
    }

    /** An inner join loses a user whose role row is missing. */
    public function testTheActorLookupUsesALeftJoin(): void
    {
        $code = self::code('core/workflow.php');

        $this->assertStringContainsString('LEFT JOIN roles r ON u.role_id = r.role_id', $code);
    }
}
