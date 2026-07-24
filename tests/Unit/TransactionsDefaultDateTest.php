<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the Transactions page defaulted its "From" date filter to a rolling
 * 90-day window (and the Reset button re-applied it). For a fresh/imported group
 * whose records predate that window (e.g. the M-Koba statement dated months back),
 * the page loaded showing "No transactions" even though thousands existed — and
 * Reset didn't help. The list is server-side paginated, so an unbounded default is
 * still a cheap first load. The default is now empty (show full history).
 */
class TransactionsDefaultDateTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/transactions.php');
    }

    public function testFromDateDefaultsToEmptyNotNinetyDays(): void
    {
        $this->assertStringContainsString("\$default_from = '';", $this->src);
        // The 90-day rolling window that hid older/imported data is gone.
        $this->assertStringNotContainsString("strtotime('-90 days')", $this->src);
    }

    public function testResetClearsToTheSameEmptyDefault(): void
    {
        // Reset re-applies $default_from to the From field; now that it's empty,
        // Reset genuinely clears the date instead of snapping back to 90 days.
        $this->assertStringContainsString("\$('#fFrom').val('<?= \$default_from ?>')", $this->src);
        // The From field's initial value is the same (empty) default.
        $this->assertStringContainsString('id="fFrom"', $this->src);
        $this->assertStringContainsString('value="<?= $default_from ?>"', $this->src);
    }
}
