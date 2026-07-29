<?php
$REQUIRE_PERMISSION = 'view_payments';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

$id = $_GET['id'] ?? null;

if (!is_numeric($id) || (int)$id <= 0) {
    pop("Missing Payment ID.", "/payment/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}
$id = (int)$id;

// Payment + related data
$stmt = $pdo->prepare("
    SELECT p.*,
           i.invoice_number, i.invoice_amount, i.invoice_date, i.invoice_id,
           COALESCE(po.po_number, sc.contract_number) AS source_number,
           COALESCE(po.po_id, NULL) AS po_id,
           po.po_total,
           sc.contract_id AS sc_id,
           sc.contract_title,
           u.full_name AS entered_by_name
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.invoice_id
    LEFT JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN service_contracts sc ON i.contract_id = sc.contract_id
    LEFT JOIN users u ON p.created_by = u.user_id
    WHERE p.payment_id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    pop("Payment not found.", "/payment/list.php");
    exit;
}

// Payment voucher attachments
$attStmt = $pdo->prepare("
    SELECT a.*, u.full_name AS uploader_name
    FROM payment_voucher_attachments a
    LEFT JOIN users u ON a.uploaded_by = u.user_id
    WHERE a.payment_id = ? AND a.is_deleted = 0
    ORDER BY a.uploaded_date DESC
");
$attStmt->execute([$id]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="container mt-4">

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h3 class="section-title mb-1">
            <i class="bi bi-cash-stack me-2"></i>Payment: <?= htmlspecialchars($p['payment_reference']) ?>
        </h3>
        <small class="text-muted">
            Invoice
            <a href="/invoice/view.php?id=<?= (int)$p['invoice_id'] ?>" class="text-decoration-none fw-semibold">
                <?= htmlspecialchars($p['invoice_number']) ?>
            </a>
            <?php if ($p['source_number']): ?>
            &mdash; <?= htmlspecialchars($p['source_number']) ?>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-success fs-6">
            <i class="bi bi-check-circle me-1"></i>Payment Recorded
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-green h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Payment Amount</small>
                <h3 class="mb-0 fw-bold"><?= money((float)$p['payment_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Invoice Amount</small>
                <h3 class="mb-0 fw-bold" style="color:#1a237e;"><?= money((float)$p['invoice_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">Vouchers</small>
                <h3 class="mb-0 fw-bold" style="color:#880e4f;"><?= count($attachments) ?></h3>
                <small class="text-muted">attachment<?= count($attachments) !== 1 ? 's' : '' ?></small>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PAYMENT DETAILS
═══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Payment Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Payment Reference</label>
                        <p class="mb-0 fw-semibold fs-5">
                            <span class="badge bg-info text-dark"><?= htmlspecialchars($p['payment_reference']) ?></span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Payment Date</label>
                        <p class="mb-0"><?= !empty($p['payment_date']) ? date('d M Y', strtotime($p['payment_date'])) : '&mdash;' ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Payment Amount</label>
                        <p class="mb-0 fs-5 fw-bold text-success"><?= money((float)$p['payment_amount']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Entered By</label>
                        <p class="mb-0"><?= htmlspecialchars($p['entered_by_name'] ?? '&mdash;') ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Invoice</label>
                        <p class="mb-0">
                            <a href="/invoice/view.php?id=<?= (int)$p['invoice_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($p['invoice_number']) ?>
                            </a>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Invoice Date</label>
                        <p class="mb-0"><?= !empty($p['invoice_date']) ? date('d M Y', strtotime($p['invoice_date'])) : '&mdash;' ?></p>
                    </div>
                    <?php if ($p['source_number']): ?>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0"><?= $p['po_id'] ? 'Purchase Order' : 'Service Contract' ?></label>
                        <p class="mb-0">
                            <?php if ($p['po_id']): ?>
                            <a href="/po/view.php?po_id=<?= (int)$p['po_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($p['source_number']) ?>
                            </a>
                            <?php elseif ($p['sc_id']): ?>
                            <a href="/contracts/view.php?id=<?= (int)$p['sc_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($p['source_number']) ?>
                            </a>
                            <?php else: ?>
                            <?= htmlspecialchars($p['source_number']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Recorded At</label>
                        <p class="mb-0 text-muted small"><?= !empty($p['created_at']) ? date('d M Y, g:i A', strtotime($p['created_at'])) : '&mdash;' ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions</h5>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="/invoice/view.php?id=<?= (int)$p['invoice_id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-receipt me-1"></i>View Invoice
                </a>
                <?php if ($p['po_id']): ?>
                <a href="/po/view.php?po_id=<?= (int)$p['po_id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-ruled me-1"></i>View Purchase Order
                </a>
                <?php elseif ($p['sc_id']): ?>
                <a href="/contracts/view.php?id=<?= (int)$p['sc_id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-text me-1"></i>View Contract
                </a>
                <?php endif; ?>
                <a href="<?= auditUrl('payments', $id) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-journal-text me-1"></i>Audit Trail
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PAYMENT VOUCHER ATTACHMENTS
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Payment Voucher Attachments</h5>
        <span class="badge bg-light text-dark"><?= count($attachments) ?> file<?= count($attachments) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body">

        <?php if (has_permission('upload_payment_voucher')): ?>
        <form method="post" action="/payment/upload_voucher.php" enctype="multipart/form-data" class="mb-4">
            <input type="hidden" name="payment_id" value="<?= $id ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small fw-bold text-muted mb-1">Upload Payment Voucher</label>
                    <input type="file" name="voucher_file" class="form-control form-control-sm"
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <small class="text-muted">Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX &mdash; Max 10 MB</small>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark btn-sm w-100">
                        <i class="bi bi-upload me-1"></i>Upload Payment Voucher
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if (empty($attachments)): ?>
        <div class="text-center py-4">
            <i class="bi bi-folder2-open text-muted fs-1"></i>
            <p class="text-muted mt-2 mb-0">No payment vouchers uploaded for this payment.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa; color: #000;">
                        <th class="ps-3"><i class="bi bi-file-earmark me-1"></i>File Name</th>
                        <th><i class="bi bi-person me-1"></i>Uploaded By</th>
                        <th><i class="bi bi-calendar me-1"></i>Upload Date</th>
                        <th><i class="bi bi-hdd me-1"></i>Size</th>
                        <th class="text-center pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attachments as $att): ?>
                    <tr>
                        <td class="ps-3">
                            <i class="bi bi-file-earmark-<?= strpos($att['file_type'], 'pdf') !== false ? 'pdf text-danger' : (strpos($att['file_type'], 'image') !== false ? 'image text-primary' : 'word text-info') ?> me-1"></i>
                            <?= htmlspecialchars($att['original_file_name']) ?>
                        </td>
                        <td><?= htmlspecialchars($att['uploader_name'] ?? '—') ?></td>
                        <td><?= date('d M Y, g:i A', strtotime($att['uploaded_date'])) ?></td>
                        <td><?= number_format($att['file_size'] / 1024, 1) ?> KB</td>
                        <td class="text-center pe-3">
                            <div class="btn-group btn-group-sm">
                                <?php if (in_array($att['file_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)): ?>
                                <a href="/payment/download_voucher.php?id=<?= (int)$att['id'] ?>&action=view"
                                   class="btn btn-outline-primary" target="_blank" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php endif; ?>
                                <a href="/payment/download_voucher.php?id=<?= (int)$att['id'] ?>&action=download"
                                   class="btn btn-outline-secondary" title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                                <?php if (has_permission('delete_payment_voucher')): ?>
                                <form method="post" action="/payment/delete_voucher.php" class="d-inline"
                                      onsubmit="return confirm('Delete this voucher? This cannot be undone.')">
                                    <input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     NAVIGATION
═══════════════════════════════════════════════════════ -->
<div class="d-flex gap-2 mb-4">
    <a href="/payment/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Payments
    </a>
    <a href="/invoice/view.php?id=<?= (int)$p['invoice_id'] ?>" class="btn btn-outline-secondary">
        <i class="bi bi-receipt me-1"></i>Back to Invoice
    </a>
</div>

</div><!-- /.container -->

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
