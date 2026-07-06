<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member profile print layout. Guards the two intent-bearing print rules: the
 * printout shows ONLY the member-details pane (never Security / Preferences /
 * Activity), and the identity/details columns stack to full width instead of the
 * old cramped nested two-column grid.
 */
class ProfilePrintLayoutTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/profile/profile.php');
    }

    public function testPrintShowsOnlyTheDetailsPane(): void
    {
        // All panes hidden by default, then #details re-shown — so a print never
        // dumps the Security / Preferences / Activity tabs.
        $this->assertStringContainsString('.tab-content > .tab-pane { display: none !important; }', $this->src);
        $this->assertStringContainsString('#details { display: block !important;', $this->src);
    }

    public function testPrintStacksColumnsFullWidth(): void
    {
        // The identity sidebar and details print full-width (not the cramped
        // 32% / 68% nested grid that squeezed label/value pairs).
        $this->assertStringContainsString(
            '.col-lg-4, .col-md-5, .col-lg-8, .col-md-7 { width: 100% !important;',
            $this->src
        );
        $this->assertStringNotContainsString('width: 32% !important', $this->src);
    }

    public function testDprintNoneIsReassertedLast(): void
    {
        // `.row { display:flex !important }` above would otherwise resurrect hidden
        // `.row.d-print-none` blocks (the on-screen page title leaked into print).
        // The final re-assert must come AFTER that rule in source order to win.
        $rowPos  = strpos($this->src, '.row { display: flex !important;');
        $hidePos = strrpos($this->src, '.d-print-none { display: none !important; }');
        $this->assertNotFalse($rowPos);
        $this->assertNotFalse($hidePos);
        $this->assertGreaterThan($rowPos, $hidePos, 'd-print-none re-assert must follow the .row flex rule');
    }

    public function testSidebarHiddenInPrint(): void
    {
        // The avatar/activity/account sidebar is screen-only; identity lives in the
        // branded print header instead.
        $this->assertStringContainsString('col-lg-4 col-md-5 d-print-none', $this->src);
    }

    public function testEmptyRelationSectionsShowCleanNote(): void
    {
        // Empty beneficiary blocks print a single "none recorded" line, not a
        // table full of N/A. The has-data flags gate the tables.
        $this->assertStringContainsString('$has_parents', $this->src);
        $this->assertStringContainsString('$has_spouse', $this->src);
        $this->assertStringContainsString('$has_guarantor', $this->src);
        $this->assertStringContainsString('No spouse recorded.', $this->src);
        $this->assertStringContainsString('No guarantor on file.', $this->src);
    }
}
