<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Four loose ends found by clicking through the demo site as each role:
 * the Treasurer and Secretary bounced off Finance > Expenses, the Secretary had
 * no link to Group Settings, a Member was shown a link they could not follow,
 * and the "Printed by" footer sat across the bottom of the screen.
 */
class OfficerAccessFixesTest extends TestCase
{
    private static string $header;

    public static function setUpBeforeClass(): void
    {
        self::$header = file_get_contents(__DIR__ . '/../../header.php');
    }

    // ── 1. Expenses: the key the page gates on must exist ────────────────────
    public function testTheBereavementKeyIsRegisteredBeforeRolesAreSeeded(): void
    {
        $migrate = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $a = strpos($migrate, "'add_death_expenses_permission.php'");
        $this->assertNotFalse($a);
        $this->assertLessThan(strpos($migrate, "'seed_vicoba_roles.php'"), $a);
        // The page still gates on that key — the fix is to make the key exist.
        $page = file_get_contents(__DIR__ . '/../../app/constant/accounts/expenses.php');
        $this->assertStringContainsString("requireViewPermission('death_expenses');", $page);
    }

    public function testTheMigrationChangesNothingWhereTheKeyExists(): void
    {
        $m = file_get_contents(__DIR__ . '/../../database/add_death_expenses_permission.php');
        $guard = strpos($m, "if (\$permId > 0) {");
        $this->assertNotFalse($guard);
        // The early return comes before any insert or grant.
        $this->assertLessThan(strpos($m, 'INSERT INTO permissions'), $guard);
        $this->assertLessThan(strpos($m, 'INSERT INTO role_permissions'), $guard);
        $this->assertStringNotContainsString('UPDATE role_permissions', $m);
        $this->assertStringNotContainsString('DELETE FROM', $m);
    }

    public function testGrantsAreMirroredFromExpensesNotInvented(): void
    {
        $m = file_get_contents(__DIR__ . '/../../database/add_death_expenses_permission.php');
        $this->assertStringContainsString("const VK_DEATH_MODEL = 'expenses';", $m);
        $this->assertStringContainsString('FROM role_permissions WHERE permission_id = ?', $m);

        // A Member's default for both keys is view-only, which is what
        // includes/death_expense_access.php is written around.
        require_once __DIR__ . '/../../includes/role_grants.php';
        $this->assertSame([1, 0, 0, 0, 0, 0], vk_role_grants('view', 'expenses'));
        $this->assertSame([1, 0, 0, 0, 0, 0], vk_role_grants('view', 'death_expenses'));
        $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('operational', 'death_expenses'));
    }

    // ── 2. The Secretary can reach Group Settings ────────────────────────────
    public function testTheSecretaryHasALinkToTheSettingsTheyMayEdit(): void
    {
        $this->assertStringContainsString(
            "if (in_array(\$user_role_lower, ['secretary', 'katibu'], true) && !vk_role_is_admin(\$_SESSION['role_id'] ?? null, \$user_role)): ?>",
            self::$header
        );
        // Two links in the source: the Administration menu's, and the Secretary's.
        $this->assertSame(2, substr_count(self::$header, "getUrl('group_settings')"));

        // ...and the page and its save handler really do admit the Secretary.
        foreach (['app/bms/customer/group_settings.php', 'actions/save_group_settings.php'] as $f) {
            $this->assertStringContainsString("['secretary', 'katibu']", file_get_contents(__DIR__ . '/../../' . $f));
        }
    }

    // ── 3. No link a Member cannot follow ────────────────────────────────────
    public function testTheReconciliationLinkIsShownOnlyToThoseWhoCanOpenIt(): void
    {
        $link = strpos(self::$header, "href=\"<?= getUrl('mkoba_reconciliation') ?>\"");
        $gate = strrpos(substr(self::$header, 0, $link), "<?php if (canView('mkoba_reconciliation')):");
        $this->assertNotFalse($gate);
        // The gate is the line directly above the link.
        $this->assertSame(1, substr_count(substr(self::$header, $gate, $link - $gate), "\n"));

        $page = file_get_contents(__DIR__ . '/../../app/constant/accounts/mkoba_reconciliation.php');
        $this->assertStringContainsString("requireViewPermission('mkoba_reconciliation');", $page);
    }

    // ── 4. The "Printed by" footer is for paper ──────────────────────────────
    public function testThePrintFooterIsHiddenOnScreenInsideTheApplicationOnly(): void
    {
        $css = file_get_contents(__DIR__ . '/../../includes/print_footer_css.php');
        $this->assertMatchesRegularExpression(
            '/@media screen \{\s*body:has\(\.header-wrapper\) \.print-footer,\s*body:has\(\.header-wrapper\) \.footer-spacer \{ display: none !important; \}/',
            $css
        );
        // It keys on the application header, which really is what wraps the menu...
        $this->assertStringContainsString('<div class="header-wrapper">', self::$header);
        // ...and which the standalone print pages do not have, so their preview keeps it.
        foreach (['print_general_expense.php', 'print_budget.php', 'print_death_expense.php'] as $f) {
            $src = file_get_contents(__DIR__ . '/../../app/constant/accounts/' . $f);
            $this->assertStringNotContainsString('includeHeader()', $src);
            $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
        }
        // Printing is untouched: no rule hides the footer in print.
        $print = substr($css, strpos($css, '@media print'));
        $this->assertStringNotContainsString('.print-footer', $print);
    }

    // ── the settings form must not invent the group's rules ──────────────────
    public function testTheSettingsFormShowsNoInventedContributionOrEntranceFee(): void
    {
        // Found on the demo site: the boxes opened on 10000 / 20000 when nothing
        // was set, and Save stores what the form holds. Uploading a logo gave the
        // group a 20,000 entrance fee, and every member's statement then showed a
        // month unpaid.
        $form = file_get_contents(__DIR__ . '/../../app/bms/customer/group_settings.php');
        $this->assertStringNotContainsString("gs(\$settings_raw, 'monthly_contribution', '10000')", $form);
        $this->assertStringNotContainsString("gs(\$settings_raw, 'entrance_fee', '20000')", $form);
        $this->assertStringContainsString("value=\"<?= htmlspecialchars(gs(\$settings_raw, 'monthly_contribution')) ?>\"", $form);
        $this->assertStringContainsString("value=\"<?= htmlspecialchars(gs(\$settings_raw, 'entrance_fee')) ?>\"", $form);

        // Unset really does mean "none" to the calculations that read them.
        $standing = file_get_contents(__DIR__ . '/../../includes/contribution_standing.php');
        $this->assertStringContainsString("(float) (\$settings['entrance_fee'] ?? 0)", $standing);
        $this->assertStringContainsString('cr_monthly_rule($settings)', $standing);
        $rates = file_get_contents(__DIR__ . '/../../includes/contribution_rates.php');
        $this->assertStringContainsString("(float) (\$settings['monthly_contribution'] ?? 0)", $rates);
    }

    public function testTheDemoSeederStatesItsEntranceFeeOutright(): void
    {
        $seeder = file_get_contents(__DIR__ . '/../../database/seed_wise_wallet_demo.php');
        $this->assertMatchesRegularExpression("/'entrance_fee'\\s+=> '0',/", $seeder);
    }

    // ── a comment must not swallow the code after it ─────────────────────────
    public function testNoInlineCommentSwallowsAClosingBracketInAPageScript(): void
    {
        // Found in the browser: `render: d => ... // XSS-005 },` commented out the
        // "}," that closed the column, the Fines page script stopped parsing, and
        // the list never loaded — on the live site too, since 31 July.
        $fines = file_get_contents(__DIR__ . '/../../app/bms/customer/manage_fines.php');
        $this->assertStringNotContainsString('// XSS-005 },', $fines);
        $this->assertStringContainsString("{ data:'status', render:d=>`<span class=\"badge bg-\${badge(d)}\">\${vkEsc(d)}</span>` },", $fines);

        // The same mistake anywhere else: a tagged // comment followed, on the same
        // line, by a bracket that closes something.
        $root = realpath(__DIR__ . '/../../app');
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('~//\s*(XSS|SEC)-\d+[^\n]*[})\]];?,?\s*$~', $line)) {
                    $bad[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'a // comment is commenting out the bracket that follows it');
    }

    // ── My Settings shows the login username ─────────────────────────────────
    public function testMySettingsShowsTheLoginUsernameNotTheFullName(): void
    {
        $page = file_get_contents(__DIR__ . '/../../app/constant/profile/my_settings.php');
        $this->assertStringContainsString("htmlspecialchars((string) (\$user_data['username'] ?? ''))", $page);
        $this->assertStringNotContainsString('value="<?= htmlspecialchars($username) ?>" readonly', $page);
        // header.php really does reuse $username for the display name.
        $header = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertStringContainsString("\$username = trim((\$user['first_name'] ?? '')", $header);
    }
}
