<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Any endpoint that calls a permission helper must actually load the file those
 * helpers live in.
 *
 * Endpoints under actions/, api/ and ajax/ are served DIRECTLY by Apache — the
 * .htaccess sends them straight to the file — so they never pass through
 * roots.php, which is what pulls in core/permissions.php for ordinary pages. An
 * endpoint that calls canCreate() without requiring that file does not fail a
 * permission check: it fatals with "Call to undefined function", returns a 500,
 * and the user sees "Server error".
 *
 * This was found by submitting the form, not by running the tests. The tests
 * asserted that the STRING canCreate('leadership_applications') appeared in the
 * source, which it did — they never executed the file, so an undefined function
 * was invisible to them. This test closes that gap across the whole tree rather
 * than for the two endpoints that happened to have it.
 */
class ActionPermissionIncludeTest extends TestCase
{
    /** The helpers that live in core/permissions.php. */
    private const HELPERS = [
        'canView', 'canCreate', 'canEdit', 'canDelete', 'canApprove',
        'isAdmin', 'requirePermissionJson', 'requireViewPermission',
    ];

    /** Every directly-served endpoint in the product. */
    private function endpoints(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        foreach (['actions', 'api', 'ajax'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            foreach (glob("$root/$dir/*.php") as $f) {
                $files[] = $f;
            }
            foreach (glob("$root/$dir/*/*.php") as $f) {
                $files[] = $f;
            }
        }
        return $files;
    }

    public function testEveryEndpointUsingAPermissionHelperLoadsThePermissionsFile(): void
    {
        $root      = dirname(__DIR__, 2) . '/';
        $offenders = [];

        foreach ($this->endpoints() as $path) {
            $src = file_get_contents($path);

            // Comments would otherwise count as usage.
            $code = preg_replace('!/\*.*?\*/!s', '', $src);
            $code = preg_replace('!//.*$!m', '', (string) $code);

            $usesHelper = false;
            foreach (self::HELPERS as $fn) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/', (string) $code)) {
                    $usesHelper = true;
                    break;
                }
            }
            if (!$usesHelper) {
                continue;
            }

            // roots.php loads core/permissions.php itself, so either include is fine.
            $loads = str_contains($code, 'core/permissions.php')
                  || str_contains($code, "/roots.php");

            if (!$loads) {
                $offenders[] = str_replace($root, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these endpoints call a permission helper without loading core/permissions.php,\n"
            . "which fatals at runtime rather than refusing access:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testTheSweepActuallyLooksAtEndpoints(): void
    {
        // A scan that silently matched nothing would pass for ever. This codebase has
        // well over a hundred endpoints; if the glob breaks, this fails loudly.
        $this->assertGreaterThan(100, count($this->endpoints()));
    }

    public function testTheTwoLeadershipEndpointsLoadIt(): void
    {
        // The pair that exposed the gap, pinned by name so a future refactor that
        // drops the include is caught even if the sweep above is ever narrowed.
        foreach (['save_leadership_application.php', 'review_leadership_application.php'] as $file) {
            $src = file_get_contents(dirname(__DIR__, 2) . '/actions/' . $file);
            $this->assertStringContainsString("require_once __DIR__ . '/../core/permissions.php';", $src, $file);
        }
    }
}
