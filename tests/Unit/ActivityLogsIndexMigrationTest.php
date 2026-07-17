<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 2 of the audit-logs work: `activity_logs` shipped with only a PRIMARY KEY,
 * but the viewer filters/sorts on created_at, user_id, module and action every
 * request. This guards the index migration that keeps that fast at scale.
 */
class ActivityLogsIndexMigrationTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function test_migration_is_registered_in_runner(): void
    {
        $this->assertStringContainsString(
            'add_activity_logs_indexes.php',
            $this->read('database/migrate.php'),
            'The index migration must be chained in migrate.php so deploys apply it'
        );
    }

    public function test_migration_is_idempotent_via_information_schema(): void
    {
        $src = $this->read('database/add_activity_logs_indexes.php');
        // Guards existence before ALTER — safe to re-run on every deploy.
        $this->assertStringContainsString('information_schema.TABLES', $src);
        $this->assertStringContainsString('information_schema.STATISTICS', $src);
        $this->assertStringContainsString('DATABASE()', $src);
        $this->assertStringContainsString('ADD INDEX', $src);
    }

    public function test_migration_covers_every_queried_column(): void
    {
        $src = $this->read('database/add_activity_logs_indexes.php');
        $this->assertStringContainsString('idx_alog_created', $src);
        $this->assertStringContainsString('idx_alog_user_created', $src);
        $this->assertStringContainsString('idx_alog_module_created', $src);
        $this->assertStringContainsString('idx_alog_action', $src);
        // action is a TEXT column, so it must be a prefix index (not a full-column key).
        $this->assertStringContainsString('`action`(20)', $src);
    }

    public function test_base_schema_declares_the_indexes(): void
    {
        // Fresh installs (schema_sync.sql) must ship with the indexes already present.
        $src = $this->read('database/schema_sync.sql');
        foreach (['idx_alog_created', 'idx_alog_user_created', 'idx_alog_module_created', 'idx_alog_action'] as $idx) {
            $this->assertStringContainsString($idx, $src, "schema_sync.sql must declare $idx");
        }
    }
}
