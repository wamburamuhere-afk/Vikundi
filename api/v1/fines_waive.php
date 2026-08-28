<?php
/** POST /api/v1/fines/{id}/waive — forgive a fine. */
$vkFineTarget = 'waived';
require __DIR__ . '/fines_status_change.php';
