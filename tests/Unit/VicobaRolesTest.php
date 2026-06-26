<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The four VICOBA system roles (database/seed_vicoba_roles.php): Chairperson and
 * Secretary/Treasurer get the right CRUD split, Member is view-only, and the BMS
 * leftover roles are removed. The seeder runs against the DB on deploy, so here we
 * unit-test its pure permission-grant logic and guard its declarations.
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

    private function grants(int $roleId, string $key): ?array
    {
        return vk_role_grants($roleId, $key, self::$adminOnlyKeys, self::$memberViewKeys);
    }

    public function testChairpersonGetsEverything(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants(2, 'customers'));
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants(2, 'users')); // even admin keys
    }

    public function testSecretaryTreasurerGetOperationalCrudNotAdmin(): void
    {
        foreach ([3, 4] as $rid) {
            $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants($rid, 'customers'), "role $rid CRUD on operational");
            $this->assertSame([1, 1, 1, 1, 1, 1], $this->grants($rid, 'loans'));
            $this->assertNull($this->grants($rid, 'users'), "role $rid must NOT manage users");
            $this->assertNull($this->grants($rid, 'user_roles'), "role $rid must NOT manage roles");
            $this->assertNull($this->grants($rid, 'system_settings'), "role $rid must NOT touch settings");
        }
    }

    public function testMemberIsViewOnlyAndLimited(): void
    {
        $this->assertSame([1, 0, 0, 0, 0, 0], $this->grants(13, 'customers'), 'member views members');
        $this->assertSame([1, 0, 0, 0, 0, 0], $this->grants(13, 'dashboard'));
        $this->assertNull($this->grants(13, 'loans'), 'member has no loans access');
        $this->assertNull($this->grants(13, 'users'));
    }

    public function testSeederDeclaresRolesAndRemovesBms(): void
    {
        $src = file_get_contents(self::SEEDER);
        foreach (['Chairperson', 'Secretary', 'Treasurer', 'Member'] as $name) {
            $this->assertStringContainsString("'$name'", $src);
        }
        $this->assertStringContainsString('[5, 6, 7, 8, 9]', $src, 'BMS roles must be removed');

        $runner = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString('seed_vicoba_roles.php', $runner, 'seeder must run on deploy');
    }
}
