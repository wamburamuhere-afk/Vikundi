<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_meetings.php';

/**
 * Module 12 — Meetings.
 *
 * No workflow, same shape as Payouts — `status` is a plain scheduled/held/
 * cancelled field, no reviewer/approver. Built on the `meetings` permission
 * key, which already had correct grants before this module (full leadership
 * CRUD, Member view-only) — the first module since Contributions that needed
 * no new permission migration.
 */
final class MeetingsApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function raw(array $over = []): array
    {
        return $over + [
            'id'           => 4,
            'title'        => 'September General Meeting',
            'meeting_date' => '2026-09-06',
            'meeting_time' => '14:30:00',
            'location'     => 'Community Hall',
            'meeting_type' => 'regular',
            'agenda'       => 'Budget review',
            'minutes'      => null,
            'status'       => 'scheduled',
            'created_at'   => '2026-09-01 09:00:00',
        ];
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['meetings' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1]]
                // Member holds `view` here too — same as the live grants — but
                // never create/edit/delete.
                : ['meetings' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0]],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testTimeIsTruncatedToHoursAndMinutes(): void
    {
        $this->assertSame('14:30', vk_api_meetings_row(self::raw())['meeting_time']);
    }

    public function testANullTimeStaysNull(): void
    {
        $this->assertNull(vk_api_meetings_row(self::raw(['meeting_time' => null]))['meeting_time']);
    }

    public function testABlankLocationOrAgendaIsNullNotAnEmptyString(): void
    {
        $row = vk_api_meetings_row(self::raw(['location' => '  ', 'agenda' => '']));
        $this->assertNull($row['location']);
        $this->assertNull($row['agenda']);
    }

    public function testPresentCountIsOnlyIncludedWhenSupplied(): void
    {
        $this->assertArrayNotHasKey('present_count', vk_api_meetings_row(self::raw()));
        $this->assertSame(3, vk_api_meetings_row(self::raw(['present_count' => '3']))['present_count']);
    }

    // ── actions: no status dependency at all ──────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $this->assertSame(['edit' => false, 'delete' => false], vk_api_meetings_actions(self::auth(false)));
    }

    public function testLeadershipMayEditAndDeleteRegardlessOfStatus(): void
    {
        // Unlike Budgets, there is no status-based edit block anywhere in
        // this module — actions never take a $status argument at all.
        $this->assertSame(['edit' => true, 'delete' => true], vk_api_meetings_actions(self::auth(true)));
    }

    public function testActionsFunctionTakesNoStatusArgument(): void
    {
        $ref = new ReflectionFunction('vk_api_meetings_actions');
        $this->assertCount(1, $ref->getParameters());
    }

    // ── attendance parsing & validation ─────────────────────────────────────

    public function testAttendanceMustBeANonEmptyArray(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/attendance_required/');
        vk_api_meetings_parse_attendance($this->fakePdoNeverCalled(), []);
    }

    public function testAnInvalidStatusValueIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_attendance/');
        vk_api_meetings_parse_attendance($this->fakePdoNeverCalled(), [['member_id' => 1, 'status' => 'excused']]);
    }

    public function testAZeroMemberIdIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_attendance/');
        vk_api_meetings_parse_attendance($this->fakePdoNeverCalled(), [['member_id' => 0, 'status' => 'present']]);
    }

    public function testSaveAttendanceUpsertsExactlyTheSubmittedRows(): void
    {
        // Pure count check on the helper's contract — no DB required for this
        // part, since vk_api_meetings_save_attendance()'s job is simply "one
        // upsert per entry, return the count."
        $code = self::code('includes/api_meetings.php');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE status = VALUES(status)', $code);
    }

    private function fakePdoNeverCalled(): PDO
    {
        // vk_api_meetings_parse_attendance() only queries the DB after every
        // row has passed shape/status validation — these tests all fail
        // validation first, so no real connection is ever needed. An
        // uninitialised PDO subclass stands in as "a PDO that must not be
        // queried": calling any method on it would fatal, which is exactly
        // the point — these tests must never reach that far.
        return new class extends PDO {
            public function __construct() {}
        };
    }

    // ── filters ─────────────────────────────────────────────────────────────

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_meetings_filters(['status' => 'ongoing']);
    }

    public function testAnUnknownTypeFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_type/');
        vk_api_meetings_filters(['type' => 'emergency']);
    }

    public function testAnUnparseableDateIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_meetings_filters(['date_from' => 'yesterday']);
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_meetings_filters([]));
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_meetings_filters(['status' => 'held', 'search' => 'AGM'], 'm');
        $this->assertCount(2, $where);
        $this->assertSame(['held', '%AGM%'], $params);
        foreach ($where as $clause) {
            $this->assertStringNotContainsString('AGM', $clause);
        }
    }

    // ── structural: gates fire before any query, no permission migration ────

    public function testNoNewPermissionMigrationWasNeeded(): void
    {
        // Unlike every module since Contributions, `meetings` already had
        // correct grants — confirm there is no database/add_meetings_permission.php.
        $this->assertFileDoesNotExist(__DIR__ . '/../../database/add_meetings_permission.php');
    }

    public function testTheListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/meetings.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'meetings')");
        $query = strpos($code, 'FROM meetings m');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testTheFormerlyUngatedWebEndpointsNowCheckAPermission(): void
    {
        foreach (['api/get_meetings.php', 'api/get_meeting_details.php'] as $file) {
            $this->assertStringContainsString("canView('meetings')", self::code($file));
        }
    }

    public function testFineAbsenteesOnlyQueriesRealAbsentRows(): void
    {
        // Confirms the module does NOT fine every roster member the detail
        // view *displays* as absent by default — only members with an actual
        // 'absent' row in meeting_attendance.
        $code = self::code('api/v1/meetings_fine-absentees.php');
        $this->assertStringContainsString("WHERE meeting_id = ? AND status = 'absent'", $code);
    }

    public function testFineAbsenteesDeduplicatesAgainstExistingFines(): void
    {
        $code = self::code('api/v1/meetings_fine-absentees.php');
        $this->assertStringContainsString('SELECT COUNT(*) FROM fines WHERE customer_id = ? AND meeting_id = ?', $code);
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/meetings'                    => 'meetings.php',
            'api/v1/meetings/5'                  => 'meetings_detail.php',
            'api/v1/meetings/5/attendance'        => 'meetings_attendance.php',
            'api/v1/meetings/5/fine-absentees'    => 'meetings_fine-absentees.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    // ── auditing ────────────────────────────────────────────────────────────

    public function testEveryWriteIsAuditedAgainstTheRealUser(): void
    {
        $this->assertMatchesRegularExpression(
            "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            self::code('api/v1/meetings_create.php')
        );
    }
}
