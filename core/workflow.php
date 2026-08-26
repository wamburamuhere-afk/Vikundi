<?php
/**
 * Three-Approval Workflow Helpers — Vikundi
 * pending → reviewed → approved
 */

if (!function_exists('assertReviewable')) {
    function assertReviewable($status)
    {
        if ($status !== 'pending') {
            throw new Exception('Only a pending document can be reviewed (current: ' . $status . ').');
        }
    }
}

if (!function_exists('assertApprovable')) {
    function assertApprovable($status)
    {
        if ($status !== 'reviewed') {
            throw new Exception('Only a reviewed document can be approved (current: ' . $status . ').');
        }
    }
}

if (!function_exists('canEditDocument')) {
    function canEditDocument($status, $isAdmin)
    {
        if ($isAdmin) return true;
        return $status !== 'approved';
    }
}

if (!function_exists('workflowActorSnapshot')) {
    /**
     * Returns ['name' => ..., 'role' => ...] for the signed-in user — the person
     * whose name goes onto an e-signature and into the approval trail.
     *
     * WHY THIS NO LONGER READS $username. It used to open with:
     *
     *     global $pdo, $username, $user_role;
     *     $name = !empty($username) ? $username : '';
     *
     * on the assumption that $username is the value header.php:34 sets from the
     * users table. It is not the only thing that sets it: includes/config.php:7
     * declares `$username = '...'` for the PDO connection, and config.php is
     * included by every endpoint. On any path that does NOT go through
     * header.php — which is every AJAX endpoint under api/, every handler under
     * actions/, and the whole mobile API — the global still holds the DATABASE
     * user, and !empty() accepted it.
     *
     * The result: workflow_signatures recorded the database account as the
     * approver. Every signature row in the local database read "vikundi"; on the
     * server it would read the production DB user. The three-approval rule the
     * group's books rest on was recording, for each step, a name that identifies
     * nobody — and it looked completely normal in the UI.
     *
     * So the session is now the only input. It is what the endpoints have already
     * authenticated, it cannot be confused with a connection string, and the
     * lookup is one row by primary key. The globals are gone rather than merely
     * reordered: a fallback to them would restore the same bug the moment the
     * query missed.
     */
    function workflowActorSnapshot(): array
    {
        global $pdo;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 || !$pdo) {
            return ['name' => 'System', 'role' => 'System'];
        }

        // LEFT JOIN, not JOIN: a user whose role_id no longer matches a roles row
        // must still be named. The inner join silently returned nothing, which
        // sent the caller to the fallback and lost the person entirely.
        $stmt = $pdo->prepare(
            'SELECT TRIM(CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name)) AS full_name,
                    u.username, r.role_name
               FROM users u
               LEFT JOIN roles r ON u.role_id = r.role_id
              WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['name' => 'System', 'role' => 'System'];
        }

        return [
            'name' => trim((string) $row['full_name']) ?: (string) $row['username'],
            'role' => (string) ($row['role_name'] ?? '') ?: 'Member',
        ];
    }
}

if (!function_exists('workflowCaptureSignature')) {
    /**
     * Records the actor's e-signature against a workflow action.
     * Inserts or updates `workflow_signatures`.
     * Returns ['sig_path' => string|null, 'has_signature' => bool].
     */
    function workflowCaptureSignature(
        PDO    $pdo,
        string $entityType,
        int    $entityId,
        string $action,
        int    $userId,
        string $userName,
        string $userRole
    ): array {
        $sig = $pdo->prepare(
            'SELECT file_path FROM user_signatures
              WHERE user_id = ? AND status = "active"
              ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $sig->execute([$userId]);
        $sigPath = $sig->fetchColumn() ?: null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $pdo->prepare(
            'INSERT INTO workflow_signatures
               (entity_type, entity_id, action, user_id, user_name, user_role, sig_path, ip_address, consent_accepted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
               user_id   = VALUES(user_id),
               user_name = VALUES(user_name),
               user_role = VALUES(user_role),
               sig_path  = VALUES(sig_path),
               signed_at = CURRENT_TIMESTAMP,
               ip_address= VALUES(ip_address)'
        )->execute([$entityType, $entityId, $action, $userId, $userName, $userRole, $sigPath, $ip]);

        return ['sig_path' => $sigPath, 'has_signature' => ($sigPath !== null)];
    }
}

if (!function_exists('getWorkflowSignatures')) {
    /**
     * Returns captured signature rows keyed by action.
     * ['created' => [...], 'reviewed' => [...], 'approved' => [...]]
     */
    function getWorkflowSignatures(PDO $pdo, string $entityType, int $entityId): array
    {
        $blank  = ['user_name' => '', 'user_role' => '', 'sig_path' => null, 'signed_at' => null];
        $result = ['created' => $blank, 'reviewed' => $blank, 'approved' => $blank];

        $stmt = $pdo->prepare(
            'SELECT action, user_name, user_role, sig_path, signed_at
               FROM workflow_signatures
              WHERE entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$entityType, $entityId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['action']] = $row;
        }
        return $result;
    }
}

if (!function_exists('workflowStatusBadge')) {
    function workflowStatusBadge(string $status): string
    {
        $map = [
            'pending'  => 'warning',
            'reviewed' => 'info',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled'=> 'secondary',
            'draft'    => 'secondary',
        ];
        $cls = $map[$status] ?? 'secondary';
        return '<span class="badge bg-' . $cls . '">' . ucfirst($status) . '</span>';
    }
}
