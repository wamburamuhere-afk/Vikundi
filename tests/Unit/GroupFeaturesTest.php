<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-group features (includes/features.php), switched by the super-admin.
 *
 * UKUU has no shares and no dividends. Removing the permissions was not enough:
 * every UKUU login is a Chairperson, Secretary or Treasurer, and canView() lets
 * those roles see everything. So the switch lives on the group's registry row,
 * is set only from the super-admin panel, and hides the feature for everyone.
 */
final class GroupFeaturesTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/tenant.php';
        require_once self::$root . '/includes/admin_requests.php';
        require_once self::$root . '/includes/api_group_settings.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__vk_tenant']);
    }

    private function asGroup(?string $features): void
    {
        vk_tenant_set(['subdomain' => 'ukuu', 'features' => $features], '/tmp/none');
    }

    private function src(string $file): string
    {
        return (string) file_get_contents(self::$root . '/' . $file);
    }

    // ── reading and writing the stored value ──────────────────────────────────

    public function testNothingStoredMeansEveryFeatureIsOn(): void
    {
        $all = ['shares' => true, 'dividends' => true];
        $this->assertSame($all, vk_features_parse(null));
        $this->assertSame($all, vk_features_parse(''));
        $this->assertSame($all, vk_features_parse('not json'));
        $this->assertSame($all, vk_features_parse('{}'));
    }

    public function testOnlyAnExplicitFalseSwitchesAFeatureOff(): void
    {
        $this->assertSame(['shares' => false, 'dividends' => true], vk_features_parse('{"shares":false}'));
        $this->assertSame(['shares' => true, 'dividends' => true], vk_features_parse('{"shares":0,"dividends":"off"}'));
        $this->assertArrayNotHasKey('loans', vk_features_parse('{"loans":false}'), 'unknown keys are dropped');
    }

    public function testEncodeListsEveryKnownFeature(): void
    {
        $this->assertSame('{"shares":false,"dividends":true}', vk_features_encode(['shares' => false, 'junk' => false]));
        $this->assertSame(['shares' => false, 'dividends' => false], vk_features_parse(vk_features_encode(['shares' => false, 'dividends' => false])));
    }

    public function testTheCommandLineSpecRoundTrips(): void
    {
        $this->assertSame(['shares' => false, 'dividends' => true], vk_features_from_spec('shares:off,dividends:on'));
        $this->assertSame(['dividends' => false, 'shares' => true], vk_features_from_spec(' dividends:off , shares:on '));
        $this->assertSame('shares:off,dividends:on', vk_features_to_spec(['shares' => false, 'dividends' => true]));
        $this->assertSame('shares:on,dividends:on', vk_features_to_spec([]));
    }

    public function testABadSpecIsRefusedWithAReason(): void
    {
        $this->assertIsString(vk_features_from_spec(''));
        $this->assertIsString(vk_features_from_spec('shares:off'), 'every feature must be given');
        $this->assertIsString(vk_features_from_spec('shares:off,dividends:maybe'));
        $this->assertIsString(vk_features_from_spec('shares:off,loans:off,dividends:on'));
        $this->assertIsString(vk_features_from_spec('shares:off,shares:on,dividends:on'));
    }

    // ── the current group ─────────────────────────────────────────────────────

    public function testSingleSiteModeHasEverything(): void
    {
        $this->assertTrue(vk_feature('shares'));
        $this->assertTrue(vk_feature('dividends'));
        $this->assertTrue(vk_feature('anything-unknown'));
        $this->assertSame([], vk_feature_hidden_settings());
    }

    public function testAGroupWithoutTheColumnYetHasEverything(): void
    {
        vk_tenant_set(['subdomain' => 'ukuu'], '/tmp/none');
        $this->assertTrue(vk_feature('shares'));
    }

    public function testASwitchedOffFeatureIsOffForTheGroup(): void
    {
        $this->asGroup('{"shares":false,"dividends":false}');
        $this->assertFalse(vk_feature('shares'));
        $this->assertFalse(vk_feature('dividends'));
        $this->assertSame(['share_price', 'shares_from_monthly', 'dividend_distribute_pct'], vk_feature_hidden_settings());
    }

    public function testTheMobileApiNeitherShowsNorSavesTheirSettings(): void
    {
        $this->assertArrayHasKey('share_price', vk_group_settings_writable());
        $this->asGroup('{"shares":false,"dividends":true}');
        $w = vk_group_settings_writable();
        $this->assertArrayNotHasKey('share_price', $w);
        $this->assertArrayNotHasKey('shares_from_monthly', $w);
        $this->assertArrayHasKey('dividend_distribute_pct', $w);
        $this->assertArrayHasKey('monthly_contribution', $w);
    }

    // ── the request queue ─────────────────────────────────────────────────────

    public function testAFeaturesRequestIsCheckedAndBecomesOneCommand(): void
    {
        $ok = ['features' => ['shares' => false, 'dividends' => false]];
        $this->assertSame([], vk_req_validate('features', 'ukuu', $ok));
        $this->assertSame([], vk_req_validate('features', 'ukuu', ['features' => ['dividends' => true, 'shares' => false]]));
        $this->assertNotSame([], vk_req_validate('features', 'UKUU!', $ok));
        $this->assertNotSame([], vk_req_validate('features', 'ukuu', []));
        $this->assertNotSame([], vk_req_validate('features', 'ukuu', ['features' => ['shares' => false]]));
        $this->assertNotSame([], vk_req_validate('features', 'ukuu', ['features' => ['shares' => 'off', 'dividends' => true]]));
        $this->assertNotSame([], vk_req_validate('features', 'ukuu', ['features' => ['shares' => false, 'dividends' => true, 'loans' => false]]));

        $this->assertSame(['--features=ukuu', '--set=shares:off,dividends:off'], vk_req_command_args('features', 'ukuu', $ok));
        $this->assertSame('set features', vk_req_audit_action('features'));
    }

    public function testTheProvisionerSetsOnlyTheFeaturesColumn(): void
    {
        $src = $this->src('bin/provision_tenant.php');
        $this->assertStringContainsString("if (isset(\$args['features'])) {", $src);
        $this->assertStringContainsString("'UPDATE tenants SET features = ? WHERE id = ?'", $src);
        $this->assertStringContainsString('vk_features_from_spec((string) ($args[\'set\'] ?? \'\'))', $src);
        // It runs before --create can be reached, and ends the run.
        $this->assertLessThan(strpos($src, "// ── --add-host"), strpos($src, '// ── --features'));
    }

    public function testThePanelOffersTheSwitchesThroughTheQueueOnly(): void
    {
        $page = $this->src('superadmin/src/pages/group.php');
        $this->assertStringContainsString("queue_request((int) \$me['id'], 'features', \$g['subdomain'], ['features' => \$want]);", $page);
        $this->assertStringContainsString('name="feature[<?= e($k) ?>]"', $page);
        // The panel's login still only reads the registry.
        $grants = $this->src('database/control/admin_grants.sql');
        $this->assertStringContainsString("GRANT SELECT ON vikundi_control.tenants TO 'vikundi_admin'@'localhost';", $grants);
        $this->assertStringNotContainsString('UPDATE (features', $grants);
    }

    public function testTheRegistryChangeIsSafeToRunTwiceAndMatchesTheSchema(): void
    {
        $sql = $this->src('database/control/add_tenant_features.sql');
        $this->assertStringContainsString("COLUMN_NAME = 'features'", $sql);
        $this->assertStringContainsString("'ALTER TABLE tenants ADD COLUMN features JSON NULL AFTER is_demo', 'DO 0'", $sql);
        $enum = "ENUM('" . implode("','", vk_req_actions()) . "')";
        $this->assertStringContainsString($enum, $sql);
        $this->assertStringContainsString($enum, $this->src('database/control/admin_schema.sql'));
        $this->assertStringContainsString('features      JSON         NULL', $this->src('database/control/schema.sql'));
    }

    // ── the group's site ──────────────────────────────────────────────────────

    public function testTheMenusHideWhatTheGroupDoesNotHave(): void
    {
        $h = $this->src('header.php');
        $this->assertStringContainsString("<?php if (vk_feature('dividends') && canView('dividends')): ?>", $h);
        $link = strpos($h, "getUrl('reports/shares')");
        $gate = strrpos(substr($h, 0, $link), "<?php if (vk_feature('shares')): ?>");
        $this->assertNotFalse($gate);
        $this->assertSame(1, substr_count(substr($h, $gate, $link - $gate), "\n"), 'the gate is the line above the link');
    }

    public function testThePagesRefuseBeforeAnythingIsShown(): void
    {
        foreach ([
            'app/constant/accounts/dividends.php'     => "vk_require_feature('dividends');",
            'app/constant/reports/shares_register.php' => 'vk_require_feature("shares");',
        ] as $file => $gate) {
            $src = $this->src($file);
            $this->assertNotFalse(strpos($src, $gate), $file);
            $this->assertLessThan(strpos($src, 'includeHeader();'), strpos($src, $gate), $file);
        }
        $action = $this->src('actions/dividend_action.php');
        $this->assertLessThan(strpos($action, "\$_SERVER['REQUEST_METHOD']"), strpos($action, "vk_require_feature('dividends', true);"));
    }

    public function testSettingsAndFormsFollowTheSwitches(): void
    {
        $save = $this->src('actions/save_group_settings.php');
        $this->assertStringContainsString('$allowed_keys = array_values(array_diff($allowed_keys, vk_feature_hidden_settings()));', $save);
        $this->assertLessThan(strpos($save, 'foreach ($allowed_keys as $key)'), strpos($save, 'vk_feature_hidden_settings()'));

        $form = $this->src('app/bms/customer/group_settings.php');
        $this->assertLessThan(strpos($form, 'name="share_price"'), strpos($form, "<?php if (vk_feature('shares')):"));
        $this->assertLessThan(strpos($form, 'name="dividend_distribute_pct"'), strpos($form, "<?php if (vk_feature('dividends')): ?>"));

        $this->assertStringContainsString("unset(\$types['share']);", $this->src('app/bms/customer/transactions.php'));
        $this->assertStringContainsString("array_diff(\$allowed_types, ['share'])", $this->src('actions/process_contribution.php'));

        $home = $this->src('app/bms/customer/my_home.php');
        $this->assertStringContainsString("vk_feature('dividends') ? dv_member_latest(\$pdo, \$customer_id) : null", $home);
        $this->assertStringContainsString('<?php if ($has_shares): ?>', $home);
    }

    public function testFeaturesAreLoadedOnEveryPage(): void
    {
        $this->assertStringContainsString("require_once ROOT_DIR . '/includes/features.php';", $this->src('roots.php'));
        $this->assertStringContainsString("require_once __DIR__ . '/includes/features.php';", $this->src('header.php'));
    }
}
