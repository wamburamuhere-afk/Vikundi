<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 3 of the audit-logs work: the viewer was dominated by page-view
 * ('Viewed'/'Navigation') noise that buried real events. The default view now
 * hides page views (opt-in toggle), and timestamps show seconds.
 */
class AuditLogsViewTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/audit_logs.php');
    }

    public function test_page_views_are_hidden_by_default(): void
    {
        // The default query excludes 'Viewed' rows unless the user opts in.
        $this->assertStringContainsString("if (!\$show_views)", $this->src);
        $this->assertStringContainsString("al.action <> 'Viewed'", $this->src);
    }

    public function test_include_page_views_is_opt_in(): void
    {
        $this->assertStringContainsString("\$_GET['show_views']", $this->src);
        $this->assertStringContainsString('name="show_views"', $this->src);
    }

    public function test_navigation_filter_auto_includes_views(): void
    {
        // Selecting the Navigation module explicitly must not return an empty page.
        $this->assertStringContainsString("\$type_filter === 'Navigation'", $this->src);
    }

    public function test_timestamps_include_seconds(): void
    {
        $this->assertStringContainsString("date('d/m/y H:i:s'", $this->src);
        $this->assertStringNotContainsString("date('d/m/y H:i'", $this->src);
    }
}
