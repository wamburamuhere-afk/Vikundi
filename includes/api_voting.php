<?php
/**
 * includes/api_voting.php — Module 14: Voting & Leadership Applications.
 *
 * TWO features sharing one `votes` table, exactly as the web keeps them:
 *
 *   ELECTIONS — a `votes` row (vote_type candidate|motion) lifecycle
 *   draft -> open -> closed, options in `vote_options`, secret-ballot casting
 *   via `vote_eligibility` (who may)/`vote_participation` (that they did,
 *   unique)/`vote_ballots` (the anonymous choice, no member_id). Gated on
 *   `manage_voting` for the leadership CRUD/lifecycle surface, `voting` for a
 *   member casting their own ballot.
 *
 *   LEADERSHIP APPLICATIONS — a member applies to stand for a leadership
 *   position in a still-`draft` candidate election; the Committee approves
 *   (writes a `vote_options` row — literally puts the name on the ballot) or
 *   rejects (reason required). Gated on `leadership_applications` (apply) and
 *   `manage_leadership_applications` (review).
 *
 * PERMISSION-KEY NOTE: unlike the Documents module (see includes/api_documents.php's
 * header — a real live incident, `library` vs `document_library`), every gate
 * in this feature's web files, api/*.php files, and migrations was checked
 * directly and found internally consistent — always exactly one of `voting`,
 * `manage_voting`, `leadership_applications`, `manage_leadership_applications`,
 * no sibling-file drift. What WAS found and fixed here instead: `voting` (the
 * page a member uses to cast their own ballot) was never explicitly granted to
 * Secretary/Treasurer by any migration — only `manage_voting` was — so on an
 * existing deployment a Secretary/Treasurer could manage elections but not
 * vote in one themselves. Fixed by database/grant_voting_permission.php,
 * mirroring the exact pattern create_voting_tables.php already used for
 * manage_voting. Given this module is BRAND NEW (its tables/permissions may
 * not exist on demo/production before this deploys), this is re-verified live
 * after deploy exactly like every permission claim this session makes,
 * precisely because a claim like this shipped wrong once already.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/vote_helpers.php';        // vk_vote_types/statuses, vk_vote_input_errors, vk_vote_tally, vk_turnout_percent
require_once __DIR__ . '/leadership_helpers.php';  // vk_leadership_positions, vk_member_application, vk_application_is_editable, ...

// ═══════════════════════════════ Elections ═══════════════════════════════════

if (!function_exists('vk_api_election_row')) {
    /** One election, as the app renders it. Never includes the tally. */
    function vk_api_election_row(array $r): array
    {
        $row = [
            'id'              => (int) $r['id'],
            'title'           => (string) $r['title'],
            'description'     => trim((string) ($r['description'] ?? '')) ?: null,
            'vote_type'       => (string) $r['vote_type'],
            'status'          => (string) $r['status'],
            'opens_at'        => !empty($r['opens_at']) ? date(DATE_ATOM, strtotime((string) $r['opens_at'])) : null,
            'closes_at'       => !empty($r['closes_at']) ? date(DATE_ATOM, strtotime((string) $r['closes_at'])) : null,
            'publish_results' => (bool) $r['publish_results'],
            'created_at'      => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        ];
        foreach (['option_count', 'eligible_count', 'voted_count'] as $k) {
            if (array_key_exists($k, $r)) {
                $row[$k] = (int) $r[$k];
            }
        }
        return $row;
    }
}

if (!function_exists('vk_api_election_option_row')) {
    function vk_api_election_option_row(array $r): array
    {
        return [
            'id'    => (int) $r['id'],
            'label' => (string) $r['label'],
        ];
    }
}

if (!function_exists('vk_api_election_actions')) {
    /** Lifecycle actions available to THIS caller, given the row's own status. */
    function vk_api_election_actions(array $auth, string $status): array
    {
        $canManage = vk_api_can($auth, 'edit', 'manage_voting');
        return [
            'edit'   => $canManage && $status === 'draft',
            'open'   => $canManage && $status === 'draft',
            'close'  => $canManage && $status === 'open',
            'delete' => vk_api_can($auth, 'delete', 'manage_voting') && $status !== 'open',
        ];
    }
}

if (!function_exists('vk_api_election_load')) {
    /** One election by id, or a 404. */
    function vk_api_election_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'An election id is required.');
        }
        // Auto-close if the deadline passed, same as every web read path.
        $pdo->prepare("UPDATE votes SET status='closed' WHERE id = ? AND status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")
            ->execute([$id]);
        $st = $pdo->prepare('SELECT * FROM votes WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No election was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_election_options')) {
    function vk_api_election_options(PDO $pdo, int $voteId): array
    {
        $st = $pdo->prepare('SELECT id, label, member_id, position FROM vote_options WHERE vote_id = ? ORDER BY position, id');
        $st->execute([$voteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_api_election_build_options')) {
    /**
     * The option list to insert for a NEW election, mirroring actions/save_vote.php:
     * a motion always uses the fixed Yes/No/Abstain set; a candidate election uses
     * whatever labels were submitted (zero is valid — see vk_vote_input_errors()'s
     * own doc comment: a leadership election is deliberately created empty so
     * members can apply into it).
     *
     * @return array{0:string,1:?int}[] [label, member_id][]
     */
    function vk_api_election_build_options(string $type, array $labels, array $memberIds): array
    {
        if ($type === 'motion') {
            return array_map(static fn(string $l): array => [$l, null], vk_default_motion_options());
        }
        $out = [];
        foreach ($labels as $i => $l) {
            $l = trim((string) $l);
            if ($l === '') {
                continue;
            }
            $mid = isset($memberIds[$i]) && ctype_digit((string) $memberIds[$i]) && (int) $memberIds[$i] > 0
                ? (int) $memberIds[$i] : null;
            $out[] = [$l, $mid];
        }
        return $out;
    }
}

if (!function_exists('vk_api_election_results')) {
    /**
     * Mirrors api/get_vote_results.php exactly: turnout is always visible;
     * the tally is gated by the secrecy rule (closed AND (leader OR published)).
     */
    function vk_api_election_results(PDO $pdo, array $vote, bool $isLeader): array
    {
        $id = (int) $vote['id'];
        $canSeeTally = $vote['status'] === 'closed' && ($isLeader || (int) $vote['publish_results'] === 1);

        $options = vk_api_election_options($pdo, $id);

        $eligSt = $pdo->prepare('SELECT COUNT(*) FROM vote_eligibility WHERE vote_id = ?');
        $eligSt->execute([$id]);
        $eligible = (int) $eligSt->fetchColumn();

        $votedSt = $pdo->prepare('SELECT COUNT(*) FROM vote_participation WHERE vote_id = ?');
        $votedSt->execute([$id]);
        $voted = (int) $votedSt->fetchColumn();

        $result = [
            'status'        => (string) $vote['status'],
            'title'         => (string) $vote['title'],
            'turnout'       => ['voted' => $voted, 'eligible' => $eligible, 'percent' => vk_turnout_percent($voted, $eligible)],
            'can_see_tally' => $canSeeTally,
        ];

        if ($canSeeTally) {
            $cSt = $pdo->prepare('SELECT option_id, COUNT(*) AS n FROM vote_ballots WHERE vote_id = ? GROUP BY option_id');
            $cSt->execute([$id]);
            $counts = [];
            foreach ($cSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(int) $r['option_id']] = (int) $r['n'];
            }
            $result['tally'] = vk_vote_tally($options, $counts);
        } else {
            $result['options'] = array_map('vk_api_election_option_row', $options);
        }

        return $result;
    }
}

// ═══════════════════════════════ Casting a vote ══════════════════════════════

if (!function_exists('vk_api_cast_vote')) {
    /**
     * Mirrors actions/cast_vote.php exactly: non-open election refused,
     * eligibility snapshot enforced, option-belongs-to-vote enforced, second
     * vote blocked by vote_participation's UNIQUE(vote_id, member_id) — caught
     * here, not pre-checked, for the same race-safety reason the web action
     * gives in its own header comment.
     */
    function vk_api_cast_vote(PDO $pdo, int $voteId, int $memberId, int $optionId): void
    {
        $pdo->prepare("UPDATE votes SET status='closed' WHERE id = ? AND status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")
            ->execute([$voteId]);

        $st = $pdo->prepare('SELECT status FROM votes WHERE id = ?');
        $st->execute([$voteId]);
        $status = $st->fetchColumn();
        if ($status === false) {
            vk_api_error(404, 'not_found', 'No election was found with that id.');
        }
        if ($status !== 'open') {
            vk_api_error(409, 'not_open', 'This election is not open for voting.');
        }

        $e = $pdo->prepare('SELECT COUNT(*) FROM vote_eligibility WHERE vote_id = ? AND member_id = ?');
        $e->execute([$voteId, $memberId]);
        if ((int) $e->fetchColumn() === 0) {
            vk_api_error(403, 'not_eligible', 'You are not eligible to vote in this election.');
        }

        $o = $pdo->prepare('SELECT COUNT(*) FROM vote_options WHERE id = ? AND vote_id = ?');
        $o->execute([$optionId, $voteId]);
        if ((int) $o->fetchColumn() === 0) {
            vk_api_error(422, 'invalid_option', 'That option does not belong to this election.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO vote_participation (vote_id, member_id) VALUES (?, ?)')->execute([$voteId, $memberId]);
        } catch (\PDOException $dup) {
            $pdo->rollBack();
            vk_api_error(409, 'already_voted', 'You have already voted in this election.');
        }
        $pdo->prepare('INSERT INTO vote_ballots (vote_id, option_id) VALUES (?, ?)')->execute([$voteId, $optionId]);
        $pdo->commit();
    }
}

// ══════════════════════════ Member-facing: open elections ════════════════════

if (!function_exists('vk_api_voting_open_row')) {
    /**
     * One open election as a member browses it: options, and whether THEY
     * (not anyone else) have already cast a ballot — never the tally, which
     * stays secret while the election is open regardless of caller.
     */
    function vk_api_voting_open_row(array $vote, array $options, bool $hasVoted): array
    {
        return [
            'id'          => (int) $vote['id'],
            'title'       => (string) $vote['title'],
            'description' => trim((string) ($vote['description'] ?? '')) ?: null,
            'vote_type'   => (string) $vote['vote_type'],
            'closes_at'   => !empty($vote['closes_at']) ? date(DATE_ATOM, strtotime((string) $vote['closes_at'])) : null,
            'has_voted'   => $hasVoted,
            'options'     => array_map('vk_api_election_option_row', $options),
        ];
    }
}

// ═══════════════════════ Leadership Applications ═════════════════════════════

if (!function_exists('vk_api_application_row')) {
    function vk_api_application_row(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'election'   => [
                'id'     => (int) $r['vote_id'],
                'title'  => (string) ($r['election_title'] ?? ''),
                'status' => (string) ($r['election_status'] ?? ''),
            ],
            'member'     => array_key_exists('member_name', $r) ? [
                'id'   => (int) $r['member_id'],
                'name' => trim((string) ($r['member_name'] ?? '')) ?: null,
            ] : null,
            'position'   => (string) $r['position'],
            'statement'  => (string) ($r['statement'] ?? ''),
            'experience' => trim((string) ($r['experience'] ?? '')) ?: null,
            'proposer'   => !empty($r['proposer_member_id']) ? [
                'id'   => (int) $r['proposer_member_id'],
                'name' => trim((string) ($r['proposer_name'] ?? '')) ?: null,
            ] : null,
            'status'      => (string) $r['status'],
            'review_note' => trim((string) ($r['review_note'] ?? '')) ?: null,
            'reviewed_by' => !empty($r['reviewed_by']) ? [
                'id'   => (int) $r['reviewed_by'],
                'name' => trim((string) ($r['reviewer_name'] ?? '')) ?: null,
            ] : null,
            'reviewed_at' => !empty($r['reviewed_at']) ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            'created_at'  => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'updated_at'  => !empty($r['updated_at']) ? date(DATE_ATOM, strtotime((string) $r['updated_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_application_actions')) {
    /** @param bool $isOwner viewer is the applicant themselves */
    function vk_api_application_actions(array $auth, bool $isOwner, array $application, string $electionStatus): array
    {
        $editable = $isOwner && vk_application_is_editable($application, $electionStatus);
        $canReview = vk_api_can($auth, 'edit', 'manage_leadership_applications')
            && $electionStatus === 'draft'
            && $application['status'] !== 'withdrawn';
        return [
            'edit'     => $editable,
            'withdraw' => $editable,
            'approve'  => $canReview && $application['status'] !== 'approved',
            'reject'   => $canReview && $application['status'] !== 'rejected',
            'reset'    => $canReview && $application['status'] !== 'pending',
        ];
    }
}

if (!function_exists('vk_api_application_load')) {
    /** One application by id, with every display join, or a 404. */
    function vk_api_application_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'An application id is required.');
        }
        $st = $pdo->prepare("
            SELECT a.*, v.status AS election_status, v.title AS election_title,
                   TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
                   TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS proposer_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS reviewer_name
              FROM leadership_applications a
              JOIN votes v ON v.id = a.vote_id
              LEFT JOIN customers c ON c.customer_id = a.member_id
              LEFT JOIN customers p ON p.customer_id = a.proposer_member_id
              LEFT JOIN users     u ON u.user_id     = a.reviewed_by
             WHERE a.id = ?
             LIMIT 1
        ");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No application was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_application_input_errors')) {
    /**
     * @param string[] $positions the group's configured positions (vk_leadership_positions())
     * @return string[]
     */
    function vk_api_application_input_errors(array $body, array $positions): array
    {
        $errors = [];
        if (!$positions) {
            $errors[] = 'Leadership positions have not been configured for this group.';
            return $errors;
        }
        $position = trim((string) ($body['position'] ?? ''));
        if (!in_array($position, $positions, true)) {
            $errors[] = 'position must be one of the group\'s configured positions.';
        }
        if (trim((string) ($body['statement'] ?? '')) === '') {
            $errors[] = 'statement is required.';
        }
        if (empty($body['declaration'])) {
            $errors[] = 'declaration must be accepted.';
        }
        return $errors;
    }
}
