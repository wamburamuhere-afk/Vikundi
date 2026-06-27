<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The four VICOBA system roles (database/seed_vicoba_roles.php). Roles are
 * resolved by NAME (reuse existing, create if absent), and permissions are seeded
 * by role PURPOSE. The seeder runs against the DB on deploy, so here we unit-test
 * its pure permission-grant logic and guard its declarations.
 */
class VicobaRolesTest extends TestCase
{
    private const SEEDER = __DIR__ . '/../../database/seed_vicoba_roles.php';

    private static array $adminOnlyKeys  = ['users', 'user_roles', 'add_user', 'edit_user', 'system_settings', 'policy_management'];
    private static array $memberViewKeys = ['customers', 'customer_details', 'dashboard'];

    public static function setUpBeforeClass(): void
    {
        // Pull just the pure vk_role_grants() function out of the seeder and define
        // it (requiring the seeder would run the DB migration).
        if (!function_exists('vk_role_grants')) {
            $src = file_get_contents(self::SEEDER);
            $start = strpos($src, 'function vk_role_grants');
            $depth = 0; $end = $start;
            for ($i = strpos($src, '{', $start); $i < strlen($src); $i++) {
                if ($src[$i] === '{') $depth++;
                elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
            }
            eval(substr($src, $start, $end - $start + 1));
        }
    }

    private function grants(string $purpose, string $key): ?array
    {
        return vk_role_grants($purpose, $key, self::$adminOnlyKeys, self::$memberViewKeys);
    }

    public function testChairpersonGetsEverything(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants('admin', 'customers'));
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants('admin', 'users')); // even admin keys
    }

    public function testOperationalRolesGetCrudButNotAdmin(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants('operational', 'customers'));
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants('operational', 'loans'));
        $this->assertNull($this->grants('operational', 'users'), 'operational role must NOT manage users');
        $this->assertNull($this->grants('operational', 'user_roles'), 'operational role must NOT manage roles');
        $this->assertNull($this->grants('operational', 'system_settings'), 'operational role must NOT touch settings');
    }

    public function testMemberIsViewOnlyAndLimited(): void
    {
        $this->assertSame([1, 0, 0, 0, 0, 0], $this->grants('view', 'customers'), 'member views members');
        $this->assertSame([1, 0, 0, 0, 0, 0], $this->grants('view', 'dashboard'));
        $this->assertNull($this->grants('view', 'loans'), 'member has no loans access');
        $this->assertNull($this->grants('view', 'users'));
    }

    public function testSeederResolvesByNameAndRemovesBms(): void
    {
        $src = file_get_contents(self::SEEDER);
        foreach (['Chairperson', 'Secretary', 'Treasurer', 'Member'] as $name) {
            $this->assertStringContainsString("'$name'", $src);
        }
        // Roles are resolved by name (reuse existing), not a fixed-id INSERT.
        $this->assertStringContainsString('LOWER(role_name) = LOWER(?)', $src);
        // BMS leftovers removed by name.
        $this->assertStringContainsString('Loan Manager', $src);
        $this->assertStringContainsString('Director', $src);

        $runner = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString('seed_vicoba_roles.php', $runner, 'seeder must run on deploy');
    }

    public function testMemberRoleIsForcedToViewOnly(): void
    {
        $src = file_get_contents(self::SEEDER);
        // Member is enforced (reset to view-only every run), not seed-if-empty —
        // so its permissions can't drift and break the sensitive-data masking.
        $this->assertMatchesRegularExpression("/'Member'\\s*=>\\s*\\['view',\\s*\\d+,[^\\]]*,\\s*true\\]/", $src);
        $this->assertStringContainsString('Reset to default permissions', $src);
    }
}
