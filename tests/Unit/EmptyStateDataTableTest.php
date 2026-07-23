<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: DataTables throws a blocking "Incorrect column count" alert (tn/18)
 * when a DOM-sourced table's <tbody> contains a `<td colspan>` — which happens with
 * the old hand-rolled "no records" empty rows, but ONLY when the table is empty
 * (e.g. a fresh production database). The fix removes those manual empty rows and
 * lets DataTables render its own language.emptyTable message instead.
 *
 * These three pages each initialise a client-side DataTable over a static <tbody>,
 * so each must (a) no longer ship a colspan empty row, and (b) define emptyTable.
 */
class EmptyStateDataTableTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testEachFixedPageDefinesEmptyTableMessage(): void
    {
        foreach ([
            'app/constant/reports/vicoba_reports.php',
            'app/constant/reports/death_analysis.php',
            'app/bms/customer/dormant_members.php',
        ] as $file) {
            $this->assertStringContainsString(
                'emptyTable',
                $this->src($file),
                "$file must define a DataTables emptyTable message now that the manual empty row is gone"
            );
        }
    }

    /**
     * No DataTables-managed <tbody> across these pages may contain a colspan cell —
     * that is the exact trigger for the tn/18 crash.
     */
    public function testNoColspanInsideAnyDataTableBody(): void
    {
        foreach ([
            'app/constant/reports/vicoba_reports.php' => 'expensesReportDetailTable',
            'app/constant/reports/death_analysis.php' => 'deathSustainabilityTable',
            'app/bms/customer/dormant_members.php'    => 'dormantTable',
        ] as $file => $tableId) {
            $body = $this->tbodyOf($this->src($file), $tableId);
            $this->assertDoesNotMatchRegularExpression(
                '/<td[^>]*colspan\s*=/i',
                $body,
                "$tableId ($file) still has a colspan cell in its <tbody> — this crashes DataTables when the table is empty"
            );
        }
    }

    /**
     * Return the first <tbody>…</tbody> that follows the given table id, so the
     * assertions look only at the DataTable's body (not its <tfoot>, where a
     * colspan total row is legitimate).
     */
    private function tbodyOf(string $src, string $tableId): string
    {
        $idPos = strpos($src, $tableId);
        if ($idPos === false) {
            return '';
        }
        $open = strpos($src, '<tbody', $idPos);
        $close = $open === false ? false : strpos($src, '</tbody>', $open);
        if ($open === false || $close === false) {
            return '';
        }
        return substr($src, $open, $close - $open);
    }
}
