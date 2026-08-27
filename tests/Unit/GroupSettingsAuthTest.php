<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Changing group settings must require authorisation, on both transports.
 *
 * WHAT THIS CAUGHT. actions/save_group_settings.php checked only that SOMEONE
 * was signed in. app/bms/customer/group_settings.php gates its form to admins
 * and the Secretary, but hiding a form is not a control: a plain member could
 * POST to the action directly and rename the group, set monthly_contribution
 * to 1, or zero the fines.
 *
 * monthly_contribution is what the arrears calculation multiplies by, so that
 * one request cleared every member's arrears across the whole group. Verified
 * exploitable against a running instance — a Member account posted
 * "group_name=HACKED BY A MEMBER&monthly_contribution=1&fine_absent_meeting=0"
 * and got {"success":true} — before the gate was added.
 *
 * This is the same class as the expense endpoints that shipped ungated: the UI
 * hides the control, so nobody notices the handler never checked.
 */
class GroupSettingsAuthTest extends TestCase
{
    private static function code(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, "{$rel} is missing.");
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $out .= $t[1];
                continue;
            }
            $out .= $t;
        }
        return $out;
    }

    /**
     * Both handlers that can write settings, and the call each one's gate is
     * expressed as.
     *
     * The web action still inlines the role check. The API delegates to
     * vk_group_settings_may_edit(), which GET uses too so that the form and the
     * permission to submit it are one decision — see
     * testTheSharedGateStillChecksTheRole() for the other half of that.
     */
    public static function writers(): array
    {
        return [
            ['actions/save_group_settings.php', 'vk_role_is_admin('],
            ['api/v1/group_settings_update.php', 'vk_group_settings_may_edit('],
        ];
    }

    /**
     * The API's gate is a function call, so this is what stops that indirection
     * from becoming a gate that checks nothing.
     */
    public function testTheSharedGateStillChecksTheRole(): void
    {
        $code = self::code('includes/api_group_settings.php');

        $this->assertStringContainsString(
            'vk_role_is_admin(',
            $code,
            'vk_group_settings_may_edit() must check the role, not just return true.'
        );
        $this->assertMatchesRegularExpression(
            "/\['secretary',\s*'katibu'\]/i",
            $code,
            'It must name the officers allowed alongside admins.'
        );

        require_once __DIR__ . '/../../includes/api_group_settings.php';
        $this->assertFalse(
            vk_group_settings_may_edit(['role_id' => 15, 'user' => ['user_role' => 'Member']]),
            'A member must not be able to change the group configuration.'
        );
        $this->assertTrue(
            vk_group_settings_may_edit(['role_id' => 99, 'user' => ['user_role' => 'Secretary']])
        );
    }

    #[DataProvider('writers')]
    public function testWritingSettingsRequiresMoreThanBeingSignedIn(string $rel, string $gate): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            $gate,
            $code,
            "{$rel} must check the caller's role, not merely that a session exists."
        );
    }

    /** The refusal must come before anything is written. */
    #[DataProvider('writers')]
    public function testTheRefusalPrecedesAnyWrite(string $rel, string $gate): void
    {
        $code = self::code($rel);

        $gate = strpos($code, $gate);
        $this->assertNotFalse($gate);

        foreach (['INSERT INTO group_settings', 'REPLACE INTO group_settings'] as $write) {
            $at = strpos($code, $write);
            if ($at !== false) {
                $this->assertGreaterThan(
                    $gate,
                    $at,
                    "{$rel} writes settings before deciding whether the caller may."
                );
            }
        }
    }

    /**
     * assets/images/ is web-served, and an SVG is a script-carrying document:
     * <svg><script>…</script></svg> served from the app's own origin is stored
     * XSS with the session cookie in reach. Raster formats cannot do that.
     */
    public function testTheGroupLogoUploadRefusesSvg(): void
    {
        $code = self::code('actions/save_group_settings.php');

        $this->assertSame(1, preg_match('/\$allowed_exts\s*=\s*\[(.*?)\];/s', $code, $m));
        $this->assertStringNotContainsString(
            "'svg'",
            $m[1],
            'An SVG logo is stored XSS on an origin that holds the session cookie.'
        );
        $this->assertStringContainsString(
            'vk_api_sniff_mime(',
            $code,
            'The extension is attacker-chosen; the bytes must be checked too.'
        );
    }

    /**
     * group_settings is a free-form key/value table that also holds operational
     * state. A client must not be able to write auto_termination_last_run or
     * the cached group_balance through the API.
     */
    public function testTheApiWhitelistExcludesOperationalState(): void
    {
        // The whitelist moved to includes/api_group_settings.php when GET began
        // sharing it, so assert on the list itself rather than on a literal that
        // no longer lives in this file.
        require_once __DIR__ . '/../../includes/api_group_settings.php';

        $writable = vk_group_settings_writable();
        foreach (['auto_termination_last_run', 'group_balance'] as $operational) {
            $this->assertArrayNotHasKey(
                $operational,
                $writable,
                "'{$operational}' is operational state, not a client-editable setting."
            );
        }

        $this->assertStringContainsString(
            'vk_group_settings_writable()',
            self::code('api/v1/group_settings_update.php'),
            'PUT must loop the shared list, or the exclusion above proves nothing.'
        );
    }

    /**
     * Clearing monthly_contribution means "no monthly target", which switches
     * the arrears calculation off — it is not the same as setting it to zero,
     * and coercing an empty string to 0 would quietly change the meaning.
     */
    public function testAnEmptyNumericSettingIsPreservedRatherThanCoerced(): void
    {
        // The validation moved into vk_group_settings_validate() when GET and PUT
        // began sharing one definition, so assert the behaviour rather than the
        // shape of the branch that used to implement it.
        require_once __DIR__ . '/../../includes/api_group_settings.php';

        $this->assertSame(
            ['', null],
            vk_group_settings_validate('monthly_contribution', 'money', ''),
            'An empty numeric setting must be stored as empty, not as 0.'
        );
        $this->assertSame(
            ['', null],
            vk_group_settings_validate('max_members', 'int', '')
        );
        $this->assertSame(
            ['0', null],
            vk_group_settings_validate('monthly_contribution', 'money', '0'),
            'An explicit 0 is a different instruction from a blank and must survive.'
        );
    }

    public function testTheApiWriteIsAudited(): void
    {
        $this->assertMatchesRegularExpression(
            "/logUpdate\([^;]*\\\$auth\['user_id'\]\s*\)/s",
            self::code('api/v1/group_settings_update.php'),
            'The API has no session, so the audit user id must be passed explicitly.'
        );
    }
}
