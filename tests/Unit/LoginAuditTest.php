<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 1 of the audit-logs "trust + signal" work: unsuccessful sign-in attempts
 * must leave a security audit trail. Previously only successful logins were
 * logged (actions/login.php), so brute-force attempts were invisible.
 *
 * The password must NEVER reach the logger — only the attempted username/email.
 */
class LoginAuditTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testFailedLoginHelperExistsAndIsASafeNoopWithoutDb(): void
    {
        require_once __DIR__ . '/../../includes/activity_logger.php';
        $this->assertTrue(function_exists('logFailedLogin'), 'logFailedLogin() must exist');

        // With no DB connection the logger short-circuits; the helper must not throw.
        global $pdo;
        $pdo = null;
        logFailedLogin('someone@example.com', 'wrong password', 7);
        $this->assertTrue(true, 'logFailedLogin() must be a safe no-op when $pdo is unavailable');
    }

    public function testFailedLoginHelperTakesNoPasswordParameter(): void
    {
        require_once __DIR__ . '/../../includes/activity_logger.php';
        $ref = new \ReflectionFunction('logFailedLogin');
        foreach ($ref->getParameters() as $p) {
            $this->assertStringNotContainsStringIgnoringCase(
                'password',
                $p->getName(),
                'logFailedLogin() must not accept a password argument'
            );
        }
    }

    public function testLoginActionLogsWrongPasswordAndUnknownAccount(): void
    {
        $src = $this->src('actions/login.php');
        $this->assertStringContainsString('activity_logger.php', $src, 'login action must load the logger');
        $this->assertStringContainsString("logFailedLogin(\$login_input, 'wrong password'", $src);
        $this->assertStringContainsString("logFailedLogin(\$login_input, 'account not found'", $src);
    }

    public function testLoginActionLogsBlockedAccountAttempts(): void
    {
        $src = $this->src('actions/login.php');
        // Correct password against a pending/rejected/inactive/suspended account is logged too.
        $this->assertStringContainsString("logFailedLogin(\$login_input, 'account ' . \$user['status']", $src);
    }

    public function testLoginActionNeverPassesPasswordToLogger(): void
    {
        $src = $this->src('actions/login.php');
        // Every logFailedLogin(...) call must use the login input, never the raw password.
        if (preg_match_all('/logFailedLogin\([^;]*\)/', $src, $m)) {
            foreach ($m[0] as $call) {
                $this->assertStringNotContainsString('$password', $call, "Password leaked into: $call");
            }
        } else {
            $this->fail('Expected at least one logFailedLogin() call in actions/login.php');
        }
    }
}
