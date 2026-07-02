<?php
// includes/fine_helpers.php
//
// Pure helpers for the Fines pages — no DB, so they can be unit tested.

if (!function_exists('vk_fine_statuses')) {
    function vk_fine_statuses(): array { return ['pending', 'paid', 'waived']; }
}

if (!function_exists('vk_normalize_fine_status')) {
    /** Return a valid fine status, defaulting to 'pending'. */
    function vk_normalize_fine_status($s): string {
        $s = strtolower(trim((string) $s));
        return in_array($s, vk_fine_statuses(), true) ? $s : 'pending';
    }
}

if (!function_exists('vk_fine_status_badge')) {
    /** Bootstrap badge colour for a fine status. */
    function vk_fine_status_badge(string $s): string {
        switch (strtolower($s)) {
            case 'paid':   return 'success';
            case 'waived': return 'secondary';
            default:       return 'warning'; // pending
        }
    }
}

if (!function_exists('vk_fine_summary')) {
    /**
     * Summarise fine rows into totals by status. Only 'pending' is money still
     * owed; 'paid' collected; 'waived' forgiven.
     *
     * @param array $rows each row has 'amount' and 'status'
     */
    function vk_fine_summary(array $rows): array {
        $out = ['pending' => 0.0, 'paid' => 0.0, 'waived' => 0.0, 'count' => count($rows)];
        foreach ($rows as $r) {
            $st = vk_normalize_fine_status($r['status'] ?? 'pending');
            $out[$st] += (float) ($r['amount'] ?? 0);
        }
        return $out;
    }
}
