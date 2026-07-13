<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * When the deceased in a death expense is the MEMBER (not a dependent), the
 * member's customers.is_deceased must be set. The reliable signal is
 * deceased_id === 'member'; keying only on the free-text deceased_type label
 * silently missed records from the beneficiaries endpoint (type='member', not
 * 'mwanachama'). These guards pin the robust condition in both handlers.
 */
class DeathExpenseDeceasedFlagTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testApproveFlagsMemberByStableId(): void
    {
        $p = $this->src('actions/approve_death_expense.php');
        $this->assertStringContainsString("if (\$deceased_id === 'member' || \$deceased_type === 'mwanachama')", $p);
        $this->assertStringContainsString('is_deceased = 1', $p);
    }

    public function testSubmissionFlagsMemberByStableId(): void
    {
        $p = $this->src('actions/process_death_expense.php');
        $this->assertStringContainsString("if (\$deceased_id === 'member' || strtolower(\$deceased_type) === 'mwanachama')", $p);
        $this->assertStringContainsString('SET is_deceased = 1 WHERE customer_id = ?', $p);
    }

    public function testDependentDeathsDoNotFlagTheMember(): void
    {
        // the member branch must still be gated (not an unconditional update),
        // so a child/spouse/parent death does not mark the member deceased
        $p = $this->src('actions/approve_death_expense.php');
        $this->assertStringContainsString("\$deceased_id === 'spouse'", $p);
        $this->assertStringContainsString("strpos(\$deceased_id, 'child_')", $p);
    }
}
