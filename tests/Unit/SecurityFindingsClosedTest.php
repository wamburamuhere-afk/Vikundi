<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Five findings from the 2026-07-29 security audit (docs/analysis/02-security.md)
 * — SEC-002, SEC-003/004, SEC-006, SEC-009 — were already fixed in code by the
 * time this test was written, across remediation batches that predate the
 * mobile-API work. None of the fixes had a regression test, so nothing would
 * have failed if one of them were quietly reverted.
 *
 * This pins the five fixes down before an API layer is built on top of this
 * code — an endpoint copied from one of these files inherits whatever these
 * tests do or don't catch.
 */
class SecurityFindingsClosedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $relPath): string
    {
        $full = $this->root . '/' . $relPath;
        $this->assertFileExists($full);
        return file_get_contents($full);
    }

    // -------------------------------------------------------------------------
    // SEC-002 — the DB backup endpoints must require the backup_restore permission,
    // not merely a login.
    // -------------------------------------------------------------------------

    #[DataProvider('backupEndpoints')]
    public function testBackupEndpointsRequireThePermission(string $relPath): void
    {
        $src = $this->read($relPath);
        $this->assertStringContainsString(
            "requirePermissionJson('view', 'backup_restore')",
            $src,
            "$relPath must gate on the backup_restore permission (SEC-002)"
        );
    }

    public static function backupEndpoints(): array
    {
        return [
            ['api/create_backup.php'],
            ['api/download_backup.php'],
        ];
    }

    // -------------------------------------------------------------------------
    // SEC-003 / SEC-004 — member PII and phone-search endpoints must require
    // authentication, not be reachable anonymously.
    // -------------------------------------------------------------------------

    #[DataProvider('memberPiiEndpoints')]
    public function testMemberPiiEndpointsRequireAuth(string $relPath): void
    {
        $src = $this->read($relPath);
        $this->assertStringContainsString(
            'require_auth.php',
            $src,
            "$relPath must include the central auth guard (SEC-003/004)"
        );
    }

    public static function memberPiiEndpoints(): array
    {
        return [
            ['ajax/get_member_beneficiaries.php'],
            ['api/search_members_with_phone.php'],
        ];
    }

    // -------------------------------------------------------------------------
    // SEC-006 — DataTables ORDER BY: both the column and the direction must come
    // from a whitelist, never from raw request input concatenated into SQL.
    // -------------------------------------------------------------------------

    #[DataProvider('orderByEndpoints')]
    public function testOrderByDirectionIsWhitelistedNotConcatenated(string $relPath): void
    {
        $src = $this->read($relPath);

        // The direction must be produced by a ternary collapsing to exactly
        // 'ASC' or 'DESC' — never the raw $_GET value reaching the query string.
        $this->assertMatchesRegularExpression(
            "/'ASC'\s*:\s*'DESC'/",
            $src,
            "$relPath must whitelist the ORDER BY direction to ASC/DESC (SEC-006)"
        );

        // The raw direction string must never be concatenated into the query
        // directly — only the whitelisted variable may appear after ORDER BY.
        $this->assertDoesNotMatchRegularExpression(
            "/ORDER BY[^\"'\\n]*\\\$_GET/",
            $src,
            "$relPath must not build ORDER BY directly from \$_GET (SEC-006)"
        );
    }

    #[DataProvider('orderByEndpoints')]
    public function testOrderByColumnComesFromAnArrayIndexNotRawText(string $relPath): void
    {
        $src = $this->read($relPath);

        // The column must be selected by indexing into a fixed columns array
        // (the DataTables convention already used across this codebase), never
        // by taking a column *name* straight from the request.
        $this->assertMatchesRegularExpression(
            '/\$(columns|cols)\s*\[[^\]]*(column|idx|index)[^\]]*\]/i',
            $src,
            "$relPath must select the ORDER BY column via an index into a whitelist array (SEC-006)"
        );
    }

    public static function orderByEndpoints(): array
    {
        return [
            ['ajax/get_users.php'],
            ['api/account/get_expenses.php'],
            ['api/get_leads.php'],
            ['api/get_purchase_returns.php'],
            ['api/get_campaigns.php'],
        ];
    }

    // -------------------------------------------------------------------------
    // SEC-009 — session ID must be regenerated at login (fixation), and the
    // session must carry idle/absolute expiry bounds.
    // -------------------------------------------------------------------------

    public function testLoginRegeneratesTheSessionIdBeforeAnySessionWrite(): void
    {
        $src = $this->read('actions/login.php');

        $regenPos = strpos($src, 'session_regenerate_id(true)');
        $firstWritePos = strpos($src, "\$_SESSION['user_id'] = \$user['user_id']");

        $this->assertNotFalse($regenPos, 'login.php must call session_regenerate_id(true)');
        $this->assertNotFalse($firstWritePos, 'login.php must set $_SESSION[\'user_id\'] on success');
        $this->assertLessThan(
            $firstWritePos,
            $regenPos,
            'session_regenerate_id() must run before the session is written to, or the pre-auth session ID is still valid post-login (SEC-009)'
        );
    }

    public function testLoginStampsTimestampsForTheSessionGuard(): void
    {
        $src = $this->read('actions/login.php');
        $this->assertStringContainsString("\$_SESSION['login_time'] = time()", $src);
        $this->assertStringContainsString("\$_SESSION['last_activity'] = time()", $src);
    }

    public function testSessionGuardEnforcesBothAnIdleAndAnAbsoluteBound(): void
    {
        $src = $this->read('includes/session_guard.php');
        $this->assertStringContainsString('VK_SESSION_IDLE_SECONDS', $src);
        $this->assertStringContainsString('VK_SESSION_ABSOLUTE_SECONDS', $src);
        $this->assertStringContainsString('function vk_session_expired', $src);
    }

    // -------------------------------------------------------------------------
    // SEC-002-class, found and fixed 2026-09-09 while tracing Module 17
    // (Settings & Roles) before building any API on top of it. Two more
    // backup endpoints had the exact same session-only gate SEC-002 already
    // fixed on their siblings, and two admin pages had NO gate at all.
    // -------------------------------------------------------------------------

    #[DataProvider('backupPermissionEndpoints')]
    public function testMoreBackupEndpointsRequireThePermission(string $relPath, string $action): void
    {
        $src = $this->read($relPath);
        $this->assertStringContainsString(
            "requirePermissionJson('$action', 'backup_restore')",
            $src,
            "$relPath must gate on the backup_restore permission"
        );
    }

    public static function backupPermissionEndpoints(): array
    {
        return [
            ['api/delete_backup.php', 'delete'],
            ['api/get_backup_list.php', 'view'],
        ];
    }

    public function testSystemSettingsPageGatesOnAPermissionThatActuallyExists(): void
    {
        // Previously called has_permission('manage_settings') — a function
        // that exists nowhere in the codebase — commented out rather than
        // fixed, leaving the page with NO gate. Any authenticated user,
        // including a plain Member, could read the SMTP password and SMS
        // gateway secret in plaintext (rendered into form field values) and
        // POST any save_* action to rewrite mail/SMS credentials, security
        // policy, or disable audit logging and 2FA.
        $src = $this->read('app/constant/settings/system_settings.php');

        $this->assertStringNotContainsString(
            'has_permission(',
            $src,
            'has_permission() does not exist anywhere in this codebase; nothing should call it'
        );

        $viewCheck = strpos($src, "requireViewPermission('system_settings')");
        $headerRequire = strpos($src, "require_once ROOT_DIR . '/header.php'");
        $postBlock = strpos($src, 'if ($_POST)');
        $editCheck = strpos($src, "canEdit('system_settings')");

        $this->assertNotFalse($viewCheck, 'system_settings.php must call requireViewPermission(\'system_settings\')');
        $this->assertNotFalse($headerRequire);
        $this->assertNotFalse($postBlock);
        $this->assertNotFalse($editCheck, 'the save_* handlers must check canEdit(\'system_settings\'), not view alone');

        $this->assertLessThan(
            $headerRequire,
            $viewCheck,
            'the permission check must run BEFORE header.php, which emits HTML — a redirect after that point fails silently'
        );
        $this->assertLessThan(
            $editCheck,
            $postBlock,
            'the edit check must sit inside the $_POST block, gating every save_* action'
        );
    }

    public function testUsersListPageHasARealGateNotAJustifyingComment(): void
    {
        // Previously the only text near a permission check was a comment
        // claiming "Permissions are automatically enforced by header.php" —
        // header.php has no such mechanism. Any authenticated user, including
        // a plain Member, could view the full user list (names, emails,
        // roles, status).
        $src = $this->read('app/constant/settings/users.php');

        $viewCheck = strpos($src, "requireViewPermission('users')");
        $headerRequire = strpos($src, "require_once 'header.php'");

        $this->assertNotFalse($viewCheck, 'users.php must call requireViewPermission(\'users\')');
        $this->assertNotFalse($headerRequire);
        $this->assertLessThan(
            $headerRequire,
            $viewCheck,
            'the permission check must run before header.php emits any HTML'
        );
    }
}
