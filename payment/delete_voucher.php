<?php
/**
 * Payment Voucher Delete Handler
 */
$REQUIRE_PERMISSION = 'delete_payment_voucher';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /payment/list.php');
    exit;
}

$id = (int)($_POST['attachment_id'] ?? 0);
if ($id <= 0) {
    modalPop('Error', 'Invalid attachment reference.', '/payment/list.php', 'error');
    exit;
}

// Fetch attachment
$stmt = $pdo->prepare("
    SELECT a.*, p.payment_reference
    FROM payment_voucher_attachments a
    JOIN payments p ON a.payment_id = p.payment_id
    WHERE a.id = ? AND a.is_deleted = 0
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    modalPop('Error', 'Attachment not found.', '/payment/list.php', 'error');
    exit;
}

// Soft-delete
$upd = $pdo->prepare("UPDATE payment_voucher_attachments SET is_deleted = 1 WHERE id = ?");
$upd->execute([$id]);

logAudit($pdo, 'payment_voucher_attachments', $id, 'DELETE',
    "Payment voucher deleted: {$att['original_file_name']} (Payment #{$att['payment_reference']})");

pop('Payment voucher deleted.', "/payment/view.php?id={$att['payment_id']}", POP_DEFAULT_DELAY_MS, 'success');
