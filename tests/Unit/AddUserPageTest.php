<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Add New User" on the Users page (app/constant/settings/add_user.php).
 * It was never routed (Page Not Found), and once reachable it created users
 * the login refuses ('pending' is the column default), with no role name, no
 * CSRF token and no audit entry. Found on UKUU, 28 Oct 2026.
 */
class AddUserPageTest extends TestCase
{
    private static string $page;
    private static string $roots;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$page = (string) file_get_contents($root . '/app/constant/settings/add_user.php');
        self::$roots = (string) file_get_contents($root . '/roots.php');
    }

    public function testThePageIsRouted(): void
    {
        $this->assertMatchesRegularExpression("#'add_user'\\s*=>\\s*SETTINGS_DIR \\. '/add_user\\.php'#", self::$roots);
    }

    public function testANewUserCanSignInAndCarriesItsRoleName(): void
    {
        $this->assertStringContainsString("status, is_active, preferred_language, created_at", self::$page);
        $this->assertStringContainsString("VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, 'en', NOW())", self::$page);
        $this->assertStringContainsString('$role_id, $role_name, $role_name, $password_hash', self::$page);
    }

    public function testTheFormNeedsTheSessionsTokenAndOnlyAdminsGetThere(): void
    {
        $guard = strpos(self::$page, "require_once __DIR__ . '/../../../includes/require_csrf.php';");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos(self::$page, "require_once 'header.php';"), $guard, 'checked before any output');
        $this->assertGreaterThan(strpos(self::$page, 'if (!isAdmin()) {'), $guard);
        $this->assertStringContainsString('<?= csrf_field() ?>', self::$page);
        $this->assertStringNotContainsString('Location: unauthorized.php', self::$page, 'that page does not exist');
    }

    public function testEveryNewUserIsAuditLogged(): void
    {
        $this->assertStringContainsString("logCreate('Users', \"\$username (\$role_name)\", 'USER#' . \$pdo->lastInsertId());", self::$page);
    }
}
