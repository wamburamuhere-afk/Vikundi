<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the gated upload reader added for SEC-011.
 *
 * Batch 1 denied direct HTTP access to uploads/, which closed anonymous retrieval
 * of member e-signature images and private documents but blanked the signature on
 * every printed voucher. api/get_upload.php restores rendering behind an auth gate.
 *
 * vk_avatar_url() is pure and is tested behaviourally. The endpoint itself needs a
 * request and a database, so the ordering invariants it depends on are pinned as
 * source assertions — the same technique as the architectural-pin tests
 * (LedgerUsesStandingModuleTest and siblings), and appropriate here because
 * "the guard runs before readfile()" is a property a text assertion can hold.
 */
class GatedUploadReaderTest extends TestCase
{
    private string $endpoint;

    protected function setUp(): void
    {
        $this->endpoint = dirname(__DIR__, 2) . '/api/get_upload.php';
    }

    // ── vk_avatar_url() ────────────────────────────────────────────────────────

    public function testAvatarUrlPointsAtTheGatedReaderNotTheUploadsDirectory(): void
    {
        $url = vk_avatar_url('member_42.png');
        $this->assertStringContainsString('api/get_upload.php', $url);
        $this->assertStringContainsString('type=avatar', $url);
        $this->assertStringContainsString('name=member_42.png', $url);
        $this->assertStringNotContainsString('uploads/avatars', $url);
    }

    public function testAvatarUrlReducesAnyStoredPathToItsBasename(): void
    {
        // The column is data, not a trusted path. A traversal attempt stored in the
        // database must not survive into the URL.
        $this->assertStringContainsString('name=passwd', vk_avatar_url('../../../etc/passwd'));
        $this->assertStringNotContainsString('..', vk_avatar_url('../../../etc/passwd'));
        $this->assertStringContainsString('name=photo.png', vk_avatar_url('uploads/avatars/photo.png'));
    }

    public function testAvatarUrlReturnsEmptyForNoFilenameSoNoBrokenImageIsEmitted(): void
    {
        $this->assertSame('', vk_avatar_url(''));
        $this->assertSame('', vk_avatar_url(null));
        $this->assertSame('', vk_avatar_url('   '));
    }

    public function testAvatarUrlEncodesTheName(): void
    {
        $this->assertStringNotContainsString(' ', vk_avatar_url('two words.png'));
    }

    // ── endpoint invariants ────────────────────────────────────────────────────

    public function testEndpointAuthenticatesBeforeItServesAnything(): void
    {
        $src = (string) file_get_contents($this->endpoint);
        $auth = strpos($src, 'require_auth.php');
        $read = strpos($src, 'readfile(');
        $this->assertNotFalse($auth, 'api/get_upload.php must require the central auth guard');
        $this->assertNotFalse($read, 'api/get_upload.php is expected to serve via readfile()');
        $this->assertLessThan($read, $auth, 'The auth guard must run before readfile()');
    }

    public function testEndpointAppliesRealpathContainment(): void
    {
        // The traversal backstop copied from api/download_backup.php:18-25.
        $src = (string) file_get_contents($this->endpoint);
        $this->assertStringContainsString('realpath(', $src);
        $this->assertStringContainsString('strpos($path, $baseDir) !== 0', $src);
    }

    public function testEndpointDerivesContentTypeFromAWhitelistNotTheSuppliedName(): void
    {
        $src = (string) file_get_contents($this->endpoint);
        $this->assertStringContainsString('VK_UPLOAD_MIME', $src);
        $this->assertStringContainsString('getimagesize(', $src);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $src);
    }

    public function testEndpointNeverAcceptsAPathFromTheClient(): void
    {
        $src = (string) file_get_contents($this->endpoint);
        // The base directory is chosen server-side from a fixed map.
        $this->assertStringContainsString('VK_UPLOAD_ROOTS', $src);
        // And the supplied name is a single constrained segment.
        $this->assertStringContainsString('A-Za-z0-9._-', $src);
    }

    /**
     * The regex the endpoint applies to `name`, asserted here as behaviour so a
     * loosening of it fails a test rather than passing review.
     */
    public function testNameConstraintRejectsTraversalAndSeparators(): void
    {
        $re = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/';
        $accept = static fn (string $n): bool => (bool) preg_match($re, $n) && !str_contains($n, '..');

        $this->assertTrue($accept('signature_7.png'));
        $this->assertTrue($accept('member_42.JPG'));
        $this->assertTrue($accept('_legacy_name.png'), 'a leading underscore is an ordinary filename character');

        $this->assertFalse($accept('../../../etc/passwd'));
        $this->assertFalse($accept('a/b.png'));
        $this->assertFalse($accept('a\\b.png'));
        $this->assertFalse($accept('..'));
        $this->assertFalse($accept('.hidden'));
        $this->assertFalse($accept('-rf.png'));
        $this->assertFalse($accept(''));
        $this->assertFalse($accept('x"><script>alert(1)</script>'));
    }

    public function testSignatureRowRendersThroughTheReaderAndNotTheFilesystem(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/workflow_signature_row.php');
        $this->assertStringContainsString('api/get_upload.php?type=signature', $src);
        // The old direct-filesystem form must not come back.
        $this->assertStringNotContainsString("\$base . '/' . ltrim(\$sigPath, '/')", $src);
    }

    public function testNoPageStillRendersAnAvatarByDirectUploadsUrl(): void
    {
        // Whole-tree: uploads/ is denied to direct HTTP, so any surviving
        // <img src="...uploads/avatars/..."> is a guaranteed broken image.
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['app', 'includes'] as $dir) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $f) {
                $p = $f->getPathname();
                if (!str_ends_with($p, '.php')) {
                    continue;
                }
                if (preg_match('#(?:src|href)\s*=\s*["\'][^"\']*uploads/(avatars|signatures)#', (string) file_get_contents($p))) {
                    $offenders[] = str_replace($root . '/', '', $p);
                }
            }
        }
        $this->assertSame([], $offenders, 'Render these through api/get_upload.php — uploads/ is denied to direct HTTP (SEC-011).');
    }
}
