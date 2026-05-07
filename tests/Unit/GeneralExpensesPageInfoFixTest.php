<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the ReferenceError fix in general_expenses.php (other_expenses):
 *  - expenseTablePage() and updateExpensePageInfo() previously referenced a `table`
 *    const that was scoped inside $(document).ready(), causing:
 *      Uncaught ReferenceError: table is not defined at updateExpensePageInfo
 *  - Fix: both functions now call $('#expensesTable').DataTable() directly.
 */
class GeneralExpensesPageInfoFixTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/general_expenses.php');
    }

    // ── Regression: no bare `table` reference outside ready scope ─────────────

    public function test_expense_table_page_does_not_use_bare_table_var(): void
    {
        // The fixed version uses $('#expensesTable').DataTable() — not `table`
        $pattern = '/function\s+\w*expenseTablePage[^}]*\btable\b\.page/';
        $this->assertDoesNotMatchRegularExpression($pattern, $this->src,
            'expenseTablePage must not reference a bare `table` variable');
    }

    public function test_update_expense_page_info_does_not_use_bare_table_var(): void
    {
        $pattern = '/function\s+updateExpensePageInfo[^}]*\btable\b\.page/';
        $this->assertDoesNotMatchRegularExpression($pattern, $this->src,
            'updateExpensePageInfo must not reference a bare `table` variable');
    }

    // ── Correct fix: DataTable() called via selector ──────────────────────────

    public function test_expense_table_page_uses_selector(): void
    {
        $this->assertStringContainsString(
            "$('#expensesTable').DataTable().page(",
            $this->src
        );
    }

    public function test_update_expense_page_info_uses_selector(): void
    {
        $this->assertStringContainsString(
            "$('#expensesTable').DataTable()",
            $this->src
        );
    }

    // ── Functions still exist and are wired ───────────────────────────────────

    public function test_expense_table_page_function_exists(): void
    {
        $this->assertStringContainsString('expenseTablePage', $this->src);
        $this->assertStringContainsString("expenseTablePage('previous')", $this->src);
        $this->assertStringContainsString("expenseTablePage('next')", $this->src);
    }

    public function test_update_expense_page_info_called_in_draw_callback(): void
    {
        $this->assertStringContainsString('updateExpensePageInfo()', $this->src);
    }

    public function test_prev_next_buttons_exist(): void
    {
        $this->assertStringContainsString('id="expensePrevBtn"', $this->src);
        $this->assertStringContainsString('id="expenseNextBtn"', $this->src);
        $this->assertStringContainsString('id="expensePageInfo"', $this->src);
    }
}
