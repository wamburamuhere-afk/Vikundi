<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One definition of "leadership", shared by the web dashboard and the mobile API.
 *
 * WHAT THIS CAUGHT. app/dashboard.php carried its own hard-coded list:
 *
 *     ['admin','secretary','katibu','chairman','mwenyekiti','mhazini','treasurer']
 *
 * which has 'chairman' and 'mwenyekiti' but NOT 'chairperson' — and
 * 'Chairperson' is exactly the name database/seed_vicoba_roles.php creates. The
 * head of the group was therefore served the ordinary member dashboard, with no
 * pending-approvals strip, no group-wide contribution counts and no expense or
 * budget chips. Verified against the live site before the fix, by comparing the
 * rendered leadership-only block across five real accounts.
 *
 * It was an oversight rather than policy: core/permissions.php::isAdmin() has
 * always treated 'chairperson' as an admin, so every other screen disagreed with
 * the dashboard.
 *
 * These tests exist because the same defect is easy to reintroduce — the failure
 * is silent, and it under-reports rather than over-reports, so nobody gets an
 * error, they just quietly see less than they should.
 */
class RoleLeadershipTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/roles.php';
    }

    // — The regression itself ————————————————————————————————————————————————

    public function testTheChairpersonIsLeadership(): void
    {
        $this->assertTrue(
            vk_role_is_leadership(null, 'Chairperson'),
            'Chairperson is the name seed_vicoba_roles.php creates and the head of the group.'
        );
    }

    public function testTheChairpersonIsAnAdmin(): void
    {
        $this->assertTrue(
            vk_role_is_admin(null, 'Chairperson'),
            'core/permissions.php::isAdmin() treats chairperson as an admin; this must agree.'
        );
    }

    // — Leadership by name ————————————————————————————————————————————————

    #[DataProvider('leadershipNames')]
    public function testLeadershipRoleNamesAreRecognised(string $name): void
    {
        $this->assertTrue(vk_role_is_leadership(null, $name), "{$name} should be leadership");
    }

    public static function leadershipNames(): array
    {
        return array_map(static fn ($n) => [$n], [
            'Admin', 'admin', 'Administrator',
            'Chairperson', 'chairperson', 'CHAIRPERSON', 'Mwenyekiti', 'Chairman',
            'Secretary', 'secretary', 'Katibu',
            'Treasurer', 'treasurer', 'Mweka Hazina', 'mweka-hazina', 'Mhazini', 'Mhasibu',
        ]);
    }

    #[DataProvider('nonLeadershipNames')]
    public function testOrdinaryRolesAreNotLeadership(string $name): void
    {
        $this->assertFalse(vk_role_is_leadership(null, $name), "{$name} must not be leadership");
    }

    public static function nonLeadershipNames(): array
    {
        return array_map(static fn ($n) => [$n], [
            'Member', 'member', 'Mwanachama', 'user', 'Guest', 'Committee Member', '',
        ]);
    }

    /** Role names are free text an admin can edit, so whitespace must not decide access. */
    public function testRoleNamesAreTrimmedAndCaseInsensitive(): void
    {
        $this->assertTrue(vk_role_is_leadership(null, '  Chairperson  '));
        $this->assertTrue(vk_role_is_leadership(null, "\tTREASURER\n"));
    }

    // — Leadership by id ——————————————————————————————————————————————————
    //
    // Ids are checked as well as names because neither is reliable alone: ids
    // differ between installs (the live system's Member role is 15, a fresh one
    // gets 13) and names are editable in Settings.

    public function testAdminRoleIdsAreRecognisedWithoutAName(): void
    {
        foreach ([1, 2, 12] as $id) {
            $this->assertTrue(vk_role_is_admin($id), "role_id {$id} should be admin");
            $this->assertTrue(vk_role_is_leadership($id), "role_id {$id} should be leadership");
        }
    }

    public function testOfficerRoleIdsAreLeadershipButNotAdmin(): void
    {
        foreach ([3, 4] as $id) {
            $this->assertTrue(vk_role_is_leadership($id), "role_id {$id} should be leadership");
            $this->assertFalse(vk_role_is_admin($id), "role_id {$id} must NOT be a full admin");
        }
    }

    /**
     * The live system's Member role is id 15 and a freshly seeded one is 13.
     * Neither may be leadership under any circumstances.
     */
    public function testMemberRoleIdsAreNeverLeadership(): void
    {
        foreach ([13, 15] as $id) {
            $this->assertFalse(vk_role_is_leadership($id), "role_id {$id} is Member");
            $this->assertFalse(vk_role_is_leadership($id, 'Member'));
        }
    }

    // — Fail closed ————————————————————————————————————————————————————————

    public function testUnknownInputGrantsNothing(): void
    {
        $this->assertFalse(vk_role_is_leadership(null, null));
        $this->assertFalse(vk_role_is_leadership(null));
        $this->assertFalse(vk_role_is_admin(null, null));
        $this->assertFalse(vk_role_is_leadership(999, 'Nonsense'));
    }

    // — The web dashboard must use the shared definition ————————————————————

    /**
     * A shared definition that one caller ignores protects nothing. The
     * dashboard's own array is exactly what went wrong, so its absence is the
     * property worth pinning.
     */
    public function testTheWebDashboardDelegatesInsteadOfHardCodingItsOwnList(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/dashboard.php');

        $this->assertStringContainsString(
            'vk_role_is_leadership(',
            $src,
            'app/dashboard.php must use the shared leadership definition.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$viongozi_roles\s*=\s*\[/',
            $src,
            'app/dashboard.php must not carry its own role list again — that list omitted '
            . "'chairperson' and served the group's chairperson a member dashboard."
        );
    }

    /** And so must the API, or the two transports drift apart again. */
    public function testTheApiDashboardUsesTheSameDefinition(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/dashboard.php');

        $this->assertStringContainsString('vk_role_is_leadership(', $src);
        $this->assertStringContainsString("require_once __DIR__ . '/../../includes/roles.php'", $src);
    }
}
