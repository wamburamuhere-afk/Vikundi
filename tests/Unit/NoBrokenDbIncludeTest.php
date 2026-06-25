<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards audit H4: includes/db.php does not exist, so no file may require it.
 * Three dead handlers (register.php, register_customer.php, upload_attachments.php)
 * required it and would fatal if reached; they were removed. The live DB include
 * is includes/config.php.
 */
class NoBrokenDbIncludeTest extends TestCase
{
    public function testIncludesDbPhpDoesNotExist(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/includes/db.php');
    }

    public function testNoPhpFileRequiresMissingDbInclude(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            $p = $f->getPathname();
            if (!str_ends_with($p, '.php')) continue;
            if (strpos($p, '/vendor/') !== false || strpos($p, '/tests/') !== false) continue;
            if (preg_match('#(?:require|include)(?:_once)?\s*\(?\s*[\'"][^\'"]*includes/db\.php#', file_get_contents($p))) {
                $offenders[] = str_replace($root . '/', '', $p);
            }
        }
        $this->assertSame([], $offenders, 'No file may require the non-existent includes/db.php (use includes/config.php)');
    }
}
