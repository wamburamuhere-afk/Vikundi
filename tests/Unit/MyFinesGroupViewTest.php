<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * My Fines now has two views: the member's own fines, and every fine in the group.
 *
 * The group asked for this directly. It is the same disclosure they already make
 * elsewhere — the Group Financial Ledger shows any member every other member's
 * contributions and shortfall — so it widens no boundary that was not already open.
 *
 * What this test mostly guards is that OWN fines stay the default. The page is
 * reached from a menu entry called "My Fines"; landing on a list of other people's
 * debts instead would be a surprise, and a surprise about other people's money.
 */
class MyFinesGroupViewTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/my_fines.php');
    }

    // -------------------------------------------------------------------------
    // The default must not drift
    // -------------------------------------------------------------------------

    public function testOwnFinesRemainTheDefaultView(): void
    {
        // Anything other than an explicit ?view=all resolves to the member's own.
        $this->assertStringContainsString("\$view = ((\$_GET['view'] ?? 'mine') === 'all') ? 'all' : 'mine';", $this->src);
    }

    public function testTheOwnViewIsStillScopedToTheMemberInSql(): void
    {
        $this->assertStringContainsString('WHERE f.customer_id = ?', $this->src);
        $this->assertStringContainsString('$fstmt->execute([$customer_id]);', $this->src);
    }

    public function testTheGroupViewIsReachableAndLabelled(): void
    {
        $this->assertStringContainsString("getUrl('my_fines') ?>?view=all", $this->src);
        $this->assertStringContainsString("All Members' Fines", $this->src);
        $this->assertStringContainsString('Faini za Wanachama Wote', $this->src);
    }

    public function testBothToggleButtonsExistInBothLanguages(): void
    {
        $this->assertStringContainsString("\$t('Mine', 'Zangu')", $this->src);
        $this->assertStringContainsString("\$t('All Members', 'Wanachama Wote')", $this->src);
    }

    // -------------------------------------------------------------------------
    // The group list reads as a group list
    // -------------------------------------------------------------------------

    public function testTheMemberColumnAppearsOnlyInTheGroupView(): void
    {
        // Own fines need no name column — every row is the reader's.
        $this->assertStringContainsString("<?php if (\$view === 'all'): ?><th><?= \$t('Member', 'Mwanachama') ?></th><?php endif; ?>", $this->src);
    }

    public function testTheReadersOwnRowsAreMarkedInTheGroupList(): void
    {
        // Finding yourself in a list of 327 names is the first thing anyone does.
        $this->assertStringContainsString("(int) \$f['customer_id'] === \$customer_id", $this->src);
        $this->assertStringContainsString("\$t('You', 'Wewe')", $this->src);
    }

    public function testMemberNamesAreEscaped(): void
    {
        $this->assertStringContainsString("safe_output(\$f['member_name'] ?: '—')", $this->src);
    }

    public function testTheTotalRowSpansCorrectlyInBothViews(): void
    {
        // The group view has one more column; a hard-coded colspan would shunt the
        // total under the wrong heading and misstate what the figure refers to.
        $this->assertStringContainsString("colspan=\"<?= \$view === 'all' ? 3 : 2 ?>\"", $this->src);
        $this->assertStringContainsString("\$t('Group total owing', 'Jumla ya deni la kikundi')", $this->src);
    }

    public function testTheEmptyStateSaysWhichListIsEmpty(): void
    {
        // "You have no fines. Well done!" would be wrong under a group heading.
        $this->assertStringContainsString('No fines have been recorded in the group.', $this->src);
        $this->assertStringContainsString('Hakuna faini iliyorekodiwa kwenye kikundi.', $this->src);
    }

    // -------------------------------------------------------------------------
    // Printing
    // -------------------------------------------------------------------------

    public function testThePrintedHeadingMatchesTheViewOnScreen(): void
    {
        // Printing a group list under the heading "MY FINES" would misrepresent the
        // page to anyone handed the paper.
        $this->assertStringContainsString("ALL MEMBERS' FINES", $this->src);
        $this->assertStringContainsString('FAINI ZA WANACHAMA WOTE', $this->src);
    }

    public function testTheScopeLineIsPrintedButTheLeadershipNoteIsNot(): void
    {
        // A printed page of other people's fines must state how many members it
        // covers, or the reader cannot tell whether they hold all of it. The
        // "confirmed by leadership" note stays screen-only, as it always was.
        $this->assertStringContainsString('bi bi-people me-1"></i><?= count($fines) ?>', $this->src);
        $this->assertStringContainsString('mb-0 d-print-none"><i class="bi bi-info-circle', $this->src);
    }

    public function testTheMemberCountIsDistinctNotARowCount(): void
    {
        // "9 fines across 9 members" would be wrong if one member had three.
        $this->assertStringContainsString("count(array_unique(array_column(\$fines, 'customer_id')))", $this->src);
    }
}
