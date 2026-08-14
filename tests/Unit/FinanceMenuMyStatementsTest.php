<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Finance menu now separates the group's money from the member's own.
 *
 * The group asked for this directly: a member opening Finance should find My
 * Contributions, My Transactions and My M-Koba Reconciliation next to the My Fines
 * entry that was already there, while the group-wide entries stay exactly as they
 * are. This test holds that split in place.
 *
 * The personal block is hidden for accounts with no member record — an Admin login
 * has none, and a menu entry that leads to "Member not found" is worse than no menu
 * entry at all.
 */
class FinanceMenuMyStatementsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../header.php');
    }

    /** The Finance dropdown markup only. */
    private function financeMenu(): string
    {
        $start = strpos($this->src, 'aria-labelledby="financeDropdown"');
        $this->assertNotFalse($start, 'the Finance dropdown must exist');
        $end = strpos($this->src, '</ul>', $start);
        return substr($this->src, $start, $end - $start);
    }

    // -------------------------------------------------------------------------
    // The member's own entries
    // -------------------------------------------------------------------------

    public function testMyContributionsAndMyTransactionsAreInTheFinanceMenu(): void
    {
        $menu = $this->financeMenu();

        $this->assertStringContainsString("getUrl('member_statement')", $menu);
        $this->assertStringContainsString("getUrl('member_transactions')", $menu);
        $this->assertStringContainsString('My Contributions', $menu);
        $this->assertStringContainsString('My Transactions', $menu);
    }

    public function testTheMemberEntriesAreLabelledInBothLanguages(): void
    {
        $menu = $this->financeMenu();

        $this->assertStringContainsString('Michango Yangu', $menu);
        $this->assertStringContainsString('Miamala Yangu', $menu);
        $this->assertStringContainsString('Taarifa Zangu', $menu, 'the section heading needs Swahili too');
        $this->assertStringContainsString('My Information', $menu);
    }

    public function testPersonalEntriesSitBelowTheGroupWideOnes(): void
    {
        // The group was explicit that the existing entries stay as group information.
        // Mixing a member's own figures into that list is what makes a finance menu
        // confusing, so the personal block comes after a divider.
        $menu = $this->financeMenu();

        $group    = strpos($menu, "getUrl('budget')");
        $divider  = strpos($menu, 'dropdown-divider');
        $personal = strpos($menu, "getUrl('member_statement')");

        $this->assertNotFalse($divider, 'a divider must separate group money from personal money');
        $this->assertLessThan($divider, $group);
        $this->assertLessThan($personal, $divider);
    }

    public function testTheGroupWideEntriesAreUntouched(): void
    {
        $menu = $this->financeMenu();

        foreach (['transactions', 'mkoba_reconciliation', 'manage_contributions', 'expenses', 'petty_cash', 'budget'] as $route) {
            $this->assertStringContainsString("getUrl('$route')", $menu, "group entry $route must remain");
        }
    }

    // -------------------------------------------------------------------------
    // Hidden when there is no member record
    // -------------------------------------------------------------------------

    public function testThePersonalBlockIsGuardedByAMemberRecord(): void
    {
        $menu = $this->financeMenu();

        $this->assertStringContainsString('<?php if ($vk_is_member): ?>', $menu);

        // Every personal entry must sit inside that guard, My Fines included.
        $guardStart = strpos($menu, '<?php if ($vk_is_member): ?>');
        $guardEnd   = strpos($menu, '<?php endif; ?>', $guardStart);
        $block      = substr($menu, $guardStart, $guardEnd - $guardStart);

        foreach (["getUrl('member_statement')", "getUrl('member_transactions')", "getUrl('my_fines')"] as $entry) {
            $this->assertStringContainsString($entry, $block, "$entry must be inside the member guard");
        }
    }

    public function testTheMemberRecordIsResolvedOnceAndCached(): void
    {
        // Resolving this on every page load would add a query to every request in the
        // product for a value that changes at most once in a member's lifetime.
        $this->assertStringContainsString("if (empty(\$_SESSION['vk_member_id'])) {", $this->src);
        $this->assertStringContainsString('SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1', $this->src);
        $this->assertStringContainsString('$vk_is_member = ((int) ($_SESSION[\'vk_member_id\'] ?? 0)) > 0;', $this->src);
    }

    public function testTheLookupIsBoundNotInterpolated(): void
    {
        // The user id comes from the session, but a bound parameter costs nothing and
        // keeps this consistent with the rest of the codebase.
        $this->assertStringNotContainsString('WHERE user_id = " . $_SESSION', $this->src);
        $this->assertStringContainsString("\$vk_m->execute([\$_SESSION['user_id']]);", $this->src);
    }

    // -------------------------------------------------------------------------
    // The pages behind the links
    // -------------------------------------------------------------------------

    public function testBothTargetsDefaultToTheViewersOwnRecord(): void
    {
        // These menu entries pass no id, so each page must fall back to the logged-in
        // member — and must keep forcing an ordinary member back to their own record
        // even when an id IS supplied.
        foreach (['member_statement.php', 'member_transactions.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../../app/constant/reports/' . $file);
            $this->assertStringContainsString('if (!$is_leader || !$member_id) {', $src, "$file");
            $this->assertStringContainsString('$member_id = $own_cid;', $src, "$file");
        }
    }

    public function testBothTargetsAreRouted(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');
        $this->assertStringContainsString("'member_statement'", $roots);
        $this->assertStringContainsString("'member_transactions'", $roots);
    }
}
