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

    // ── PR 4: server-side export of the full filtered set ──────────────────────

    public function test_export_is_server_side_and_streams_full_filtered_set(): void
    {
        $this->assertStringContainsString("\$_GET['export'] === 'csv'", $this->src);
        // Export query reuses the same WHERE (filters + page-view toggle) ...
        $this->assertMatchesRegularExpression('/\$sql\s*=\s*"SELECT.*\$where/s', $this->src);
        // ... but must NOT be limited to a page.
        $this->assertStringContainsString('ORDER BY al.created_at DESC";', $this->src);
        $this->assertStringNotContainsString('LIMIT :lmt OFFSET :ofs";', $this->src); // no paging on the export query
    }

    public function test_export_sends_csv_download_headers_with_bom(): void
    {
        $this->assertStringContainsString('Content-Type: text/csv', $this->src);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="', $this->src);
        $this->assertStringContainsString("\\xEF\\xBB\\xBF", $this->src, 'UTF-8 BOM for Excel');
    }

    public function test_export_is_recorded_in_the_audit_trail(): void
    {
        // Exporting the audit log is itself an audited action.
        $this->assertStringContainsString("logActivity('Exported', 'Activity Logs'", $this->src);
    }

    public function test_frontend_no_longer_scrapes_the_dom(): void
    {
        // Old behaviour exported only the 25 visible rows via DOM scraping — gone.
        $this->assertStringNotContainsString('exportTableToCSV', $this->src);
        $this->assertStringContainsString("?export=csv&' + params", $this->src);
    }
}
