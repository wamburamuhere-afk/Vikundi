<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fines pages. Pure tests cover the status helpers + summary; source-guards pin
 * the wiring (migration registered before the role seed, member-hidden key,
 * manage list is permission-gated, the status action carries the guard stack,
 * and my_fines is scoped to the logged-in member only).
 */
class FinesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/fine_helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure helpers -------------------------------------------------------

    public function testStatusNormalisation(): void
    {
        $this->assertSame('paid', vk_normalize_fine_status('PAID'));
        $this->assertSame('waived', vk_normalize_fine_status(' waived '));
        $this->assertSame('pending', vk_normalize_fine_status('nonsense'));
        $this->assertSame('pending', vk_normalize_fine_status(''));
    }

    public function testStatusBadges(): void
    {
        $this->assertSame('success', vk_fine_status_badge('paid'));
        $this->assertSame('secondary', vk_fine_status_badge('waived'));
        $this->assertSame('warning', vk_fine_status_badge('pending'));
    }

    public function testSummaryTotalsByStatus(): void
    {
        $rows = [
            ['amount' => 1000, 'status' => 'pending'],
            ['amount' => 500,  'status' => 'pending'],
            ['amount' => 2000, 'status' => 'paid'],
            ['amount' => 300,  'status' => 'waived'],
        ];
        $s = vk_fine_summary($rows);
        $this->assertSame(1500.0, $s['pending']);
        $this->assertSame(2000.0, $s['paid']);
        $this->assertSame(300.0, $s['waived']);
        $this->assertSame(4, $s['count']);
    }

    // --- wiring (source guards) --------------------------------------------

    public function testMigrationRegisteredBeforeRoleSeed(): void
    {
        $mig = $this->src('database/migrate.php');
        $this->assertStringContainsString('add_fines_status_and_permission.php', $mig);
        $this->assertLessThan(
            strpos($mig, 'seed_vicoba_roles.php'),
            strpos($mig, 'add_fines_status_and_permission.php'),
            'fines migration must run before the role seed'
        );
        $m = $this->src('database/add_fines_status_and_permission.php');
        $this->assertStringContainsString('waived', $m);
        $this->assertStringContainsString('manage_fines', $m);
    }

    public function testMembersCannotSeeManageFines(): void
    {
        $this->assertStringContainsString("'manage_fines'", $this->src('includes/role_grants.php'));
    }

    public function testManageListIsPermissionGated(): void
    {
        $get = $this->src('api/get_fines.php');
        $this->assertStringContainsString('require_auth.php', $get);
        $this->assertStringContainsString("canView('manage_fines')", $get);
    }

    public function testStatusActionCarriesGuardStack(): void
    {
        $a = $this->src('actions/update_fine_status.php');
        $this->assertStringContainsString('require_auth.php', $a);
        $this->assertStringContainsString('require_csrf.php', $a);
        $this->assertStringContainsString("requirePermissionJson('edit', 'manage_fines')", $a);
    }

    public function testMyFinesScopedToLoggedInMember(): void
    {
        $my = $this->src('app/bms/customer/my_fines.php');
        $this->assertStringContainsString('require_login.php', $my);
        // must select the member's own customer_id from the session user, then
        // filter fines by it — never show another member's fines.
        $this->assertStringContainsString('WHERE user_id = ?', $my);
        $this->assertStringContainsString('f.customer_id = ?', $my);
    }
}
