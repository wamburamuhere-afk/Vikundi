<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Applies the user's e-signature to a document: resolves any pending request and records
// the signing event. Vikundi schema: signature_documents (request state) + signature_history (event).
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!canCreate('documents') && !canEdit('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to apply signatures to documents');
    }

    $document_id  = intval($_POST['document_id']  ?? 0);
    $signature_id = intval($_POST['signature_id'] ?? 0);
    $position     = $_POST['signature_position']  ?? 'bottom_right';

    if (!$document_id || !$signature_id) {
        throw new Exception('Document and signature are required');
    }

    $allowed_positions = ['bottom_right', 'bottom_left', 'bottom_center', 'custom'];
    if (!in_array($position, $allowed_positions, true)) {
        throw new Exception('Invalid signature position');
    }

    // The signature must belong to the current user and be active.
    $stmt = $pdo->prepare("SELECT id FROM user_signatures WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$signature_id, (int) $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Signature not found or not active');
    }

    // The document must exist.
    $stmt = $pdo->prepare("SELECT id FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Document not found');
    }

    $userId = (int) $_SESSION['user_id'];
    $ip     = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $pdo->beginTransaction();

    // If there is a pending request for this document assigned to this user, mark it signed.
    $stmt = $pdo->prepare("
        SELECT id FROM signature_documents
        WHERE document_id = ? AND signatory_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$document_id, $userId]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $pdo->prepare("UPDATE signature_documents SET status = 'signed', signed_at = NOW() WHERE id = ?")
            ->execute([$pending['id']]);
    } else {
        // Self-initiated signing: record a completed request row.
        $pdo->prepare("
            INSERT INTO signature_documents (document_id, signatory_id, requested_by, status, signed_at)
            VALUES (?, ?, ?, 'signed', NOW())
        ")->execute([$document_id, $userId, $userId]);
    }

    // Record the immutable signing event (who, what, where, when).
    $pdo->prepare("
        INSERT INTO signature_history (user_id, document_id, signature_id, signature_position, ip_address, user_agent, signed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ")->execute([$userId, $document_id, $signature_id, $position, $ip, $ua]);

    $pdo->commit();

    if (function_exists('logActivity')) {
        logActivity('Updated', 'E-Signatures', "Applied an e-signature to document ID: $document_id", 'DOC#' . $document_id, $userId);
    }

    echo json_encode(['success' => true, 'message' => 'Signature applied successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
