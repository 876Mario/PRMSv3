<?php
/**
 * Legacy bypass endpoint retained only to block the old shortcut.
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$rfq_id = (int)($_GET['rfq_id'] ?? 0);
pop(
    'This shortcut is no longer available. Select a quote first, then complete requestor specification confirmation and Branch Head approval before funds verification.',
    '/rfq/view.php?id=' . $rfq_id,
    POP_DEFAULT_DELAY_MS,
    'error'
);
exit;
