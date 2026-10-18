<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The super-admin panel's pure helpers (superadmin/src/lib.php) and the rules
 * that keep the panel apart from the groups' sites.
 */
class SuperAdminPanelTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/superadmin/src/lib.php';
    }

    #[DataProvider('routes')]
    public function testPathsMapToPages(string $path, ?array $expected): void
    {
        $this->assertSame($expected, vk_admin_route($path));
    }

    public static function routes(): array
    {
        return [
            'home'              => ['/', ['groups', null]],
            'groups'            => ['/groups', ['groups', null]],
            'groups with query' => ['/groups?status=active', ['groups', null]],
            'one group'         => ['/groups/wisewallet', ['group', 'wisewallet']],
            'trailing slash'    => ['/groups/demo/', ['group', 'demo']],
            'request'           => ['/requests/12', ['request', '12']],
            'history'           => ['/history?group=demo', ['history', null]],
            'login'             => ['/login', ['login', null]],
            'bad group name'    => ['/groups/../etc', null],
            'upper case group'  => ['/groups/Demo', null],
            'request zero'      => ['/requests/0', null],
            'php file'          => ['/index.php', null],
            'unknown'           => ['/admin', null],
        ];
    }

    public function testTheFifthWrongPasswordLocksForFifteenMinutes(): void
    {
        $now = 1_800_000_000;
        $this->assertSame([1, null], vk_admin_after_failed_login(0, $now));
        $this->assertSame([4, null], vk_admin_after_failed_login(3, $now));
        $this->assertSame([0, $now + 900], vk_admin_after_failed_login(4, $now));
    }

    public function testALockLastsUntilItsTime(): void
    {
        $now = strtotime('2026-10-18 12:00:00');
        $this->assertTrue(vk_admin_is_locked('2026-10-18 12:10:00', $now));
        $this->assertFalse(vk_admin_is_locked('2026-10-18 11:59:00', $now));
        $this->assertFalse(vk_admin_is_locked(null, $now));
    }

    public function testSessionsEndAfterThirtyIdleMinutes(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(vk_admin_session_expired($now - 1799, $now));
        $this->assertTrue(vk_admin_session_expired($now - 1801, $now));
        $this->assertTrue(vk_admin_session_expired(null, $now));
    }

    public function testMysqlTimesAreReadInMysqlsOwnZone(): void
    {
        // MySQL on Tanzania time (+3h) wrote 23:43; that is 20:43 UTC.
        $this->assertSame(strtotime('2026-10-18 20:43:00 UTC'), vk_admin_db_ts('2026-10-18 23:43:00', 3 * 3600));
        $this->assertSame(strtotime('2026-10-18 23:43:00 UTC'), vk_admin_db_ts('2026-10-18 23:43:00', 0));
        $this->assertNull(vk_admin_db_ts(null, 0));
    }

    public function testTimesReadAsTodayYesterdayOrADate(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Africa/Dar_es_Salaam');
        $now = strtotime('2026-10-18 12:00:00');
        $this->assertSame('Today 02:15', vk_admin_when(strtotime('2026-10-18 02:15:00'), $now));
        $this->assertSame('Yesterday 18:40', vk_admin_when(strtotime('2026-10-17 18:40:00'), $now));
        $this->assertSame('12 Oct 2026 09:05', vk_admin_when(strtotime('2026-10-12 09:05:00'), $now));
        $this->assertSame('', vk_admin_when(null, $now));
        date_default_timezone_set($tz);
    }

    public function testSizesReadInPlainUnits(): void
    {
        $this->assertSame('512 B', vk_admin_bytes(512));
        $this->assertSame('1.5 KB', vk_admin_bytes(1536));
        $this->assertSame('293 KB', vk_admin_bytes(300000));
        $this->assertSame('1.2 MB', vk_admin_bytes(1258291));
    }

    public function testFiltersAcceptOnlyKnownValuesAndSearchIsCleaned(): void
    {
        $this->assertSame('active', vk_admin_filter('active', ['active', 'suspended']));
        $this->assertSame('', vk_admin_filter("active' OR 1=1", ['active']));
        $this->assertSame('demo', vk_admin_search("  de\x00mo "));
        $this->assertSame(60, mb_strlen(vk_admin_search(str_repeat('a', 100))));
    }

    public function testFormsNeedTheSessionsToken(): void
    {
        $this->assertTrue(vk_admin_csrf_valid('abc123', 'abc123'));
        $this->assertFalse(vk_admin_csrf_valid('abc123', 'abc124'));
        $this->assertFalse(vk_admin_csrf_valid(null, ''));
        $this->assertFalse(vk_admin_csrf_valid('', ''));
    }

    public function testThePanelAllowsNoScriptsAndNoFraming(): void
    {
        $h = vk_admin_security_headers();
        $this->assertStringContainsString("default-src 'none'", $h['Content-Security-Policy']);
        $this->assertStringNotContainsString('script-src', $h['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', $h['Content-Security-Policy']);
        $this->assertSame('DENY', $h['X-Frame-Options']);
    }

    public function testNoPageUsesInlineScriptsOrStyles(): void
    {
        foreach (glob(self::$root . '/superadmin/src/{,pages/}*.php', GLOB_BRACE) as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringNotContainsString('<script', $src, basename($f));
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $src, basename($f));
        }
    }

    public function testEveryPageButLoginNeedsASignedInSuperAdmin(): void
    {
        $index = (string) file_get_contents(self::$root . '/superadmin/public/index.php');
        $this->assertStringContainsString("if (\$page !== 'login') {\n    \$me = admin_require();", $index);
        $this->assertStringContainsString("if (\$_SERVER['REQUEST_METHOD'] === 'POST') {\n    csrf_check();", $index);
    }

    public function testThePanelIsNeverServedFromAGroupsAddress(): void
    {
        $this->assertStringContainsString('RewriteRule ^superadmin(/|$) - [R=404,L]', (string) file_get_contents(self::$root . '/.htaccess'));
        $this->assertStringContainsString('Require all denied', (string) file_get_contents(self::$root . '/superadmin/.htaccess'));
    }

    public function testThePanelNeverOpensAGroupsDatabase(): void
    {
        foreach (glob(self::$root . '/superadmin/src/{,pages/}*.php', GLOB_BRACE) as $f) {
            $src = (string) file_get_contents($f);
            // Mentioned in comments is fine; loaded is not.
            $this->assertDoesNotMatchRegularExpression('/\b(require|include)(_once)?(\s+|\s*\()[^;]*(tenant_config|provisioner)\.php/', $src, basename($f));
            $this->assertStringNotContainsString("'/etc/vikundi/tenants", $src, basename($f));
            $this->assertStringNotContainsString("'/etc/vikundi/provisioner", $src, basename($f));
        }
    }
}
