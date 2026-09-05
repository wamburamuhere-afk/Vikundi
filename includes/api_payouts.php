<?php
/**
 * includes/api_payouts.php — the shared rules for the Payouts module.
 *
 * Deliberately requires only config-free files, so it is testable in CI.
 *
 * NO WORKFLOW. Mirrors app/bms/customer/record_payout.php exactly: a payout
 * is written straight to `'paid'` on creation. The `member_payouts.status`
 * column's `pending`/`approved` enum values exist but no code path — web or
 * API — ever writes them. There is no review, no approve, and (unlike every
 * other money-out module built so far) NO fund-balance gate: the web has
 * never checked the group's balance before recording one, so this does not
 * add a check the web has never enforced.
 *
 * PERMISSION KEY: `member_payouts` — a NEW catalog key
 * (database/add_member_payouts_permission.php), mirroring
 * record_payout.php's own role list exactly: Admin, Chairperson, Secretary.
 * Deliberately NOT Treasurer, unlike every other financial module — a payout
 * is member assistance leadership decides on, not a treasury operation.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';

if (!function_exists('vk_api_payouts_amount')) {
    /** Validate a submitted payout amount, returning it rounded. */
    function vk_api_payouts_amount($raw): float
    {
        $clean = str_replace([',', ' '], '', (string) $raw);
        if ($clean === '' || !is_numeric($clean)) {
            vk_api_error(422, 'invalid_amount', 'amount is required and must be a number.');
        }
        $amount = round((float) $clean, 2);
        if ($amount <= 0) {
            vk_api_error(422, 'invalid_amount', 'amount must be greater than zero.');
        }
        if ($amount > 9999999999999.99) {
            vk_api_error(422, 'invalid_amount', 'amount is too large.');
        }
        return $amount;
    }
}

if (!function_exists('vk_api_payouts_row')) {
    /** One payout, as the app renders it. */
    function vk_api_payouts_row(array $r): array
    {
        return [
            'id'          => (int) $r['payout_id'],
            'member'      => [
                'id'   => (int) $r['member_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ],
            'amount'      => (float) $r['amount'],
            'description' => trim((string) ($r['description'] ?? '')) ?: null,
            'payout_date' => (string) $r['payout_date'],
            'status'      => (string) ($r['status'] ?? 'paid'),
            'created_at'  => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        ];
    }
}
