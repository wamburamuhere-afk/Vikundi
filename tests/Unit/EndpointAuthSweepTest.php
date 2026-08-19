<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Whole-tree invariant: every reachable endpoint under actions/, ajax/ and api/
 * must reach an authentication or fail-closed authorisation check.
 *
 * WHY THIS SHAPE. Every other security test in this suite is a hard-coded
 * allowlist — CsrfCoverageTest, EndpointAuthGuardTest, MoneyEndpointSweepTest and
 * ExpenseEndpointAccessTest between them name 36 of ~275 entry points and assert
 * those files still contain a guard string. Such a test can only detect the
 * REMOVAL of a guard from a file someone already fixed. It cannot detect a NEW
 * ungated endpoint, which is the defect that actually recurs here: twelve
 * endpoints were serving member PII and the group's cash position to anonymous
 * callers while all 275 files passed CI.
 *
 * This test is closed over the property it claims to protect: add an ungated file
 * anywhere under those three directories and it goes red. That is the whole point.
 * Modelled on NoBrokenDbIncludeTest, the only other whole-tree invariant here.
 *
 * IF THIS TEST FAILS, the fix is to gate the endpoint — not to add it to the
 * exemption list. Every exemption below carries a justification, and anything in
 * the "tracked" group is a known defect with a finding ID, not an approval.
 */
class EndpointAuthSweepTest extends TestCase
{
    /** Directories whose PHP files are directly web-servable (.htaccess:23-25). */
    private const ENDPOINT_DIRS = ['actions', 'ajax', 'api'];

    /**
     * Any one of these means the request cannot reach the handler body
     * unauthenticated.
     *
     * The permission helpers count because they fail closed: an anonymous caller
     * has an empty $_SESSION['permissions'], so canView() and friends return
     * false and the helper exits. requireViewPermission() additionally calls
     * isAuthenticated() directly. Including header.php covers the pages that
     * inherit the header.php:6-9 gate (e.g. api/account/export_invoices.php,
     * which 302s to login for exactly this reason).
     */
    private const AUTH_MARKERS = [
        'require_auth.php',
        'require_login.php',
        'isAuthenticated',
        "\$_SESSION['user_id']",
        '$_SESSION["user_id"]',
        'header.php',
        'HEADER_FILE',
        'includeHeader(',
        'requireViewPermission',
        'requireCreatePermission',
        'requireEditPermission',
        'requireDeletePermission',
        'requirePermissionJson',
        // The mobile API's token equivalent (includes/api_bootstrap.php). It is a
        // real gate, not a weaker one: it verifies a signed access token AND
        // re-reads the account's status on every request, so a disabled account
        // stops working immediately rather than when its token expires.
        'vk_api_require_auth',
        'vk_api_require_permission',
        'hasPermission',
        'canView',
        'canCreate',
        'canEdit',
        'canDelete',
        'canApprove',
        'isAdmin',
    ];

    /**
     * Files that legitimately have no gate. Keyed by path so the reason travels
     * with the entry. Structural exemptions (two-line shims, zero-byte files) are
     * detected programmatically instead — see isStructurallyExempt().
     */
    private const EXEMPT = [
        // — Public by design: these ARE the unauthenticated entry points. —
        'actions/login.php'                => 'Creates the session; cannot require one.',
        'api/v1/auth/login.php'            => 'Issues the token; cannot require one. Token equivalent of actions/login.php.',
        'api/v1/auth/refresh.php'          => 'Authorised by the refresh token it is given, not by an access token.',
        'actions/process_registration.php' => 'Public self-registration (MAP §2.3).',
        'actions/forgot_password.php'      => 'Public password-reset request.',
        'actions/reset_password.php'       => 'Public; authorised by the emailed token, not a session.',

        // — Not web endpoints, despite living under a served directory. —
        'api/helpers/transaction_helper.php' => 'Function library, emits nothing. SEC-017: move out of web root.',
        'actions/auto_terminate_members.php' => 'Cron body included by header.php:4. ARCH-006 tracks the ordering bug.',
        'actions/calculate_penalties.php'    => 'Dead: requires a ../config.php that does not exist, fatals on include (SEC-017).',

        // — Inert stubs that touch no data. —
        'ajax/get_access_log.php'    => 'Returns hard-coded zeros, no DB access (QUAL-011: delete).',
        'api/dashboard_updates.php'  => 'Static placeholder, no DB access (SEC-017: delete).',

        // — KNOWN UNGATED, TRACKED FOR THE NEXT BATCH. Not approved. —
        // These are real defects carried deliberately so this test can ship and
        // start protecting the tree today. Remove each entry as it is fixed.
        'actions/contribution_reminders.php' => 'SEC-016 (S2): unauthenticated SMS send to the whole membership.',
        'api/get_products.php'               => 'MAP §2.5: inherited BMS tree, unauthenticated read.',
        'api/get_stock_counts.php'           => 'MAP §2.5: inherited BMS tree, unauthenticated read.',
        'api/get_sms_templates.php'          => 'MAP §2.5: unauthenticated read of SMS templates.',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return string[] repo-relative paths of every PHP file under the endpoint dirs */
    private function endpointFiles(): array
    {
        $root = $this->root();
        $files = [];
        foreach (self::ENDPOINT_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $f) {
                $p = $f->getPathname();
                if (str_ends_with($p, '.php')) {
                    $files[] = str_replace($root . '/', '', $p);
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Zero-byte files and the two-line api/ -> api/account/ shims need no gate of
     * their own. A shim is exactly a require_once of its delegate with nothing
     * else, so it cannot carry logic and the target's guard always runs. Detected
     * rather than listed so a new shim does not need a test edit — and so a file
     * that grows real logic stops qualifying automatically.
     */
    private function isStructurallyExempt(string $abs): bool
    {
        $src = (string) file_get_contents($abs);
        if (trim($src) === '') {
            return true; // zero-byte
        }
        $code = array_values(array_filter(
            array_map('trim', explode("\n", $src)),
            static fn ($l) => $l !== '' && $l !== '<?php' && $l !== '?>' && !str_starts_with($l, '//')
        ));
        return count($code) === 1
            && (bool) preg_match('#^require(?:_once)?\s+__DIR__\s*\.\s*[\'"][^\'"]+\.php[\'"];$#', $code[0]);
    }

    public function testEveryEndpointReachesAnAuthCheck(): void
    {
        $root = $this->root();
        $offenders = [];

        foreach ($this->endpointFiles() as $rel) {
            if (isset(self::EXEMPT[$rel])) {
                continue;
            }
            $abs = $root . '/' . $rel;
            if ($this->isStructurallyExempt($abs)) {
                continue;
            }
            $src = (string) file_get_contents($abs);
            foreach (self::AUTH_MARKERS as $marker) {
                if (str_contains($src, $marker)) {
                    continue 2;
                }
            }
            $offenders[] = $rel;
        }

        $this->assertSame(
            [],
            $offenders,
            "These endpoints are reachable with no authentication or fail-closed authorisation check.\n"
            . "Gate them with includes/require_auth.php + requirePermissionJson(); do NOT add them to\n"
            . "EndpointAuthSweepTest::EXEMPT unless they are genuinely public.\n"
        );
    }

    /** An exemption for a file that no longer exists is stale and hides drift. */
    public function testNoStaleExemptions(): void
    {
        $root = $this->root();
        $stale = [];
        foreach (array_keys(self::EXEMPT) as $rel) {
            if (!is_file($root . '/' . $rel)) {
                $stale[] = $rel;
            }
        }
        $this->assertSame([], $stale, 'Exempted files that no longer exist — remove them from EXEMPT.');
    }

    /** The sweep is worthless if it silently walks nothing. */
    public function testSweepActuallyCoversTheEndpointSurface(): void
    {
        $this->assertGreaterThan(
            200,
            count($this->endpointFiles()),
            'Expected ~275 endpoint files; the sweep appears to be walking the wrong tree.'
        );
    }
}
