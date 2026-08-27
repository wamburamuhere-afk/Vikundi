<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_group_settings.php';

/**
 * GET /group-settings must be able to pre-fill the form PUT accepts.
 *
 * The two used to be written out by hand in separate files: GET published seven
 * fields under invented names (contributions.monthly_target), PUT accepted
 * eighteen under their storage names (monthly_contribution). An edit screen
 * could not fill itself in, and a client that tried had to maintain a private
 * mapping between the two vocabularies.
 *
 * These tests pin the properties that make that impossible to reintroduce.
 */
final class GroupSettingsParityTest extends TestCase
{
    /** Source with comments and docblocks removed. */
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    // ── the shared list is the single source ────────────────────────────────

    public function testBothEndpointsDeriveTheirFieldsFromTheSharedList(): void
    {
        foreach (['api/v1/group-settings.php', 'api/v1/group_settings_update.php'] as $file) {
            $this->assertStringContainsString(
                'vk_group_settings_writable()',
                self::code($file),
                "{$file} must take its field list from the shared definition, not "
                . 'a local copy — a second list is how GET and PUT drifted apart.'
            );
        }
    }

    public function testNeitherEndpointKeepsItsOwnWhitelistConstant(): void
    {
        foreach (['api/v1/group-settings.php', 'api/v1/group_settings_update.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/const VK_GROUP_SETTING(S_WRITABLE|_KEYS) = \[/',
                self::code($file),
                "{$file} still declares a local whitelist; there must be exactly one."
            );
        }
    }

    /**
     * The whole point: every key PUT accepts is readable back under the SAME
     * name. Asserted against the endpoint's actual output loop rather than a
     * restatement of the list.
     */
    public function testGetPublishesEveryWritableKeyUnderItsWriteName(): void
    {
        $code = self::code('api/v1/group-settings.php');

        $this->assertMatchesRegularExpression(
            '/foreach \(\$writable as \$key => \$rule\) \{\s*\$settings\[\$key\] = vk_group_settings_cast\(/',
            $code,
            'GET must emit one entry per writable key, keyed by the key itself — '
            . 'renaming any of them on the way out is what broke pre-fill.'
        );
    }

    // ── operational state stays out of both directions ──────────────────────

    public function testOperationalStateIsNeitherReadableNorWritable(): void
    {
        $writable = vk_group_settings_writable();

        foreach (['auto_termination_last_run', 'group_balance'] as $operational) {
            $this->assertArrayNotHasKey(
                $operational,
                $writable,
                "'{$operational}' is operational state, not client configuration; "
                . 'it must be neither returned by GET nor accepted by PUT.'
            );
        }
    }

    public function testTheListIsNotAccidentallyEmptied(): void
    {
        $writable = vk_group_settings_writable();
        $this->assertGreaterThanOrEqual(18, count($writable));
        $this->assertArrayHasKey('group_name', $writable);
        $this->assertArrayHasKey('monthly_contribution', $writable);
        $this->assertArrayHasKey('auto_termination', $writable);
    }

    // ── "not set" survives the round trip ───────────────────────────────────

    /**
     * Clearing monthly_contribution means "no monthly target", which switches
     * the arrears calculation off. Zero would mean "the target is nothing" and
     * put every member permanently in credit. null and 0 are different answers.
     */
    public function testAnUnsetMoneyFieldReadsBackAsNullNotZero(): void
    {
        $this->assertNull(vk_group_settings_cast('money', ''));
        $this->assertNull(vk_group_settings_cast('int', ''));
        $this->assertSame(0.0, vk_group_settings_cast('money', '0'));
        $this->assertSame(0, vk_group_settings_cast('int', '0'));
    }

    public function testAnUnsetMoneyFieldIsStoredBlankRatherThanCoerced(): void
    {
        $this->assertSame(['', null], vk_group_settings_validate('monthly_contribution', 'money', ''));
        $this->assertSame(['1500', null], vk_group_settings_validate('max_members', 'int', '1500'));
    }

    public function testMoneyRejectsNonNumbersAndNegatives(): void
    {
        [, $err] = vk_group_settings_validate('entrance_fee', 'money', 'free');
        $this->assertNotNull($err);
        [, $err] = vk_group_settings_validate('entrance_fee', 'money', '-1');
        $this->assertNotNull($err);
    }

    public function testCastLeavesAStoredNonNumberOutOfANumericField(): void
    {
        // A value written by some other path must not surface as 0.
        $this->assertNull(vk_group_settings_cast('money', 'n/a'));
        $this->assertNull(vk_group_settings_cast('int', 'many'));
    }

    // ── deadline_day / meeting_day: the corruption this change closes ───────

    /**
     * THE BUG. app/bms/customer/group_settings.php stores a Swahili day NAME in
     * deadline_day when cycle_type is weekly, and a number 1-31 when monthly.
     * The old rule typed it 'int', and (string)(int)'Jumatatu' is '0'.
     *
     * A pre-filled edit form reads the value, sends it back untouched, and the
     * group's deadline silently becomes 0 — without anyone editing that field.
     */
    public function testAWeeklyDayNameIsNotCoercedToZero(): void
    {
        [$value, $err] = vk_group_settings_validate('deadline_day', 'day', 'Jumatatu');

        $this->assertNull($err);
        $this->assertSame('Jumatatu', $value);
        $this->assertNotSame('0', $value, '(int) on a day name silently zeroes the deadline.');
    }

    public function testEveryStoredDayNameSurvivesTheRoundTrip(): void
    {
        foreach (vk_group_settings_day_names() as $swahili) {
            [$value, $err] = vk_group_settings_validate('deadline_day', 'day', $swahili);
            $this->assertNull($err, "{$swahili} must be accepted.");
            $this->assertSame($swahili, $value);
            $this->assertSame($swahili, vk_group_settings_cast('day', $swahili));
        }
    }

    public function testAnEnglishDayNameIsNormalisedToTheStoredSwahili(): void
    {
        // Storing 'Monday' would leave a value no other screen matches on.
        $this->assertSame(['Jumatatu', null], vk_group_settings_validate('meeting_day', 'day', 'Monday'));
        $this->assertSame(['Jumapili', null], vk_group_settings_validate('meeting_day', 'day', 'sunday'));
        $this->assertSame(['Ijumaa', null], vk_group_settings_validate('meeting_day', 'day', 'ijumaa'));
    }

    public function testAMonthlyDeadlineStaysAnIntegerDayOfTheMonth(): void
    {
        $this->assertSame(['15', null], vk_group_settings_validate('deadline_day', 'day', '15'));
        $this->assertSame(15, vk_group_settings_cast('day', '15'));
        $this->assertSame(1, vk_group_settings_cast('day', '1'));
    }

    public function testADayOutsideTheMonthIsRefused(): void
    {
        foreach (['0', '32', '99', '-3'] as $bad) {
            [, $err] = vk_group_settings_validate('deadline_day', 'day', $bad);
            $this->assertNotNull($err, "day {$bad} must be refused, not stored.");
        }
    }

    public function testAnUnrecognisedDayIsRefusedRatherThanZeroed(): void
    {
        [, $err] = vk_group_settings_validate('deadline_day', 'day', 'Someday');
        $this->assertNotNull($err);
    }

    public function testMeetingDayAndDeadlineDayShareTheDayRule(): void
    {
        $writable = vk_group_settings_writable();
        $this->assertSame('day', $writable['meeting_day']);
        $this->assertSame('day', $writable['deadline_day'], 'int would zero a weekly group\'s deadline.');
    }

    /**
     * deadline_day is uninterpretable without knowing the cycle: 15 and
     * 'Jumatatu' live in the same key. The client cannot render its picker
     * without cycle_type, so cycle_type must come back with it.
     */
    public function testCycleTypeIsExposedBecauseDeadlineDayDependsOnIt(): void
    {
        $writable = vk_group_settings_writable();
        $this->assertArrayHasKey('cycle_type', $writable);
        $this->assertSame('enum:monthly,weekly', $writable['cycle_type']);
    }

    // ── enums ───────────────────────────────────────────────────────────────

    public function testEnumsAcceptOnlyTheirOwnValues(): void
    {
        $this->assertSame(['on', null], vk_group_settings_validate('auto_termination', 'enum:on,off', 'on'));

        [, $err] = vk_group_settings_validate('auto_termination', 'enum:on,off', 'yes');
        $this->assertNotNull($err);

        [, $err] = vk_group_settings_validate('cycle_type', 'enum:monthly,weekly', 'daily');
        $this->assertNotNull($err);
    }

    // ── defaults match the web form ─────────────────────────────────────────

    /**
     * The app and the web page must open on the same numbers, or an officer
     * comparing the two sees a discrepancy that is only in the display.
     */
    public function testDefaultsMirrorTheWebForm(): void
    {
        $d = vk_group_settings_defaults();
        $this->assertSame('30', $d['max_members']);      // gs(..., 'max_members', '30')
        $this->assertSame('15', $d['deadline_day']);     // gs(..., 'deadline_day', '15')
        $this->assertSame('off', $d['auto_termination']); // unchecked checkbox
        $this->assertSame('TZS', $d['currency']);
    }

    public function testEveryDefaultNamesARealWritableKey(): void
    {
        $writable = vk_group_settings_writable();
        foreach (array_keys(vk_group_settings_defaults()) as $key) {
            $this->assertArrayHasKey($key, $writable, "default for '{$key}' names no writable setting.");
        }
    }

    // ── who gets the pre-fill block ─────────────────────────────────────────

    /**
     * The web settings page is admin/Secretary only. Returning the whole
     * configuration to every caller would make the API more permissive than the
     * screen it mirrors — which is exactly how the contribution endpoints came
     * to serve the group's finances to any member.
     */
    public function testOnlyAnOfficerMayEdit(): void
    {
        $secretary = ['role_id' => 99, 'user' => ['user_role' => 'Secretary']];
        $katibu    = ['role_id' => 99, 'user' => ['user_role' => 'katibu']];
        $member    = ['role_id' => 15, 'user' => ['user_role' => 'Member']];
        $treasurer = ['role_id' => 99, 'user' => ['user_role' => 'Treasurer']];

        $this->assertTrue(vk_group_settings_may_edit($secretary));
        $this->assertTrue(vk_group_settings_may_edit($katibu), 'Swahili role name is the seeded one.');
        $this->assertFalse(vk_group_settings_may_edit($member));
        $this->assertFalse(
            vk_group_settings_may_edit($treasurer),
            'The Treasurer is leadership for money but not for group configuration.'
        );
    }

    public function testTheChairpersonCountsAsAnAdmin(): void
    {
        // The list that used to be hardcoded read ['Admin','Secretary','Katibu'],
        // omitting the exact role name seed_vicoba_roles.php creates — so the
        // head of the group could not open the group's own settings.
        $this->assertTrue(vk_group_settings_may_edit(
            ['role_id' => 99, 'user' => ['user_role' => 'Chairperson']]
        ));
    }

    public function testAMissingRoleIsNotAnOfficer(): void
    {
        $this->assertFalse(vk_group_settings_may_edit([]));
        $this->assertFalse(vk_group_settings_may_edit(['role_id' => null, 'user' => []]));
    }

    /**
     * can_edit and the presence of `settings` must be the same decision, taken
     * once — a client shown a form it cannot submit is the /auth/me bug again.
     */
    public function testGetGatesThePrefillBlockOnTheSameRuleAsPut(): void
    {
        $get = self::code('api/v1/group-settings.php');
        $put = self::code('api/v1/group_settings_update.php');

        $this->assertStringContainsString('vk_group_settings_may_edit($auth)', $get);
        $this->assertStringContainsString('vk_group_settings_may_edit($auth)', $put);

        $this->assertMatchesRegularExpression(
            '/\'can_edit\' => \$canEdit,\s*\'settings\' => \$canEdit \? \$settings : null,/',
            $get,
            'The flag and the block must come from one variable, or they can disagree.'
        );
    }

    /**
     * The blocks Modules 1-3 already read must keep their names and shape; this
     * change adds fields, it does not reshape the response.
     */
    public function testTheExistingResponseShapeIsPreserved(): void
    {
        $get = self::code('api/v1/group-settings.php');
        foreach (["'group'", "'contributions'", "'fines'", "'leadership_positions'",
                  "'monthly_target'", "'meeting_absence'", "'org_type'"] as $key) {
            $this->assertStringContainsString($key, $get, "{$key} is already consumed by the app.");
        }
    }
}
