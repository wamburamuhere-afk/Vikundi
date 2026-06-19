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
     * Returns ['name' => ..., 'role' => ...] for the logged-in user.
     * Reads $username / $user_role set by header.php from DB.
     * Falls back to a direct DB query when those vars are absent (e.g. API calls).
     */
    function workflowActorSnapshot(): array
    {
        global $pdo, $username, $user_role;

        $name = !empty($username) ? $username : '';
        $role = !empty($user_role) ? $user_role : '';

        if (($name === '' || $role === '') && !empty($_SESSION['user_id']) && $pdo) {
            $stmt = $pdo->prepare(
                'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name,
                        username, r.role_name
                   FROM users u
                   JOIN roles r ON u.role_id = r.role_id
                  WHERE u.user_id = ?'
            );
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($name === '') $name = trim($row['full_name']) ?: $row['username'];
                if ($role === '') $role = $row['role_name'] ?? 'Member';
            }
        }

        return [
            'name' => $name ?: ($_SESSION['username'] ?? 'System'),
            'role' => $role ?: 'Member',
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
