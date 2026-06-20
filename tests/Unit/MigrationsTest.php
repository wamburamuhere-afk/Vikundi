<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the database migration tooling that keeps production in sync with the
 * code (fixes the "Unknown column 'reviewed_by'/'approved_by'" schema-drift fatals).
 */
class MigrationsTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function test_runner_exists_and_chains_migrations(): void
    {
        $src = $this->read('database/migrate.php');
        $this->assertStringContainsString('sync_workflow_columns.php', $src);
        $this->assertStringContainsString('ai_assistant_setup.php', $src);
    }

    public function test_sync_is_idempotent_via_information_schema(): void
    {
        $src = $this->read('database/sync_workflow_columns.php');
        // Must check columns/tables exist before altering — safe to re-run.
        $this->assertStringContainsString('information_schema.COLUMNS', $src);
        $this->assertStringContainsString('information_schema.TABLES', $src);
        $this->assertStringContainsString('DATABASE()', $src);
    }

    public function test_sync_covers_reported_tables(): void
    {
        $src = $this->read('database/sync_workflow_columns.php');
        // The two tables that produced fatals must be covered.
        $this->assertStringContainsString("'contributions'", $src);
        $this->assertStringContainsString("'budgets'", $src);
        $this->assertStringContainsString('approved_by', $src);
        $this->assertStringContainsString('reviewed_by', $src);
    }

    public function test_deploy_workflow_runs_migrations(): void
    {
        $src = $this->read('.github/workflows/deploy.yml');
        $this->assertStringContainsString('php database/migrate.php', $src);
    }
}
