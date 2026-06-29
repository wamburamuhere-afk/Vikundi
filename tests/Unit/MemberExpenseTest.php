<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-member expenses: an expense in general_expenses can be whole-organization
 * (member_id NULL) or charged to one member (member_id set).
 *
 * Pure tests cover the request->id normalisation; source-guard tests pin the
 * wiring (migration registered, column added, endpoints carry the member link)
 * — the DB behaviour itself is verified live.
 */
class MemberExpenseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/expense_helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- vk_expense_member_id (pure) ----------------------------------------

    public function testEmptyOrMissingMeansWholeOrg(): void
    {
        $this->assertNull(vk_expense_member_id(null));
        $this->assertNull(vk_expense_member_id(''));
        $this->assertNull(vk_expense_member_id('   '));
    }

    public function testZeroAndNonNumericMeanWholeOrg(): void
    {
        $this->assertNull(vk_expense_member_id('0'));
        $this->assertNull(vk_expense_member_id('abc'));
        $this->assertNull(vk_expense_member_id('-3'));
        $this->assertNull(vk_expense_member_id('1.5'));
    }

    public function testValidMemberIdReturnsPositiveInt(): void
    {
        $this->assertSame(5, vk_expense_member_id('5'));
        $this->assertSame(7, vk_expense_member_id(' 7 '));
        $this->assertSame(42, vk_expense_member_id(42));
    }

    // --- wiring (source guards) ---------------------------------------------

    public function testMigrationIsRegisteredAndAddsMemberColumn(): void
    {
        $this->assertStringContainsString(
            'add_member_expense_column.php',
            $this->src('database/migrate.php'),
            'The member-expense migration must be registered in migrate.php.'
        );
        $mig = $this->src('database/add_member_expense_column.php');
        $this->assertStringContainsString('general_expenses', $mig);
        $this->assertStringContainsString('member_id', $mig);
    }

    public function testAddEndpointStoresMemberId(): void
    {
        $add = $this->src('api/add_general_expense.php');
        $this->assertStringContainsString('vk_expense_member_id', $add);
        // member_id is part of the INSERT column list.
        $this->assertMatchesRegularExpression('/INSERT INTO general_expenses[^;]*member_id/s', $add);
    }

    public function testListEndpointExposesMemberAndScopeFilters(): void
    {
        $get = $this->src('api/get_general_expenses.php');
        $this->assertStringContainsString('member_name', $get);          // joined name returned
        $this->assertStringContainsString('LEFT JOIN customers', $get);  // join present
        $this->assertStringContainsString("ge.member_id IS NULL", $get); // 'general' scope
        $this->assertStringContainsString("ge.member_id IS NOT NULL", $get); // 'member' scope
        $this->assertStringContainsString('recordsFiltered', $get);
    }

    public function testUiHasMemberPickerAndChargedToColumn(): void
    {
        $ui = $this->src('app/constant/accounts/general_expenses.php');
        $this->assertStringContainsString('name="member_id"', $ui);   // modal picker
        $this->assertStringContainsString('renderChargedTo', $ui);    // column renderer
        $this->assertStringContainsString('memberFilter', $ui);       // filter control
    }
}
