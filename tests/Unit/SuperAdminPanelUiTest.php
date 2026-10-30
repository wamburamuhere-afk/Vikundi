<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The super-admin panel's look (superadmin/src/bootstrap.php layout, the pages,
 * public/assets/admin.css): the helpers the pages share, and the rules the
 * redesign must keep. The panel allows no scripts and no inline styles
 * (vk_admin_security_headers), so neither may appear in its pages.
 */
final class SuperAdminPanelUiTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/superadmin/src/lib.php';
    }

    private function src(string $file): string
    {
        return (string) file_get_contents(self::$root . '/superadmin/' . $file);
    }

    public function testBackupFreshness(): void
    {
        $now = 1_800_000_000;
        $this->assertSame('none', vk_admin_backup_state(null, $now));
        $this->assertSame('ok', vk_admin_backup_state($now - 3600, $now));
        $this->assertSame('ok', vk_admin_backup_state($now - 36 * 3600, $now), 'last night\'s backup is still fresh the next evening');
        $this->assertSame('late', vk_admin_backup_state($now - 36 * 3600 - 1, $now));
    }

    public function testRequestProgressSteps(): void
    {
        $states = static fn(string $s) => array_column(vk_admin_request_steps($s), 1);
        $this->assertSame(['done', 'current', 'todo'], $states('waiting'));
        $this->assertSame(['done', 'current', 'todo'], $states('running'));
        $this->assertSame(['done', 'done', 'done'], $states('done'));
        $this->assertSame(['done', 'done', 'failed'], $states('failed'));
        $this->assertSame('Failed', vk_admin_request_steps('failed')[2][0]);
        $this->assertCount(3, vk_admin_request_steps('something-else'));
    }

    public function testGroupCounts(): void
    {
        $c = vk_admin_group_counts([
            ['status' => 'active', 'is_demo' => 1], ['status' => 'active', 'is_demo' => 0],
            ['status' => 'suspended', 'is_demo' => '1'], ['status' => 'archived', 'is_demo' => 0], ['status' => 'odd'],
        ]);
        $this->assertSame(['total' => 5, 'active' => 2, 'suspended' => 1, 'provisioning' => 0, 'archived' => 1, 'demo' => 2], $c);
    }

    public function testNoScriptsAndNoInlineStylesAnywhereInThePanel(): void
    {
        $files = array_merge(glob(self::$root . '/superadmin/src/*.php'), glob(self::$root . '/superadmin/src/pages/*.php'));
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringNotContainsString('<script', $src, basename($f));
            $this->assertStringNotContainsString(' style="', $src, basename($f));
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $src, basename($f) . ': no inline event handlers');
        }
        $h = vk_admin_security_headers();
        $this->assertStringNotContainsString('script-src', $h['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', $h['Content-Security-Policy']);
    }

    public function testTheLayoutWorksWithoutJavaScript(): void
    {
        $b = $this->src('src/bootstrap.php');
        $this->assertStringContainsString('<details class="vk-mobilebar">', $b, 'the phone menu is a details element');
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/admin.css">', $b);
        $this->assertStringContainsString("'<span class=\"vk-pill ' . vk_admin_status_class(\$status) . '\">' . e(ucfirst(\$status)) . '</span>'", $b);
        $this->assertStringContainsString('data_pending_count()', $b);
        $css = $this->src('public/assets/admin.css');
        foreach (['.vk-side', '.vk-mobilebar', '.vk-gcard', '.vk-steps', '.vk-danger', '.vk-pill.success', '.vk-pill.danger'] as $cls) {
            $this->assertStringContainsString($cls, $css);
        }
        // The pages that wait for the worker reload themselves.
        $this->assertStringContainsString("header('Refresh: 5');", $this->src('src/pages/request.php'));
        $this->assertStringContainsString("header('Refresh: 10');", $this->src('src/pages/requests.php'));
    }

    public function testDangerousActionsStillNeedTheTypedAddress(): void
    {
        $page = $this->src('src/pages/group.php');
        $zone = substr($page, strpos($page, '<div class="vk-danger">'));
        foreach (['clear_demo', 'archive'] as $action) {
            $form = substr($zone, strpos($zone, 'name="action" value="' . $action . '"'));
            $form = substr($form, 0, strpos($form, '</form>'));
            $this->assertStringContainsString('<input class="form-control form-control-sm mb-2" name="confirm" autocomplete="off" required>', $form, $action);
        }
        // Only the danger zone holds them, and only when no request is pending.
        $this->assertStringContainsString("<?php if (\$canAct && (in_array('archive', \$allowed, true) || in_array('clear_demo', \$allowed, true))): ?>", $page);
        $this->assertStringContainsString('$canAct = !$pending && $allowed;', $page);
    }
}
