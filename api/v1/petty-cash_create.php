<?php
/**
 * POST /api/v1/petty-cash — record a petty-cash voucher.
 *
 * Reached through petty-cash.php, which has already authenticated. Also
 * usable directly. Mirrors actions/save_petty_cash.php's create path (this
 * endpoint does not handle the edit path — that is PUT /petty-cash/{id}).
 *
 * `create` on `petty_cash` — leadership only.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

vk_api_require_permission($auth, 'create', 'petty_cash');

$body = vk_api_body();

$payeeName = trim((string) ($body['payee_name'] ?? ''));
if ($payeeName === '') {
    vk_api_error(422, 'payee_required', 'payee_name is required.');
}

$description = trim((string) ($body['description'] ?? ''));
if ($description === '') {
    vk_api_error(422, 'description_required', 'description is required.');
}

$amount = vk_api_petty_amount($body['amount'] ?? null);

$category = trim((string) ($body['category'] ?? '')) ?: 'Other';

$date = trim((string) ($body['transaction_date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
} else {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        vk_api_error(422, 'invalid_date', 'transaction_date must be in YYYY-MM-DD format.');
    }
}

// Same shape as the web's generator (actions/save_petty_cash.php): a
// human-readable, not-guaranteed-unique reference. The column has a UNIQUE
// index, so a rare collision fails the insert rather than silently
// duplicating a voucher number.
$voucherNo = 'PCV-' . date('ym') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

$st = $pdo->prepare(
    "INSERT INTO petty_cash_vouchers
        (voucher_no, transaction_date, payee_name, amount, category, description, prepared_by, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$st->execute([$voucherNo, $date, $payeeName, $amount, $category, $description, (int) $auth['user_id']]);
$newId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = (int) $auth['user_id']; // logActivity() reads the session
logActivity('Created', 'Petty Cash', "Created petty cash voucher $voucherNo for $payeeName", $voucherNo);

$row = vk_api_petty_row(vk_api_petty_load($pdo, $newId));
$row['actions'] = vk_api_petty_actions($auth, $row['status']);

vk_api_ok([
    'voucher' => $row,
    'message' => "Voucher $voucherNo saved and awaiting review.",
], 201);
