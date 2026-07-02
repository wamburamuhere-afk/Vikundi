<?php
// database/backfill_document_relations.php
//
// One-off: give existing Document Library rows the structured link
// (related_type/related_id) that new uploads now carry, so older expense
// receipts show up on the expense detail views via the exact link.
//
//   General expenses  — exact: the description carries "(Expense ID: N)".
//   Death expenses    — NOT backfilled: historical death docs are keyed only by
//                       member id and can't be pinned to one death record, so
//                       they are shown via the legacy fallback instead.
//
// Idempotent: only fills rows where related_id IS NULL. Safe to re-run.
//
// Usage:  php database/backfill_document_relations.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/expense_attachments.php';

$rows = $pdo->query("
    SELECT id, description
      FROM documents
     WHERE related_id IS NULL
       AND description LIKE '%Expense ID:%'
")->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE documents SET related_type = 'general_expense', related_id = ? WHERE id = ?");

$updated = 0;
foreach ($rows as $r) {
    $expId = vk_parse_expense_id_from_description($r['description']);
    if ($expId) {
        $upd->execute([$expId, $r['id']]);
        $updated++;
    }
}

fwrite(STDOUT, "Backfilled structured link for {$updated} general-expense document(s).\n");
fwrite(STDOUT, "Death-expense documents are shown via the legacy member-id fallback (not backfilled).\n");
