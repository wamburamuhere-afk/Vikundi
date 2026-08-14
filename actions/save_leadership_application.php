<?php
// actions/save_leadership_application.php — a member submits, updates or withdraws
// their application for a leadership position.
//
// One application per member per election, enforced by a UNIQUE key on
// (vote_id, member_id) rather than by a check here that a second browser tab can
// race past. Withdrawing sets status='withdrawn' and re-applying updates that same
// row, so the constraint holds without ever blocking someone who changed their mind.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/leadership_helpers.php';
require_once __DIR__ . '/../includes/activity_logger.php';
global $pdo;

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$err = fn(string $m) => json_encode(['success' => false, 'message' => $m]);

// Members apply; this is not a leadership action, but it still needs the grant so
// the group can close applications off entirely by revoking it.
if (!canCreate('leadership_applications')) {
    http_response_code(403);
    echo $err($is_sw ? 'Hauna ruhusa ya kutuma maombi.' : 'You do not have permission to apply.');
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
$c = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
$c->execute([$uid]);
$member_id = (int) ($c->fetchColumn() ?: 0);
if ($member_id <= 0) {
    echo $err($is_sw ? 'Akaunti yako si ya mwanachama.' : 'Your account is not a member account.');
    exit;
}

$vote_id = isset($_POST['vote_id']) && ctype_digit((string) $_POST['vote_id']) ? (int) $_POST['vote_id'] : 0;
$action  = ($_POST['do'] ?? 'apply') === 'withdraw' ? 'withdraw' : 'apply';
if ($vote_id <= 0) {
    echo $err($is_sw ? 'Ombi si sahihi.' : 'Invalid request.');
    exit;
}

// The election must exist, be a candidate election, and still be in draft. Once
// voting opens the ballot is fixed — accepting a late application would change the
// list of names under people who have already voted.
$v = $pdo->prepare("SELECT id, title, status, vote_type FROM votes WHERE id = ? LIMIT 1");
$v->execute([$vote_id]);
$election = $v->fetch(PDO::FETCH_ASSOC);
if (!$election || $election['vote_type'] !== 'candidate') {
    echo $err($is_sw ? 'Uchaguzi haujapatikana.' : 'Election not found.');
    exit;
}
if ($election['status'] !== 'draft') {
    echo $err($is_sw
        ? 'Maombi yamefungwa kwa uchaguzi huu.'
        : 'Applications are closed for this election.');
    exit;
}

$existing = vk_member_application($pdo, $vote_id, $member_id);

try {
    if ($action === 'withdraw') {
        if (!$existing || $existing['status'] !== 'pending') {
            echo $err($is_sw
                ? 'Hakuna ombi linalosubiri ambalo unaweza kuondoa.'
                : 'There is no pending application to withdraw.');
            exit;
        }
        $pdo->prepare("UPDATE leadership_applications SET status='withdrawn' WHERE id = ?")
            ->execute([$existing['id']]);
        logUpdate('Leadership Applications', $election['title'], 'LA#' . $existing['id']);
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Ombi lako limeondolewa.' : 'Your application has been withdrawn.']);
        exit;
    }

    // --- Apply / re-apply ---
    // A ruled-on application is final. Letting someone re-open a rejected one would
    // hand them an unlimited retry against a decision the Committee already made.
    if ($existing && in_array($existing['status'], ['approved', 'rejected'], true)) {
        echo $err($is_sw
            ? 'Ombi lako tayari limepitiwa na Kamati.'
            : 'Your application has already been reviewed by the Committee.');
        exit;
    }

    $position   = trim((string) ($_POST['position'] ?? ''));
    $statement  = trim((string) ($_POST['statement'] ?? ''));
    $experience = trim((string) ($_POST['experience'] ?? ''));
    $proposer   = isset($_POST['proposer_member_id']) && ctype_digit((string) $_POST['proposer_member_id'])
        ? (int) $_POST['proposer_member_id'] : null;
    $declared   = !empty($_POST['declaration']);

    // The position must be one the group actually configured — not whatever the
    // form happened to post.
    $positions = vk_leadership_positions($pdo);
    if (!$positions) {
        echo $err($is_sw ? 'Nafasi za uongozi hazijawekwa.' : 'Leadership positions have not been configured.');
        exit;
    }
    if (!in_array($position, $positions, true)) {
        echo $err($is_sw ? 'Chagua nafasi sahihi.' : 'Choose a valid position.');
        exit;
    }
    if ($statement === '') {
        echo $err($is_sw ? 'Andika maelezo ya kwa nini unaomba.' : 'Please write a short statement.');
        exit;
    }
    if (!$declared) {
        echo $err($is_sw ? 'Lazima ukubali masharti.' : 'You must accept the declaration.');
        exit;
    }
    // Proposing yourself is not a proposal.
    if ($proposer === $member_id) {
        echo $err($is_sw ? 'Huwezi kujidhamini mwenyewe.' : 'You cannot propose yourself.');
        exit;
    }
    if ($proposer !== null) {
        $pc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE customer_id = ? AND status <> 'deleted'");
        $pc->execute([$proposer]);
        if ((int) $pc->fetchColumn() === 0) {
            echo $err($is_sw ? 'Mdhamini hajapatikana.' : 'Proposer not found.');
            exit;
        }
    }

    if ($existing) {
        $pdo->prepare("
            UPDATE leadership_applications
               SET position = ?, statement = ?, experience = ?, proposer_member_id = ?,
                   declaration = 1, status = 'pending', review_note = NULL,
                   reviewed_by = NULL, reviewed_at = NULL
             WHERE id = ?")
            ->execute([$position, $statement, $experience, $proposer, $existing['id']]);
        $appId = (int) $existing['id'];
        logUpdate('Leadership Applications', $position, 'LA#' . $appId);
    } else {
        $pdo->prepare("
            INSERT INTO leadership_applications
                (vote_id, member_id, position, statement, experience, proposer_member_id, declaration)
            VALUES (?,?,?,?,?,?,1)")
            ->execute([$vote_id, $member_id, $position, $statement, $experience, $proposer]);
        $appId = (int) $pdo->lastInsertId();
        logCreate('Leadership Applications', $position, 'LA#' . $appId);
    }

    echo json_encode([
        'success' => true,
        'message' => $is_sw
            ? 'Ombi lako limetumwa. Litapitiwa na Kamati.'
            : 'Your application has been submitted. The Committee will review it.',
    ]);
} catch (PDOException $e) {
    // The UNIQUE key firing means a second tab got there first; that is not an error
    // worth showing as a database failure.
    if ($e->getCode() === '23000') {
        echo $err($is_sw
            ? 'Tayari una ombi kwenye uchaguzi huu.'
            : 'You already have an application for this election.');
        exit;
    }
    http_response_code(500);
    echo $err($is_sw ? 'Imeshindikana kuhifadhi ombi.' : 'Could not save the application.');
}
