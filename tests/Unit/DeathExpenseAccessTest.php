<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A member's private grief is not group property.
 *
 * WHAT THIS CAUGHT. `death_expenses.view` is granted to the Member role — the
 * same shape of grant as `manage_contributions.view` was, and it should have
 * been the lesson already learned. Three endpoints read it as permission to
 * see EVERYONE's condolence cases, and a fourth had no permission check at
 * all:
 *
 *     requirePermissionJson('view', 'death_expenses');   // the whole list
 *     requireViewPermission('death_expenses');           // any record, by id
 *     (nothing)                                          // the console itself
 *
 * Verified against the live demo site, signed in as an ordinary member
 * (`hmbwana1`, member 30):
 *
 *   - api/get_death_expenses.php returned both condolence cases in the group,
 *     including the Chairperson's TZS 900,000 case with her name attached
 *   - death_expense_view?id=2 rendered that same case in full
 *   - the Condolences management console opened, record button and all
 *
 * THE DISTINCTION, in one place from the start this time:
 *
 *     group-wide data  -> LEADERSHIP (admin, or `edit`)
 *     a single record  -> OWNERSHIP  (it is yours), or leadership
 *
 * UNLIKE CONTRIBUTIONS, the list here is put behind leadership outright,
 * because — unlike manage_contributions.php — no web screen branches on a
 * member's own `view` grant to show them just their own cases. That grant's
 * only legitimate use is the mobile API's /my/condolences.
 */
class DeathExpenseAccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/death_expense_access.php';
    }

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

    // — The rule itself ——————————————————————————————————————————————————

    public function testOnlyAdminOrEditIsLeadership(): void
    {
        $this->assertTrue(vk_death_leader_from(true, false), 'an admin is a leader');
        $this->assertTrue(vk_death_leader_from(false, true), 'edit is a leader');
        $this->assertTrue(vk_death_leader_from(true, true));
        $this->assertFalse(vk_death_leader_from(false, false), 'view alone is not leadership');
    }

    /**
     * The whole defect in one assertion: `view` is not an input to this
     * function. If it ever becomes one, a Member is a leader again.
     */
    public function testViewIsNotAnInputToTheLeadershipRule(): void
    {
        $code = self::code('includes/death_expense_access.php');

        $this->assertStringNotContainsString(
            "canView('death_expenses')",
            $code,
            'view must play no part in deciding leadership — it is the Member grant.'
        );
        $this->assertStringContainsString("canEdit('death_expenses')", $code);
    }

    // — Group-wide endpoints must require leadership ——————————————————————

    #[DataProvider('groupWideEndpoints')]
    public function testGroupWideEndpointsRequireLeadership(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_death_web_require_leader(',
            $code,
            "{$rel} serves the whole group and must require leadership."
        );
    }

    public static function groupWideEndpoints(): array
    {
        return [
            'condolences list' => ['api/get_death_expenses.php'],
        ];
    }

    /**
     * The console had NO gate at all — reachable by anyone signed in. Pinned so
     * a future edit cannot quietly drop the check back out.
     */
    public function testTheConsoleItselfIsGatedOnLeadership(): void
    {
        $code = self::code('app/constant/accounts/death_expenses.php');

        $this->assertStringContainsString(
            "requireEditPermission('death_expenses')",
            $code,
            'The console has no member-facing branch, so it must require edit, not merely view.'
        );
    }

    // — Per-record endpoints must check ownership ——————————————————————————

    /**
     * death_expenses ids are sequential, so an endpoint that loads by id and
     * renders without an ownership test is a walkable ledger of who has lost
     * a family member.
     */
    #[DataProvider('perRecordEndpoints')]
    public function testPerRecordEndpointsCheckOwnership(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            'vk_death_web_require_own_or_leader(',
            $code,
            "{$rel} loads a row by id and must check who it belongs to."
        );
    }

    public static function perRecordEndpoints(): array
    {
        return [
            'view'  => ['app/constant/accounts/death_expense_view.php'],
            'print' => ['app/constant/accounts/print_death_expense.php'],
        ];
    }

    /** The check must come after the row is loaded — it needs the row's member. */
    #[DataProvider('perRecordEndpoints')]
    public function testTheOwnershipCheckUsesTheLoadedRowsMember(string $rel): void
    {
        $code = self::code($rel);

        $this->assertStringContainsString(
            "vk_death_web_require_own_or_leader(\$pdo, (int) \$de['member_id'])",
            $code,
            "{$rel} must test the member the row actually belongs to."
        );
    }

}
