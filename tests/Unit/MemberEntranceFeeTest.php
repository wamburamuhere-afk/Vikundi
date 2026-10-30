<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Each member's own entrance fee (customers.entrance_fee), and the group switch
 * 'mkoba_pays_entrance' that takes the fee out of M-Koba money first (UKUU asked
 * for both). The switch is off by default, and off must mean exactly the old
 * behaviour for every other group.
 */
final class MemberEntranceFeeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/contribution_standing.php';
        require_once self::$root . '/includes/api_financial_ledger.php';
        require_once self::$root . '/includes/late_rules.php';
    }

    private static function src(string $rel): string
    {
        return (string) file_get_contents(self::$root . '/' . $rel);
    }

    // ── which fee, and is the switch on ─────────────────────────────────────────

    public function testAMemberWithoutTheirOwnFeeUsesTheGroupFee(): void
    {
        $group = ['entrance_fee' => '20000'];
        $this->assertSame(20000.0, cs_entrance_for(null, $group));
        $this->assertSame(20000.0, cs_entrance_for('', $group));
        $this->assertSame(45000.0, cs_entrance_for('45000.00', $group));
        $this->assertSame(0.0, cs_entrance_for('0', $group), 'their own 0 means no fee for them');
        $this->assertSame(0.0, cs_entrance_for(null, []), 'no group fee set: none');
    }

    public function testTheSwitchIsOffUnlessSetToOne(): void
    {
        $this->assertFalse(cs_mkoba_pays_entrance([]));
        $this->assertFalse(cs_mkoba_pays_entrance(['mkoba_pays_entrance' => '0']));
        $this->assertTrue(cs_mkoba_pays_entrance(['mkoba_pays_entrance' => '1']));
    }

    // ── the member's schedule ───────────────────────────────────────────────────

    public function testSwitchOffMkobaMoneyNeverPaysTheEntranceFee(): void
    {
        $asOf = new DateTime('2026-10-15');
        $s = cs_build_schedule(100000.0, 0.0, 10000.0, 45000.0, '2026-01-01', null, $asOf);
        $this->assertSame(0.0, $s['entrance_paid']);
        $this->assertSame('unpaid', $s['entrance_status']);
        $this->assertSame(100000.0, $s['monthly_pot']);
        $this->assertSame(10, $s['total_months_covered']);

        // Same as leaving the new argument out: off is the old behaviour.
        $this->assertSame($s, cs_build_schedule(100000.0, 0.0, 10000.0, 45000.0, '2026-01-01', null, $asOf, false));
    }

    public function testSwitchOnMkobaMoneyPaysTheEntranceFeeFirstThenTheMonths(): void
    {
        $asOf = new DateTime('2026-10-15');
        $s = cs_build_schedule(100000.0, 0.0, 10000.0, 45000.0, '2026-01-01', null, $asOf, true);
        $this->assertSame(45000.0, $s['entrance_paid']);
        $this->assertSame('paid', $s['entrance_status']);
        $this->assertSame(55000.0, $s['monthly_pot']);
        $this->assertSame(5, $s['total_months_covered'], '55,000 covers five 10,000 months');
        $this->assertSame(100000.0, $s['total_paid'], 'nothing is lost: fee + months = all the money');
        $this->assertSame(100000.0, $s['opening'], 'the M-Koba figure itself is unchanged');
    }

    public function testNotEnoughMoneyLeavesTheFeePartlyPaidAndNothingForTheMonths(): void
    {
        $s = cs_build_schedule(30000.0, 0.0, 10000.0, 45000.0, '2026-01-01', null, new DateTime('2026-10-15'), true);
        $this->assertSame(30000.0, $s['entrance_paid']);
        $this->assertSame('partial', $s['entrance_status']);
        $this->assertSame(0.0, $s['monthly_pot']);
        $this->assertSame(0, $s['total_months_covered']);
    }

    public function testWhatIsOwedIncludesTheFeeOnlyWithTheSwitchOn(): void
    {
        $asOf = new DateTime('2026-03-15');
        $s = cs_build_schedule(0.0, 0.0, 10000.0, 45000.0, '2026-01-01', null, $asOf, true);
        $this->assertSame(30000.0, cs_schedule_expected($s, [], $asOf), 'off: three months only');
        $this->assertSame(75000.0, cs_schedule_expected($s, ['mkoba_pays_entrance' => '1'], $asOf), 'on: the fee too');
    }

    // ── the Financial Ledger ────────────────────────────────────────────────────

    public function testLedgerSwitchOffIsUnchanged(): void
    {
        $valid = [true, true, true];
        $off = vk_api_ledger_member_calc(100000.0, 0.0, 0.0, 0.0, 10000.0, 45000.0, $valid, 3);
        $this->assertSame(100000.0, $off['opening']);
        $this->assertSame(0.0, $off['entrance_paid']);
        $this->assertSame([0.0, 0.0, 0.0], $off['monthly_by_month'], 'M-Koba money stays an opening balance');
        $this->assertSame(30000.0, $off['target_amt']);
    }

    public function testLedgerSwitchOnSpreadsMkobaMoneyAfterTheFee(): void
    {
        $valid = [true, true, true];
        $on = vk_api_ledger_member_calc(100000.0, 0.0, 0.0, 0.0, 10000.0, 45000.0, $valid, 3, null, true);
        $this->assertSame(0.0, $on['opening']);
        $this->assertSame(45000.0, $on['entrance_paid']);
        $this->assertSame([10000.0, 10000.0, 35000.0], $on['monthly_by_month'], 'the rest across the months, extra on the last');
        $this->assertSame(75000.0, $on['target_amt'], 'the fee is owed on top of the months');
        $this->assertSame(25000.0, $on['surplus_deficit']);
        $this->assertSame(100000.0, $on['total_member_contributed']);
    }

    // ── the late-payment rules ──────────────────────────────────────────────────

    public function testTheFeeIsTakenOffTheOldestPaymentsFirst(): void
    {
        $payments = [
            ['date' => '2026-03-05', 'amount' => 10000],
            ['date' => '2026-01-05', 'amount' => 40000],
            ['date' => '2026-02-05', 'amount' => 10000],
        ];
        $left = cs_skim_entrance($payments, 45000.0);
        $this->assertSame([
            ['date' => '2026-02-05', 'amount' => 5000.0],
            ['date' => '2026-03-05', 'amount' => 10000],
        ], $left);
        $this->assertSame($payments, cs_skim_entrance($payments, 0.0), 'no fee: untouched');
    }

    public function testLateRulesOnlySkimTheFeeWithTheSwitchOn(): void
    {
        $code = self::src('includes/late_rules.php');
        $this->assertStringContainsString('$skimEntrance = cs_mkoba_pays_entrance($settings);', $code);
        $this->assertStringContainsString('if ($skimEntrance) {', $code);
        $this->assertStringContainsString("cs_skim_entrance(\$memberPayments, cs_entrance_for(\$m['entrance_fee'] ?? null, \$settings))", $code);
        $this->assertStringContainsString('last_name, entrance_fee', $code);
    }

    // ── every screen uses the member's own fee ──────────────────────────────────

    public function testEveryCalculationReadsTheMembersOwnFee(): void
    {
        $standing = self::src('includes/contribution_standing.php');
        $this->assertStringContainsString("SELECT initial_savings, created_at, entrance_fee FROM customers", $standing);
        $this->assertStringContainsString("c.created_at, c.initial_savings, c.entrance_fee,", $standing);
        $this->assertStringContainsString("cs_entrance_for(\$r['entrance_fee'] ?? null, \$settings)", $standing);
        $this->assertSame(0, substr_count($standing, "\$entrance  = (float) (\$settings['entrance_fee'] ?? 0);"),
            'no schedule reads the group fee for everyone any more');

        foreach (['app/bms/customer/financial_ledger.php', 'includes/api_financial_ledger.php'] as $f) {
            $code = self::src($f);
            $this->assertStringContainsString('c.created_at, c.entrance_fee', $code, $f);
            $this->assertStringContainsString("cs_entrance_for(\$m['entrance_fee'] ?? null, \$settings)", $code, $f);
            $this->assertStringContainsString('cs_mkoba_pays_entrance($settings)', $code, $f);
        }
        $this->assertStringContainsString("\$entranceAmt = (float) \$sched['entrance_amt'];", self::src('api/v1/member-statement_detail.php'));
    }

    // ── saving it ───────────────────────────────────────────────────────────────

    public function testTheColumnIsAddedByAMigrationThatRunsOnDeploy(): void
    {
        $this->assertStringContainsString("'add_member_entrance_fee.php',", self::src('database/migrate.php'));
        $m = self::src('database/add_member_entrance_fee.php');
        $this->assertStringContainsString('ADD COLUMN `entrance_fee` DECIMAL(15,2) NULL DEFAULT NULL', $m);
        $this->assertStringContainsString("COLUMN_NAME = 'entrance_fee'", $m, 'only added when missing');
    }

    public function testOnlyLeadersCanSetAMembersFee(): void
    {
        $a = self::src('actions/save_member_entrance_fee.php');
        $this->assertStringContainsString("requirePermissionJson('edit', 'customers');", $a);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_csrf.php';", $a);
        $this->assertStringContainsString('UPDATE customers SET entrance_fee = ? WHERE customer_id = ?', $a);
        $this->assertStringContainsString('logUpdate(', $a);
        $this->assertStringContainsString("'actions/save_member_entrance_fee' => ROOT_DIR . '/actions/save_member_entrance_fee.php',", self::src('roots.php'));

        $list = self::src('app/bms/customer/customers.php');
        $this->assertStringContainsString('setEntranceFee(', $list);
        // The menu item sits inside the leaders-only block, next to Registration No.
        $item = strpos($list, "onclick='setEntranceFee(");
        $this->assertGreaterThan(strpos($list, '<?php if ($can_edit_members): ?>'), $item);
    }

    public function testTheGroupSwitchIsSavedAndOffWhenUnticked(): void
    {
        $save = self::src('actions/save_group_settings.php');
        $this->assertStringContainsString("'entrance_fee', 'mkoba_pays_entrance',", $save);
        $this->assertStringContainsString("\$_POST['mkoba_pays_entrance'] = (\$_POST['mkoba_pays_entrance'] ?? '') === '1' ? '1' : '0';", $save);
        $page = self::src('app/bms/customer/group_settings.php');
        $this->assertStringContainsString('name="mkoba_pays_entrance"', $page);
        $this->assertStringContainsString("(string) (\$settings_raw['mkoba_pays_entrance'] ?? '0') === '1'", $page);
    }
}
