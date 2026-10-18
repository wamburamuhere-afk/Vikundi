<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules behind bin/provision_tenant.php and bin/migrate_all.php
 * (includes/provisioning.php). Creating a group touches MySQL, the registry and
 * the disk; these tests pin the decisions that decide what gets created.
 */
class ProvisioningTest extends TestCase
{
    private const BASE = 'vikundi.bjptechnologies.co.tz';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/provisioning.php';
    }

    public function testDatabaseAndUserNameComeFromTheSubdomain(): void
    {
        $this->assertSame('vk_wisewallet', vk_prov_db_name('wisewallet'));
        $this->assertSame('vk_ukuu_msakuzi', vk_prov_db_name('ukuu-msakuzi'));
    }

    public function testAcceptsAFreeValidSubdomain(): void
    {
        $this->assertNull(vk_prov_validate_new('wisewallet', ['ukuu'], ['ukuu.' . self::BASE], self::BASE));
    }

    #[DataProvider('refusedSubdomains')]
    public function testRefusesSubdomainsThatCannotBeUsed(string $sub, array $taken, array $hosts, string $because): void
    {
        $why = vk_prov_validate_new($sub, $taken, $hosts, self::BASE);
        $this->assertNotNull($why);
        $this->assertStringContainsString($because, $why);
    }

    public static function refusedSubdomains(): array
    {
        return [
            'invalid'             => ['Wise_Wallet', [], [], 'not a valid subdomain'],
            'too long for MySQL'  => [str_repeat('a', 30), [], [], 'too long'],
            'reserved'            => ['admin', [], [], 'reserved'],
            'reserved staging'    => ['staging', [], [], 'reserved'],
            'already a group'     => ['ukuu', ['ukuu'], [], 'already exists'],
            'host already mapped' => ['ukuu', [], ['ukuu.' . self::BASE], 'already mapped'],
        ];
    }

    public function testTwentyNineCharactersIsTheLongestThatFitsAMysqlUserName(): void
    {
        $sub = str_repeat('a', 29);
        $this->assertNull(vk_prov_validate_new($sub, [], [], self::BASE));
        $this->assertSame(32, strlen(vk_prov_db_name($sub)));
    }

    public function testOnlyPlainIdentifiersAreAcceptedForAdoptedDatabases(): void
    {
        $this->assertTrue(vk_prov_valid_db_identifier('bjptechn_vikundi'));
        $this->assertFalse(vk_prov_valid_db_identifier('x`; DROP DATABASE y; --'));
        $this->assertFalse(vk_prov_valid_db_identifier("a'b"));
        $this->assertFalse(vk_prov_valid_db_identifier(''));
    }

    public function testSecretsAreRandomAndSafeInsideQuotes(): void
    {
        $a = vk_prov_secret();
        $b = vk_prov_secret();
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $a);
    }

    public function testTheSecretsFileReturnsBothSecrets(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vk_sec');
        file_put_contents($file, vk_prov_secrets_file("p'w\\d", 'jwt'));
        $loaded = require $file;
        unlink($file);

        $this->assertSame(['db_password' => "p'w\\d", 'jwt_secret' => 'jwt'], $loaded);
    }

    public function testEveryGroupGetsTheFoldersTheAppWritesTo(): void
    {
        foreach (['uploads', 'assets/images', 'downloads', 'backups', 'sessions', 'private'] as $d) {
            $this->assertContains($d, vk_prov_data_dirs());
        }
    }

    public function testUsernameFollowsTheAppsConvention(): void
    {
        $this->assertSame('amollel', vk_prov_username('Amina', 'Mollel'));
        $this->assertSame('lmushioneil', vk_prov_username('Laurian', "Mushi-O'Neil"));
    }

    public function testArgumentsParse(): void
    {
        $args = vk_prov_parse_args(['x.php', '--create', '--subdomain=wisewallet', '--name', 'Wise Wallet', '--demo']);
        $this->assertSame(['create' => true, 'subdomain' => 'wisewallet', 'name' => 'Wise Wallet', 'demo' => true], $args);
    }

    public function testStatusChangesFollowTheLifecycle(): void
    {
        $this->assertSame(['suspended', 'suspended_at'], vk_prov_status_change('suspend', 'active'));
        $this->assertSame(['active', null], vk_prov_status_change('resume', 'suspended'));
        $this->assertSame(['archived', 'archived_at'], vk_prov_status_change('archive', 'active'));
        $this->assertSame(['archived', 'archived_at'], vk_prov_status_change('archive', 'suspended'));

        $this->assertIsString(vk_prov_status_change('resume', 'active'));
        $this->assertIsString(vk_prov_status_change('suspend', 'archived'));
        $this->assertIsString(vk_prov_status_change('archive', 'provisioning'));
    }

    public function testTheScriptsRefuseToRunOverHttp(): void
    {
        foreach (['bin/provision_tenant.php', 'bin/migrate_all.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;/", $src, $f);
        }
        $this->assertFileExists(dirname(__DIR__, 2) . '/bin/.htaccess');
    }

    public function testCreateUndoesItsStepsOnFailureAndNeverTakesAPasswordOnTheCommandLine(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php');

        $this->assertStringContainsString('$rollback();', $src);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS', $src);
        $this->assertStringContainsString('DROP USER IF EXISTS', $src);
        $this->assertStringContainsString("stty -echo", $src, 'adopt reads the password from the terminal');
        $this->assertStringNotContainsString("\$args['db-password']", $src);
    }

    public function testTheDeployUpdatesTheSharedFolderAndMigratesEveryGroup(): void
    {
        $yml = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy.yml');

        $this->assertStringContainsString('path: /srv/vikundi/app', $yml);
        $this->assertStringContainsString('php bin/migrate_all.php', $yml);
        // Merging before the server is set up must not break the existing deploys.
        $this->assertMatchesRegularExpression('/SITE_MODE" = "shared" \]; then\s*echo "==> \$SITE_PATH is not set up[^\n]*\n\s*exit 0/', $yml);
        // The two existing sites keep deploying exactly as before.
        $this->assertStringContainsString('path: /var/www/vikundi.bjptechnologies.co.tz/public_html', $yml);
        $this->assertStringContainsString('php database/migrate.php || echo "migrate step reported issues"', $yml);
    }

    public function testTheNightlyBackupUsesTheAppsOwnAutoBackupNamingAndPruning(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/backup_all.php');

        $this->assertStringContainsString("'auto_backup_' . date('Y-m-d_H-i-s') . '.sql'", $src);
        $this->assertStringContainsString('vikundi_prune_backups($dir, 7)', $src);
        $this->assertStringContainsString("'.last_auto_backup'", $src);
        $this->assertMatchesRegularExpression("/if \\(PHP_SAPI !== 'cli'\\)/", $src);
    }

    public function testTheCertificateCoversEveryGroupsOwnAddressSortedAndOnce(): void
    {
        $this->assertSame(
            ['ukuu.' . self::BASE, 'wisewallet.' . self::BASE],
            vk_prov_cert_names(['wisewallet', 'ukuu', 'UKUU', ''], self::BASE)
        );
    }

    public function testCertbotProvesEachAddressOverHttpAndExpandsOneCertificate(): void
    {
        [$ok, $cmd] = vk_prov_certbot_command(['a.' . self::BASE, 'b.' . self::BASE], '/var/lib/vikundi/acme', 'vikundi-groups', false);

        $this->assertTrue($ok);
        $this->assertStringStartsWith("sudo -n 'certbot' 'certonly' '--webroot' '-w' '/var/lib/vikundi/acme'", $cmd);
        $this->assertStringContainsString("'--cert-name' 'vikundi-groups' '--expand' '--non-interactive'", $cmd);
        $this->assertStringContainsString("'--deploy-hook' 'systemctl reload apache2'", $cmd);
        $this->assertStringContainsString("'-d' 'a." . self::BASE . "' '-d' 'b." . self::BASE . "'", $cmd);
    }

    public function testRootRunsCertbotWithoutSudo(): void
    {
        [, $cmd] = vk_prov_certbot_command(['a.' . self::BASE], '/w', 'c', true);
        $this->assertStringStartsWith("'certbot'", $cmd);
    }

    public function testCertbotRefusesAnEmptyOrOverfullCertificate(): void
    {
        $this->assertFalse(vk_prov_certbot_command([], '/w', 'c', false)[0]);
        $many = array_map(static fn($i) => "g$i." . self::BASE, range(1, 101));
        [$ok, $why] = vk_prov_certbot_command($many, '/w', 'c', false);
        $this->assertFalse($ok);
        $this->assertStringContainsString('100', $why);
    }

    public function testAFailedCertificateNeverUndoesTheGroup(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php');
        // The certificate step runs after the rollback block, outside it.
        $this->assertGreaterThan(strrpos($src, '$rollback();'), strrpos($src, 'update_certificate($reg, $control, $base) ? 0 : 2'));
        $this->assertStringContainsString("isset(\$args['cert'])", $src);
    }

    public function testDatabasePasswordsPassTheServersMediumPasswordPolicy(): void
    {
        $pw = vk_prov_db_password();
        $this->assertGreaterThanOrEqual(8, strlen($pw));
        $this->assertMatchesRegularExpression('/[A-Z]/', $pw);
        $this->assertMatchesRegularExpression('/[a-z]/', $pw);
        $this->assertMatchesRegularExpression('/[0-9]/', $pw);
        $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $pw);
        $this->assertStringNotContainsString("'", $pw);
        $this->assertNotSame($pw, vk_prov_db_password());
        $this->assertStringContainsString('$dbPassword = vk_prov_db_password();',
            (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php'));
    }

    public function testTheGroupsGrantGoesThroughRootsOnePurposeRoutine(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/provision_tenant.php');
        $this->assertStringContainsString("CALL vikundi_control.vk_grant_group(?)", $src);
        $this->assertStringNotContainsString('->exec("GRANT ', $src, 'the provisioner itself cannot GRANT (MySQL 1044)');

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/control/procedures.sql');
        $this->assertStringContainsString('SQL SECURITY DEFINER', $sql);
        $this->assertStringContainsString("REGEXP_LIKE(p_name, '^vk_[a-z0-9_]{1,29}$', 'c')", $sql);
        $this->assertStringContainsString("GRANT EXECUTE ON PROCEDURE vikundi_control.vk_grant_group TO 'vikundi_prov'@'localhost'", $sql);
        // Database and user share the name, so the routine needs only one argument.
        $this->assertSame(vk_prov_db_name('a-b'), 'vk_a_b');
    }

    public function testTheDemoSiteCanBeRegisteredAsTheGroupDemo(): void
    {
        $this->assertNull(vk_prov_validate_new('demo', ['wisewallet'], [], self::BASE));
        $this->assertNotContains('demo', vk_tenant_reserved_subdomains());
    }

    public function testTheDemoSiteIsNoLongerDeployedToItsOldFolder(): void
    {
        $yml = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy.yml');
        $this->assertStringNotContainsString('/var/www/demo.vikundi.bjptechnologies.co.tz', $yml);
    }
}
