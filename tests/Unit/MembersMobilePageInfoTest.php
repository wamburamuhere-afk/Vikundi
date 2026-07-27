<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The members list threw a JS error on load: updateMobilePageInfo() read
 * `table.page.info()`, but DataTables' initComplete runs before the outer `table`
 * variable is assigned, so `table` was undefined ("Cannot read properties of
 * undefined (reading 'page')"). The fix passes the API instance (this.api()) into the
 * function and guards against a missing instance.
 */
class MembersMobilePageInfoTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/customers.php');
    }

    public function testInitCompletePassesTheApiInstance(): void
    {
        $this->assertStringContainsString('updateMobilePageInfo(this.api());', $this->src);
    }

    public function testHelperUsesThePassedApiAndGuards(): void
    {
        $this->assertStringContainsString('function updateMobilePageInfo(api)', $this->src);
        $this->assertStringContainsString('var dt = api || table;', $this->src);
        $this->assertStringContainsString('if (!dt) return;', $this->src);
        $this->assertStringContainsString('var info = dt.page.info();', $this->src);
        // The old unguarded reference that ran before `table` existed is gone.
        $this->assertStringNotContainsString('var info = table.page.info();', $this->src);
    }
}
