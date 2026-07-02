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
