<?php
// actions/download_unmatched.php
// Streams the rows that didn't match a member in the most recent transaction
// import as a CSV, so the user can onboard those members and re-import them.
// The rows are held in the session by actions/import_contributions.php; this is
// a one-shot download (the session copy is cleared once served).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized.');
}

require_once __DIR__ . '/../includes/transaction_import.php';

$rows = $_SESSION['import_unmatched'] ?? [];
if (!is_array($rows) || count($rows) === 0) {
    http_response_code(404);
    die('No unmatched rows to download.');
}

$csv  = unmatched_rows_to_csv($rows);
$body = "\xEF\xBB\xBF" . $csv; // UTF-8 BOM so Excel renders member names correctly
unset($_SESSION['import_unmatched']); // one-shot — don't re-serve stale rejects

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="unmatched_transactions.csv"');
header('Content-Length: ' . strlen($body));
echo $body;
exit;
