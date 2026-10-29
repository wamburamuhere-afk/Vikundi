<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules behind the super-admin panel's requests (includes/admin_requests.php)
 * and the worker that carries them out (bin/run_requests.php).
 */
class AdminRequestsTest extends TestCase
{
    private const BASE = 'vikundi.bjptechnologies.co.tz';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/admin_requests.php';
        require_once dirname(__DIR__, 2) . '/includes/admin_mail.php';
    }

    private static function goodCreate(): array
    {
        return ['name' => 'Kikundi cha Mfano', 'chair_first' => 'Amina', 'chair_last' => 'Juma',
                'chair_phone' => '255 712 345 678', 'chair_email' => '', 'demo' => '0'];
    }

    public function testAValidCreateRequestPasses(): void
    {
        $this->assertSame([], vk_req_validate('create', 'mfano', self::goodCreate(), ['demo'], [], self::BASE));
    }

    #[DataProvider('badCreates')]
    public function testBadCreateRequestsAreRefusedWithAReason(string $sub, array $change, string $because): void
    {
        $errors = vk_req_validate('create', $sub, array_merge(self::goodCreate(), $change), ['demo'], [], self::BASE);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($because, implode(' ', $errors));
    }

    public static function badCreates(): array
    {
        return [
            'taken address'        => ['demo', [], 'already exists'],
            'reserved address'     => ['admin', [], 'reserved'],
            'invalid address'      => ['Mfano!', [], 'not a valid subdomain'],
            'no group name'        => ['mfano', ['name' => ' '], 'Group name'],
            'newline in name'      => ['mfano', ['name' => "Kikundi\nX"], 'Group name'],
            'digits in first name' => ['mfano', ['chair_first' => 'Am1na'], 'first name'],
            'option as last name'  => ['mfano', ['chair_last' => '--demo'], 'last name'],
            'short phone'          => ['mfano', ['chair_phone' => '12345'], 'phone'],
            'bad email'            => ['mfano', ['chair_email' => 'not-an-email'], 'email'],
        ];
    }

    public function testOtherActionsNeedOnlyAValidAddressAndUnknownActionsAreRefused(): void
    {
        $this->assertSame([], vk_req_validate('suspend', 'wisewallet'));
        $this->assertSame([], vk_req_validate('cert', null));
        $this->assertNotEmpty(vk_req_validate('archive', 'x; rm -rf /'));
        $this->assertNotEmpty(vk_req_validate('drop', 'wisewallet'));
    }

    public function testCreateBecomesOneArgumentPerValue(): void
    {
        $args = vk_req_command_args('create', 'mfano', array_merge(self::goodCreate(), ['chair_email' => 'a@b.tz', 'demo' => '1']));
        $this->assertSame([
            '--create', '--subdomain=mfano', '--name=Kikundi cha Mfano', '--chair-first=Amina',
            '--chair-last=Juma', '--chair-phone=255712345678', '--chair-email=a@b.tz', '--demo',
        ], $args);
    }

    public function testOtherActionsBecomeTheirSingleOption(): void
    {
        $this->assertSame(['--suspend=wisewallet'], vk_req_command_args('suspend', 'wisewallet'));
        $this->assertSame(['--cert'], vk_req_command_args('cert', null));
    }

    public function testAValueCannotSmuggleInAnotherOption(): void
    {
        // The value stays inside its own --key=value element; provision_tenant's
        // parser splits on the first '=' only.
        $args = vk_req_command_args('create', 'mfano', array_merge(self::goodCreate(), ['name' => 'X --demo --archive=ukuu']));
        $parsed = vk_prov_parse_args(array_merge(['provision_tenant.php'], $args));
        $this->assertSame('X --demo --archive=ukuu', $parsed['name']);
        $this->assertArrayNotHasKey('archive', $parsed);
        $this->assertArrayNotHasKey('demo', $parsed);
    }

    public function testTheChairpersonPasswordIsTakenOutOfTheStoredOutput(): void
    {
        $raw = "6/6 Active: https://mfano." . self::BASE . "\n  First Chairperson: username ajuma, password Xy9_kLm-Q2rT\n  (shown once)\n";
        [$out, $secret] = vk_req_extract_secret($raw);
        $this->assertSame('Xy9_kLm-Q2rT', $secret);
        $this->assertStringNotContainsString('Xy9_kLm-Q2rT', $out);
        $this->assertStringContainsString('username ajuma, password (shown once in the panel)', $out);

        $this->assertSame(['nothing here', null], vk_req_extract_secret('nothing here'));
    }

    public function testExitCodesBecomeStatuses(): void
    {
        $this->assertSame(['done', null], vk_req_outcome('suspend', 0));
        [$status, $warning] = vk_req_outcome('create', 2);
        $this->assertSame('done', $status);
        $this->assertStringContainsString('Retry certificate', $warning);
        $this->assertSame(['failed', null], vk_req_outcome('cert', 2));
        $this->assertSame(['failed', null], vk_req_outcome('create', 1));
        $this->assertSame(['failed', null], vk_req_outcome('create', 255));
    }

    public function testLongOutputKeepsItsEnd(): void
    {
        $out = vk_req_trim_output(str_repeat('a', 100) . 'THE-END', 20);
        $this->assertStringEndsWith('THE-END', $out);
        $this->assertStringStartsWith('[... earlier output cut ...]', $out);
        $this->assertSame('short', vk_req_trim_output('short'));
    }

    public function testSuperAdminUsernamesAndPasswords(): void
    {
        $this->assertTrue(vk_superadmin_valid_username('jabir'));
        $this->assertFalse(vk_superadmin_valid_username('Jabir'));
        $this->assertFalse(vk_superadmin_valid_username("ja'bir"));

        $this->assertNull(vk_superadmin_password_problem('Kilimanjaro2026', 'jabir'));
        $this->assertNotNull(vk_superadmin_password_problem('short1', 'jabir'));
        $this->assertNotNull(vk_superadmin_password_problem('lettersonlyhere', 'jabir'));
        $this->assertNotNull(vk_superadmin_password_problem('jabir12345678', 'jabir'));
    }

    public function testTheWorkerRunsTheCommandWithoutAShellOneAtATime(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/run_requests.php');

        $this->assertStringContainsString("GET_LOCK('vikundi_run_requests', 0)", $src);
        $this->assertStringContainsString("WHERE id = ? AND status = 'waiting'", $src, 'claims a request atomically');
        $this->assertStringContainsString('proc_open($cmd,', $src);
        $this->assertStringContainsString('array_merge([PHP_BINARY, $script], vk_req_command_args(', $src, 'argv array: no shell');
        $this->assertStringNotContainsString('shell_exec', $src);
        $this->assertStringContainsString('vk_req_validate($action, $sub, $params, $taken, $hosts, $base)', $src, 'checks again before running');
        $this->assertStringContainsString("\$env['VIKUNDI_REQUEST_ID']", $src);
    }

    public function testCommandLineChangesAreRecordedUnlessTheWorkerRanThem(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php');
        $this->assertStringContainsString("if (getenv('VIKUNDI_REQUEST_ID')) {", $src);
        $this->assertSame(6, substr_count($src, 'server_audit($reg,'), 'cert, status changes, features, add-host, create failed, create done');
    }

    public function testTheNewScriptsRefuseToRunOverHttp(): void
    {
        foreach (['bin/run_requests.php', 'bin/superadmin.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;/", $src, $f);
        }
    }

    public function testTheAdminTablesExistInTheSchema(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/control/admin_schema.sql');
        foreach (['superadmins', 'provision_requests', 'admin_audit_log'] as $t) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $t", $sql);
        }
        $this->assertStringContainsString("ENUM('create','suspend','resume','archive','cert','features')", $sql);
    }

    public function testAnEmailGoesOutForFailuresAndWarningsOnly(): void
    {
        $this->assertTrue(vk_admin_alert_needed('failed', null));
        $this->assertTrue(vk_admin_alert_needed('done', 'Group created, but the https certificate failed.'));
        $this->assertFalse(vk_admin_alert_needed('done', null));
    }

    public function testTheAlertNamesTheRequestAndCarriesTheEndOfTheOutput(): void
    {
        $out = implode("\n", array_map(static fn($i) => "line $i", range(1, 60))) . "\nError: cannot create database";
        [$subject, $body] = vk_admin_alert_message(7, 'create', 'mfano', 'failed', null, 'Jabir Mussa', $out, self::BASE);
        $this->assertSame('[Vikundi] Request #7 failed: Create group mfano', $subject);
        $this->assertStringContainsString('Asked by: Jabir Mussa', $body);
        $this->assertStringContainsString('https://admin.' . self::BASE . '/requests/7', $body);
        $this->assertStringContainsString('Error: cannot create database', $body);
        $this->assertStringNotContainsString("line 1\n", $body, 'only the last 40 lines');

        [$subject] = vk_admin_alert_message(8, 'create', 'x', 'done', 'cert failed', 'J', '', self::BASE);
        $this->assertSame('[Vikundi] Request #8 needs a look: Create group x', $subject);
    }

    public function testWithoutMailSettingsNothingIsSentAndTheWorkerCarriesOn(): void
    {
        $this->assertSame('no alert_to / smtp settings in admin.php', vk_admin_send_alert(null, 's', 'b'));
        $this->assertSame('no alert_to / smtp settings in admin.php', vk_admin_send_alert(['alert_to' => 'a@b.tz'], 's', 'b'));
    }

    public function testTheWorkerEmailsFromTheStoredPasswordFreeOutput(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/run_requests.php');
        $this->assertStringContainsString('if (vk_admin_alert_needed($status, $warning)) {', $src);
        // $output is the copy after vk_req_extract_secret() removed the password.
        $this->assertLessThan(strpos($src, 'vk_admin_alert_message('), strpos($src, '[$output, $secret] = vk_req_extract_secret($raw);'));
    }
}
