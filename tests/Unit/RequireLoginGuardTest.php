<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards audit M5: the HTML auth gate exists, and the profile pages include it
 * BEFORE they read $_SESSION['user_id'] — so an anonymous hit redirects to login
 * instead of emitting "Undefined array key user_id" warnings and querying with a
 * null user id.
 */
class RequireLoginGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testGuardRedirectsAndStops(): void
    {
        $src = file_get_contents($this->root . '/includes/require_login.php');
        $this->assertStringContainsString("empty(\$_SESSION['user_id'])", $src);
        $this->assertStringContainsString('Location:', $src);
        $this->assertStringContainsString('exit', $src);
    }

    /**
     * The guard must appear before the first $_SESSION['user_id'] read, or the
     * warning it fixes would still fire.
     */
    #[DataProvider('guardedPages')]
    public function testPageGuardsBeforeSessionUse(string $relPath): void
    {
        $full = $this->root . '/' . $relPath;
        $this->assertFileExists($full);
        $src = file_get_contents($full);

        $guardPos = strpos($src, 'require_login.php');
        $this->assertNotFalse($guardPos, "$relPath must include the login guard (audit M5)");

        $usePos = strpos($src, "\$_SESSION['user_id']");
        if ($usePos !== false) {
            $this->assertLessThan(
                $usePos,
                $guardPos,
                "$relPath must require the login guard before reading \$_SESSION['user_id']"
            );
        }
    }

    public static function guardedPages(): array
    {
        return [
            ['app/constant/profile/profile.php'],
            ['app/constant/profile/my_settings.php'],
        ];
    }
}
