<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Meetings extras: absence -> fines and the SMS reminder. Pure tests cover the
 * message/reason builders; source-guards pin the wiring (migration registered,
 * both handlers carry the full guard stack, fines carry the meeting link).
 */
class MeetingsExtrasTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/meeting_helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure builders ------------------------------------------------------

    public function testReminderMessageContainsTitleAndDate(): void
    {
        $m = ['title' => 'Monthly AGM', 'meeting_date' => '2026-07-10', 'meeting_time' => '14:00', 'location' => 'Hall'];
        $en = vk_meeting_reminder_message($m, false);
        $this->assertStringContainsString('Monthly AGM', $en);
        $this->assertStringContainsString('10 Jul 2026', $en);
        $this->assertStringContainsString('Hall', $en);

        $sw = vk_meeting_reminder_message($m, true);
        $this->assertStringContainsString('Mkutano', $sw);
        $this->assertStringContainsString('Monthly AGM', $sw);
    }

    public function testReminderMessageWithoutTimeOrLocation(): void
    {
        $m = ['title' => 'Quick meet', 'meeting_date' => '2026-07-10'];
        $msg = vk_meeting_reminder_message($m, false);
        $this->assertStringContainsString('Quick meet', $msg);
        $this->assertStringNotContainsString('venue', $msg);
        $this->assertStringNotContainsString(' at ', $msg);
    }

    public function testFineReason(): void
    {
        $m = ['title' => 'AGM', 'meeting_date' => '2026-07-10'];
        $this->assertStringContainsString('AGM', vk_meeting_fine_reason($m, false));
        $this->assertStringContainsString('Meeting absence', vk_meeting_fine_reason($m, false));
        $this->assertStringContainsString('Faini', vk_meeting_fine_reason($m, true));
    }

    // --- wiring (source guards) --------------------------------------------

    public function testFinesMigrationRegistered(): void
    {
        $this->assertStringContainsString('add_meeting_id_to_fines.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/add_meeting_id_to_fines.php');
        $this->assertStringContainsString('fines', $mig);
        $this->assertStringContainsString('meeting_id', $mig);
    }

    public function testHandlersCarryGuardStack(): void
    {
        foreach (['actions/generate_absence_fines.php', 'actions/send_meeting_reminder.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('require_auth.php', $s, "$f needs auth guard (B3)");
            $this->assertStringContainsString('require_csrf.php', $s, "$f needs CSRF guard (H6)");
            $this->assertStringContainsString('requirePermissionJson', $s, "$f needs authorization (H3)");
        }
    }

    public function testAbsenceFinesLinkMeetingAndDedup(): void
    {
        $s = $this->src('actions/generate_absence_fines.php');
        $this->assertStringContainsString('meeting_id', $s);           // fines carry the meeting
        $this->assertStringContainsString("status = 'absent'", $s);    // only absentees
        $this->assertStringContainsString('COUNT(*) FROM fines', $s);  // dedup check
    }
}
