<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The VICOBA role seeder (database/seed_vicoba_roles.php): it declares the four
 * system roles, removes the BMS leftover roles, re-syncs the fixed Member role on
 * every run, and is wired into the deploy migration. The pure permission-grant
 * policy itself now lives in includes/role_grants.php and is covered by
 * RoleGrantsTest — this guards the seeder's wiring around it.
 */
class VicobaRolesTest extends TestCase
{
    private const SEEDER  = __DIR__ . '/../../database/seed_vicoba_roles.php';
    private const MIGRATE = __DIR__ . '/../../database/migrate.php';

    public function testSeederDeclaresRolesAndRemovesBms(): void
    {
        $src = file_get_contents(self::SEEDER);
        foreach (['Chairperson', 'Secretary', 'Treasurer', 'Member'] as $name) {
            $this->assertStringContainsString("'$name'", $src);
        }
        $this->assertStringContainsString('[5, 6, 7, 8, 9]', $src, 'BMS roles must be removed');
    }

    public function testSeederUsesSharedGrantRulesAndResyncsMember(): void
    {
        $src = file_get_contents(self::SEEDER);
        $this->assertStringContainsString('role_grants.php', $src, 'seeder uses the shared grant policy');
        $this->assertStringContainsString('vk_role_grants(', $src);
        // Member (role 13) is re-synced on every run so the view-only rule self-heals.
        $this->assertStringContainsString('resyncEveryRun', $src);
        $this->assertStringContainsString('13', $src);
    }

    public function testSeederRunsOnDeploy(): void
    {
        $runner = file_get_contents(self::MIGRATE);
        $this->assertStringContainsString('seed_vicoba_roles.php', $runner, 'seeder must run on deploy');
    }
}
