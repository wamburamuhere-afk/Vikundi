<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards audit B2: the workflow-columns migration must declare the RBAC
 * permission flags role_permissions.can_review / can_approve. Without them,
 * core/permissions.php's SELECT throws and ALL permission loading fails
 * (granular roles get nothing).
 */
class WorkflowColumnsMigrationTest extends TestCase
{
    private string $migration;
    private string $loader;

    protected function setUp(): void
    {
        $this->migration = file_get_contents(__DIR__ . '/../../database/sync_workflow_columns.php');
        $this->loader    = file_get_contents(__DIR__ . '/../../core/permissions.php');
    }

    public function testMigrationDeclaresRolePermissionsReviewApprove(): void
    {
        $matched = preg_match("/'role_permissions'\s*=>\s*\[(.+?)\]/", $this->migration, $m);
        $this->assertSame(1, $matched, 'migration must declare a role_permissions column map');
        $this->assertStringContainsString('can_review', $m[1], 'must add role_permissions.can_review');
        $this->assertStringContainsString('can_approve', $m[1], 'must add role_permissions.can_approve');
    }

    public function testPermissionLoaderStillSelectsThoseColumns(): void
    {
        // Cross-check the consumer: if these are ever removed from the SELECT the
        // migration is no longer required — keep them in lockstep.
        $this->assertMatchesRegularExpression('/rp\.can_review/', $this->loader);
        $this->assertMatchesRegularExpression('/rp\.can_approve/', $this->loader);
    }
}
