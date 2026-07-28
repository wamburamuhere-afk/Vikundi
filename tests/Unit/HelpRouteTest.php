<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Help item in the top-right nav links to getUrl('help') -> /help, but roots.php
 * only registered the 'help.php' key, so /help returned the "Page Not Found" screen.
 * The clean-URL 'help' key must map to the coming-soon page too.
 */
class HelpRouteTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../roots.php');
    }

    public function testHelpCleanUrlRouteIsRegistered(): void
    {
        $this->assertStringContainsString("'help' => COMING_SOON_FILE", $this->src);
    }

    public function testNavLinksToTheHelpCleanUrl(): void
    {
        // Guard the assumption the route fix depends on: the nav uses getUrl('help').
        $header = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertStringContainsString("getUrl('help')", $header);
    }
}
