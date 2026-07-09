<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Transactions table = a server-side (paginated) DataTable over the growing
 * contributions table. Source-guards pin the endpoint (gated, page-size clamp,
 * whitelisted sort, bounded counts), the page wiring (server-side DataTable, no
 * preloaded rows), and the supporting index migration.
 */
class TransactionsTableTest extends TestCase
{
    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    public function testEndpointIsGatedAndSafe(): void
    {
        $e = $this->src('api/get_transactions.php');
        $this->assertStringContainsString('require_auth.php', $e);                 // must be logged in
        $this->assertStringContainsString("canView('manage_contributions')", $e);  // leadership only
        $this->assertStringContainsString('http_response_code(403)', $e);
        // page size is clamped (never let the client request the whole table)
        $this->assertStringContainsString('$length > 200', $e);
        // sort column comes from a whitelist, never interpolated from the request
        $this->assertStringContainsString('$orderCols', $e);
        $this->assertStringContainsString('recordsFiltered', $e);
    }

    public function testCountsAreBoundedByTheDateWindow(): void
    {
        // recordsTotal is counted within the base (date) scope, so we never
        // COUNT(*) the whole unbounded table on every request.
        $e = $this->src('api/get_transactions.php');
        $this->assertStringContainsString('$baseWhere', $e);
        $this->assertStringContainsString('FROM contributions con $baseWhere', $e);
    }

    public function testPageIsServerSideDataTableWithNoPreload(): void
    {
        $p = $this->src('app/bms/customer/transactions.php');
        $this->assertStringContainsString('id="transactionsTable"', $p);
        $this->assertStringContainsString('serverSide: true', $p);
        $this->assertStringContainsString("getUrl(\"api/get_transactions\")", $p);
        // the old "load the latest 15 server-side" preload is gone
        $this->assertStringNotContainsString('LIMIT 15', $p);
        $this->assertStringContainsString('$default_from', $p); // bounded default window
    }

    public function testIndexMigrationRegisteredAndReflected(): void
    {
        $this->assertStringContainsString('add_contributions_indexes.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/add_contributions_indexes.php');
        $this->assertStringContainsString('ADD INDEX', $mig);
        $this->assertStringContainsString('idx_contrib_status_date', $mig);
        // idempotency guard: only add an index that isn't already present
        $this->assertStringContainsString('information_schema.STATISTICS', $mig);
        // reflected in the committed schema
        $this->assertStringContainsString('idx_contrib_status_date', $this->src('database/schema_sync.sql'));
    }
}
