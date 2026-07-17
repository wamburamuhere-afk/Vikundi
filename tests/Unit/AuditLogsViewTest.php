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

    // ── PR 5: action filter + summary strip ────────────────────────────────────

    public function test_action_filter_is_parsed_and_applied(): void
    {
        $this->assertStringContainsString("\$action_filter  = trim(\$_GET['action'] ?? '')", $this->src);
        $this->assertStringContainsString('al.action = :act', $this->src);
        $this->assertStringContainsString('name="action"', $this->src);
    }

    public function test_viewed_action_filter_forces_page_views_on(): void
    {
        // Filtering to the Viewed action must override the default hide, or it
        // would return an empty page.
        $this->assertStringContainsString("(\$action_filter === 'Viewed')", $this->src);
    }

    public function test_summary_counts_by_action_ignoring_the_drilldown(): void
    {
        // Summary snapshots the filter conditions BEFORE the action filter is added,
        // so the breakdown is stable when you drill into one action.
        $this->assertStringContainsString('$summary_conditions = $conditions;', $this->src);
        $this->assertStringContainsString('GROUP BY al.action', $this->src);
    }

    public function test_summary_strip_highlights_failed_logins(): void
    {
        $this->assertStringContainsString('function renderSummaryStrip', $this->src);
        $this->assertStringContainsString("'Login Failed'", $this->src);
        // The failed-login card is alarmed (filled red) only when the count is > 0.
        $this->assertStringContainsString("\$c['key'] === 'Login Failed' && \$val > 0", $this->src);
    }

    public function test_summary_updates_over_ajax(): void
    {
        // Filter changes reload rows over AJAX, so the summary must travel with them.
        $this->assertStringContainsString("'summary' => renderSummaryStrip(", $this->src);
        $this->assertStringContainsString("$('#auditSummary').html(res.summary)", $this->src);
    }
}
