<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nothing private is served over the web (security fix, 24 Oct 2026).
 *
 * The bug: Apache denied /backups/x.sql with a 403, but its error page pointed
 * at a missing /403.php, so the request fell through to index.php, whose router
 * require_once'd any existing non-PHP file named in the URL. A full database
 * dump came back with status 403 and a 1.4 MB body; a PHP payload hidden in an
 * upload would have been executed the same way.
 */
class WebRootSecurityTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/route_guard.php';
        require_once self::$root . '/includes/tenant.php';
    }

    // ── the router never includes a file it should not ─────────────────────────
    #[DataProvider('refusedPaths')]
    public function testTheRouterFallbackRefusesAnythingButAnEndpoint(string $uri): void
    {
        $this->assertNull(vk_route_endpoint_file($uri, self::$root));
    }

    public static function refusedPaths(): array
    {
        return [
            'a backup dump'           => ['backups/.gitkeep'],
            'a schema file'           => ['database/schema_sync.sql'],
            'an htaccess'             => ['backups/.htaccess'],
            'the session log'         => ['sessions.md'],
            'a non-PHP file in api'   => ['api/.htaccess'],
            'climbing out of api'     => ['api/../roots.php'],
            'climbing into includes'  => ['api/../includes/tenant.php'],
            'a PHP file outside api'  => ['includes/tenant.php'],
            'a null byte'             => ["api/get_backup_list.php\0.sql"],
            'empty'                   => [''],
        ];
    }

    public function testTheRouterFallbackStillFindsRealEndpoints(): void
    {
        $real = realpath(self::$root . '/api/get_backup_list.php');
        $this->assertSame($real, vk_route_endpoint_file('api/get_backup_list', self::$root));
        $this->assertSame($real, vk_route_endpoint_file('api/get_backup_list.php', self::$root));
    }

    public function testTheRouterNoLongerIncludesNonPhpFiles(): void
    {
        $src = (string) file_get_contents(self::$root . '/roots.php');
        $this->assertStringNotContainsString("if (\$ext !== 'php' || \$isInternal) {", $src);
        $this->assertStringContainsString('$f = vk_route_endpoint_file($clean_uri, ROOT_DIR);', $src);
    }

    // ── Apache: only what a browser needs ──────────────────────────────────────
    public function testDeniedRequestsEndInApacheWithASmallBody(): void
    {
        $ht = (string) file_get_contents(self::$root . '/.htaccess');
        $this->assertStringContainsString('ErrorDocument 403 "Forbidden"', $ht);
        $this->assertStringContainsString('ErrorDocument 404 "Not Found"', $ht);
        $this->assertStringNotContainsString('ErrorDocument 403 /403.php', $ht);
    }

    public function testPrivateFoldersDotFilesAndNonAssetsAreRefused(): void
    {
        $ht = (string) file_get_contents(self::$root . '/.htaccess');
        $this->assertStringContainsString('RewriteRule (^|/)\.(?!well-known/) - [R=404,L]', $ht);
        foreach (['backups', 'database', 'bin', 'deploy', 'docs', 'tests', 'vendor', 'includes', 'core', 'uploads', 'private'] as $dir) {
            $this->assertMatchesRegularExpression('#RewriteRule \^\([^)]*\b' . $dir . '\b[^)]*\)\(/\|\$\) - \[R=404,L\]#', $ht, $dir);
        }
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !\.(css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|otf|php)$ [NC]', $ht);
        $this->assertMatchesRegularExpression('/<FilesMatch "\(\?i\)\(\\\\\.\(sql\|gz\|[^"]*\bkey\b[^"]*\bmd\b[^"]*\)\|~\)\$">\s*Require all denied/', $ht);
        // The blocks come before any rule that could hand the request to PHP.
        $this->assertLessThan(strpos($ht, 'RewriteRule ^ index.php [L,QSA]'), strpos($ht, '(^|/)\.(?!well-known/)'));
    }

    public function testBackupsAndDatabaseFoldersDenyThemselves(): void
    {
        foreach (['backups', 'database'] as $dir) {
            $this->assertStringContainsString('Require all denied', (string) file_get_contents(self::$root . "/$dir/.htaccess"), $dir);
        }
    }

    public function testTheUnusedSqlDumpsAreGone(): void
    {
        foreach (['bms (2).sql', 'roles (1).sql', 'permissions (1).sql', 'role_permissions (2).sql', 'email_templates.sql',
                  'email_logs.sql', 'v_schema_updates.sql', 'add_invoice_permission.sql', 'add_bank_reconciliation_permission.sql'] as $f) {
            $this->assertFileDoesNotExist(self::$root . "/database/$f");
        }
        $this->assertFileExists(self::$root . '/database/schema_sync.sql', 'still read by the migrations');
    }

    // ── a single site's backups and AI key live outside the web root ───────────
    public function testASingleSitesBackupsAreOutsideTheCodeFolder(): void
    {
        $this->assertNull(vk_tenant_current());
        $backups = vk_storage_path('backups');
        $this->assertStringStartsNotWith(self::$root . '/', $backups);
        $this->assertSame(vk_private_dir() . '/backups', $backups);
        $this->assertSame(vk_private_dir() . '/backups/x.sql', vk_storage_path('backups/x.sql'));
        // Everything else a single site stores is where it always was.
        $this->assertSame(self::$root . '/uploads/avatars', vk_storage_path('uploads/avatars'));
    }

    public function testThePrivateFolderDefaultsBesideTheCodeFolderAndCanBeSet(): void
    {
        putenv('VIKUNDI_PRIVATE_DIR');
        $this->assertSame(dirname(self::$root) . '/private', vk_private_dir());
        putenv('VIKUNDI_PRIVATE_DIR=/srv/somewhere/private/');
        $this->assertSame('/srv/somewhere/private', vk_private_dir());
        putenv('VIKUNDI_PRIVATE_DIR');
    }

    public function testTheAiKeyMovesToThePrivateFolderWithoutLosingAnOldOne(): void
    {
        $src = (string) file_get_contents(self::$root . '/includes/tenant.php');
        $this->assertStringContainsString("\$new = vk_private_dir() . '/ai_secret.key';", $src);
        $this->assertStringContainsString('return (!is_file($new) && is_file($old)) ? $old : $new;', $src);
        $crypto = (string) file_get_contents(self::$root . '/core/ai_crypto.php');
        $this->assertStringContainsString('@mkdir(dirname($keyFile), 0700, true);', $crypto);
    }

    public function testBackupFoldersAreOwnerOnlyAndPathChecksNeedTheSeparator(): void
    {
        $this->assertStringContainsString('mkdir($backup_dir, 0700, true);', (string) file_get_contents(self::$root . '/api/create_backup.php'));
        $this->assertStringContainsString('mkdir($backupsDir, 0700, true);', (string) file_get_contents(self::$root . '/api/backup_actions.php'));
        foreach (['api/download_backup.php', 'api/v1/backup-download.php', 'api/delete_backup.php'] as $f) {
            $this->assertStringContainsString('DIRECTORY_SEPARATOR) !== 0', (string) file_get_contents(self::$root . "/$f"), $f);
        }
    }

    public function testDumpsAndPrivateDataStayOutOfGit(): void
    {
        $gi = (string) file_get_contents(self::$root . '/.gitignore');
        foreach (['/backups/*', '/private/', '*.sql.gz', '*.import.csv', '.claude/settings.local.json'] as $rule) {
            $this->assertStringContainsString($rule, $gi, $rule);
        }
    }
}
