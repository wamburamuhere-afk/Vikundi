<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * header.php runs on every page load. It read the same `users` row twice — once for
 * the display name, once for the role via a JOIN. These are now one LEFT JOIN query,
 * so every page in the app does one fewer round-trip. LEFT JOIN (not INNER) keeps the
 * old behaviour: a user with no matching role still gets their name and the 'user'
 * fallback role.
 */
class HeaderUserRoleQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../header.php');
    }

    public function testIdentityAndRoleComeFromOneLeftJoin(): void
    {
        $this->assertStringContainsString('LEFT JOIN roles r ON u.role_id = r.role_id', $this->src);
        $this->assertStringContainsString('u.username, u.first_name, u.middle_name, u.last_name, r.role_name', $this->src);
        // Role is read off the same row now.
        $this->assertStringContainsString("\$user_role = \$user['role_name'] ?? 'user';", $this->src);
    }

    public function testTheSeparateRoleQueryIsGone(): void
    {
        // The old second lookup (INNER JOIN into a $role_stmt) must be removed.
        $this->assertStringNotContainsString('$role_stmt', $this->src);
        $this->assertStringNotContainsString("FROM users u JOIN roles r ON u.role_id = r.role_id", $this->src);
    }
}
