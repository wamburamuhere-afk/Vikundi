<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bin/wisewallet_go_live.php turns the Wise Wallet demo into the client's real, empty
 * group. It TRUNCATEs tables, so the safety rules are pinned here.
 */
final class WisewalletGoLiveTest extends TestCase
{
    private static string $code;

    public static function setUpBeforeClass(): void
    {
        self::$code = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/wisewallet_go_live.php');
    }

    /** The PHP array literal of a const in the script. */
    private function constList(string $name): array
    {
        $this->assertSame(1, preg_match('/const ' . $name . ' = \[(.*?)\];/s', self::$code, $m), $name);
        preg_match_all("/'([a-z_]+)'/", $m[1], $t);
        return $t[1];
    }

    public function testItOnlyRunsFromTheCommandLine(): void
    {
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')", self::$code);
    }

    public function testItRefusesAnyGroupButTheDemoBeforeTouchingAnything(): void
    {
        $this->assertStringContainsString("const WWGL_DEMO_GROUP = 'Wise Wallet M-KOBA';", self::$code);
        $this->assertStringContainsString('if ($group !== WWGL_DEMO_GROUP) {', self::$code);
        $guard = strpos(self::$code, 'if ($group !== WWGL_DEMO_GROUP) {');
        $this->assertLessThan(strpos(self::$code, 'TRUNCATE TABLE'), $guard);
        $this->assertLessThan(strpos(self::$code, 'TRUNCATE TABLE'), strpos(self::$code, 'if (!$commit) {'), 'a dry run by default');
    }

    public function testItWipesTheMembersAndTheirRecordsButNeverTheSetup(): void
    {
        $wipe = $this->constList('WWGL_WIPE');
        foreach (['users', 'customers', 'contributions', 'vicoba_loans', 'fines', 'activity_logs', 'mkoba_statement_rows'] as $t) {
            $this->assertContains($t, $wipe);
        }
        foreach (['roles', 'role_permissions', 'permissions', 'group_settings', 'system_settings', 'authored_document_templates'] as $t) {
            $this->assertNotContains($t, $wipe, "$t is setup, not sample data");
        }
        // Only tables in the list are wiped; everything else is reported as kept.
        $this->assertStringContainsString('if (in_array($t, WWGL_WIPE, true)) {', self::$code);
        $this->assertStringContainsString('foreach (array_keys($wipe) as $t) {', self::$code);
    }

    public function testTheDemoRulesGoAndTheRealDetailsComeIn(): void
    {
        $gone = $this->constList('WWGL_DEMO_SETTINGS');
        foreach (['monthly_contribution', 'share_price', 'group_logo', 'group_registration_number', 'contribution_start_date'] as $k) {
            $this->assertContains($k, $gone);
        }
        $this->assertNotContains('group_name', $gone);
        $this->assertStringContainsString("'group_name' => \$name, 'group_physical_address' => \$address, 'group_email' => \$email, 'group_phone' => \$phone", self::$code);
    }

    public function testTheChairpersonIsMadeLikeANewGroupsFirstChairperson(): void
    {
        $prov = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php');
        $insert = "VALUES (?, ?, ?, ?, 'Chairperson', 'Chairperson', ?, 'active', 1, 0, ?, ?, 'en', 'en', NOW())";
        $this->assertStringContainsString($insert, $prov);
        $this->assertStringContainsString($insert, self::$code);
        $this->assertStringContainsString("VALUES (?, ?, ?, 'M001', ?, 'individual', ?, ?, ?, 'active', NOW(), ?)", self::$code);
        $this->assertStringContainsString('password_hash($password, PASSWORD_DEFAULT)', self::$code);
        $this->assertStringContainsString('$password = vk_prov_secret(9);', self::$code);
    }
}
