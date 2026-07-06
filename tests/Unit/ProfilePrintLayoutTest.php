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
}
