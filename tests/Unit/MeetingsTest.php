<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Meetings module. Pure tests cover input validation, type/status normalisation
 * and the attendance summary; source-guards pin the wiring (tables + permission
 * migration registered before the role seed, action handlers carry the full
 * guard stack, routes + nav present).
 */
class MeetingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/meeting_helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure helpers -------------------------------------------------------

    public function testTitleAndDateRequired(): void
    {
        $errs = vk_meeting_input_errors(['title' => '', 'meeting_date' => '']);
        $this->assertNotEmpty($errs);
        $this->assertCount(2, $errs);
    }

    public function testValidMeetingPasses(): void
    {
        $this->assertSame([], vk_meeting_input_errors(['title' => 'AGM', 'meeting_date' => '2026-07-10']));
    }

    public function testInvalidDateRejected(): void
    {
        $errs = vk_meeting_input_errors(['title' => 'x', 'meeting_date' => '2026-13-40']);
        $this->assertNotEmpty($errs);
    }

    public function testTypeAndStatusNormalisation(): void
    {
        $this->assertSame('agm', vk_normalize_meeting_type('AGM'));
        $this->assertSame('regular', vk_normalize_meeting_type('nonsense'));
        $this->assertSame('held', vk_normalize_meeting_status('Held'));
        $this->assertSame('scheduled', vk_normalize_meeting_status(''));
    }

    public function testIniBytesParsing(): void
    {
        $this->assertSame(8 * 1048576, vk_ini_bytes('8M'));
        $this->assertSame(512 * 1024, vk_ini_bytes('512K'));
        $this->assertSame(1073741824, vk_ini_bytes('1G'));
        $this->assertSame(1048576, vk_ini_bytes('1048576'));
        $this->assertSame(0, vk_ini_bytes(''));
    }

    public function testUploadOverflowGuardPresent(): void
    {
        // The handler must catch a post_max_size overflow (empty $_POST/$_FILES
        // with a non-zero body) instead of showing "field required".
        $save = $this->src('actions/save_meeting.php');
        $this->assertStringContainsString('CONTENT_LENGTH', $save);
        $this->assertStringContainsString('post_max_size', $save);
    }

    public function testAttendanceSummary(): void
    {
        $rows = [['status' => 'present'], ['status' => 'absent'], ['status' => 'present'], ['status' => '']];
        $s = vk_attendance_summary($rows);
        $this->assertSame(2, $s['present']);
        $this->assertSame(2, $s['absent']);
        $this->assertSame(4, $s['total']);
    }

    // --- wiring (source guards) --------------------------------------------

    public function testMigrationRegisteredBeforeRoleSeed(): void
    {
        $mig = $this->src('database/migrate.php');
        $this->assertStringContainsString('create_meetings_tables.php', $mig);
        // must run before the role seed so the 'meetings' permission exists when granted
        $this->assertLessThan(
            strpos($mig, 'seed_vicoba_roles.php'),
            strpos($mig, 'create_meetings_tables.php'),
            'create_meetings_tables must be registered before seed_vicoba_roles'
        );
        $create = $this->src('database/create_meetings_tables.php');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `meetings`', $create);
        $this->assertStringContainsString('meeting_attendance', $create);
        $this->assertStringContainsString("'meetings'", $create); // permission page-key
    }

    public function testActionHandlersCarryGuardStack(): void
    {
        foreach (['actions/save_meeting.php', 'actions/save_meeting_attendance.php', 'actions/delete_meeting.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('require_auth.php', $s, "$f needs auth guard (B3)");
            $this->assertStringContainsString('require_csrf.php', $s, "$f needs CSRF guard (H6)");
            $this->assertStringContainsString('requirePermissionJson', $s, "$f needs authorization (H3)");
        }
    }

    public function testRouteAndNavRegistered(): void
    {
        $this->assertStringContainsString("'meetings' => MEETINGS_DIR", $this->src('roots.php'));
        $this->assertStringContainsString("getUrl('meetings')", $this->src('header.php'));
    }

    public function testMeetingDocumentsUseStructuredLink(): void
    {
        $save = $this->src('actions/save_meeting.php');
        $this->assertStringContainsString("'meeting'", $save);       // related_type
        $this->assertStringContainsString('related_type', $save);
    }
}
