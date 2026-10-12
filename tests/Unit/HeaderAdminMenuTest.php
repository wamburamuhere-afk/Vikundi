<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Administration menu in header.php, and the demo group's logo.
 *
 * The menu was gated on a hand-written role list that omitted 'chairperson' —
 * the role name the system itself creates — so the head of a group had admin
 * access to every page and no link to any of them.
 */
class HeaderAdminMenuTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/roles.php';
    }

    public function testTheMenuUsesTheSharedAdminRuleNotItsOwnList(): void
    {
        $src = file_get_contents(__DIR__ . '/../../header.php');
        $menu = strpos($src, 'id="adminDropdown"');
        $this->assertNotFalse($menu);

        $gate = substr($src, $menu - 900, 900);
        $this->assertStringContainsString("vk_role_is_admin(\$_SESSION['role_id'] ?? null, \$user_role)", $gate);
        $this->assertStringNotContainsString("in_array(\$user_role_lower, ['admin', 'mwenyekiti', 'chairman'])", $src);
    }

    public function testEveryAdminRoleSeesTheMenuAndOfficersDoNot(): void
    {
        // By name, as the seeder creates them and as groups spell them.
        foreach (['Chairperson', 'chairperson', 'Mwenyekiti', 'Chairman', 'Admin', 'Administrator'] as $role) {
            $this->assertTrue(vk_role_is_admin(null, $role), "$role must see the Administration menu");
        }
        // By id: 2 is the Chairperson on a fresh install.
        $this->assertTrue(vk_role_is_admin(2, null));

        foreach (['Secretary', 'Treasurer', 'Member', 'Katibu', '', null] as $role) {
            $this->assertFalse(vk_role_is_admin(13, $role), var_export($role, true) . ' must not see the Administration menu');
        }
    }

    public function testTheMenuAgreesWithIsAdmin(): void
    {
        // isAdmin() (core/permissions.php) decides page access; the menu must not
        // be stricter than the pages it links to.
        $perms = file_get_contents(__DIR__ . '/../../core/permissions.php');
        foreach (vk_role_admin_names() as $name) {
            $this->assertStringContainsString("'$name'", $perms, "isAdmin() must also know '$name'");
        }
    }

    // ── the demo group's logo ────────────────────────────────────────────────
    public function testTheDemoSeederGivesTheGroupItsOwnLogo(): void
    {
        $seeder = file_get_contents(__DIR__ . '/../../database/seed_wise_wallet_demo.php');
        $this->assertStringContainsString("'group_logo'                 => 'wise_wallet_demo_logo.png'", $seeder);

        $png = __DIR__ . '/../../assets/images/wise_wallet_demo_logo.png';
        $this->assertFileExists($png);
        // A raster file: the app refuses SVG logos, and PDF print headers need one.
        $this->assertSame("\x89PNG", substr((string) file_get_contents($png), 0, 4));
        $this->assertSame('image/png', (string) (getimagesize($png)['mime'] ?? ''));
    }

    public function testTheLogoSourceIsPlainArtwork(): void
    {
        $svg = file_get_contents(__DIR__ . '/../../assets/images/demo/wise_wallet_logo.svg');
        $this->assertStringContainsString('<svg', $svg);
        foreach (['<script', 'onload=', 'javascript:', '<foreignObject', 'href='] as $active) {
            $this->assertStringNotContainsString($active, $svg);
        }
    }
}
