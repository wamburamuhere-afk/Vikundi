<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The single source of truth for which pot a contribution belongs to
 * (includes/fund_buckets.php): share capital, the Social Fund, the membership
 * deposit. Covers the pure rules; the two DB readers are verified live.
 */
class FundBucketsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/fund_buckets.php';
        require_once __DIR__ . '/../../includes/contribution_standing.php';
        require_once __DIR__ . '/../../includes/transaction_import.php';
    }

    private function rules(array $over = []): array
    {
        return fb_settings($over);
    }

    // ── type lists ───────────────────────────────────────────────────────────
    public function testEveryExistingTypeIsStillAllowed(): void
    {
        foreach (['entrance', 'monthly', 'agm', 'fine', 'other'] as $legacy) {
            $this->assertContains($legacy, fb_types());
        }
        $this->assertSame(fb_types(), txn_allowed_types());
    }

    public function testAFineCannotBeRecordedAsAContribution(): void
    {
        $this->assertNotContains('fine', fb_recordable_types());
        $this->assertArrayNotHasKey('fine', fb_type_labels(false));
    }

    public function testEveryRecordableTypeHasALabelInBothLanguages(): void
    {
        foreach ([true, false] as $isSw) {
            $this->assertSame(fb_recordable_types(), array_keys(fb_type_labels($isSw)));
        }
    }

    // ── the separation the Social Fund and the deposit depend on ─────────────
    public function testSocialFundAndDepositAreNeverSavings(): void
    {
        foreach (fb_ring_fenced_types() as $type) {
            $this->assertNotContains($type, fb_savings_types());
            $this->assertContains($type, fb_not_savings_types());
            $this->assertTrue(fb_is_ring_fenced($type));
        }
        $this->assertFalse(fb_is_ring_fenced('share'));
        $this->assertFalse(fb_is_ring_fenced('monthly'));
        $this->assertFalse(fb_is_ring_fenced(null));
    }

    public function testSharesAreSavings(): void
    {
        $this->assertContains('share', fb_savings_types());
        $this->assertNotContains('share', fb_not_savings_types());
    }

    public function testTheStatementFilterAdmitsSharesAndNotTheRingFencedTypes(): void
    {
        $sql = cs_statement_filter_sql('co');
        $this->assertStringContainsString("'share'", $sql);
        $this->assertStringNotContainsString('social_fund', $sql);
        $this->assertStringNotContainsString('deposit', $sql);
    }

    // ── fb_sql_list ──────────────────────────────────────────────────────────
    public function testSqlListQuotesTheFixedTypes(): void
    {
        $this->assertSame("'social_fund','deposit'", fb_sql_list(['social_fund', 'deposit']));
    }

    public function testSqlListDropsAnythingThatIsNotAPlainIdentifier(): void
    {
        $this->assertSame("'share'", fb_sql_list(['share', "x') OR 1=1 --", 'A', 7]));
    }

    // ── fb_settings ──────────────────────────────────────────────────────────
    public function testUnsetRulesAreOffNotInvented(): void
    {
        $r = $this->rules();
        $this->assertSame(0.0, $r['share_price']);
        $this->assertFalse($r['shares_from_monthly']);
        $this->assertSame(0.0, $r['social_fund_amount']);
        $this->assertSame(0.0, $r['membership_deposit_amount']);
    }

    public function testBlankNegativeAndJunkAmountsAreOff(): void
    {
        $r = $this->rules(['share_price' => '', 'social_fund_amount' => '-500', 'membership_deposit_amount' => 'abc']);
        $this->assertSame(0.0, $r['share_price']);
        $this->assertSame(0.0, $r['social_fund_amount']);
        $this->assertSame(0.0, $r['membership_deposit_amount']);
    }

    // ── fb_bucket_for_type ───────────────────────────────────────────────────
    public function testMonthlyIsShareCapitalOnlyWhenTheGroupSaysSo(): void
    {
        $this->assertNull(fb_bucket_for_type('monthly', false));
        $this->assertSame('shares', fb_bucket_for_type('monthly', true));
        $this->assertSame('shares', fb_bucket_for_type('share', false));
        $this->assertSame('shares', fb_bucket_for_type('share', true));
    }

    public function testRingFencedTypesKeepTheirOwnBucketEitherWay(): void
    {
        foreach ([true, false] as $fromMonthly) {
            $this->assertSame('social_fund', fb_bucket_for_type('social_fund', $fromMonthly));
            $this->assertSame('deposit', fb_bucket_for_type('deposit', $fromMonthly));
            $this->assertNull(fb_bucket_for_type('fine', $fromMonthly));
            $this->assertNull(fb_bucket_for_type('entrance', $fromMonthly));
            $this->assertNull(fb_bucket_for_type(null, $fromMonthly));
        }
    }

    // ── fb_share_count ───────────────────────────────────────────────────────
    public function testShareCountIsCapitalOverPrice(): void
    {
        $this->assertSame(12.0, fb_share_count(60000, 5000));
        $this->assertSame(2.5, fb_share_count(12500, 5000));
    }

    public function testNoPriceMeansNoShareCount(): void
    {
        $this->assertSame(0.0, fb_share_count(60000, 0));
        $this->assertSame(0.0, fb_share_count(60000, -1));
        $this->assertSame(0.0, fb_share_count(0, 5000));
    }

    // ── fb_balances_from_totals ──────────────────────────────────────────────
    public function testBalancesSplitAMembersMoneyIntoItsPots(): void
    {
        $totals = [
            'monthly' => '40000.00', 'share' => '20000.00', 'social_fund' => '6000.00',
            'deposit' => '10000.00', 'entrance' => '5000.00', 'fine' => '2000.00',
        ];

        $separate = fb_balances_from_totals($totals, $this->rules(['share_price' => '5000']));
        $this->assertSame(20000.0, $separate['shares']);
        $this->assertSame(4.0, $separate['share_count']);
        $this->assertSame(6000.0, $separate['social_fund']);
        $this->assertSame(10000.0, $separate['deposit']);

        $combined = fb_balances_from_totals($totals, $this->rules(['share_price' => '5000', 'shares_from_monthly' => 'on']));
        $this->assertSame(60000.0, $combined['shares']);
        $this->assertSame(12.0, $combined['share_count']);
        // Turning the switch on moves monthly money into shares and nothing else.
        $this->assertSame(6000.0, $combined['social_fund']);
        $this->assertSame(10000.0, $combined['deposit']);
    }

    public function testAMemberWithNoMoneyHasEmptyPots(): void
    {
        $this->assertSame(
            ['shares' => 0.0, 'share_count' => 0.0, 'social_fund' => 0.0, 'deposit' => 0.0],
            fb_balances_from_totals([], $this->rules(['share_price' => '5000']))
        );
    }
}
