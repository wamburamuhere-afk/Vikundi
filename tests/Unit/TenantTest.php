<?php

namespace Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Multi-tenant lookup (includes/tenant.php). One shared code folder serves every
 * group; the host name decides which group's database and files a request gets.
 * The property these tests protect above all: a request that does not name a
 * known, active group reaches NOTHING — never a default database, never another
 * group's files.
 */
class TenantTest extends TestCase
{
    private const BASE = 'vikundi.bjptechnologies.co.tz';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/tenant.php';
        require_once dirname(__DIR__, 2) . '/includes/tenant_pages.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__vk_tenant']);
    }

    // — Subdomain rules ————————————————————————————————————————————————————

    #[DataProvider('validSubdomains')]
    public function testAcceptsValidSubdomains(string $sub): void
    {
        $this->assertTrue(vk_tenant_valid_subdomain($sub));
    }

    public static function validSubdomains(): array
    {
        return [['abc'], ['wisewallet'], ['ukuu-msakuzi'], ['group2026'], [str_repeat('a', 30)]];
    }

    #[DataProvider('invalidSubdomains')]
    public function testRejectsInvalidSubdomains(string $sub): void
    {
        $this->assertFalse(vk_tenant_valid_subdomain($sub));
    }

    public static function invalidSubdomains(): array
    {
        return [
            'too short'        => ['ab'],
            'too long'         => [str_repeat('a', 31)],
            'uppercase'        => ['WiseWallet'],
            'leading hyphen'   => ['-abc'],
            'trailing hyphen'  => ['abc-'],
            'double hyphen'    => ['ab--cd'],
            'underscore'       => ['wise_wallet'],
            'dot'              => ['a.bc'],
            'traversal'        => ['../x'],
            'empty'            => [''],
        ];
    }

    // — Host → subdomain ———————————————————————————————————————————————————

    public function testTakesTheSingleLabelInFrontOfTheBaseDomain(): void
    {
        $this->assertSame('wisewallet', vk_tenant_subdomain_for_host('wisewallet.' . self::BASE, self::BASE));
    }

    public function testIgnoresPortCaseAndTrailingDot(): void
    {
        $this->assertSame('wisewallet', vk_tenant_subdomain_for_host('WiseWallet.' . strtoupper(self::BASE) . '.:443', self::BASE));
    }

    #[DataProvider('hostsThatAreNotGroups')]
    public function testHostsThatAreNotAGroupAddressGiveNull(string $host): void
    {
        $this->assertNull(vk_tenant_subdomain_for_host($host, self::BASE));
    }

    public static function hostsThatAreNotGroups(): array
    {
        return [
            'the bare base'        => [self::BASE],
            'nested label'         => ['a.wisewallet.' . self::BASE],
            'another domain'       => ['wisewallet.example.com'],
            'suffix trick'         => ['wisewallet' . self::BASE],
            'base inside a label'  => ['wisewallet.' . self::BASE . '.evil.com'],
            'invalid label'        => ['wise_wallet.' . self::BASE],
            'IPv6 literal'         => ['[::1]'],
            'empty'                => [''],
        ];
    }

    // — Registry status → what the request gets ——————————————————————————

    public function testOnlyAnActiveGroupIsServed(): void
    {
        $this->assertSame('serve', vk_tenant_decide(['status' => 'active']));
        $this->assertSame('suspended', vk_tenant_decide(['status' => 'suspended']));
        $this->assertSame('not_found', vk_tenant_decide(['status' => 'archived']));
        $this->assertSame('not_found', vk_tenant_decide(['status' => 'provisioning']));
        $this->assertSame('not_found', vk_tenant_decide(null));
    }

    // — Lookup against the registry ————————————————————————————————————————

    public function testAFullHostMappingWinsOverTheSubdomain(): void
    {
        $prod = ['id' => 1, 'subdomain' => 'ukuu', 'status' => 'active'];
        $pdo = $this->registry(hostRow: $prod, subdomainRow: null);

        $this->assertSame($prod, vk_tenant_lookup($pdo, 'vikundi.bjptechnologies.co.tz', self::BASE));
    }

    public function testFallsBackToTheSubdomainWhenNoHostMappingExists(): void
    {
        $ww = ['id' => 2, 'subdomain' => 'wisewallet', 'status' => 'active'];
        $pdo = $this->registry(hostRow: null, subdomainRow: $ww);

        $this->assertSame($ww, vk_tenant_lookup($pdo, 'wisewallet.' . self::BASE, self::BASE));
    }

    public function testAnUnknownHostFindsNothing(): void
    {
        $pdo = $this->registry(hostRow: null, subdomainRow: null);

        $this->assertNull(vk_tenant_lookup($pdo, 'nobody.' . self::BASE, self::BASE));
        $this->assertNull(vk_tenant_lookup($pdo, 'example.com', self::BASE));
    }

    public function testAnInvalidSubdomainNeverReachesTheDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertNull(vk_tenant_find($pdo, "x' OR '1'='1"));
    }

    // — Command line ————————————————————————————————————————————————————

    public function testTheTenantFlagWinsOverTheEnvironment(): void
    {
        $this->assertSame('wisewallet', vk_tenant_cli_name(['migrate.php', '--tenant=wisewallet'], 'other'));
        $this->assertSame('wisewallet', vk_tenant_cli_name(['migrate.php', '--tenant', 'wisewallet'], null));
        $this->assertSame('other', vk_tenant_cli_name(['migrate.php'], 'other'));
        $this->assertNull(vk_tenant_cli_name(['migrate.php'], null));
        $this->assertNull(vk_tenant_cli_name(['migrate.php'], '  '));
    }

    // — Storage paths ————————————————————————————————————————————————————

    public function testStoredPathsAreNormalised(): void
    {
        $this->assertSame('uploads/a.pdf', vk_storage_relative('uploads/a.pdf'));
        $this->assertSame('uploads/a.pdf', vk_storage_relative('/uploads/a.pdf'));
        $this->assertSame('uploads/a.pdf', vk_storage_relative('../uploads/a.pdf'));
        $this->assertSame('uploads/a.pdf', vk_storage_relative('./uploads//a.pdf'));
        $this->assertSame('uploads/a.pdf', vk_storage_relative('uploads\\a.pdf'));
    }

    public function testAPathThatClimbsOutIsRefused(): void
    {
        $this->assertNull(vk_storage_relative('uploads/../../etc/passwd'));
        $this->assertNull(vk_storage_relative(''));
        $this->assertNull(vk_storage_relative('/'));
    }

    public function testSingleSiteModeKeepsFilesInTheCodeFolder(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertNull(vk_tenant_current());
        $this->assertSame($root . '/uploads/avatars', vk_storage_path('uploads/avatars'));
        // The AI key lives in the private folder outside the web root; a key
        // still at the old place (includes/) is used until it is moved, so the
        // answer depends on whether this checkout has one (CI starts without).
        $old = $root . '/includes/ai_secret.key';
        $expected = (!is_file(vk_private_dir() . '/ai_secret.key') && is_file($old)) ? $old : vk_private_dir() . '/ai_secret.key';
        $this->assertSame($expected, vk_secret_key_file());
        $this->assertStringStartsNotWith($root . '/backups', vk_storage_path('backups'), 'backups are outside the code folder');
    }

    public function testTenantModePutsEveryFileUnderTheGroupsOwnFolder(): void
    {
        $data = sys_get_temp_dir() . '/vk_tenant_test_' . bin2hex(random_bytes(4));
        vk_tenant_set(['subdomain' => 'wisewallet', 'status' => 'active'], $data . '/');

        $this->assertSame($data . '/uploads/avatars', vk_storage_path('uploads/avatars'));
        $this->assertSame($data . '/uploads/document_library/x.pdf', vk_storage_path('../uploads/document_library/x.pdf'));
        $this->assertSame($data . '/backups', vk_storage_path('backups'));
        $this->assertStringStartsWith($data . '/', vk_storage_path('uploads/../../other-group/x'),
            'a climbing path must still resolve inside this group\'s folder');
        $this->assertStringStartsWith($data . '/private/', vk_secret_key_file());

        @rmdir($data . '/private');
        @rmdir($data);
    }

    public function testAnUploadedLogoIsReadFromTheGroupsStorageAndTheShippedOnesFromTheCode(): void
    {
        $data = sys_get_temp_dir() . '/vk_tenant_logo_' . bin2hex(random_bytes(4));
        mkdir($data . '/assets/images', 0700, true);
        file_put_contents($data . '/assets/images/group_logo_1.png', 'x');
        vk_tenant_set(['subdomain' => 'wisewallet', 'status' => 'active'], $data);

        $this->assertSame($data . '/assets/images/group_logo_1.png', vk_logo_file('group_logo_1.png'));
        $this->assertSame(dirname(__DIR__, 2) . '/assets/images/logo1.png', vk_logo_file('logo1.png'));
        $this->assertSame($data . '/assets/images/group_logo_1.png', vk_logo_file('../../group_logo_1.png'),
            'only the base name is ever used');

        unlink($data . '/assets/images/group_logo_1.png');
        rmdir($data . '/assets/images');
        rmdir($data . '/assets');
        rmdir($data);
    }

    // — The pages a request gets when it reaches no group ——————————————————

    public function testNotFoundAndOfflineCarryTheRightStatus(): void
    {
        $this->assertSame(404, vk_tenant_page_text('not_found')[0]);
        $this->assertSame(503, vk_tenant_page_text('suspended')[0]);
    }

    public function testTheNotFoundPageNeverNamesAGroup(): void
    {
        $html = vk_tenant_page_html('not_found', ['name' => 'Wise Wallet M-KOBA']);

        $this->assertStringNotContainsString('Wise Wallet', $html);
        $this->assertStringContainsString('Kikundi hakijapatikana', $html);
    }

    public function testTheOfflinePageNamesTheGroupItBelongsTo(): void
    {
        $html = vk_tenant_page_html('suspended', ['name' => 'Wise <Wallet>']);

        $this->assertStringContainsString('Wise &lt;Wallet&gt;', $html);
        $this->assertStringContainsString('Haipatikani kwa sasa', $html);
    }

    public function testApiCallersGetJson(): void
    {
        $this->assertTrue(vk_tenant_wants_json(['REQUEST_URI' => '/api/v1/members']));
        $this->assertTrue(vk_tenant_wants_json(['REQUEST_URI' => '/x', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        $this->assertFalse(vk_tenant_wants_json(['REQUEST_URI' => '/login']));
    }

    // — The bootstrap's safety rules, pinned in its source ———————————————————

    public function testTheBootstrapNeverFallsBackToADefaultDatabase(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/tenant_config.php');

        $this->assertStringContainsString("vk_tenant_stop(\$decision, \$row)", $src);
        $this->assertStringNotContainsString("dbname=vikundi", $src);
        // A session started before the group was known would live in the shared
        // folder; the bootstrap must refuse rather than carry on.
        $this->assertStringContainsString('session_status() === PHP_SESSION_ACTIVE', $src);
        $this->assertStringContainsString("session_save_path(", $src);
    }

    /**
     * No code may build a stored-file path from the code folder: every upload,
     * backup and logo goes through vk_storage_path(), or one group's files land
     * in the shared code folder where every other group can reach them.
     */
    public function testNoCodeBuildsStoragePathsFromTheCodeFolder(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen($root) + 1);
            if (!str_ends_with($rel, '.php')
                || preg_match('#^(vendor|tests|TCPDF|PHPMailer|docs|node_modules)/#', $rel)
                || $rel === 'includes/tenant.php') {
                continue;
            }
            foreach (file($path) as $n => $line) {
                if (preg_match('#^\s*(\*|//|\#)#', $line)) {
                    continue;
                }
                if (preg_match('#(__DIR__|ROOT_DIR|dirname\(__DIR__[^)]*\))[^;]*[\'"]/?(\.\./)*(uploads|backups|downloads)\b#', $line)
                    || preg_match('#(__DIR__|ROOT_DIR|dirname\(__DIR__[^)]*\))[^;]*[\'"]/?(\.\./)*assets/images/[\'"]\s*\.\s*\$#', $line)) {
                    $offenders[] = $rel . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'Use vk_storage_path() (includes/tenant.php) for stored files.');
    }

    /** A registry stand-in: the first prepared query is the host lookup, the second the subdomain. */
    private function registry(?array $hostRow, ?array $subdomainRow): PDO
    {
        $host = $this->createMock(PDOStatement::class);
        $host->method('execute')->willReturn(true);
        $host->method('fetch')->willReturn($hostRow ?? false);

        $sub = $this->createMock(PDOStatement::class);
        $sub->method('execute')->willReturn(true);
        $sub->method('fetch')->willReturn($subdomainRow ?? false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static fn(string $sql) => str_contains($sql, 'tenant_hosts') ? $host : $sub
        );
        return $pdo;
    }
}
