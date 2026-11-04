<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Every page that writes its own <head> shows the Vikundi icon in the browser tab. */
final class FaviconTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/favicon.php';
    }

    public function testTheIconFilesExist(): void
    {
        foreach (['favicon.ico', 'assets/images/icons/icon-32.png', 'assets/images/icons/icon-192.png',
                  'assets/images/icons/apple-touch-icon.png', 'superadmin/public/favicon.ico',
                  'superadmin/public/assets/icons/icon-32.png', 'superadmin/public/assets/icons/apple-touch-icon.png'] as $f) {
            $this->assertFileExists(self::$root . '/' . $f);
            $this->assertGreaterThan(500, filesize(self::$root . '/' . $f), "$f is not empty");
        }
        $this->assertSame([32, 32], array_slice(getimagesize(self::$root . '/assets/images/icons/icon-32.png'), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesize(self::$root . '/assets/images/icons/apple-touch-icon.png'), 0, 2));
    }

    public function testTheTagsPointAtTheFiles(): void
    {
        $tags = vk_favicon_tags();
        foreach (['favicon.ico', 'assets/images/icons/icon-32.png', 'assets/images/icons/icon-192.png', 'assets/images/icons/apple-touch-icon.png'] as $f) {
            $this->assertStringContainsString($f . '"', $tags);
        }
        $this->assertStringContainsString('rel="apple-touch-icon"', $tags);
    }

    public function testEveryPageWithItsOwnHeadUsesThem(): void
    {
        foreach (['header.php', 'login.php', 'register.php', 'forgot_password.php', 'reset_password.php', 'privacy.php', 'guide.php'] as $f) {
            $src = (string) file_get_contents(self::$root . '/' . $f);
            $this->assertStringContainsString("require_once __DIR__ . '/includes/favicon.php'; echo vk_favicon_tags();", $src, $f);
            $this->assertLessThan(strpos($src, '</head>'), strpos($src, 'vk_favicon_tags()'), "$f: inside <head>");
        }
        foreach (['includes/tenant_pages.php', 'includes/features.php'] as $f) {
            $this->assertStringContainsString('vk_favicon_tags()', (string) file_get_contents(self::$root . '/' . $f), $f);
        }
        $panel = (string) file_get_contents(self::$root . '/superadmin/src/bootstrap.php');
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico" sizes="any">', $panel);
    }

    public function testApacheServesTheFilesDirectly(): void
    {
        // .htaccess lets existing .ico and .png files through instead of answering 404.
        $this->assertStringContainsString('ico|woff2?', (string) file_get_contents(self::$root . '/.htaccess'));
    }
}
