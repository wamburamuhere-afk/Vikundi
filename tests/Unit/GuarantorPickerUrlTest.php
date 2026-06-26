<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression for the guarantor picker not loading members: the Select2 search and
 * the autofill fetch must use getUrl() (absolute, base-path aware) clean routes —
 * a relative "api/...php" resolves to the wrong path on the admin page's clean URL
 * and 404s. Also guards that the autofill endpoint is a registered route.
 */
class GuarantorPickerUrlTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public function testPickerUsesGetUrlNotRelativePaths(): void
    {
        $src = file_get_contents(self::ROOT . 'app/bms/customer/customers.php');

        // The fix: clean routes via getUrl().
        $this->assertStringContainsString('getUrl("api/search_customers")', $src,
            'picker search must use the getUrl clean route');
        $this->assertStringContainsString('getUrl("api/get_guarantor_member")', $src,
            'autofill must use the getUrl clean route');

        // The bug: bare relative .php paths must not come back.
        $this->assertStringNotContainsString("url: 'api/search_customers.php'", $src);
        $this->assertStringNotContainsString("fetch('api/get_guarantor_member.php", $src);
    }

    public function testAutofillEndpointRouteIsRegistered(): void
    {
        $routes = file_get_contents(self::ROOT . 'roots.php');
        $this->assertStringContainsString("'api/get_guarantor_member'", $routes,
            'the autofill endpoint must be a registered clean route or getUrl 404s it');
    }
}
