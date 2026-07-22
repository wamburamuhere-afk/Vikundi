<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The boss wants the Transactions page to mirror the M-Koba statement column for
 * column (S/No · Trans ID · Receipt · Date · Member · Member ID · Source ·
 * Destination · Amount · Trans Type), and the M-Koba importer on that page to
 * both store those fields and build the reconciliation mirror. These lock the
 * backend query, the frontend columns, and the shared mirror wiring.
 */
class TransactionsMkobaColumnsTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testApiReturnsEveryMkobaField(): void
    {
        $api = $this->src('api/get_transactions.php');
        foreach (['con.mkoba_sno', 'con.mkoba_trans_id', 'con.mkoba_member_id_str',
                  'con.mkoba_source', 'con.mkoba_destination', 'con.mkoba_trans_type'] as $col) {
            $this->assertStringContainsString($col, $api, "API must return $col");
        }
        // empty middle name must not create a double space in the member name
        $this->assertStringContainsString("NULLIF(c.middle_name, '')", $api);
    }

    public function testPageHeadersMirrorTheStatement(): void
    {
        $page = $this->src('app/bms/customer/transactions.php');
        foreach (['S/No', 'Trans ID', 'Receipt', 'Member ID', 'Source', 'Destination', 'Trans Type'] as $header) {
            $this->assertStringContainsString($header, $page, "Missing column header: $header");
        }
    }

    public function testPageDataTableMapsTheMkobaColumns(): void
    {
        $page = $this->src('app/bms/customer/transactions.php');
        foreach (['mkoba_sno', 'mkoba_trans_id', 'mkoba_member_id_str', 'mkoba_source',
                  'mkoba_destination', 'mkoba_trans_type'] as $field) {
            $this->assertStringContainsString("data: '$field'", $page, "DataTable must map $field");
        }
        // Date is now column index 3 — default sort follows it.
        $this->assertStringContainsString("order: [[3, 'desc']]", $page);
    }

    public function testSharedMirrorHelperExists(): void
    {
        $mirror = $this->src('includes/mkoba_mirror.php');
        $this->assertStringContainsString('function mkoba_populate_mirror', $mirror);
        $this->assertStringContainsString('INSERT INTO mkoba_statement_rows', $mirror);
    }

    public function testCliUsesTheSharedMirrorHelper(): void
    {
        $cli = $this->src('database/import_mkoba_oneoff.php');
        $this->assertStringContainsString("includes/mkoba_mirror.php", $cli);
        // no longer defines its own copy
        $this->assertStringNotContainsString('function mkoba_populate_mirror', $cli);
    }

    public function testWebImporterBuildsTheMirror(): void
    {
        $imp = $this->src('actions/import_contributions.php');
        $this->assertStringContainsString('includes/mkoba_mirror.php', $imp);
        $this->assertStringContainsString('mkoba_populate_mirror($pdo, $mkobaRows', $imp);
        // collected only for M-Koba uploads
        $this->assertStringContainsString('if ($isMkoba) $mkobaRows[] = $assoc;', $imp);
    }
}
