<?php
/**
 * includes/api_contributions.php — the rules the contributions endpoints share.
 *
 * Six handlers touch this table (list, detail, create, review, approve, cancel)
 * and every one of them needs the same three answers: who may see whose money,
 * what a contribution looks like on the wire, and which status transitions are
 * legal. Answering those in six files is how they drift, and a drift here is a
 * member reading another member's savings.
 *
 * WHO SEES WHOSE MONEY. The web page settles this in one line:
 *
 *     $is_leader = isAdmin() || canEdit('manage_contributions');
 *     ... if (!$is_leader) $query .= " AND c.user_id = ?";
 *
 * That is mirrored exactly by vk_api_contrib_scope(). A caller who is not a
 * leader is pinned to their OWN member id — not by omitting a filter the client
 * is trusted to send, but by overwriting whatever they asked for. A JSON body
 * has no template to hide behind: a row placed in the response is readable by
 * whoever holds the token.
 *
 * SUBMITTING IS NOT THE SAME AS RECORDING. actions/process_contribution.php has
 * always let ANY signed-in user file a contribution for themselves, and uses
 * canCreate('manage_contributions') only to decide whether they may file one
 * against SOMEONE ELSE. That is the correct shape for a savings group — a member
 * declaring "I paid" is the normal case — and it is carried over unchanged
 * rather than re-litigated here.
 *
 * EVERY NEW ROW IS 'pending'. The status is never taken from the request. The
 * group's rule is that money is counted only once it has been reviewed and then
 * approved, and a client that could post status=approved would be able to move
 * the group's books on its own.
 */

/*
 * DEPENDENCIES. Only config-free files are required here, so the rules in this
 * module can be loaded and tested without a database — includes/config.php is
 * gitignored and absent in CI, and a require of api_bootstrap.php would make
 * every one of these decisions untestable.
 *
 * The functions that touch HTTP or the database (vk_api_contrib_scope() calls
 * vk_api_error() and vk_api_member_id(); vk_api_contrib_transition() needs a
 * PDO) are only ever called from an endpoint under api/v1/, and every one of
 * those requires api_bootstrap.php on its first line. Nothing else may call
 * them.
 */
require_once __DIR__ . '/api_auth.php';             // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/contribution_access.php';  // vk_contrib_leader_from() — one rule, both transports
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/contribution_standing.php'; // cs_is_opening() and the statement rules
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/../core/workflow.php';

if (!function_exists('vk_api_contrib_types')) {
    /** The contribution_type enum, as the column declares it. */
    function vk_api_contrib_types(): array
    {
        return ['entrance', 'monthly', 'agm', 'fine', 'other'];
    }
}

if (!function_exists('vk_api_contrib_accounts')) {
    /** Where the money landed. Matches actions/process_contribution.php. */
    function vk_api_contrib_accounts(): array
    {
        return ['M-Koba', 'Bank', 'Cash', 'Mobile Money'];
    }
}

if (!function_exists('vk_api_contrib_statuses')) {
    /** The status enum, as the column declares it. */
    function vk_api_contrib_statuses(): array
    {
        return ['pending', 'reviewed', 'approved', 'cancelled'];
    }
}

if (!function_exists('vk_api_contrib_is_leader')) {
    /**
     * Mirrors manage_contributions.php's $is_leader.
     *
     * Note this is `edit`, not `view`. A Member holds view (they can see their
     * own page) and must NOT thereby see the whole group's ledger, so view is
     * the wrong test for "may see everyone".
     */
    function vk_api_contrib_is_leader(array $auth): bool
    {
        // Delegates to the shared rule so the API and the web cannot answer this
        // differently. They already did once: six web endpoints tested `view`,
        // which the Member role holds, and served the whole group's savings to
        // any signed-in member. See includes/contribution_access.php.
        return vk_contrib_leader_from(
            vk_api_is_admin((int) $auth['role_id']),
            vk_api_can($auth, 'edit', 'manage_contributions')
        );
    }
}

if (!function_exists('vk_api_contrib_scope')) {
    /**
     * Resolve which member's rows this caller is allowed to read, honouring an
     * optional requested member_id.
     *
     * A leader may ask for anyone (0 => everyone). Anyone else is pinned to
     * their own record regardless of what they asked for, and is refused
     * outright when their account has no member record to pin them to — an
     * account with no customers row has no contributions of its own, and
     * falling through with member_id 0 would hand them the entire group.
     *
     * @return array{is_leader:bool, member_id:int, own_member_id:int}
     */
    function vk_api_contrib_scope(array $auth, int $requestedMemberId = 0): array
    {
        $isLeader = vk_api_contrib_is_leader($auth);
        $own      = vk_api_member_id((int) $auth['user_id']);

        if ($isLeader) {
            return [
                'is_leader'     => true,
                'member_id'     => max(0, $requestedMemberId),
                'own_member_id' => $own,
            ];
        }

        if ($own <= 0) {
            vk_api_error(
                403,
                'no_member_record',
                'This account has no member record, so it has no contributions of its own.'
            );
        }

        return ['is_leader' => false, 'member_id' => $own, 'own_member_id' => $own];
    }
}

if (!function_exists('vk_api_contrib_row')) {
    /**
     * One contributions row on the wire.
     *
     * `counts_toward_savings` is computed here rather than left to the client.
     * Whether a row is savings is not obvious from its fields — cancelled money
     * and 'agm'/'fine' rows are excluded by includes/contribution_standing.php,
     * which is why a member's own arithmetic over this list would not match the
     * statement. Answering it server-side keeps the two agreeing.
     */
    function vk_api_contrib_row(array $r): array
    {
        $type   = (string) ($r['contribution_type'] ?? '');
        $status = (string) ($r['status'] ?? '');

        $name = trim((string) ($r['customer_name'] ?? ''));
        if ($name === '') {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }

        return [
            'contribution_id' => (int) $r['contribution_id'],
            'member_id'       => (int) $r['member_id'],
            'member_name'     => $name,
            'amount'          => (float) $r['amount'],
            'type'            => $type,
            'status'          => $status,
            'date'            => (string) $r['contribution_date'],
            'description'     => $r['description'] !== null ? (string) $r['description'] : null,
            'receipt_number'  => $r['receipt_number'] ?? null,
            'account'         => $r['account'] ?? null,
            'evidence_url'    => !empty($r['evidence_path']) ? (string) $r['evidence_path'] : null,
            'is_opening'      => cs_is_opening($r['mkoba_trans_id'] ?? null, $r['account'] ?? null),
            'counts_toward_savings' => in_array($status, ['approved', 'confirmed', ''], true)
                && in_array($type, ['monthly', 'entrance', 'other', ''], true),
            'created_at'  => $r['created_at']  ? date(DATE_ATOM, strtotime((string) $r['created_at']))  : null,
            'reviewed_at' => $r['reviewed_at'] ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            'approved_at' => $r['approved_at'] ? date(DATE_ATOM, strtotime((string) $r['approved_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_contrib_actions')) {
    /**
     * What THIS caller may do to a row in this status.
     *
     * Sent with every row so the app never has to re-derive the workflow. The
     * app showing a button the server would refuse is the same class of bug as
     * /auth/me under-reporting an admin's permissions: one inconsistency every
     * consumer has to special-case.
     */
    function vk_api_contrib_actions(array $auth, string $status): array
    {
        $canReview  = vk_api_can($auth, 'review', 'manage_contributions')
            && vk_api_can($auth, 'view', 'manage_contributions');
        $canApprove = vk_api_can($auth, 'approve', 'manage_contributions')
            && vk_api_can($auth, 'view', 'manage_contributions');
        $canCancel  = vk_api_can($auth, 'edit', 'manage_contributions');

        return [
            'review'  => $canReview  && $status === 'pending',
            'approve' => $canApprove && $status === 'reviewed',
            'cancel'  => $canCancel  && $status !== 'approved' && $status !== 'cancelled',
        ];
    }
}

if (!function_exists('vk_api_contrib_transition')) {
    /**
     * Run one workflow transition and return the fresh row.
     *
     * The three transition endpoints are the same twelve lines with a different
     * verb, so they live here once. What each of them must NOT be allowed to
     * skip is why:
     *
     *  - SELECT ... FOR UPDATE inside the transaction. Two officers tapping
     *    Approve at the same moment would otherwise both read 'reviewed', both
     *    pass the guard, and both write — producing two approval signatures for
     *    one contribution and an audit trail that cannot be reconciled.
     *
     *  - The FROM-status guard. Status is not a free-text field: pending may
     *    only become reviewed, reviewed may only become approved. Without this,
     *    an approve call on a pending row silently skips review, which is the
     *    entire control the group's three-approval rule exists to provide.
     *    (actions/update_contribution.php on the web accepts any status string
     *    and is fixed alongside this.)
     *
     *  - The signature capture and the activity log, in the same transaction as
     *    the write. A committed status change with no record of who made it is
     *    worse than a failed one.
     *
     * @param array<string> $allowedFrom statuses this transition may start from
     * @return array{row:array, has_signature:bool}
     */
    function vk_api_contrib_transition(
        PDO $pdo,
        array $auth,
        int $id,
        string $to,
        array $allowedFrom,
        string $signatureAction,
        string $logVerb
    ): array {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A contribution id is required.');
        }
        if (!in_array($to, vk_api_contrib_statuses(), true)) {
            vk_api_error(500, 'invalid_transition', 'Unsupported target status.');
        }

        // workflowActorSnapshot() and logActivity() both read $_SESSION['user_id'];
        // the API has no session, so the token's identity is placed there for the
        // duration of the request. Without it the audit trail records the action
        // against nobody.
        $_SESSION['user_id'] = (int) $auth['user_id'];

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare(
                'SELECT status FROM contributions WHERE contribution_id = ? FOR UPDATE'
            );
            $cur->execute([$id]);
            $from = $cur->fetchColumn();

            if ($from === false) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No contribution was found with that id.');
            }

            if (!in_array((string) $from, $allowedFrom, true)) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    sprintf(
                        'A contribution that is %s cannot be %s. Expected: %s.',
                        (string) $from,
                        $logVerb,
                        implode(' or ', $allowedFrom)
                    )
                );
            }

            $actor = workflowActorSnapshot();

            // Stamp the reviewer/approver columns only on their own transition,
            // so cancelling never rewrites who reviewed it.
            if ($to === 'reviewed') {
                $sql = 'UPDATE contributions SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                               updated_at = CURRENT_TIMESTAMP WHERE contribution_id = ?';
                $args = [$to, (int) $auth['user_id'], $id];
            } elseif ($to === 'approved') {
                $sql = 'UPDATE contributions SET status = ?, approved_by = ?, approved_at = NOW(),
                               updated_at = CURRENT_TIMESTAMP WHERE contribution_id = ?';
                $args = [$to, (int) $auth['user_id'], $id];
            } else {
                $sql = 'UPDATE contributions SET status = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE contribution_id = ?';
                $args = [$to, $id];
            }
            $pdo->prepare($sql)->execute($args);

            $hasSignature = true;
            if ($signatureAction !== '') {
                $sig = workflowCaptureSignature(
                    $pdo,
                    'contribution',
                    $id,
                    $signatureAction,
                    (int) $auth['user_id'],
                    $actor['name'],
                    $actor['role']
                );
                $hasSignature = (bool) $sig['has_signature'];
            }

            logActivity(
                'Updated',
                'Contributions',
                $actor['name'] . ' ' . $logVerb . ' Contribution #' . $id,
                'CONTRIB#' . $id
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $row = $pdo->prepare(
            'SELECT co.*, c.customer_name, c.first_name, c.last_name
               FROM contributions co
               LEFT JOIN customers c ON c.customer_id = co.member_id
              WHERE co.contribution_id = ?'
        );
        $row->execute([$id]);

        return [
            'row'           => vk_api_contrib_row($row->fetch(PDO::FETCH_ASSOC) ?: []),
            'has_signature' => $hasSignature,
        ];
    }
}
