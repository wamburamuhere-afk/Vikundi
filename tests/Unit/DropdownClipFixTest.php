<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Row-action dropdowns (View Details / Print / Edit / Delete) live inside
 * .table-responsive (overflow-x:auto) and usually a .card.overflow-hidden.
 * Either wrapper clips the open menu, forcing a scrollbar that hides the lower
 * items until the user scrolls. footer.php carries a global handler that lets a
 * dropdown's clipping ancestors overflow while it is open, then restores them.
 * These tests lock that handler in place.
 */
class DropdownClipFixTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../footer.php');
    }

    public function testListensForBootstrapDropdownShowAndHide(): void
    {
        $this->assertStringContainsString("'show.bs.dropdown'", $this->src);
        $this->assertStringContainsString("'hide.bs.dropdown'", $this->src);
    }

    public function testResolvesTheDropdownToggleFromTheEvent(): void
    {
        $this->assertStringContainsString('[data-bs-toggle="dropdown"]', $this->src);
    }

    public function testNeutralisesClippingAncestorsWithImportantPriority(): void
    {
        // Must use !important — .overflow-hidden is `overflow:hidden !important`,
        // so a plain inline value would be ignored on card wrappers.
        $this->assertStringContainsString("setProperty('overflow', 'visible', 'important')", $this->src);
    }

    public function testOnlyTouchesAncestorsThatActuallyClip(): void
    {
        // Guarded by a computed-overflow check so visible wrappers are left alone.
        $this->assertStringContainsString('getComputedStyle', $this->src);
        $this->assertStringContainsString("!== 'visible'", $this->src);
    }

    public function testRestoresOriginalInlineStyleOnClose(): void
    {
        // The exact original style attribute is snapshotted and written back on hide.
        $this->assertStringContainsString('getAttribute(\'style\')', $this->src);
        $this->assertStringContainsString('setAttribute(\'style\', rec[1])', $this->src);
        $this->assertStringContainsString("removeAttribute('style')", $this->src);
    }

    public function testRunsAfterBootstrapBundleIsLoaded(): void
    {
        $bundlePos = strpos($this->src, 'bootstrap.bundle.min.js');
        $handlerPos = strpos($this->src, 'show.bs.dropdown');
        $this->assertNotFalse($bundlePos);
        $this->assertNotFalse($handlerPos);
        $this->assertGreaterThan($bundlePos, $handlerPos, 'Handler must be registered after Bootstrap loads.');
    }
}
