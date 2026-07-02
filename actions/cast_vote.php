<?php
// actions/cast_vote.php — a member casts ONE secret ballot.
//
// Secrecy: we record THAT the member voted (vote_participation, unique) and,
// separately, the anonymous CHOICE (vote_ballots, no member_id). The two are
// never linked, and we deliberately do NOT write an activity-log entry naming
// the choice. So no one can see who voted for whom — only the tally.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3: must be logged in
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6: valid CSRF token
global $pdo;

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$uid = (int) ($_SESSION['user_id'] ?? 0);
$vote_id   = isset($_POST['vote_id'])   && ctype_digit((string) $_POST['vote_id'])   ? (int) $_POST['vote_id']   : 0;
$option_id = isset($_POST['option_id']) && ctype_digit((string) $_POST['option_id']) ? (int) $_POST['option_id'] : 0;

$err = fn($m) => json_encode(['success' => false, 'message' => $m]);

if ($vote_id <= 0 || $option_id <= 0) { echo $err($is_sw ? 'Ombi si sahihi.' : 'Invalid request.'); exit; }

// Resolve the logged-in user to their member (customer) record.
$c = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
$c->execute([$uid]);
$member_id = (int) ($c->fetchColumn() ?: 0);
if ($member_id <= 0) { echo $err($is_sw ? 'Akaunti yako si ya mwanachama.' : 'Your account is not a member account.'); exit; }

try {
    // Auto-close if the deadline passed.
    $pdo->prepare("UPDATE votes SET status='closed' WHERE id = ? AND status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")->execute([$vote_id]);

    $v = $pdo->prepare("SELECT status FROM votes WHERE id = ?");
    $v->execute([$vote_id]);
    $status = $v->fetchColumn();
    if ($status === false) { echo $err($is_sw ? 'Kura haijapatikana.' : 'Vote not found.'); exit; }
    if ($status !== 'open') { echo $err($is_sw ? 'Kura hii haipo wazi kwa kupiga kura.' : 'This vote is not open.'); exit; }

    // Eligible? (snapshot taken when the vote opened)
    $e = $pdo->prepare("SELECT COUNT(*) FROM vote_eligibility WHERE vote_id = ? AND member_id = ?");
    $e->execute([$vote_id, $member_id]);
    if ((int) $e->fetchColumn() === 0) { echo $err($is_sw ? 'Huna ruhusa ya kupiga kura hii.' : 'You are not eligible for this vote.'); exit; }

    // Option must belong to this vote.
    $o = $pdo->prepare("SELECT COUNT(*) FROM vote_options WHERE id = ? AND vote_id = ?");
    $o->execute([$option_id, $vote_id]);
    if ((int) $o->fetchColumn() === 0) { echo $err($is_sw ? 'Chaguo si sahihi.' : 'Invalid choice.'); exit; }

    // Cast: participation (unique) blocks a second vote; ballot is anonymous.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO vote_participation (vote_id, member_id) VALUES (?, ?)")->execute([$vote_id, $member_id]);
    } catch (\PDOException $dup) {
        $pdo->rollBack();
        echo $err($is_sw ? 'Tayari umepiga kura kwenye kura hii.' : 'You have already voted in this poll.');
        exit;
    }
    $pdo->prepare("INSERT INTO vote_ballots (vote_id, option_id) VALUES (?, ?)")->execute([$vote_id, $option_id]);
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Kura yako imepokelewa. Asante!' : 'Your vote has been recorded. Thank you!']);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo $err($is_sw ? 'Hitilafu imetokea.' : 'An error occurred.');
}
