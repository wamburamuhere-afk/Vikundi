<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sample data for demo groups (includes/demo_data.php, bin/demo_data.php) and the
 * panel's "Clear demo data" (provision_tenant.php --clear-demo). The seeding and
 * the clear themselves are verified by running them on a migrated database; these
 * tests pin the rules that keep real people and real groups safe.
 */
final class DemoDataTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/demo_data.php';
        require_once self::$root . '/includes/admin_requests.php';
    }

    private function src(string $file): string
    {
        return (string) file_get_contents(self::$root . '/' . $file);
    }

    private function before(string $first, string $second, string $haystack): void
    {
        $a = strpos($haystack, $first);
        $b = strpos($haystack, $second);
        $this->assertNotFalse($a, "missing: $first");
        $this->assertNotFalse($b, "missing: $second");
        $this->assertLessThan($b, $a, "\"$first\" must come before \"$second\"");
    }

    // ── the sample people ─────────────────────────────────────────────────────

    public function testSamplePeopleCanReachNobody(): void
    {
        $seen = ['username' => [], 'phone' => [], 'email' => [], 'code' => []];
        foreach (vk_demo_roster() as $i => [$first, $last]) {
            $p = vk_demo_person($i + 1, $first, $last);
            $this->assertStringEndsWith('@demo.example', $p['email']);
            $this->assertMatchesRegularExpression('/^2557000003\d{2}$/', $p['phone']);
            $this->assertStringStartsWith('DEMO-', $p['code']);
            foreach ($seen as $k => $_) {
                $this->assertNotContains($p[$k], $seen[$k], "duplicate $k");
                $seen[$k][] = $p[$k];
            }
        }
    }

    public function testTheRosterHasTheOfficersTheLoansNeed(): void
    {
        $roles = array_column(vk_demo_roster(), 3);
        $this->assertContains('Secretary', $roles);
        $this->assertContains('Treasurer', $roles);
        $this->assertNotContains('Chairperson', $roles, 'the real Chairperson is the only one');
        $users = array_map(static fn($r) => strtolower($r[0][0] . $r[1]), vk_demo_roster());
        foreach (['gmwakalinga', 'dkimaro', 'nlyimo', 'hmrisho', 'sjuma', 'ambwana', 'jswai', 'esanga', 'rshayo',
                  'bmushi', 'mkileo', 'gtemba', 'zmassawe', 'hrajabu', 'purassa'] as $u) {
            $this->assertContains($u, $users, "the seed refers to $u");
        }
    }

    public function testSampleLoginsHaveNoPasswordAnyoneKnows(): void
    {
        $lib = $this->src('includes/demo_data.php');
        $this->assertStringContainsString('password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)', $lib);
        $this->assertStringNotContainsString('echo', $lib);
    }

    public function testTheSeedSettingsStartSixMonthsBack(): void
    {
        $s = vk_demo_settings(new \DateTimeImmutable('2026-04-01'));
        $this->assertSame('2026-04-01', $s['contribution_start_date']);
        $this->assertSame('20000', $s['monthly_contribution']);
        $this->assertArrayNotHasKey('group_name', $s, 'the group keeps its own name');
    }

    // ── seeding only a brand-new group ───────────────────────────────────────

    public function testTheSeedOnlyTakesAGroupWithJustItsChairperson(): void
    {
        $lib = $this->src('includes/demo_data.php');
        $this->before("SELECT COUNT(*) FROM users') !== 1", 'INSERT INTO group_settings', $lib);
        $this->before("SELECT COUNT(*) FROM vicoba_loans') > 0", 'INSERT INTO users', $lib);
        $this->assertStringContainsString('max_share_multiple,', $lib);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?, ?, 0, ?,', $lib, 'fixed loan limits: works with Shares off');
    }

    public function testTheScriptRefusesARealGroup(): void
    {
        $bin = $this->src('bin/demo_data.php');
        $this->before("if (PHP_SAPI !== 'cli')", 'includes/config.php', $bin);
        $this->before('if ($group === null)', 'vk_demo_', substr($bin, strpos($bin, '$group = vk_tenant_current();')));
        $this->before("if (!(int) (\$group['is_demo'] ?? 0))", 'vk_demo_seed($pdo)', $bin);
    }

    // ── the clear ─────────────────────────────────────────────────────────────

    public function testTheClearEmptiesTheMoneyAndOnlyTheSamplePeople(): void
    {
        $plan = vk_demo_clear_plan();
        foreach (['contributions', 'vicoba_loans', 'vicoba_loan_installments', 'fines', 'general_expenses',
                  'death_expenses', 'member_payouts'] as $t) {
            $this->assertContains($t, $plan['wipe']);
        }
        $this->assertNotContains('users', $plan['wipe']);
        $this->assertNotContains('customers', $plan['wipe']);
        $this->assertNotContains('group_settings', $plan['wipe']);
        $this->assertNotContains('activity_logs', $plan['wipe'], 'the audit trail stays');
        // Loan children go before the loans.
        $this->assertLessThan(array_search('vicoba_loans', $plan['wipe'], true), array_search('vicoba_loan_payments', $plan['wipe'], true));

        $lib = $this->src('includes/demo_data.php');
        $this->assertStringContainsString("\$like = '%@' . VK_DEMO_EMAIL_DOMAIN;", $lib);
        $this->assertStringContainsString("DELETE FROM group_settings WHERE setting_key = ? AND setting_value = ?", $lib, 'a setting the group changed stays');
        $this->before('$pdo->beginTransaction();', "DELETE FROM `\$t`", $lib);
        $this->assertStringContainsString('$pdo->rollBack();', $lib);
    }

    public function testClearingTurnsTheGroupIntoARealOne(): void
    {
        $prov = $this->src('bin/provision_tenant.php');
        $clear = substr($prov, strpos($prov, '// ── --clear-demo'));
        $this->before("if (!(int) \$row['is_demo'])", "run_demo_data(\$row['subdomain'], 'clear')", $clear);
        $this->before("run_demo_data(\$row['subdomain'], 'clear')", "'UPDATE tenants SET is_demo = 0 WHERE id = ?'", $clear);
    }

    public function testADemoGroupIsSeededInsideTheCreateSoAFailureUndoesIt(): void
    {
        $prov = $this->src('bin/provision_tenant.php');
        $this->before("INSERT INTO customers (user_id", "run_demo_data(\$sub, 'seed')", $prov);
        $this->before("run_demo_data(\$sub, 'seed')", "UPDATE tenants SET status = 'active'", $prov);
        $this->assertStringContainsString("throw new RuntimeException('demo data failed (output above).');", $prov);
    }

    // ── the panel ─────────────────────────────────────────────────────────────

    public function testThePanelAsksForTheClearThroughTheQueue(): void
    {
        $this->assertContains('clear_demo', vk_req_actions());
        $this->assertSame([], vk_req_validate('clear_demo', 'pamoja'));
        $this->assertNotSame([], vk_req_validate('clear_demo', 'Pamoja; rm'));
        $this->assertSame(['--clear-demo=pamoja'], vk_req_command_args('clear_demo', 'pamoja'));
        $this->assertSame('clear demo data', vk_req_audit_action('clear_demo'));

        $page = $this->src('superadmin/src/pages/group.php');
        $this->assertStringContainsString("vk_admin_allowed_actions(\$g['status'], (bool) (int) \$g['is_demo'])", $page);
        $this->assertStringContainsString("\$action === 'clear_demo' && trim((string) (\$_POST['confirm'] ?? '')) !== \$g['subdomain']", $page);

        $sql = $this->src('database/control/add_clear_demo_action.sql');
        $this->assertStringContainsString("ENUM('" . implode("','", vk_req_actions()) . "')", $sql);
    }
}
