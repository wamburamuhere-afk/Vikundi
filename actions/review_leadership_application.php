<?php
// actions/review_leadership_application.php — the Committee approves or rejects a
// member's application to stand for a leadership position.
//
// Approving is what puts a name on the ballot: it writes a `vote_options` row for
// the election, which is exactly what the existing voting page already renders and
// what cast_vote.php already tallies. Nothing about the voting module changes.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/leadership_helpers.php';
require_once __DIR__ . '/../includes/activity_logger.php';
global $pdo;

header('Content-Type: application/json');

// Members apply and vote; they do not approve. This is the line the group drew.
requirePermissionJson('edit', 'manage_leadership_applications');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$err = fn(string $m) => json_encode(['success' => false, 'message' => $m]);

$app_id   = isset($_POST['application_id']) && ctype_digit((string) $_POST['application_id']) ? (int) $_POST['application_id'] : 0;
$decision = (string) ($_POST['decision'] ?? '');
$note     = trim((string) ($_POST['note'] ?? ''));

if ($app_id <= 0 || !in_array($decision, ['approve', 'reject', 'reset'], true)) {
    echo $err($is_sw ? 'Ombi si sahihi.' : 'Invalid request.');
    exit;
}

$q = $pdo->prepare("
    SELECT a.*, v.status AS election_status, v.title AS election_title,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
      FROM leadership_applications a
      JOIN votes v ON v.id = a.vote_id
      LEFT JOIN customers c ON c.customer_id = a.member_id
     WHERE a.id = ? LIMIT 1");
$q->execute([$app_id]);
$app = $q->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    echo $err($is_sw ? 'Ombi halijapatikana.' : 'Application not found.');
    exit;
}

// Once voting opens the ballot is fixed. Adding or removing a candidate underneath
// members who have already voted would change what they were choosing between.
if ($app['election_status'] !== 'draft') {
    echo $err($is_sw
        ? 'Uchaguzi umeshaanza; huwezi kubadilisha maombi sasa.'
        : 'Voting has started; applications can no longer be changed.');
    exit;
}

if ($app['status'] === 'withdrawn') {
    echo $err($is_sw
        ? 'Mwanachama ameondoa ombi lake.'
        : 'The member has withdrawn this application.');
    exit;
}

$reviewer = (int) ($_SESSION['user_id'] ?? 0);

try {
    $pdo->beginTransaction();

    if ($decision === 'approve') {
        // Label the ballot option. When every candidate in this election stands for
        // the same office the name alone is enough; when the election spans several
        // offices the position has to appear or the ballot is unreadable.
        $distinct = $pdo->prepare("
            SELECT COUNT(DISTINCT position) FROM leadership_applications
             WHERE vote_id = ? AND status IN ('approved','pending')");
        $distinct->execute([(int) $app['vote_id']]);
        $manyOffices = ((int) $distinct->fetchColumn()) > 1;

        $label = $manyOffices
            ? $app['member_name'] . ' — ' . $app['position']
            : $app['member_name'];

        if (!empty($app['vote_option_id'])) {
            // Already on the ballot — keep the same option so any ordering stays put.
            $pdo->prepare("UPDATE vote_options SET label = ?, member_id = ? WHERE id = ?")
                ->execute([$label, (int) $app['member_id'], (int) $app['vote_option_id']]);
            $optionId = (int) $app['vote_option_id'];
        } else {
            $pos = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM vote_options WHERE vote_id = ?");
            $pos->execute([(int) $app['vote_id']]);
            $pdo->prepare("INSERT INTO vote_options (vote_id, label, member_id, position) VALUES (?,?,?,?)")
                ->execute([(int) $app['vote_id'], $label, (int) $app['member_id'], (int) $pos->fetchColumn()]);
            $optionId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare("
            UPDATE leadership_applications
               SET status='approved', review_note=?, reviewed_by=?, reviewed_at=NOW(), vote_option_id=?
             WHERE id = ?")
            ->execute([$note !== '' ? $note : null, $reviewer, $optionId, $app_id]);

        $pdo->commit();
        logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (approved)', 'LA#' . $app_id);
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Ombi limekubaliwa na jina limeingia kwenye kura.' : 'Approved. The name is now on the ballot.']);
        exit;
    }

    if ($decision === 'reject') {
        // A rejection without a reason is the kind of decision a group argues about
        // for a year, so the reason is required rather than optional.
        if ($note === '') {
            $pdo->rollBack();
            echo $err($is_sw ? 'Andika sababu ya kukataa.' : 'Please give a reason for rejecting.');
            exit;
        }
        if (!empty($app['vote_option_id'])) {
            $pdo->prepare("DELETE FROM vote_options WHERE id = ?")->execute([(int) $app['vote_option_id']]);
        }
        $pdo->prepare("
            UPDATE leadership_applications
               SET status='rejected', review_note=?, reviewed_by=?, reviewed_at=NOW(), vote_option_id=NULL
             WHERE id = ?")
            ->execute([$note, $reviewer, $app_id]);

        $pdo->commit();
        logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (rejected)', 'LA#' . $app_id);
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Ombi limekataliwa.' : 'Application rejected.']);
        exit;
    }

    // reset — the Committee changed its mind before voting opened. The ballot option
    // goes with it, or a rejected candidate would still be standing.
    if (!empty($app['vote_option_id'])) {
        $pdo->prepare("DELETE FROM vote_options WHERE id = ?")->execute([(int) $app['vote_option_id']]);
    }
    $pdo->prepare("
        UPDATE leadership_applications
           SET status='pending', review_note=NULL, reviewed_by=NULL, reviewed_at=NULL, vote_option_id=NULL
         WHERE id = ?")
        ->execute([$app_id]);

    $pdo->commit();
    logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (reopened)', 'LA#' . $app_id);
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Ombi limerudishwa kusubiri.' : 'Application returned to pending.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo $err($is_sw ? 'Imeshindikana kuhifadhi uamuzi.' : 'Could not save the decision.');
}
