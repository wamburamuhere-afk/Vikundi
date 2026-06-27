<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The VICOBA role seeder (database/seed_vicoba_roles.php): roles are resolved by
 * NAME (reuse existing, create if absent), BMS leftovers are removed by name, and
 * the Member role is enforced (reset to its view-only defaults every run). The
 * pure permission-grant policy itself lives in includes/role_grants.php and is
 * covered by RoleGrantsTest — this guards the seeder's wiring around it.
 */
class VicobaRolesTest extends TestCase
{
    private const SEEDER  = __DIR__ . '/../../database/seed_vicoba_roles.php';
    private const MIGRATE = __DIR__ . '/../../database/migrate.php';

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
    }

    public function testMemberRoleIsForcedToViewOnly(): void
    {
        $src = file_get_contents(self::SEEDER);
        // Member is enforced (reset to view-only every run), not seed-if-empty —
        // so its permissions can't drift and break the sensitive-data masking.
        $this->assertMatchesRegularExpression("/'Member'\\s*=>\\s*\\['view',\\s*\\d+,[^\\]]*,\\s*true\\]/", $src);
        $this->assertStringContainsString('Reset to default permissions', $src);
    }

    public function testSeederUsesSharedGrantPolicy(): void
    {
        $src = file_get_contents(self::SEEDER);
        $this->assertStringContainsString('role_grants.php', $src, 'seeder uses the shared grant policy');
        $this->assertStringContainsString('vk_role_grants(', $src);
    }

    public function testSeederRunsOnDeploy(): void
    {
        $runner = file_get_contents(self::MIGRATE);
        $this->assertStringContainsString('seed_vicoba_roles.php', $runner, 'seeder must run on deploy');
    }
}
