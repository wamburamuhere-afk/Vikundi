<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_mkoba_reconciliation.php';

/**
 * Module 8 — M-Koba Reconciliation (group-wide and per-member "my").
 *
 * vk_api_mkoba_member_summary() and vk_api_mkoba_member_row() are direct
 * ports of member_mkoba_reconciliation.php's tie-out math (lines 62-76);
 * vk_api_mkoba_ref() is a JSON-safe copy of helpers.php's mkoba_display_ref()
 * without the HTML escaping a JSON payload does not need.
 */
final class MkobaReconciliationApiTest extends TestCase
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

    private static function memberRow(array $over = []): array
    {
        return $over + [
            'trans_date'        => '2026-02-28',
            'trans_id'          => 'ABC123',
            'receipt'           => 'DBS9N7LOXOR',
            'trans_type'        => 'Member Contribution',
            'batch'             => 'feb-2026',
            'contribution_id'   => 4,
            'mkoba_amount'      => 5000.0,
            'book_amount'       => 5000.0,
            'contribution_date' => '2026-02-28',
            'book_status'       => 'approved',
        ];
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? 4 : 15),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? [
                    'mkoba_reconciliation' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0],
                    'manage_contributions' => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
                ]
                : [],
        ];
    }

    // ── the reference-masking guard ──────────────────────────────────────────

    public function testAnOrdinaryReferencePassesThrough(): void
    {
        $this->assertSame('DBS9N7LOXOR', vk_api_mkoba_ref('DBS9N7LOXOR'));
    }

    public function testABlankReferenceIsNull(): void
    {
        $this->assertNull(vk_api_mkoba_ref(''));
        $this->assertNull(vk_api_mkoba_ref(null));
    }

    public function testAnExcelMangledScientificNotationReferenceIsNull(): void
    {
        $this->assertNull(vk_api_mkoba_ref('3.75E+15'));
        $this->assertNull(vk_api_mkoba_ref('3.8e+15'));
    }

    // ── group-wide row shaping ────────────────────────────────────────────────

    public function testAGroupRowMasksAMangledReceiptButKeepsOthers(): void
    {
        $row = vk_api_mkoba_row([
            'sno' => '1', 'receipt' => '3.8E+15', 'trans_date' => '2026-02-28',
            'member_name' => 'Consesa Munishi', 'member_id' => '255767276015',
            'amount' => '5000.00', 'trans_type' => 'Member Contribution',
            'outcome' => 'imported', 'reason' => null,
        ]);
        $this->assertNull($row['receipt']);
        $this->assertSame('imported', $row['outcome']);
        $this->assertSame(5000.0, $row['amount']);
    }

    public function testAnEmptyReasonIsNullNotAnEmptyString(): void
    {
        $row = vk_api_mkoba_row([
            'sno' => '9', 'receipt' => 'X', 'trans_date' => '2026-02-06',
            'member_name' => '', 'member_id' => '', 'amount' => '1000000.00',
            'trans_type' => 'Group Transfer', 'outcome' => 'excluded', 'reason' => '',
        ]);
        $this->assertNull($row['reason']);
    }

    // ── the empty-statement default ──────────────────────────────────────────

    public function testTheEmptySummaryIsNotReconciled(): void
    {
        $this->assertFalse(vk_api_mkoba_empty_summary()['reconciled']);
        $this->assertSame(0, vk_api_mkoba_empty_summary()['all']['count']);
    }

    // ── per-member ("my") tie-out math ────────────────────────────────────────

    public function testAPerfectMatchHasNoMismatchesAndZeroDifference(): void
    {
        $rows = [self::memberRow(), self::memberRow(['mkoba_amount' => 3000.0, 'book_amount' => 3000.0])];
        $summary = vk_api_mkoba_member_summary($rows);
        $this->assertSame(2, $summary['transactions']);
        $this->assertSame(8000.0, $summary['mkoba_total']);
        $this->assertSame(8000.0, $summary['book_total']);
        $this->assertSame(0.0, $summary['difference']);
        $this->assertSame(0, $summary['mismatches']);
        $this->assertSame(0, $summary['pending']);
    }

    public function testAnAmountMismatchIsCountedAndReflectedInTheDifference(): void
    {
        $rows = [self::memberRow(['mkoba_amount' => 5000.0, 'book_amount' => 4500.0])];
        $summary = vk_api_mkoba_member_summary($rows);
        $this->assertSame(1, $summary['mismatches']);
        $this->assertSame(500.0, $summary['difference']);
    }

    public function testAPendingBookStatusIsCountedSeparatelyFromMismatches(): void
    {
        $rows = [self::memberRow(['book_status' => 'pending'])];
        $summary = vk_api_mkoba_member_summary($rows);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(0, $summary['mismatches'], 'a pending row can still match in amount');
    }

    /** '' is a legitimate legacy "confirmed" status, same rule as the web page. */
    public function testABlankStatusCountsAsConfirmedNotPending(): void
    {
        $rows = [self::memberRow(['book_status' => ''])];
        $summary = vk_api_mkoba_member_summary($rows);
        $this->assertSame(0, $summary['pending']);
    }

    public function testTheMemberRowFlagsMatchedAndOk(): void
    {
        $row = vk_api_mkoba_member_row(self::memberRow());
        $this->assertTrue($row['matched']);
        $this->assertTrue($row['ok']);

        $mismatched = vk_api_mkoba_member_row(self::memberRow(['book_amount' => 100.0]));
        $this->assertFalse($mismatched['matched']);

        $pending = vk_api_mkoba_member_row(self::memberRow(['book_status' => 'pending']));
        $this->assertFalse($pending['ok']);
    }

    // ── leadership / override gates ──────────────────────────────────────────

    public function testAnAdminCountsAsLeadershipForTheGroupView(): void
    {
        $this->assertTrue(vk_api_mkoba_is_leader(self::auth(false, true)));
    }

    public function testAMemberWithNoGrantCannotSeeTheGroupStatement(): void
    {
        $this->assertFalse(vk_api_mkoba_is_leader(self::auth(false)));
    }

    public function testLeadershipWithTheReconciliationGrantIsAccepted(): void
    {
        $this->assertTrue(vk_api_mkoba_is_leader(self::auth(true)));
    }

    public function testTheOverrideGateIsAContributionsPermissionNotTheGroupOne(): void
    {
        // Mirrors member_mkoba_reconciliation.php's inline check exactly:
        // isAdmin() || canCreate('manage_contributions') — a different key
        // from the group-wide `mkoba_reconciliation` gate.
        $this->assertStringContainsString(
            "vk_api_can(\$auth, 'create', 'manage_contributions')",
            self::code('includes/api_mkoba_reconciliation.php')
        );
    }

    public function testAMemberCannotOverrideToSeeSomeoneElsesReconciliation(): void
    {
        $this->assertFalse(vk_api_mkoba_may_override(self::auth(false)));
        $this->assertTrue(vk_api_mkoba_may_override(self::auth(true)));
        $this->assertTrue(vk_api_mkoba_may_override(self::auth(false, true)));
    }

    // ── structural: gates fire before any query ──────────────────────────────

    public function testTheGroupEndpointGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/mkoba-reconciliation.php');
        $gate  = strpos($code, 'vk_api_mkoba_require_leader($auth)');
        $query = strpos($code, 'vk_api_mkoba_batches(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testTheOwnEndpointScopesToTheCallersOwnMemberIdByDefault(): void
    {
        $code = self::code('api/v1/my_mkoba-reconciliation.php');
        $this->assertStringContainsString(
            'vk_api_member_id((int) $auth[\'user_id\'])',
            $code,
            'the member must come from the token by default'
        );
        $this->assertStringContainsString('vk_api_mkoba_may_override($auth)', $code);
    }

    public function testAnAccountWithNoMemberRecordGetsNoOwnReconciliation(): void
    {
        $this->assertStringContainsString('no_member_record', self::code('api/v1/my_mkoba-reconciliation.php'));
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointResolvesToTheFileTheRouterExpects(): void
    {
        $expect = [
            'api/v1/mkoba-reconciliation'    => 'mkoba-reconciliation.php',
            'api/v1/my/mkoba-reconciliation' => 'my_mkoba-reconciliation.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }
}
