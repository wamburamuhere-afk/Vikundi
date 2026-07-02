<?php
// includes/contribution_grid_helpers.php
//
// Pure helpers for the Contribution Analysis Grid — no DB, so they're testable.

if (!function_exists('vk_contribution_cell_status')) {
    /**
     * A cell's payment status against the monthly target:
     *   full    — met (or exceeded) the target
     *   partial — paid something, but below target
     *   none    — nothing paid
     */
    function vk_contribution_cell_status(float $paid, float $target): string {
        if ($paid <= 0) return 'none';
        if ($target <= 0) return 'full';           // no target set -> any payment is "full"
        return $paid >= $target ? 'full' : 'partial';
    }
}

if (!function_exists('vk_grid_block_label')) {
    /**
     * A single caption for the visible block, so the year is shown once instead
     * of on every column. Same-year -> "Mar – Jun 2026"; crossing a year ->
     * "Nov 2026 – Feb 2027".
     *
     * @param array $columns each: ['month_label' => 'Mar', 'year' => '2026', ...]
     */
    function vk_grid_block_label(array $columns): string {
        if (empty($columns)) return '';
        $first = $columns[0];
        $last  = $columns[count($columns) - 1];
        $fm = $first['month_label'] ?? ''; $fy = (string) ($first['year'] ?? '');
        $lm = $last['month_label'] ?? '';  $ly = (string) ($last['year'] ?? '');
        if ($fy === $ly) return "$fm – $lm $ly";
        return "$fm $fy – $lm $ly";
    }
}

if (!function_exists('vk_collection_rate')) {
    /** Whole-number collection rate (collected / expected), 0 when nothing expected. */
    function vk_collection_rate(float $collected, float $expected): int {
        if ($expected <= 0) return 0;
        return (int) round(($collected / $expected) * 100);
    }
}
