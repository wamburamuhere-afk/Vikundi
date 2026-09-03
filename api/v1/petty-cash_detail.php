<?php
/**
 * GET /api/v1/petty-cash/{id} — one voucher, with its approval trail.
 * PUT /api/v1/petty-cash/{id} — edit (only while still 'pending').
 *
 * Mirrors app/constant/accounts/petty_cash_view.php and the edit path of
 * actions/save_petty_cash.php exactly: only a pending voucher may be edited.
 * Four trail stages (created, reviewed, approved, paid) — 'paid' never gets
 * an e-signature, read straight from paid_by/paid_at.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_petty_cash.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    vk_api_require_permission($auth, 'edit', 'petty_cash');

    $row = vk_api_petty_load($pdo, $id);
    if ($row['status'] !== 'pending') {
        vk_api_error(
            409,
            'not_editable',
            "A voucher that is {$row['status']} can no longer be edited."
        );
    }

    $body = vk_api_body();
    $sets = [];
    $vals = [];

    if (array_key_exists('payee_name', $body)) {
        $payee = trim((string) $body['payee_name']);
        if ($payee === '') {
            vk_api_error(422, 'payee_required', 'payee_name cannot be empty.');
        }
        $sets[] = 'payee_name = ?';
        $vals[] = $payee;
    }
    if (array_key_exists('description', $body)) {
        $description = trim((string) $body['description']);
        if ($description === '') {
            vk_api_error(422, 'description_required', 'description cannot be empty.');
        }
        $sets[] = 'description = ?';
        $vals[] = $description;
    }
    if (array_key_exists('amount', $body)) {
        $sets[] = 'amount = ?';
        $vals[] = vk_api_petty_amount($body['amount']);
    }
    if (array_key_exists('category', $body)) {
        $sets[] = 'category = ?';
        $vals[] = trim((string) $body['category']) ?: 'Other';
    }
    if (array_key_exists('transaction_date', $body)) {
        $date = trim((string) $body['transaction_date']);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            vk_api_error(422, 'invalid_date', 'transaction_date must be in YYYY-MM-DD format.');
        }
        $sets[] = 'transaction_date = ?';
        $vals[] = $date;
    }

    if (!$sets) {
        vk_api_error(422, 'no_fields', 'Nothing to update — send at least one editable field.');
    }

    $vals[] = $id;
    $pdo->prepare('UPDATE petty_cash_vouchers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logUpdate('Petty Cash', 'Voucher ' . $row['voucher_no'] . ' edited', 'PCV#' . $row['voucher_no'], (int) $auth['user_id']);

    $updated = vk_api_petty_row(vk_api_petty_load($pdo, $id));
    $updated['actions'] = vk_api_petty_actions($auth, $updated['status']);

    vk_api_ok(['voucher' => $updated, 'message' => 'Voucher updated.']);
}

vk_api_require_permission($auth, 'view', 'petty_cash');

$loaded = vk_api_petty_load($pdo, $id);
$voucher = vk_api_petty_row($loaded);
$voucher['actions'] = vk_api_petty_actions($auth, $voucher['status']);

$signatures = getWorkflowSignatures($pdo, 'petty_cash', $id);
$trail = [];
foreach (['created', 'reviewed', 'approved'] as $stage) {
    $s = $signatures[$stage] ?? null;
    $trail[$stage] = [
        'by'        => $s['user_name'] ?? '',
        'role'      => $s['user_role'] ?? '',
        'at'        => !empty($s['signed_at']) ? date(DATE_ATOM, strtotime((string) $s['signed_at'])) : null,
        'signed'    => !empty($s['sig_path']),
        'completed' => !empty($s['signed_at']),
    ];
}

if (!$trail['created']['completed'] && !empty($loaded['created_at'])) {
    $trail['created'] = [
        'by'        => (string) ($loaded['prepared_by_name'] ?? ''),
        'role'      => '',
        'at'        => date(DATE_ATOM, strtotime((string) $loaded['created_at'])),
        'signed'    => false,
        'completed' => true,
    ];
}

$paidBy = null;
if (!empty($loaded['paid_by'])) {
    $p = $pdo->prepare(
        'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name, username
           FROM users WHERE user_id = ?'
    );
    $p->execute([(int) $loaded['paid_by']]);
    $pr = $p->fetch(PDO::FETCH_ASSOC) ?: [];
    $paidBy = trim((string) ($pr['full_name'] ?? '')) ?: (string) ($pr['username'] ?? '');
}
$trail['paid'] = [
    'by'        => $paidBy ?? '',
    'role'      => '',
    'at'        => !empty($loaded['paid_at']) ? date(DATE_ATOM, strtotime((string) $loaded['paid_at'])) : null,
    'signed'    => false,
    'completed' => !empty($loaded['paid_at']),
];

vk_api_ok([
    'voucher' => $voucher,
    'trail'   => $trail,
]);
