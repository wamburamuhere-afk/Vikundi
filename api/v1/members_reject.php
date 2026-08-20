<?php
/**
 * POST /api/v1/members/{id}/reject
 *
 * Sets the member's status to 'rejected'. See api/v1/members_status_change.php
 * for the shared implementation and the permission rules.
 */

$vkTargetStatus = 'rejected';
$vkAuditVerb    = 'Rejected';

require __DIR__ . '/members_status_change.php';
