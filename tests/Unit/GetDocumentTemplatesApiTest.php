<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for api/document/get_document_templates.php
 *
 * Bug fixed: LIMIT and OFFSET were passed via PDO ? placeholders.
 * MySQL rejects string-quoted LIMIT values ('0', '10') — causing
 * "Syntax error near ''0', '10''" in DataTables.
 * Fix: interpolate $length and $start directly (both already intval-sanitised).
 *
 * Also guards: correct require path from api/document/ to roots.php.
 */
class GetDocumentTemplatesApiTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../api/document/get_document_templates.php');
    }

    // ── LIMIT / OFFSET must NOT use PDO binding ───────────────────────────────

    public function test_limit_does_not_use_pdo_placeholder(): void
    {
        $this->assertStringNotContainsString(
            'LIMIT ?, ?',
            $this->src,
            'PDO binds LIMIT as a quoted string which MySQL rejects with SQL syntax error'
        );
    }

    public function test_limit_uses_integer_interpolation(): void
    {
        $this->assertStringContainsString(
            'LIMIT $length OFFSET $start',
            $this->src,
            'LIMIT must use integer-interpolated variables, not PDO placeholders'
        );
    }

    // ── Require path must be two levels up from api/document/ ─────────────────

    public function test_require_path_is_correct_depth(): void
    {
        $this->assertStringContainsString(
            "require_once __DIR__ . '/../../roots.php'",
            $this->src,
            'From api/document/ the correct path to roots.php is ../../roots.php not ../roots.php'
        );
    }

    public function test_wrong_require_path_not_present(): void
    {
        $this->assertStringNotContainsString(
            "require_once __DIR__ . '/../roots.php'",
            $this->src,
            '/../roots.php resolves to api/roots.php which does not exist'
        );
    }

    // ── Response shape matches DataTables server-side expectations ────────────

    public function test_returns_draw_field(): void
    {
        $this->assertStringContainsString("'draw'", $this->src);
    }

    public function test_returns_records_total(): void
    {
        $this->assertStringContainsString("'recordsTotal'", $this->src);
    }

    public function test_returns_records_filtered(): void
    {
        $this->assertStringContainsString("'recordsFiltered'", $this->src);
    }

    public function test_returns_data_array(): void
    {
        $this->assertStringContainsString("'data'", $this->src);
    }

    public function test_returns_stats(): void
    {
        $this->assertStringContainsString("'stats'", $this->src);
    }

    // ── Auth check is present ─────────────────────────────────────────────────

    public function test_authentication_is_checked(): void
    {
        $this->assertStringContainsString(
            'isAuthenticated()',
            $this->src,
            'API must reject unauthenticated requests'
        );
    }
}
