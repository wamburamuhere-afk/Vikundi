<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A dependency-free .xlsx reader lets the importers take the ORIGINAL M-Koba
 * .xlsx, where a numeric TRANS_ID keeps its full value instead of Excel's CSV
 * "3.83E+15". These tests build a real .xlsx and read it back, and lock the
 * wiring into the web + CLI importers and the on-page guidance.
 */
class XlsxImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/xlsx_reader.php';
    }

    /** Write a minimal valid .xlsx with the given rows (first row = headers). */
    private function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mkx') . '.xlsx';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) {
            $sheet .= '<row r="' . ($ri + 1) . '">';
            foreach ($row as $ci => $cell) {
                $ref = chr(65 + $ci) . ($ri + 1);
                if (is_int($cell) || (is_string($cell) && ctype_digit($cell) && strlen($cell) > 12)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>'; // numeric cell
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $cell) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $z->close();
        return $path;
    }

    public function testReadsXlsxAndPreservesLongNumericTransId(): void
    {
        $path = $this->makeXlsx([
            ['NO', 'TRANS_ID', 'RECEIPT', 'AMOUNT'],
            [1, '3798612345678901', 'DBS9N7LOXOR', 5000],
        ]);
        $rows = xlsx_read_rows($path);
        @unlink($path);

        $this->assertSame(['NO', 'TRANS_ID', 'RECEIPT', 'AMOUNT'], $rows[0]);
        $this->assertSame('3798612345678901', $rows[1][1], 'The full 16-digit Trans ID must survive (not 3.79E+15).');
        $this->assertSame('DBS9N7LOXOR', $rows[1][2]);
        $this->assertSame('5000', $rows[1][3]);
    }

    public function testColumnLetterToIndex(): void
    {
        $this->assertSame(0, xlsx_col_index('A1'));
        $this->assertSame(1, xlsx_col_index('B2'));
        $this->assertSame(25, xlsx_col_index('Z9'));
        $this->assertSame(26, xlsx_col_index('AA1'));
    }

    public function testWebImporterReadsXlsxAndWarnsOnCorruption(): void
    {
        $imp = file_get_contents(__DIR__ . '/../../actions/import_contributions.php');
        $this->assertStringContainsString('includes/xlsx_reader.php', $imp);
        $this->assertStringContainsString('xlsx_read_rows($file)', $imp);
        $this->assertStringContainsString('$sciTransIds', $imp);          // corruption warning
        $this->assertStringContainsString("preg_match('/^\\d+(\\.\\d+)?[eE]", $imp);
    }

    public function testCliImporterReadsXlsx(): void
    {
        $cli = file_get_contents(__DIR__ . '/../../database/import_mkoba_oneoff.php');
        $this->assertStringContainsString('includes/xlsx_reader.php', $cli);
        $this->assertStringContainsString('xlsx_read_rows($csvPath)', $cli);
    }

    public function testImportModalHasGuidance(): void
    {
        $page = file_get_contents(__DIR__ . '/../../app/bms/customer/transactions.php');
        $this->assertStringContainsString('TRANS_ID column as', $page);
        $this->assertStringContainsString('.xlsx</b>', $page);
    }
}
