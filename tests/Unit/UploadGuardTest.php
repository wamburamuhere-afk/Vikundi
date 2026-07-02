<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared oversized-upload guard + the meetings leadership grant backfill.
 * Pure tests cover ini-size parsing and the overflow detector; source-guards
 * pin that the expense/death upload handlers use the guard and the meetings
 * grant migration is registered.
 */
class UploadGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/upload_guard.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure ---------------------------------------------------------------

    public function testIniBytesParsing(): void
    {
        $this->assertSame(8 * 1048576, vk_ini_bytes('8M'));
        $this->assertSame(2 * 1048576, vk_ini_bytes('2M'));
        $this->assertSame(512 * 1024, vk_ini_bytes('512K'));
        $this->assertSame(1073741824, vk_ini_bytes('1G'));
        $this->assertSame(1048576, vk_ini_bytes('1048576'));
        $this->assertSame(0, vk_ini_bytes(''));
    }

    public function testPostExceededDetection(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $len = $_SERVER['CONTENT_LENGTH'] ?? null;

        // POST + empty $_POST/$_FILES + non-zero length -> overflow.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '9999';
        $_POST = []; $_FILES = [];
        $this->assertTrue(vk_post_exceeded_limit());

        // A normal POST that carried data is not an overflow.
        $_POST = ['title' => 'x'];
        $this->assertFalse(vk_post_exceeded_limit());

        // A GET is never an overflow.
        $_POST = []; $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertFalse(vk_post_exceeded_limit());

        // restore
        if ($method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $method;
        if ($len === null) unset($_SERVER['CONTENT_LENGTH']); else $_SERVER['CONTENT_LENGTH'] = $len;
        $_POST = []; $_FILES = [];
    }

    public function testUploadLimitMessageIsClear(): void
    {
        $en = vk_upload_limit_message(false);
        $this->assertStringContainsString('too large', $en);
        $sw = vk_upload_limit_message(true);
        $this->assertStringContainsString('kubwa', $sw);
    }

    // --- wiring (source guards) --------------------------------------------

    public function testExpenseHandlersUseTheGuard(): void
    {
        foreach (['api/add_general_expense.php', 'actions/process_death_expense.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('upload_guard.php', $s, "$f must include the guard");
            $this->assertStringContainsString('vk_post_exceeded_limit', $s, "$f must check for overflow");
        }
    }

    public function testMeetingHelpersUseSharedIniBytes(): void
    {
        $mh = $this->src('includes/meeting_helpers.php');
        $this->assertStringContainsString('upload_guard.php', $mh);
        // the duplicate definition was removed
        $this->assertStringNotContainsString('function vk_ini_bytes', $mh);
    }

    public function testMeetingsGrantMigrationRegistered(): void
    {
        $this->assertStringContainsString('grant_meetings_to_leadership.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/grant_meetings_to_leadership.php');
        $this->assertStringContainsString("page_key = ?", $mig);
        $this->assertStringContainsString('role_permissions', $mig);
    }
}
