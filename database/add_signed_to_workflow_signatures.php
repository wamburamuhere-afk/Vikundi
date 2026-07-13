<?php
/**
 * database/add_signed_to_workflow_signatures.php
 * ----------------------------------------------
 * Adds a 'signed' value to workflow_signatures.action so an authored document
 * (entity_type = 'authored_document') can carry an authoritative e-signature via
 * the existing workflowCaptureSignature() flow — reusing the same signature
 * images (user_signatures) as every other signed record.
 *
 * Idempotent: only alters the enum when 'signed' is missing. Safe to re-run.
 *
 * Run manually:  php database/add_signed_to_workflow_signatures.php
 */

require_once __DIR__ . '/../includes/config.php';

$exists = $pdo->query("SHOW TABLES LIKE 'workflow_signatures'")->fetchColumn();
if (!$exists) {
    echo "workflow_signatures not present yet; skipped.\n";
    return;
}

$col  = $pdo->query("SHOW COLUMNS FROM workflow_signatures LIKE 'action'")->fetch(PDO::FETCH_ASSOC);
$type = $col['Type'] ?? '';

if ($type && stripos($type, "'signed'") === false) {
    $pdo->exec("ALTER TABLE workflow_signatures
                MODIFY COLUMN action ENUM('created','reviewed','approved','signed')
                COLLATE utf8mb4_unicode_ci NOT NULL");
    echo "workflow_signatures.action: added 'signed'.\n";
} else {
    echo "workflow_signatures.action: 'signed' already present.\n";
}
