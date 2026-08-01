<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Whole-tree invariant for XSS-001 / XSS-005.
 *
 * The `api/` endpoints return raw database rows — verified across eight feeds, none
 * of them escapes — so every value a DataTables `render` callback interpolates into
 * markup has to be escaped client-side. Before this batch, of 26 server-side tables
 * five escaped correctly and the rest did not, because the codebase contained five
 * separate private copies of an escaping helper and no shared one.
 *
 * This test is closed over the property it protects: add a `render` callback that
 * drops a value straight into HTML and it goes red, on any page, forever. That is
 * the same shape as NoBrokenDbIncludeTest and EndpointAuthSweepTest, and the
 * opposite of the allowlist tests QUAL-002 describes — those can only notice a
 * guard being removed from a file someone already fixed.
 *
 * IF THIS FAILS, wrap the interpolation in vkEsc() (header.php). Do not add the
 * file to the exemption list.
 */
class ClientSideEscapingTest extends TestCase
{
    /**
     * Inherited BMS modules. ARCH-007 established 31 of these 33 files are
     * unroutable — handleRoute() refuses to serve a .php outside actions|ajax|api —
     * and analysis/09-db-verification.md confirmed their tables hold no rows. They
     * are excluded so this test governs live code, not a museum.
     */
    private const DEAD_DIRS = ['pos', 'product', 'stock', 'loans', 'grn', 'Suppliers', 'purchase', 'sales', 'invoice'];

    /** Any of these in a render body means the value went through an escaper. */
    private const ESCAPERS = [
        'vkEsc', 'escHtml', 'safeOutput', 'txnEsc', 'esc(', 'escapeHtml',
        'DOMPurify', 'encodeURIComponent', '.text(',
    ];

    /**
     * Interpolations that cannot carry markup: loop counters, formatters that emit
     * numbers, and locally computed badge/label class names.
     */
    private const NON_DATA = '/^\s*(meta\.row|m\.row|r\.id|row\.id|d\.id|new Date|fmtDate|formatCurrency|money|Number|parseInt|parseFloat|Math\.|isSw\s*\?|JSON\.stringify)/';

    private function livePhpFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $out = [];
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            $p = $f->getPathname();
            if (!str_ends_with($p, '.php')) {
                continue;
            }
            foreach (self::DEAD_DIRS as $d) {
                if (str_contains($p, '/app/bms/' . $d . '/')) {
                    continue 2;
                }
            }
            $out[] = $p;
        }
        sort($out);
        return $out;
    }

    public function testNoDataTablesRenderInterpolatesAValueIntoHtmlUnescaped(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        // render: d => `...` — the arrow + template-literal form. This is the shape
        // that carried every finding; an earlier scan that only matched
        // `render: function(){...}` reported zero and missed all of them.
        $pat = '/render\s*:\s*(?:\([^)]*\)|\w+)\s*=>\s*`([^`]*)`/';

        foreach ($this->livePhpFiles() as $abs) {
            $src = (string) file_get_contents($abs);
            if (!preg_match_all($pat, $src, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            foreach ($ms as $m) {
                $body = $m[1][0];
                if (!str_contains($body, '${') || !str_contains($body, '<')) {
                    continue; // no interpolation, or not building markup
                }
                foreach (self::ESCAPERS as $e) {
                    if (str_contains($body, $e)) {
                        continue 2;
                    }
                }
                // Every interpolation in the body must be provably non-data.
                preg_match_all('/\$\{([^}]*)\}/', $body, $interps);
                foreach ($interps[1] as $expr) {
                    if (!preg_match(self::NON_DATA, $expr)) {
                        $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                        $offenders[] = str_replace($root . '/', '', $abs) . ':' . $line;
                        continue 2;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These DataTables render callbacks interpolate a value into HTML with no escaping.\n"
            . "api/ feeds return raw database rows, so the escaping has to happen here.\n"
            . "Wrap the value in vkEsc() — defined in header.php.\n"
        );
    }

    /** The shared helper must exist and be loaded on every page. */
    public function testSharedEscapeHelperIsDefinedInTheGlobalHeader(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/header.php');
        $this->assertStringContainsString('window.vkEsc', $src, 'vkEsc must be defined in header.php so every page has it');
        foreach (['/&/g', '/</g', '/>/g', '/"/g', "/'/g"] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $src,
                "vkEsc must escape $needle — it is used in attribute contexts as well as element content"
            );
        }
    }

    /** vkEsc must be defined before jQuery-dependent page scripts, i.e. in <head>. */
    public function testHelperIsDefinedBeforeDataTablesLoads(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/header.php');
        $helper = strpos($src, 'window.vkEsc');
        $dt     = strpos($src, 'dataTables.min.js');
        $this->assertNotFalse($helper);
        $this->assertNotFalse($dt);
        $this->assertLessThan($dt, $helper, 'vkEsc must be defined before DataTables so page render callbacks can use it');
    }

    /** The sweep is worthless if it walks nothing. */
    public function testSweepCoversTheLivePageSurface(): void
    {
        $this->assertGreaterThan(
            60,
            count($this->livePhpFiles()),
            'Expected ~100 live app/ pages; the sweep appears to be walking the wrong tree.'
        );
    }
}
