<?php
/**
 * api/get_guarantor_member.php
 * ----------------------------
 * Returns one member's basic details (name, phone, six-field location) so the
 * admin add-member form can autofill the guarantor section when an existing
 * member is chosen as the guarantor (registration PR-C).
 *
 * Auth-gated (require_auth.php): member data is never exposed to anonymous
 * callers — which is why this picker exists only on the admin form, not the
 * public self-registration page.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // logged-in users only (audit B3)

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid member id.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT customer_id, customer_name, first_name, middle_name, last_name, phone,
           country, state, district, ward, street, house_number
    FROM customers
    WHERE customer_id = ? AND is_deceased = 0
    LIMIT 1
");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m) {
    echo json_encode(['success' => false, 'message' => 'Member not found.']);
    exit;
}

$name = trim((string) $m['customer_name']);
if ($name === '') {
    $name = trim($m['first_name'] . ' ' . $m['middle_name'] . ' ' . $m['last_name']);
}

echo json_encode([
    'success' => true,
    'member'  => [
        'name'         => $name,
        'phone'        => $m['phone'],
        'country'      => $m['country'],
        'state'        => $m['state'],
        'district'     => $m['district'],
        'ward'         => $m['ward'],
        'street'       => $m['street'],
        'house_number' => $m['house_number'],
    ],
]);
