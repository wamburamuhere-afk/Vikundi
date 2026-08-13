<?php
/**
 * Group Statement of Transactions — every member's money in the month it arrived.
 * Thin by design: the document lives in includes/group_statement.php, which both
 * group statements share.
 */
ob_start();
date_default_timezone_set('Africa/Nairobi');
require_once HEADER_FILE;

$vk_statement_type = 'transactions';
require ROOT_DIR . '/includes/group_statement.php';

$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
