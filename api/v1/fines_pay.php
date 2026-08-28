<?php
/** POST /api/v1/fines/{id}/pay — record that a fine was paid. */
$vkFineTarget = 'paid';
require __DIR__ . '/fines_status_change.php';
