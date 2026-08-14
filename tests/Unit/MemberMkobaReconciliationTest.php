<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * My M-Koba Reconciliation — one member checking that every payment they made
 * through M-Koba reached their Vikundi account, for the right amount.
 *
 * The group-wide page ties a whole imported statement out against the books, which
 * is a leader's job. This asks the same question from one seat: "is my money all
 * here?" — so the thing it must never do is quietly show a subset and imply it is
 * everything.
 */
class MemberMkobaReconciliationTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_mkoba_reconciliation.php');
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    public function testAMemberCannotReadAnotherMembersReconciliation(): void
    {
        // Identical gate to the two member statements: leadership may open any member
        // with ?id, everyone else is forced back to their own record. A third pattern
        // here would be a third thing to get wrong.
        $this->assertStringContainsString("\$is_leader = isAdmin() || canCreate('manage_contributions');", $this->src);
        $this->assertStringContainsString('if (!$is_leader || !$member_id) {', $this->src);
        $this->assertStringContainsString('$member_id = $own_cid;', $this->src);
    }

    public function testRowsAreScopedToTheMemberInSql(): void
    {
        // The scoping must happen in the query, not by filtering in PHP after
        // fetching everyone's rows.
        $this->assertStringContainsString('WHERE c.member_id = ?', $this->src);
        $this->assertStringContainsString('$q->execute([$member_id]);', $this->src);
    }

    public function testItIsRoutedAndReachableFromTheFinanceMenu(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');
        $this->assertStringContainsString("'my_mkoba_reconciliation'", $roots);

        $header = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertStringContainsString("getUrl('my_mkoba_reconciliation')", $header);
        $this->assertStringContainsString('My M-Koba Reconciliation', $header);
        $this->assertStringContainsString('Ulinganishaji wa M-Koba Wangu', $header);
    }

    public function testTheMenuEntrySitsInsideTheMemberGuard(): void
    {
        // An account with no member record must not be offered a page that will tell
        // it "Member not found".
        $header = file_get_contents(__DIR__ . '/../../header.php');
        $start  = strpos($header, '<?php if ($vk_is_member): ?>');
        $end    = strpos($header, '<?php endif; ?>', $start);
        $block  = substr($header, $start, $end - $start);

        $this->assertStringContainsString("getUrl('my_mkoba_reconciliation')", $block);
    }

    // -------------------------------------------------------------------------
    // It is a reconciliation, not a list
    // -------------------------------------------------------------------------

    public function testBothAmountsAreCarriedSoTheyCanBeCompared(): void
    {
        // The whole point is comparing what M-Koba says against what Vikundi recorded.
        // Selecting one amount and printing it twice would look identical and prove
        // nothing.
        $this->assertStringContainsString('m.amount        AS mkoba_amount', $this->src);
        $this->assertStringContainsString('c.amount        AS book_amount', $this->src);
        $this->assertStringContainsString("(float) \$r['mkoba_amount'] !== (float) \$r['book_amount']", $this->src);
    }

    public function testADifferenceIsSurfacedRatherThanAveragedAway(): void
    {
        $this->assertStringContainsString('$difference = $mkoba_total - $book_total;', $this->src);
        $this->assertStringContainsString("abs(\$difference) < 0.01 ? 'ok' : 'bad'", $this->src);
        $this->assertStringContainsString('Please raise this with the Treasurer.', $this->src);
    }

    public function testUnapprovedContributionsAreCalledOut(): void
    {
        // A row can be recorded and still not count yet. Showing it as simply
        // "Recorded" would tell a member their money is in when it is not.
        $this->assertStringContainsString('Awaiting approval', $this->src);
        $this->assertStringContainsString('Inasubiri idhini', $this->src);
        $this->assertStringContainsString("do not yet count towards your contributions", $this->src);
    }

    public function testTheExcludedRowsAreExplainedNotSilentlyDropped(): void
    {
        // Every excluded M-Koba line is a group transfer, an account opening or a
        // balance row — never a member payment. So nothing of a member's can hide
        // behind that filter, and the page says so rather than leaving them to wonder
        // whether something of theirs was lost.
        $this->assertStringContainsString('group transfers, account openings and balance lines', $this->src);
        $this->assertStringContainsString('uhamisho wa kikundi', $this->src);
    }

    public function testTheEmptyCaseIsStatedPlainly(): void
    {
        $this->assertStringContainsString('No M-Koba transactions are linked to your account.', $this->src);
    }

    // -------------------------------------------------------------------------
    // Presentation
    // -------------------------------------------------------------------------

    public function testItReusesTheSharedStatementSkeleton(): void
    {
        $this->assertStringContainsString("require_once __DIR__ . '/../../../includes/statement_layout.php';", $this->src);
        $this->assertStringContainsString('stmt_css();', $this->src);
        $this->assertStringContainsString('stmt_head($group,', $this->src);
    }

    public function testTransactionReferencesAreEscaped(): void
    {
        // trans_id and receipt come from an imported CSV, which is outside our control.
        $this->assertStringContainsString("htmlspecialchars((string) \$r['trans_id'])", $this->src);
        $this->assertStringContainsString("htmlspecialchars((string) \$r['receipt'])", $this->src);
    }

    public function testColourIsPreservedWhenPrinted(): void
    {
        // The status column carries the meaning; washed to grey it says nothing.
        $this->assertStringContainsString('print-color-adjust:exact', $this->src);
    }
}
