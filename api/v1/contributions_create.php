<?php
/**
 * POST /api/v1/contributions — record a contribution.
 *
 * Reached through contributions.php, which has already authenticated. Also
 * usable directly.
 *
 * Mirrors actions/process_contribution.php, with three deliberate differences:
 *
 *  1. THE UPLOAD IS VALIDATED. The web handler builds its filename from
 *     pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION) — the client's
 *     own string — and moves it into a web-served directory. A file named
 *     receipt.php lands as receipt.php. This uses vk_api_store_upload(), which
 *     takes the extension from the whitelist key and sniffs the bytes. (The web
 *     handler is fixed in the same change.)
 *
 *  2. REFUSALS ARE HTTP ERRORS. The web handler answers "Invalid data provided"
 *     with HTTP 200, so a client cannot tell success from failure without
 *     parsing prose.
 *
 *  3. THE MEMBER MUST EXIST. Posting a member_id with no customers row would
 *     otherwise create a contribution belonging to nobody — money in the table
 *     that no statement will ever show, because every standing query joins
 *     customers.
 *
 * WHO MAY FILE FOR WHOM is unchanged from the web: any signed-in user may file
 * their own; canCreate('manage_contributions') is what permits filing against
 * another member. A member declaring "I paid" is the normal case in a savings
 * group, and it is the treasurer's approval — not the act of submitting — that
 * moves the books.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';
require_once __DIR__ . '/../../includes/api_upload.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

// Accepts JSON and multipart alike: evidence needs multipart, everything else
// is happier as JSON, and the app should not have to switch encodings to add a
// photo. vk_api_body() already merges $_POST for multipart requests.
$body = vk_api_body();

$ownMemberId = vk_api_member_id((int) $auth['user_id']);
$mayFileForOthers = vk_api_can($auth, 'create', 'manage_contributions');

$memberId = (int) ($body['member_id'] ?? 0);
if (!$mayFileForOthers) {
    // Overwritten, not validated: a member may only ever file their own.
    $memberId = $ownMemberId;
    if ($memberId <= 0) {
        vk_api_error(
            403,
            'no_member_record',
            'This account has no member record, so it cannot file a contribution for itself.'
        );
    }
} elseif ($memberId <= 0) {
    $memberId = $ownMemberId;
}

if ($memberId <= 0) {
    vk_api_error(422, 'member_required', 'member_id is required.');
}

$exists = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE customer_id = ?');
$exists->execute([$memberId]);
if ((int) $exists->fetchColumn() === 0) {
    vk_api_error(404, 'member_not_found', 'No member was found with that id.');
}

// -----------------------------------------------------------------------------
// Amount
// -----------------------------------------------------------------------------
$rawAmount = $body['amount'] ?? null;
if ($rawAmount === null || $rawAmount === '' || !is_numeric($rawAmount)) {
    vk_api_error(422, 'invalid_amount', 'amount is required and must be a number.');
}
$amount = round((float) $rawAmount, 2);
if ($amount <= 0) {
    vk_api_error(422, 'invalid_amount', 'amount must be greater than zero.');
}
// decimal(15,2) — a larger value is truncated by MySQL rather than refused,
// which would silently record a different figure from the one submitted.
if ($amount > 9999999999999.99) {
    vk_api_error(422, 'invalid_amount', 'amount is too large.');
}

// -----------------------------------------------------------------------------
// Type, account, date
// -----------------------------------------------------------------------------
$type = (string) ($body['type'] ?? $body['contribution_type'] ?? 'monthly');
if (!in_array($type, vk_api_contrib_types(), true)) {
    vk_api_error(422, 'invalid_type', 'type must be one of: '
        . implode(', ', vk_api_contrib_types()) . '.');
}

$account = trim((string) ($body['account'] ?? ''));
if ($account !== '' && !in_array($account, vk_api_contrib_accounts(), true)) {
    vk_api_error(422, 'invalid_account', 'account must be one of: '
        . implode(', ', vk_api_contrib_accounts()) . '.');
}
$account = $account !== '' ? $account : null;

$date = trim((string) ($body['date'] ?? $body['contribution_date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
} else {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        vk_api_error(422, 'invalid_date', 'date must be in YYYY-MM-DD format.');
    }
    // A future-dated contribution would count toward a month that has not
    // happened, which the statement reads as paying ahead. The web form has no
    // such guard; a phone with a wrong clock makes it worth one here.
    if ($date > date('Y-m-d')) {
        vk_api_error(422, 'invalid_date', 'date cannot be in the future.');
    }
}

$description = trim((string) ($body['description'] ?? ''));
$receipt     = trim((string) ($body['receipt_number'] ?? ''));
$receipt     = $receipt !== '' ? mb_substr($receipt, 0, 100) : null;

// -----------------------------------------------------------------------------
// Evidence (optional)
// -----------------------------------------------------------------------------
$evidencePath = null;
if (isset($_FILES['evidence']) && (int) ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    [$stored, $err] = vk_api_store_upload(
        $_FILES['evidence'],
        dirname(__DIR__, 2) . '/uploads/contributions',
        'receipt'
    );
    if ($err !== null) {
        vk_api_error(422, 'invalid_upload', $err);
    }
    $evidencePath = 'uploads/contributions/' . $stored;
}

// -----------------------------------------------------------------------------
// Insert. Status is always 'pending' — never taken from the request.
// -----------------------------------------------------------------------------
$_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session

$stmt = $pdo->prepare("
    INSERT INTO contributions
        (member_id, amount, contribution_type, contribution_date, description, status,
         receipt_number, account, evidence_path, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
$stmt->execute([
    $memberId, $amount, $type, $date, $description !== '' ? $description : null,
    $receipt, $account, $evidencePath, (int) $auth['user_id'],
]);

$newId = (int) $pdo->lastInsertId();

logCreate('Contributions', number_format($amount, 2), 'CONTRIB#' . $newId);

$row = $pdo->prepare(
    'SELECT co.*, c.customer_name, c.first_name, c.last_name
       FROM contributions co
       LEFT JOIN customers c ON c.customer_id = co.member_id
      WHERE co.contribution_id = ?'
);
$row->execute([$newId]);
$created = vk_api_contrib_row($row->fetch(PDO::FETCH_ASSOC) ?: []);
$created['actions'] = vk_api_contrib_actions($auth, $created['status']);

vk_api_ok([
    'contribution' => $created,
    'message'      => 'Contribution submitted and awaiting review.',
], 201);
