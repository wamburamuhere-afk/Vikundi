<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the backup feature:
 *  - vikundi_prune_backups()  (pure filesystem logic, no DB)
 *  - wiring: routes + files for the Backup & Restore page/dispatcher exist
 *    and are wired the way the UI expects.
 */
class BackupTest extends TestCase
{
    private string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../core/backup.php';
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vikundi_backup_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function makeFile(string $name, int $ageDays): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, '-- test');
        touch($path, time() - ($ageDays * 86400));
        return $path;
    }

    // ----- vikundi_prune_backups -------------------------------------------

    public function test_prunes_only_old_auto_and_pre_restore_files(): void
    {
        $oldAuto    = $this->makeFile('auto_backup_2020-01-01_00-00-00.sql', 10);
        $oldPre     = $this->makeFile('pre_restore_2020-01-01_00-00-00.sql', 10);
        $newAuto    = $this->makeFile('auto_backup_2030-01-01_00-00-00.sql', 1);
        $oldManual  = $this->makeFile('backup_v_2020-01-01_00-00-00.sql', 10);
        $oldUpload  = $this->makeFile('uploaded_20200101_000000_x.sql', 10);

        $deleted = vikundi_prune_backups($this->tmpDir, 7);

        // Old auto + pre_restore are pruned.
        $this->assertFileDoesNotExist($oldAuto);
        $this->assertFileDoesNotExist($oldPre);
        $this->assertContains('auto_backup_2020-01-01_00-00-00.sql', $deleted);
        $this->assertContains('pre_restore_2020-01-01_00-00-00.sql', $deleted);

        // Recent auto + manual + uploaded are NEVER pruned.
        $this->assertFileExists($newAuto);
        $this->assertFileExists($oldManual);
        $this->assertFileExists($oldUpload);
        $this->assertNotContains('backup_v_2020-01-01_00-00-00.sql', $deleted);
        $this->assertNotContains('uploaded_20200101_000000_x.sql', $deleted);
    }

    public function test_prune_returns_empty_when_nothing_old(): void
    {
        $this->makeFile('auto_backup_2030-01-01_00-00-00.sql', 0);
        $this->assertSame([], vikundi_prune_backups($this->tmpDir, 7));
    }

    public function test_engine_exposes_expected_functions(): void
    {
        $this->assertTrue(function_exists('vikundi_write_dump'));
        $this->assertTrue(function_exists('vikundi_prune_backups'));
        $this->assertTrue(function_exists('vikundi_db_size_mb'));
    }

    // ----- wiring -----------------------------------------------------------

    public function test_required_files_exist(): void
    {
        $root = __DIR__ . '/../../';
        $this->assertFileExists($root . 'core/backup.php');
        $this->assertFileExists($root . 'api/backup_actions.php');
        $this->assertFileExists($root . 'app/constant/settings/backup_restore.php');
        $this->assertFileExists($root . 'backups/.htaccess');
    }

    public function test_routes_point_to_real_files_not_coming_soon(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');

        // backup_restore must no longer map to the coming-soon placeholder.
        $this->assertMatchesRegularExpression(
            "/'backup_restore'\s*=>\s*SETTINGS_DIR\s*\.\s*'\/backup_restore\.php'/",
            $roots
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'backup_restore'\s*=>\s*COMING_SOON_FILE/",
            $roots
        );

        // Dispatcher + download alias routes are registered.
        $this->assertStringContainsString("'api/backup_actions'", $roots);
        $this->assertStringContainsString("'download_backup'", $roots);
    }

    public function test_dispatcher_handles_all_four_actions(): void
    {
        $php = file_get_contents(__DIR__ . '/../../api/backup_actions.php');
        foreach (["case 'create_backup'", "case 'restore_backup'", "case 'delete_backup'", "case 'upload_restore'"] as $needle) {
            $this->assertStringContainsString($needle, $php, "Dispatcher missing $needle");
        }
        // Gated by permission + takes a pre-restore safety snapshot.
        $this->assertStringContainsString("canDelete('backup_restore')", $php);
        $this->assertStringContainsString('pre_restore_', $php);
    }

    public function test_page_wires_to_dispatcher_and_is_admin_gated(): void
    {
        $page = file_get_contents(__DIR__ . '/../../app/constant/settings/backup_restore.php');
        $this->assertStringContainsString("getUrl('api/backup_actions')", $page);
        $this->assertStringContainsString('isAdmin()', $page);
        // Core flows present.
        foreach (['createBackup', 'restoreBackup', 'uploadRestore', 'deleteBackup'] as $fn) {
            $this->assertStringContainsString("function $fn(", $page);
        }
    }
}
