<?php
// actions/save_loan_type.php — create, edit or switch a loan type on/off.
//
// Loan types are the group's own loan rules (rate, period, limit, guarantors,
// late penalty), so they are governed exactly like the rest of Group Settings:
// admins — which includes the Chairperson — and the Secretary. A loan type is
// never deleted, only switched off: loans already issued point at it.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/loan_rules.php';
require_once __DIR__ . '/../includes/activity_logger.php';
global $pdo;

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$fail = static function (string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail($is_sw ? 'Ombi si sahihi.' : 'Invalid request.', 405);
}

// The role is read from the database, not trusted from the session, so a role
// change takes effect on the very next request.
$role_stmt = $pdo->prepare('SELECT r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
$role_stmt->execute([(int) $_SESSION['user_id']]);
$role = (string) ($role_stmt->fetchColumn() ?: ($_SESSION['user_role'] ?? ''));
$is_secretary = in_array(strtolower(trim($role)), ['secretary', 'katibu'], true);

if (!vk_role_is_admin($_SESSION['role_id'] ?? null, $role) && !$is_secretary) {
    $fail($is_sw ? 'Huna ruhusa ya kubadilisha aina za mikopo.' : 'You do not have permission to change loan types.', 403);
}

$id     = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
$action = (string) ($_POST['action'] ?? 'save');

try {
    // ── Switch on / off ──────────────────────────────────────────────────────
    if ($action === 'toggle') {
        $cur = $pdo->prepare('SELECT name, is_active FROM vicoba_loan_types WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $fail($is_sw ? 'Aina ya mkopo haipatikani.' : 'Loan type not found.', 404);
        }
        $new = ((int) $row['is_active'] === 1) ? 0 : 1;
        $pdo->prepare('UPDATE vicoba_loan_types SET is_active = ? WHERE id = ?')->execute([$new, $id]);
        logUpdate('Loan Types', $row['name'] . ($new ? ' (activated)' : ' (deactivated)'), "LOANTYPE#$id");
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Imehifadhiwa.' : 'Saved.']);
        exit;
    }

    // ── Create / edit ────────────────────────────────────────────────────────
    [$t, $errors] = lr_validate_type($_POST);
    if ($errors) {
        $fail(implode(' ', $errors));
    }

    $dupe = $pdo->prepare('SELECT id FROM vicoba_loan_types WHERE name = ? AND id <> ?');
    $dupe->execute([$t['name'], $id]);
    if ($dupe->fetchColumn()) {
        $fail($is_sw ? 'Tayari kuna aina ya mkopo yenye jina hili.' : 'A loan type with this name already exists.');
    }

    $values = [
        $t['name'], $t['description'] !== '' ? $t['description'] : null, $t['interest_rate'], $t['interest_type'],
        $t['max_term_months'], $t['max_share_multiple'], $t['max_amount'], $t['guarantors_required'],
        $t['penalty_type'], $t['penalty_value'], $t['grace_days'],
    ];

    if ($id > 0) {
        $exists = $pdo->prepare('SELECT id FROM vicoba_loan_types WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            $fail($is_sw ? 'Aina ya mkopo haipatikani.' : 'Loan type not found.', 404);
        }
        $pdo->prepare(
            'UPDATE vicoba_loan_types
                SET name = ?, description = ?, interest_rate = ?, interest_type = ?, max_term_months = ?,
                    max_share_multiple = ?, max_amount = ?, guarantors_required = ?, penalty_type = ?,
                    penalty_value = ?, grace_days = ?
              WHERE id = ?'
        )->execute(array_merge($values, [$id]));
        logUpdate('Loan Types', $t['name'], "LOANTYPE#$id");
    } else {
        $pdo->prepare(
            'INSERT INTO vicoba_loan_types
                (name, description, interest_rate, interest_type, max_term_months, max_share_multiple,
                 max_amount, guarantors_required, penalty_type, penalty_value, grace_days, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge($values, [(int) $_SESSION['user_id']]));
        $id = (int) $pdo->lastInsertId();
        logCreate('Loan Types', $t['name'], "LOANTYPE#$id");
    }

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Aina ya mkopo imehifadhiwa.' : 'Loan type saved.', 'id' => $id]);
} catch (Throwable $e) {
    error_log('save_loan_type: ' . $e->getMessage());
    $fail($is_sw ? 'Imeshindikana kuhifadhi. Jaribu tena.' : 'Could not save. Please try again.', 500);
}
