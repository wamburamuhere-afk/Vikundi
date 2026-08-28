<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_transactions.php';

/**
 * Module 5 — Transactions.
 *
 * The module exists because /contributions and /transactions answer different
 * questions over the same table: money by the month it COVERS versus money by
 * the date it ARRIVED. They may disagree year by year; their grand totals may
 * not.
 */
final class TransactionsApiTest extends TestCase
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

    private static function row(array $over = []): array
    {
        return $over + [
            'contribution_id'   => 7,
            'member_id'         => 3,
            'amount'            => '50000.00',
            'contribution_type' => 'monthly',
            'status'            => 'approved',
            'contribution_date' => '2026-01-01',
            'description'       => null,
            'receipt_number'    => null,
            'account'           => null,
            'evidence_path'     => null,
            'created_at'        => '2026-01-01 08:00:00',
            'reviewed_at'       => null,
            'approved_at'       => null,
            'customer_name'     => 'Hawa Mtui',
            'first_name'        => 'Hawa',
            'last_name'         => 'Mtui',
        ];
    }

    // ── the row carries the statement columns ───────────────────────────────

    public function testTheRowCarriesTheMkobaStatementColumns(): void
    {
        $row = vk_api_txn_row(self::row([
            'mkoba_sno'           => '12',
            'mkoba_trans_id'      => 'DBS2N6S4DVM',
            'mkoba_member_id_str' => '0783459353',
            'mkoba_source'        => 'Hawa Mtui',
            'mkoba_destination'   => 'UKUU Msakuzi',
            'mkoba_trans_type'    => 'Deposit',
        ]));

        $this->assertSame([
            'sno'           => '12',
            'trans_id'      => 'DBS2N6S4DVM',
            'member_id_str' => '0783459353',
            'source'        => 'Hawa Mtui',
            'destination'   => 'UKUU Msakuzi',
            'trans_type'    => 'Deposit',
        ], $row['mkoba']);
    }

    /**
     * Without them a treasurer cannot tie the books to the M-Koba statement,
     * which is the only reason this endpoint exists beside /contributions.
     */
    public function testTheStatementColumnsAreWhatContributionsDoesNotHave(): void
    {
        $contribRow = vk_api_contrib_row(self::row());
        $this->assertArrayNotHasKey('mkoba', $contribRow);
        $this->assertArrayHasKey('mkoba', vk_api_txn_row(self::row()));
    }

    public function testAnAbsentStatementColumnIsNullNotAnEmptyString(): void
    {
        // '' renders as a blank cell that looks like data; null renders as absent.
        $row = vk_api_txn_row(self::row(['mkoba_sno' => '', 'mkoba_source' => '   ']));

        $this->assertNull($row['mkoba']['sno']);
        $this->assertNull($row['mkoba']['source']);
        $this->assertNull($row['mkoba']['trans_id'], 'a key missing entirely is null too');
    }

    /**
     * Built on vk_api_contrib_row() so the shared fields cannot mean one thing
     * on one endpoint and something else on the other.
     */
    public function testTheSharedFieldsAgreeWithTheContributionsRow(): void
    {
        $r = self::row(['account' => 'M-Koba', 'mkoba_trans_id' => 'X1']);
        $txn = vk_api_txn_row($r);
        $con = vk_api_contrib_row($r);

        foreach (['contribution_id', 'member_id', 'member_name', 'amount', 'type',
                  'status', 'date', 'is_opening', 'counts_toward_savings'] as $k) {
            $this->assertSame($con[$k], $txn[$k], "{$k} must not diverge between the two endpoints.");
        }
    }

    public function testTheRowIsDerivedRatherThanRewritten(): void
    {
        $this->assertStringContainsString(
            'vk_api_contrib_row($r)',
            self::code('includes/api_transactions.php'),
            'A second row builder is how the two endpoints would drift apart.'
        );
    }

    // ── the group endpoint is leadership only ───────────────────────────────

    /**
     * api/get_transactions.php was gated on canView(), the grant an ordinary
     * MEMBER holds, and every signed-in member could pull all 333 rows of the
     * group's history. `edit` is the leadership test precisely because view is
     * not.
     */
    public function testTheGroupListRefusesANonLeader(): void
    {
        $this->assertStringContainsString(
            'vk_api_txn_require_leader($auth)',
            self::code('api/v1/transactions.php')
        );
        $this->assertStringContainsString(
            'vk_api_contrib_is_leader($auth)',
            self::code('includes/api_transactions.php'),
            'The gate must use the shared leadership test, not a local role list.'
        );
    }

    public function testTheRefusalNamesTheMembersOwnEndpoint(): void
    {
        // A 403 that does not say where to go instead is a dead end in the app.
        $this->assertStringContainsString(
            '/api/v1/my/transactions',
            self::code('includes/api_transactions.php')
        );
    }

    public function testTheGroupGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/transactions.php');
        $gate  = strpos($code, 'vk_api_txn_require_leader(');
        $query = strpos($code, 'FROM contributions');

        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate, 'The group is queried before the caller is checked.');
    }

    /**
     * On the group endpoint member_id is a genuine filter, not the scoping
     * overwrite /contributions applies — the caller can already see everyone.
     */
    public function testTheGroupListDoesNotScopeByOverwriting(): void
    {
        $code = self::code('api/v1/transactions.php');
        $this->assertStringNotContainsString(
            'vk_api_contrib_scope(',
            $code,
            'Scoping here would silently narrow a leader to their own rows.'
        );
    }

    // ── the member endpoint is pinned to the member ─────────────────────────

    public function testTheOwnEndpointPinsANonLeaderToTheirOwnRecord(): void
    {
        $this->assertStringContainsString(
            'vk_api_contrib_scope($auth, (int) ($_GET[\'member_id\'] ?? 0))',
            self::code('api/v1/my_transactions.php'),
            'member_id must be overwritten for a non-leader, not validated.'
        );
    }

    /**
     * The router builds the handler filename from the URL, so /my/transactions
     * resolves to api/v1/my_transactions.php. A file named anything else is
     * never reached and the endpoint silently 404s.
     */
    public function testBothEndpointsAreNamedWhatTheRouterResolvesTo(): void
    {
        preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', 'api/v1/my/transactions', $m);
        $this->assertNotEmpty($m);
        $this->assertFileExists(__DIR__ . '/../../api/v1/' . $m[1] . '_' . $m[2] . '.php');

        $this->assertFileExists(__DIR__ . '/../../api/v1/transactions.php');
    }

    // ── filters ─────────────────────────────────────────────────────────────

    public function testTheAccountFilterIsWhatContributionsLacks(): void
    {
        [$where, $params] = vk_api_txn_filters(['account' => 'M-Koba']);

        $this->assertSame(['co.account = ?'], $where);
        $this->assertSame(['M-Koba'], $params);
    }

    public function testAnUnknownAccountIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(Throwable::class);
        vk_api_txn_filters(['account' => 'Crypto']);
    }

    public function testAnUnparseableDateIsRefusedRatherThanScanningTheTable(): void
    {
        // Passing it through would silently ignore the filter and show the
        // caller the wrong figures without saying so.
        $this->expectException(Throwable::class);
        vk_api_txn_filters(['date_from' => 'last-tuesday']);
    }

    public function testADateWindowBecomesTwoBoundedComparisons(): void
    {
        [$where, $params] = vk_api_txn_filters(['date_from' => '2026-01-01', 'date_to' => '2026-06-30']);

        $this->assertSame(['co.contribution_date >= ?', 'co.contribution_date <= ?'], $where);
        $this->assertSame(['2026-01-01', '2026-06-30'], $params);
    }

    public function testNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_txn_filters([]));
    }

    public function testTheAliasIsApplied(): void
    {
        [$where] = vk_api_txn_filters(['status' => 'approved'], 'x');
        $this->assertSame(['x.status = ?'], $where);
    }

    public function testEveryFilterIsBoundNotInterpolated(): void
    {
        [$where, $params] = vk_api_txn_filters([
            'status' => 'approved', 'type' => 'monthly', 'account' => 'Cash',
            'date_from' => '2026-01-01',
        ]);
        $this->assertCount(4, $where);
        $this->assertCount(4, $params);
        foreach ($where as $clause) {
            $this->assertStringEndsWith('?', $clause, 'values must never reach the SQL string');
        }
    }

    // ── the invariant that binds the two statements ─────────────────────────

    /**
     * THE ONE THAT MATTERS. The two statements may disagree year by year — money
     * received in 2026 can cover months in 2027 — but the grand totals must
     * agree, because the first thing anyone does with two statements is check
     * the totals match.
     *
     * Both sides sum the same rows only because cs_member_transactions() uses
     * exactly cs_statement_filter_sql(), the filter cs_member_schedule() sums
     * over. This pins that they still do.
     */
    public function testTheMemberStatementSumsExactlyWhatTheScheduleSums(): void
    {
        $src = file_get_contents(__DIR__ . '/../../includes/contribution_standing.php');

        $this->assertSame(
            1,
            preg_match('/function cs_member_transactions\(.*?\n    \}/s', $src, $m),
            'cs_member_transactions() must be present to compare.'
        );
        $this->assertStringContainsString(
            'cs_statement_filter_sql()',
            $m[0],
            'The receipts query must use the same filter the schedule sums, or the '
            . 'two statements stop agreeing on the member total.'
        );
    }

    /**
     * THE ONE THAT ACTUALLY BROKE. customers.initial_savings carries no date, so
     * it sits in no month: cs_transaction_grid() cannot bucket it and
     * cs_member_transactions() never returns it. cs_member_schedule() DOES count
     * it, so a statement built only from dated receipts falls short of
     * /contributions/standing's total_saved by exactly the carried-in amount.
     *
     * Shipped that way and caught live: demo member 30 read 420,000 here against
     * 440,000 on standing — their 20,000 of carried-in savings. It passed
     * locally only because every member in the dev database has 0.
     */
    public function testCarriedInSavingsAreCountedInTheGrandTotal(): void
    {
        $summary = ['total' => ['actual' => 420000.0, 'paid' => 420000.0]];

        $this->assertSame(440000.0, vk_api_txn_received_total(20000.0, $summary));
        $this->assertSame(
            420000.0,
            vk_api_txn_received_total(0.0, $summary),
            'A member with nothing carried in is unaffected.'
        );
    }

    public function testTheBroughtForwardOpeningIsReadFromTheMemberRecord(): void
    {
        $code = self::code('api/v1/my_transactions.php');

        $this->assertStringContainsString('initial_savings', $code, 'it must be selected');
        // Not merely selected: a mutation setting $openingBf = 0.0 left every
        // other assertion here passing.
        $this->assertMatchesRegularExpression(
            '/\$openingBf = \(float\) \(\$member\[.initial_savings.\] \?\? 0\);/',
            $code,
            'The opening must come from the member record, not a literal.'
        );
        $this->assertStringContainsString(
            'vk_api_txn_received_total($openingBf, $summary)',
            $code,
            'and it must reach the total, not merely be fetched.'
        );
        $this->assertStringContainsString(
            "'opening_brought_forward' => \$openingBf",
            $code,
            'The app needs it as its own line, the way the web statement prints it.'
        );
    }

    /**
     * The web already solved this and the API must not diverge from it:
     * member_transactions.php shows the carried-in amount as a brought-forward
     * opening line so opening + dated receipts equals the contributions total.
     */
    public function testTheWebStatementStillCarriesTheSameOpeningLine(): void
    {
        $code = self::code('app/constant/reports/member_transactions.php');

        $this->assertStringContainsString('initial_savings', $code);
        $this->assertMatchesRegularExpression(
            '/\$opening_bf\s*=/',
            $code,
            'If the web drops its opening line the two statements disagree again.'
        );
    }

    public function testTheReceiptsSubtotalIsPublishedSeparatelyFromTheTotal(): void
    {
        // Three figures, because the member is owed an explanation of why the
        // receipts they can count do not add up to the total they are shown.
        $code = self::code('api/v1/my_transactions.php');
        foreach (["'opening_brought_forward'", "'receipts_total'", "'received_total'"] as $k) {
            $this->assertStringContainsString($k, $code);
        }
    }

    public function testTheOwnEndpointTakesItsTotalFromTheSharedSummary(): void
    {
        $code = self::code('api/v1/my_transactions.php');

        $this->assertStringContainsString("\$summary['total']['actual']", $code);
        $this->assertStringContainsString('cs_year_summary($grid)', $code);
        $this->assertStringContainsString('cs_transaction_grid(', $code);
        $this->assertStringContainsString('cs_member_transactions(', $code);
    }

    public function testTheOwnEndpointDoesNotWriteItsOwnReceiptsQuery(): void
    {
        // A hand-written SELECT over contributions here is exactly the drift the
        // module is built to prevent.
        $code = self::code('api/v1/my_transactions.php');
        $this->assertStringNotContainsString(
            'FROM contributions',
            $code,
            'Receipts must come from cs_member_transactions(), not a local query.'
        );
    }

    /**
     * Money carried in from M-Koba is an OPENING balance, not a fresh payment.
     * A member reading their first row otherwise sees a payment they do not
     * remember making.
     */
    public function testOpeningBalancesAreFlaggedOnTheMembersOwnReceipts(): void
    {
        $this->assertStringContainsString(
            'cs_is_opening(',
            self::code('api/v1/my_transactions.php')
        );
    }
}
