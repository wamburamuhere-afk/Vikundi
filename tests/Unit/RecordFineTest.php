<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A fine used to be recordable in two places that never agreed with each other.
 *
 * The Transactions form offered "Fine" in its contribution-type list, which wrote a
 * `contributions` row with contribution_type='fine'. My Fines, the fines register
 * and the fines report all read the `fines` TABLE, so that money was counted as
 * group income by includes/finance.php and the fine itself was invisible everywhere
 * a fine is supposed to appear. Meanwhile the only writer of the fines table was the
 * meeting-absence sweep, so an ordinary fine could not be recorded at all.
 *
 * One store, one meaning: fines live in `fines`, and there is now a way to create one.
 */
class RecordFineTest extends TestCase
{
    private string $action;
    private string $page;
    private string $migration;

    protected function setUp(): void
    {
        $root = __DIR__ . '/../../';
        $this->action    = file_get_contents($root . 'actions/save_fine.php');
        $this->page      = file_get_contents($root . 'app/bms/customer/manage_fines.php');
        $this->migration = file_get_contents($root . 'database/move_fine_contributions_to_fines.php');
    }

    // -------------------------------------------------------------------------
    // The contribution form no longer pretends to record a fine
    // -------------------------------------------------------------------------

    public function testTheContributionFormNoLongerOffersFine(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/bms/customer/transactions.php');

        // The type list must not carry it any more.
        $this->assertStringNotContainsString("'fine'     => \$isSw ? 'Faini' : 'Fine',", $src);
        $this->assertDoesNotMatchRegularExpression("/'fine'\s*=>\s*\\\$isSw/", $src);
    }

    public function testTheRemainingContributionTypesAreUntouched(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/bms/customer/transactions.php');
        foreach (['monthly', 'entrance', 'agm', 'other'] as $type) {
            $this->assertMatchesRegularExpression("/'$type'\s*=>/", $src, "$type must remain");
        }
    }

    // -------------------------------------------------------------------------
    // Recording a fine
    // -------------------------------------------------------------------------

    public function testRecordingAFineWritesToTheFinesTable(): void
    {
        $this->assertStringContainsString('INSERT INTO fines (customer_id, amount, reason, status, created_at)', $this->action);
        $this->assertStringNotContainsString('INSERT INTO contributions', $this->action);
    }

    public function testItIsGatedAuthenticatedAndCsrfProtected(): void
    {
        $this->assertStringContainsString("requirePermissionJson('create', 'manage_fines');", $this->action);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_auth.php';", $this->action);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_csrf.php';", $this->action);
        // The lesson from the leadership endpoints: helpers must be loaded explicitly.
        $this->assertStringContainsString("require_once __DIR__ . '/../core/permissions.php';", $this->action);
    }

    public function testAFineMustHaveAReason(): void
    {
        // A figure nobody can explain when the member asks is worse than no record.
        $this->assertStringContainsString("if (\$reason === '') {", $this->action);
        $this->assertStringContainsString('Give a reason for the fine.', $this->action);
    }

    public function testTheAmountIsValidatedAndTolerantOfFormatting(): void
    {
        $this->assertStringContainsString("str_replace([',', ' '], '', (string) (\$_POST['amount'] ?? ''))", $this->action);
        $this->assertStringContainsString("if (!is_numeric(\$amount_raw) || (float) \$amount_raw <= 0) {", $this->action);
    }

    public function testTheMemberMustExistAndNotBeDeleted(): void
    {
        $this->assertStringContainsString("WHERE customer_id = ? AND status <> 'deleted'", $this->action);
        $this->assertStringContainsString('Member not found.', $this->action);
    }

    public function testTheStatusIsNormalisedNotTakenRaw(): void
    {
        $this->assertStringContainsString("vk_normalize_fine_status(\$_POST['status'] ?? 'pending')", $this->action);
    }

    public function testRecordingAFineIsAudited(): void
    {
        $this->assertStringContainsString("logCreate('Fines'", $this->action);
    }

    public function testTheButtonAndFormAreBehindTheCreatePermission(): void
    {
        $this->assertStringContainsString("canCreate('manage_fines')", $this->page);
        $this->assertStringContainsString('recordFineModal', $this->page);
        $this->assertStringContainsString("/actions/save_fine", $this->page);
    }

    public function testTheMemberPickerDropsEmptyMiddleNames(): void
    {
        // This list is shown in a picker; the plain CONCAT_WS renders a double space
        // for 97% of this group's members.
        $this->assertStringContainsString("NULLIF(TRIM(middle_name), '')", $this->page);
    }

    // -------------------------------------------------------------------------
    // The migration must not move the group's money
    // -------------------------------------------------------------------------

    public function testTheMigrationPreservesGroupIncome(): void
    {
        // finance.php counts income as all approved contributions (no type filter)
        // PLUS fines with status='paid'. So a fine-contribution the books already
        // counted has to arrive as 'paid', or income silently drops.
        $this->assertStringContainsString("\$countedAsIncome ? 'paid' : 'pending'", $this->migration);
        $this->assertStringContainsString('$incomeBefore', $this->migration);
        $this->assertStringContainsString('$incomeAfter', $this->migration);
    }

    public function testTheMigrationRollsBackRatherThanChangeTheBooks(): void
    {
        // If the mapping is ever wrong, the group's income must not move quietly.
        $this->assertStringContainsString('if (abs($incomeAfter - $incomeBefore) > 0.01) {', $this->migration);
        $this->assertStringContainsString('$pdo->rollBack();', $this->migration);
        $this->assertStringContainsString('ABORTED', $this->migration);
    }

    public function testCancelledRowsAreLeftAlone(): void
    {
        // Moving a cancelled figure into a live fines register would invent a debt.
        $this->assertStringContainsString("AND status <> 'cancelled'", $this->migration);
    }

    public function testTheMigrationIsIdempotentAndRegistered(): void
    {
        // It deletes what it moves, so a second run finds nothing.
        $this->assertStringContainsString('DELETE FROM contributions WHERE contribution_id = ?', $this->migration);
        $this->assertStringContainsString('No fine-typed contributions to move.', $this->migration);

        $runner = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString("'move_fine_contributions_to_fines.php'", $runner);
    }

    public function testTheWholeMoveIsOneTransaction(): void
    {
        // Half a move is money in neither table, or in both.
        $this->assertStringContainsString('$pdo->beginTransaction();', $this->migration);
        $this->assertStringContainsString('$pdo->commit();', $this->migration);
    }
}
