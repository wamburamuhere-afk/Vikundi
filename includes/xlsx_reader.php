<?php
/**
 * includes/xlsx_reader.php
 * ------------------------
 * A minimal, dependency-free reader for .xlsx files (ZipArchive + SimpleXML — no
 * PhpSpreadsheet, nothing to `composer install` on the server). Reads the first
 * worksheet into positional rows, exactly the shape fgetcsv() returns, so the
 * importers can treat an uploaded .xlsx just like a CSV.
 *
 * Why it exists: when an M-Koba statement is saved to CSV through Excel, long
 * numeric TRANS_IDs are written as "3.83E+15" and the digits are lost. In an
 * .xlsx the cell keeps its FULL value in the XML (`<v>`), so importing the
 * original .xlsx preserves the real Trans ID. (If the .xlsx is itself a re-save
 * of an already-corrupted CSV, the damage is baked in — nothing recovers that.)
 */

if (!function_exists('xlsx_read_rows')) {

    /** Spreadsheet column letters (A, B, … AA) -> 0-based index. */
    function xlsx_col_index(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) return 0;
        $letters = $m[1];
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /** Load a zip entry's XML with the default namespace neutralised for plain SimpleXML access. */
    function xlsx_load_xml(ZipArchive $zip, string $entry): ?SimpleXMLElement
    {
        $i = $zip->locateName($entry, ZipArchive::FL_NOCASE);
        if ($i === false) return null;
        $data = $zip->getFromIndex($i);
        if ($data === false || $data === '') return null;
        // Rename the (prefix-less) default namespace so ->row / ->c / ->v resolve
        // without namespace gymnastics. Prefixed namespaces (xmlns:r=) are untouched.
        $data = preg_replace('/\sxmlns="[^"]*"/', '', $data, 1);
        return @simplexml_load_string($data) ?: null;
    }

    /**
     * Read the first worksheet of an .xlsx into rows (array of positional arrays).
     * Row 0 is the header row — same shape as reading a CSV with fgetcsv().
     *
     * @return array<int, array<int, string>>
     * @throws RuntimeException if the file is not a readable .xlsx (zip)
     */
    function xlsx_read_rows(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to read .xlsx files.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the .xlsx file (not a valid spreadsheet).');
        }

        // Shared-strings table: <sst><si><t>text</t></si>… or runs <si><r><t>…</t></r>…</si>
        $shared = [];
        $ss = xlsx_load_xml($zip, 'xl/sharedStrings.xml');
        if ($ss !== null) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $run) $txt .= (string) $run->t;
                    $shared[] = $txt;
                }
            }
        }

        // Resolve the first worksheet path (fall back to the conventional sheet1.xml).
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb   = xlsx_load_xml($zip, 'xl/workbook.xml');
        $rels = xlsx_load_xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($wb !== null && isset($wb->sheets->sheet[0]) && $rels !== null) {
            $rid = (string) $wb->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    break;
                }
            }
        }

        $sheet = xlsx_load_xml($zip, $sheetPath);
        $zip->close();
        if ($sheet === null || !isset($sheet->sheetData)) return [];

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            $auto = 0;
            foreach ($row->c as $c) {
                $ref  = (string) $c['r'];
                $col  = $ref !== '' ? xlsx_col_index($ref) : $auto;
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($c->is->t) ? (string) $c->is->t : '';
                } else {
                    // number / formula-string / bool -> the FULL stored value
                    $val = isset($c->v) ? (string) $c->v : '';
                }
                $cells[$col] = $val;
                if ($col > $maxCol) $maxCol = $col;
                $auto = $col + 1;
            }
            $dense = [];
            for ($i = 0; $i <= $maxCol; $i++) $dense[] = $cells[$i] ?? '';
            $rows[] = $dense;
        }
        return $rows;
    }
}
