<?php
// includes/contribution_statement.php
//
// Shared query-builder for the date-range contribution statement, so the
// printable page and the CSV export always filter identically.

if (!function_exists('vk_statement_filters')) {
    /** Normalise the request into a clean filter set. */
    function vk_statement_filters(array $req): array {
        $validYmd = function ($d) {
            $d = trim((string) $d);
            $dt = \DateTime::createFromFormat('Y-m-d', $d);
            return ($d !== '' && $dt && $dt->format('Y-m-d') === $d) ? $d : '';
        };
        return [
            'from'      => $validYmd($req['from'] ?? ''),
            'to'        => $validYmd($req['to'] ?? ''),
            'member_id' => ctype_digit((string) ($req['member_id'] ?? '')) ? (int) $req['member_id'] : 0,
            'status'    => in_array($req['status'] ?? '', ['pending', 'reviewed', 'approved', 'cancelled'], true) ? $req['status'] : '',
        ];
    }
}

if (!function_exists('vk_statement_where')) {
    /**
     * Build the WHERE clause + bound params for the statement query. Alias `con`
     * for contributions and `c` for customers.
     *
     * @param array $f     from vk_statement_filters()
     * @param array $params (out) bound values
     */
    function vk_statement_where(array $f, array &$params): string {
        $where = ['1=1'];
        $params = [];
        if ($f['from'] !== '')     { $where[] = 'con.contribution_date >= ?'; $params[] = $f['from']; }
        if ($f['to'] !== '')       { $where[] = 'con.contribution_date <= ?'; $params[] = $f['to']; }
        if ($f['member_id'] > 0)   { $where[] = 'con.member_id = ?';           $params[] = $f['member_id']; }
        if ($f['status'] !== '')   { $where[] = 'con.status = ?';              $params[] = $f['status']; }
        return implode(' AND ', $where);
    }
}

if (!function_exists('vk_mkoba_statement_columns')) {
    /** The M-Koba statement's column headers, in their exact order. */
    function vk_mkoba_statement_columns(): array {
        return ['NO', 'TRANS_ID', 'RECEIPT', 'DATE', 'MEMBER NAME',
                'MEMBER ID', 'SOURCE', 'DESTINATION', 'AMOUNT', 'TRANS TYPE'];
    }
}

if (!function_exists('vk_mkoba_statement_row')) {
    /**
     * Map one contribution DB row into M-Koba's statement layout, for a
     * reconciliation export that mirrors an M-Koba extract. Rows imported from an
     * M-Koba upload carry the original values in the mkoba_* columns and match
     * fully; manually-recorded rows fall back to our own fields, leaving the
     * columns we never had (SOURCE / DESTINATION / TRANS_ID) blank — "match most
     * areas, not all". NO is a running counter for this export, matching M-Koba's
     * row-counter semantics (RECEIPT / TRANS_ID are the real reconciliation keys).
     *
     * @param array $r  DB row: mkoba_* + member_name, phone, receipt_number,
     *                  contribution_type, amount, contribution_date
     * @param int   $no 1-based running number for this row in the export
     * @return array    values keyed by vk_mkoba_statement_columns()
     */
    function vk_mkoba_statement_row(array $r, int $no): array {
        $val = static function (string $k) use ($r): string {
            return isset($r[$k]) ? trim((string) $r[$k]) : '';
        };
        $pick = static function (string $mkobaKey, string $ownKey) use ($val): string {
            return $val($mkobaKey) !== '' ? $val($mkobaKey) : $val($ownKey);
        };

        // Date -> dd/mm/yyyy to match M-Koba (contribution_date is a DATE).
        $rawDate = $val('contribution_date');
        $dt = $rawDate !== '' ? date_create($rawDate) : false;
        $date = $dt ? $dt->format('d/m/Y') : $rawDate;

        // Name in CAPS, preferring the original M-Koba name when present.
        $name = mb_strtoupper($pick('mkoba_member_name', 'member_name'), 'UTF-8');

        // Amount like "5,000.00".
        $amount = number_format((float) ($r['amount'] ?? 0), 2, '.', ',');

        // Friendly label for our own contribution types when there is no
        // M-Koba trans type (i.e. the row was recorded by hand, not imported).
        $ownType = strtolower($val('contribution_type'));
        $labels = [
            'entrance' => 'Entrance Fee', 'monthly' => 'Member Contribution',
            'agm' => 'AGM Contribution', 'fine' => 'Fine', 'other' => 'Other',
        ];
        $transType = $val('mkoba_trans_type') !== ''
            ? $val('mkoba_trans_type')
            : ($ownType !== '' ? ($labels[$ownType] ?? ucfirst($ownType)) : '');

        return [
            'NO'          => (string) $no,
            'TRANS_ID'    => $val('mkoba_trans_id'),
            'RECEIPT'     => $pick('mkoba_receipt', 'receipt_number'),
            'DATE'        => $date,
            'MEMBER NAME' => $name,
            // MEMBER ID / SOURCE / DESTINATION are phone / account numbers — drop Excel's ".00".
            'MEMBER ID'   => preg_replace('/\.0+$/', '', $pick('mkoba_member_id_str', 'phone')),
            'SOURCE'      => preg_replace('/\.0+$/', '', $val('mkoba_source')),
            'DESTINATION' => preg_replace('/\.0+$/', '', $val('mkoba_destination')),
            'AMOUNT'      => $amount,
            'TRANS TYPE'  => $transType,
        ];
    }
}
