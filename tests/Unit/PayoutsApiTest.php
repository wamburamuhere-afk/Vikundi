<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_payouts.php';

/**
 * Module 11 — Payouts.
 *
 * The smallest and simplest module built so far: no workflow at all (every
 * payout is written straight to 'paid'), no fund-balance gate, and a
 * deliberately narrower leadership set than every other financial module —
 * Treasurer is excluded, matching record_payout.php's own role list exactly.
 */
final class PayoutsApiTest extends TestCase
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
            'payout_id'   => 4,
            'member_id'   => 30,
            'first_name'  => 'Hamisi',
            'last_name'   => 'Mbwana',
            'amount'      => '50000.00',
            'description' => 'Msaada wa matibabu',
            'payout_date' => '2026-09-05',
            'status'      => 'paid',
            'created_at'  => '2026-09-05 09:00:00',
        ];
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testMemberIsNestedRatherThanFlat(): void
    {
        $row = vk_api_payouts_row(self::raw());
        $this->assertSame(['id' => 30, 'name' => 'Hamisi Mbwana'], $row['member']);
    }

    public function testAmountIsANumberNotAString(): void
    {
        $this->assertSame(50000.0, vk_api_payouts_row(self::raw())['amount']);
    }

    public function testABlankDescriptionIsNullNotAnEmptyString(): void
    {
        $this->assertNull(vk_api_payouts_row(self::raw(['description' => '   ']))['description']);
    }

    public function testStatusIsAlwaysPaidInPractice(): void
    {
        $this->assertSame('paid', vk_api_payouts_row(self::raw())['status']);
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function testThousandsSeparatorsAreAcceptedInAmount(): void
    {
        $this->assertSame(50000.0, vk_api_payouts_amount('50,000'));
    }

    /**
     * @testWith ["0"]
     *           ["-500"]
     *           ["free"]
     */
    public function testAZeroOrNegativeOrNonNumericAmountIsRefused(string $bad): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_amount/');
        vk_api_payouts_amount($bad);
    }

    // ── the deliberately narrower role set ────────────────────────────────

    public function testTheMigrationExcludesTreasurer(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/add_member_payouts_permission.php');
        $this->assertStringNotContainsString("'treasurer'", $src);
        $this->assertStringNotContainsString("'mhazini'", $src);
        $this->assertStringNotContainsString("'mweka hazina'", $src);
        // But does grant the three roles record_payout.php's own array names.
        $this->assertStringContainsString("'chairperson'", $src);
        $this->assertStringContainsString("'secretary'", $src);
    }

    public function testTheMigrationGrantsViewAndCreateOnlyNoEditOrDelete(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/add_member_payouts_permission.php');
        $this->assertStringContainsString('VALUES (?, ?, 1, 1, 0, 0)', $src);
    }

    public function testTheMigrationIsRegistered(): void
    {
        $migrate = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString('add_member_payouts_permission.php', $migrate);
    }

    // ── no workflow, no fund gate ───────────────────────────────────────────

    public function testNoWorkflowHelpersExistInThisModule(): void
    {
        // Unlike every other financial module, there is no review/approve/
        // mark-paid concept at all — confirm nothing was accidentally copied
        // in from a sibling module.
        $code = self::code('includes/api_payouts.php');
        $this->assertStringNotContainsString('assertReviewable', $code);
        $this->assertStringNotContainsString('assertApprovable', $code);
        $this->assertStringNotContainsString('workflowCaptureSignature', $code);
        $this->assertStringNotContainsString('getGroupFundBalance', $code);
    }

    public function testCreateWritesStatusPaidDirectly(): void
    {
        $this->assertStringContainsString("'paid')", self::code('api/v1/payouts.php'));
    }

    // ── structural: gates fire before any query ───────────────────────────

    public function testCreateGateComesBeforeTheInsert(): void
    {
        $code   = self::code('api/v1/payouts.php');
        $gate   = strpos($code, "vk_api_require_permission(\$auth, 'create', 'member_payouts')");
        $insert = strpos($code, 'INSERT INTO member_payouts');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($insert);
        $this->assertLessThan($insert, $gate);
    }

    public function testListGateComesBeforeAnyQuery(): void
    {
        // 'FROM member_payouts p' also appears in the POST branch's
        // post-insert confirmation SELECT, which sits earlier in the file —
        // anchor on the list query's own ORDER BY instead, which is unique.
        $code  = self::code('api/v1/payouts.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'member_payouts')");
        $query = strpos($code, 'ORDER BY p.payout_date DESC, p.payout_id DESC');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testUnknownMemberIsRefusedBeforeInsert(): void
    {
        $code   = self::code('api/v1/payouts.php');
        $check  = strpos($code, 'member_not_found');
        $insert = strpos($code, 'INSERT INTO member_payouts');
        $this->assertNotFalse($check);
        $this->assertNotFalse($insert);
        $this->assertLessThan($insert, $check);
    }

    // ── auditing ────────────────────────────────────────────────────────────

    public function testTheWriteIsAuditedAgainstTheRealUser(): void
    {
        // record_payout.php itself never calls the activity logger at all —
        // the API adds this rather than mirroring that gap.
        $this->assertMatchesRegularExpression(
            "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            self::code('api/v1/payouts.php')
        );
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testTheEndpointResolvesToAFlatFileWithNoRootsChange(): void
    {
        $this->assertFileExists(__DIR__ . '/../../api/v1/payouts.php');
    }
}
