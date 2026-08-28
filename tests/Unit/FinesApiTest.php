<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_fines.php';

/**
 * Module 6 — Fines.
 *
 * Fines are deliberately MORE OPEN than contributions: my_fines.php gives any
 * member a ?view=all toggle onto every fine in the group, because the group
 * asked for it and the Group Financial Ledger already discloses as much. What
 * stays closed is writing.
 */
final class FinesApiTest extends TestCase
{
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

    private static function raw(array $over = []): array
    {
        return $over + [
            'fine_id'     => 4,
            'customer_id' => 30,
            'amount'      => '5000.00',
            'reason'      => 'Late to the January meeting',
            'status'      => 'pending',
            'meeting_id'  => null,
            'created_at'  => '2026-01-15 09:00:00',
            'updated_at'  => null,
            'member_name' => 'Hamisi Mbwana',
        ];
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id'  => 1,
            'role_id'  => $admin ? 1 : ($leader ? 4 : 15),
            'user'     => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['manage_fines' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1]]
                : [],
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testOnlyAPendingFineIsMoneyStillOwed(): void
    {
        // A client summing every row would tell a member they owe money they
        // have already settled, or that was forgiven.
        $this->assertTrue(vk_api_fine_row(self::raw(['status' => 'pending']))['is_outstanding']);
        $this->assertFalse(vk_api_fine_row(self::raw(['status' => 'paid']))['is_outstanding']);
        $this->assertFalse(vk_api_fine_row(self::raw(['status' => 'waived']))['is_outstanding']);
    }

    public function testAnUnknownStoredStatusIsNormalisedRatherThanEchoed(): void
    {
        $this->assertSame('pending', vk_api_fine_row(self::raw(['status' => 'DISPUTED']))['status']);
    }

    public function testIsSelfMarksTheReadersOwnRow(): void
    {
        // The group view lists everyone; the member needs their own rows picked out.
        $this->assertTrue(vk_api_fine_row(self::raw(), 30)['is_self']);
        $this->assertFalse(vk_api_fine_row(self::raw(), 3)['is_self']);
        $this->assertFalse(vk_api_fine_row(self::raw(), 0)['is_self'], 'no member record means nothing is self');
    }

    public function testABlankReasonIsNullNotAnEmptyString(): void
    {
        $this->assertNull(vk_api_fine_row(self::raw(['reason' => '   ']))['reason']);
        $this->assertNull(vk_api_fine_row(self::raw(['meeting_title' => '']))['meeting_title']);
    }

    public function testAmountIsANumberNotAString(): void
    {
        $this->assertSame(5000.0, vk_api_fine_row(self::raw())['amount']);
    }

    // ── actions ─────────────────────────────────────────────────────────────

    public function testAMemberIsOfferedNoActions(): void
    {
        $a = vk_api_fine_actions(self::auth(false), 'pending');
        $this->assertSame(['edit' => false, 'pay' => false, 'waive' => false], $a);
    }

    public function testLeadershipMayPayAndWaiveAPendingFine(): void
    {
        $a = vk_api_fine_actions(self::auth(true), 'pending');
        $this->assertTrue($a['edit']);
        $this->assertTrue($a['pay']);
        $this->assertTrue($a['waive']);
    }

    public function testAnAlreadySettledFineIsNotOfferedThatTransitionAgain(): void
    {
        $paid = vk_api_fine_actions(self::auth(true), 'paid');
        $this->assertFalse($paid['pay'], 'paying a paid fine is a no-op the app should not offer');
        $this->assertTrue($paid['waive']);

        $waived = vk_api_fine_actions(self::auth(true), 'waived');
        $this->assertFalse($waived['waive']);
        $this->assertTrue($waived['pay']);
    }

    public function testAnAdminCountsAsLeadership(): void
    {
        $this->assertTrue(vk_api_fines_is_leader(self::auth(false, true)));
        $this->assertFalse(vk_api_fines_is_leader(self::auth(false)));
    }

    /**
     * `edit` is the leadership test, never `view` — the rule that
     * api/get_transactions.php got wrong and leaked the group's ledger with.
     * manage_fines happens not to be granted to Member today, so `view` would
     * pass; that is a fact about the data, not about the rule.
     */
    public function testTheLeadershipTestIsEditNotView(): void
    {
        $code = self::code('includes/api_fines.php');

        $this->assertStringContainsString("vk_api_can(\$auth, 'edit', 'manage_fines')", $code);
        $this->assertStringNotContainsString("vk_api_can(\$auth, 'view', 'manage_fines')", $code);

        $viewOnly = [
            'user_id' => 9, 'role_id' => 15, 'user' => ['user_role' => 'Member'],
            'permissions' => ['manage_fines' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0]],
        ];
        $this->assertFalse(
            vk_api_fines_is_leader($viewOnly),
            'A view grant must not confer the ability to manage fines.'
        );
    }

    // ── validation ──────────────────────────────────────────────────────────

    /** A fine with no reason is a figure nobody can defend when the member asks why. */
    public function testAReasonIsRequired(): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_reason('   ');
    }

    public function testAReasonIsTrimmed(): void
    {
        $this->assertSame('Missed the AGM', vk_api_fine_reason('  Missed the AGM  '));
    }

    public function testThousandsSeparatorsAreAccepted(): void
    {
        // A treasurer typing "10,000" on a phone keypad means ten thousand;
        // refusing it teaches them to distrust the form.
        $this->assertSame(10000.0, vk_api_fine_amount('10,000'));
        $this->assertSame(10000.0, vk_api_fine_amount('10 000'));
    }

    /**
     * @testWith ["0"]
     *           ["-500"]
     *           ["0.00"]
     *
     * Written as separate cases rather than a loop with try/fail: $this->fail()
     * itself throws, so a catch(Throwable) around it swallows the failure and
     * the test passes no matter what the code does. It did — a mutation removing
     * the check survived.
     */
    public function testAZeroOrNegativeFineIsRefused(string $bad): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_amount($bad);
    }

    public function testANonNumericAmountIsRefused(): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_amount('a lot');
    }

    /** decimal(15,2) truncates rather than refusing, recording a different figure. */
    public function testAnAmountBeyondTheColumnIsRefused(): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_amount('99999999999999999');
    }

    // ── filters ─────────────────────────────────────────────────────────────

    public function testAnUnknownStatusFilterIsRefused(): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_filters(['status' => 'disputed']);
    }

    public function testAnUnparseableDateIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(Throwable::class);
        vk_api_fine_filters(['date_from' => 'yesterday']);
    }

    public function testFiltersAreBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_fine_filters([
            'status' => 'pending', 'date_from' => '2026-01-01', 'search' => 'Hamisi',
        ]);
        $this->assertCount(3, $where);
        $this->assertCount(5, $params, 'search binds three LIKEs');
        foreach ($where as $clause) {
            $this->assertStringNotContainsString('2026-01-01', $clause);
            $this->assertStringNotContainsString('Hamisi', $clause);
        }
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_fine_filters([]));
    }

    // ── the group toggle the group asked for ────────────────────────────────

    /**
     * The API must not be stricter than the website here. The data is one
     * browser tab away; refusing it in the app would not protect anybody, it
     * would only mean the app cannot show what the group agreed to show.
     */
    public function testTheGroupViewIsAvailableToAnyMember(): void
    {
        $code = self::code('api/v1/my_fines.php');

        $this->assertStringContainsString("\$view = ((\$_GET['view'] ?? 'mine') === 'all') ? 'all' : 'mine';", $code);
        $this->assertStringNotContainsString(
            'vk_api_fines_require_leader(',
            $code,
            'my/fines must not be gated on leadership — that is the whole point of the toggle.'
        );
    }

    public function testOwnFinesRemainTheDefault(): void
    {
        // Reached from a screen called "My Fines"; landing on other people's
        // debts would be a surprise about other people's money.
        $code = self::code('api/v1/my_fines.php');
        $this->assertMatchesRegularExpression(
            "/if \(\\\$view === 'mine'\) \{.*?\\\$where\[\] *= *'f\.customer_id = \?';/s",
            $code,
            'Anything other than an explicit ?view=all must scope to the member.'
        );
    }

    public function testTheOwnViewTakesNoMemberIdFromTheClient(): void
    {
        $code = self::code('api/v1/my_fines.php');
        $this->assertStringContainsString(
            'vk_api_member_id((int) $auth[\'user_id\'])',
            $code,
            'The member comes from the token, never from the query string.'
        );
        $this->assertStringNotContainsString(
            "\$_GET['member_id']",
            $code,
            'There must be no member_id parameter here to tamper with.'
        );
    }

    public function testTheWebPageStillCarriesTheSameToggle(): void
    {
        // If the web ever drops it, the API is suddenly the more permissive of
        // the two and this must be reconsidered rather than silently kept.
        $this->assertStringContainsString(
            "(\$_GET['view'] ?? 'mine') === 'all'",
            self::code('app/bms/customer/my_fines.php')
        );
    }

    // ── writing stays closed ────────────────────────────────────────────────

    public function testEveryWritePathIsGated(): void
    {
        $this->assertStringContainsString(
            "vk_api_can(\$auth, 'create', 'manage_fines')",
            self::code('api/v1/fines_create.php'),
            'Recording uses create, which a role may hold without being able to waive.'
        );
        foreach (['api/v1/fines_update.php', 'api/v1/fines_status_change.php'] as $f) {
            $this->assertStringContainsString('vk_api_fines_require_leader($auth', self::code($f), $f);
        }
    }

    public function testTheGroupListIsLeadershipOnly(): void
    {
        $this->assertStringContainsString(
            'vk_api_fines_require_leader($auth',
            self::code('api/v1/fines.php')
        );
    }

    public function testTheGateComesBeforeTheWrite(): void
    {
        foreach ([
            'api/v1/fines_update.php'        => ['vk_api_fines_require_leader(', 'UPDATE fines'],
            'api/v1/fines_status_change.php' => ['vk_api_fines_require_leader(', 'UPDATE fines'],
            'api/v1/fines_create.php'        => ['manage_fines', 'INSERT INTO fines'],
        ] as $file => [$gateToken, $write]) {
            $code  = self::code($file);
            $gate  = strpos($code, $gateToken);
            $mutate = strpos($code, $write);
            $this->assertNotFalse($gate, $file);
            $this->assertNotFalse($mutate, $file);
            $this->assertLessThan($mutate, $gate, "{$file} writes before checking the caller.");
        }
    }

    /**
     * vk_normalize_fine_status() turns anything unrecognised into 'pending'. Used
     * on a submitted status that would silently REOPEN a settled fine on a typo,
     * so the write paths must validate against the list instead.
     */
    public function testASubmittedStatusIsValidatedNotNormalised(): void
    {
        foreach (['api/v1/fines_update.php', 'api/v1/fines_create.php'] as $f) {
            $code = self::code($f);
            $this->assertStringNotContainsString(
                'vk_normalize_fine_status($body',
                $code,
                "{$f} must not normalise a submitted status — a typo would reopen a settled fine."
            );
        }
        $this->assertStringContainsString(
            'in_array($status, vk_fine_statuses(), true)',
            self::code('api/v1/fines_update.php')
        );
    }

    public function testAFineCannotBeCreatedAlreadyWaived(): void
    {
        // Waiving something never owed is not a state the group has a word for.
        $this->assertStringContainsString(
            "in_array(\$status, ['pending', 'paid'], true)",
            self::code('api/v1/fines_create.php')
        );
    }

    public function testRepeatingATransitionIsRefusedRatherThanReLogged(): void
    {
        // A second audit entry would say the treasurer did something they did not.
        $code = self::code('api/v1/fines_status_change.php');
        $this->assertStringContainsString('$current === $target', $code);
        $this->assertStringContainsString('409', $code);
    }

    public function testEveryWriteIsAuditedAgainstTheRealUser(): void
    {
        foreach (['api/v1/fines_create.php', 'api/v1/fines_update.php', 'api/v1/fines_status_change.php'] as $f) {
            $this->assertMatchesRegularExpression(
                "/log(Create|Update)\([^;]*\\\$auth\['user_id'\]/s",
                self::code($f),
                "{$f}: the API has no session, so the audit user id must be passed explicitly."
            );
        }
    }

    // ── routing ─────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/fines'         => 'fines.php',
            'api/v1/my/fines'      => 'my_fines.php',
            'api/v1/fines/5'       => 'fines_detail.php',
            'api/v1/fines/5/waive' => 'fines_waive.php',
            'api/v1/fines/5/pay'   => 'fines_pay.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } elseif (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    public function testTheTwoTransitionsShareOneImplementation(): void
    {
        foreach (['fines_waive' => 'waived', 'fines_pay' => 'paid'] as $file => $target) {
            $code = self::code("api/v1/{$file}.php");
            $this->assertStringContainsString("\$vkFineTarget = '{$target}'", $code);
            $this->assertStringContainsString('fines_status_change.php', $code);
        }
    }

    public function testAnUnsupportedTransitionCannotBeSmuggledIn(): void
    {
        $this->assertStringContainsString(
            'in_array($target, vk_fine_statuses(), true)',
            self::code('api/v1/fines_status_change.php')
        );
    }
}
