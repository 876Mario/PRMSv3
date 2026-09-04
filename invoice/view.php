<?php
$REQUIRE_PERMISSION = 'view_invoices';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

$id = $_GET['id'] ?? null;

if (!is_numeric($id) || (int)$id <= 0) {
    pop("Missing Invoice ID.", "/invoice/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}
$id = (int)$id;

// Invoice
$stmt = $pdo->prepare("
  SELECT i.*, 
         po.po_number, po.po_id AS po_ref_id, po.po_total,
         sc.contract_number, sc.contract_title, sc.contract_id AS sc_id
  FROM invoices i
  LEFT JOIN purchase_orders po ON i.po_id = po.po_id
  LEFT JOIN service_contracts sc ON i.contract_id = sc.contract_id
  WHERE i.invoice_id = ?
");
$stmt->execute([$id]);
$i = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$i) {
  pop("Invoice not found.", "/invoice/list.php");
  exit;
}

// Payments
$pay = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
$pay->execute([$id]);
$payments = $pay->fetchAll(PDO::FETCH_ASSOC);

// Attachments
$attStmt = $pdo->prepare("
    SELECT a.*, u.full_name AS uploader_name
    FROM invoice_attachments a
    LEFT JOIN users u ON a.uploaded_by = u.user_id
    WHERE a.invoice_id = ? AND a.is_deleted = 0
    ORDER BY a.uploaded_date DESC
");
$attStmt->execute([$id]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($payments, 'payment_amount'));
$balance   = (float)$i['invoice_amount'] - $totalPaid;
$paidPct   = ($i['invoice_amount'] > 0) ? min(100, round(($totalPaid / (float)$i['invoice_amount']) * 100)) : 0;

$statusMap = [
    'Paid'      => ['bg-success',   'bi-check-circle'],
    'Partial'   => ['bg-warning text-dark', 'bi-pie-chart'],
    'Unpaid'    => ['bg-danger',    'bi-exclamation-circle'],
    'Cancelled' => ['bg-secondary', 'bi-x-circle'],
];
$sBadge = $statusMap[$i['status']] ?? ['bg-dark', 'bi-question-circle'];

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="container mt-4">

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h3 class="section-title mb-1">
            <i class="bi bi-receipt me-2"></i>Invoice: <?= htmlspecialchars($i['invoice_number']) ?>
        </h3>
        <small class="text-muted">
            <?php if ($i['po_id']): ?>
            Purchase Order
            <a href="/po/view.php?po_id=<?= (int)$i['po_id'] ?>" class="text-decoration-none fw-semibold">
                <?= htmlspecialchars($i['po_number']) ?>
            </a>
            <?php elseif ($i['contract_number']): ?>
            Service Contract
            <a href="/contracts/view.php?id=<?= (int)$i['sc_id'] ?>" class="text-decoration-none fw-semibold">
                <?= htmlspecialchars($i['contract_number']) ?>
            </a>
            — <?= htmlspecialchars($i['contract_title'] ?? '') ?>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <span class="badge <?= $sBadge[0] ?> fs-6">
            <i class="bi <?= $sBadge[1] ?> me-1"></i><?= htmlspecialchars($i['status']) ?>
        </span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI METRIC CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-gold h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Invoice Amount</small>
                <h3 class="mb-0 fw-bold"><?= money((float)$i['invoice_amount']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-green h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Total Paid</small>
                <h3 class="mb-0 fw-bold"><?= money($totalPaid) ?></h3>
                <small class="text-muted"><?= $paidPct ?>% paid</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Outstanding</small>
                <h3 class="mb-0 fw-bold" style="color:#1a237e;"><?= money($balance) ?></h3>
                <small class="text-muted"><?= (100 - $paidPct) ?>% remaining</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">Payments</small>
                <h3 class="mb-0 fw-bold" style="color:#880e4f;"><?= count($payments) ?></h3>
                <small class="text-muted">transaction<?= count($payments) !== 1 ? 's' : '' ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Payment Progress Bar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <small class="fw-bold text-muted">Payment Progress</small>
            <small class="fw-bold"><?= $paidPct ?>%</small>
        </div>
        <div class="progress" style="height: 8px; border-radius: 4px;">
            <div class="progress-bar <?= $paidPct === 100 ? 'bg-success' : ($paidPct > 50 ? 'bg-primary' : 'bg-warning') ?>"
                 style="width: <?= $paidPct ?>%; transition: width 0.6s ease;"
                 role="progressbar"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     INVOICE DETAILS + ACTIONS (TWO-COLUMN)
═══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <!-- Invoice Information -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Invoice Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Invoice Number</label>
                        <p class="mb-0 fw-semibold fs-5"><?= htmlspecialchars($i['invoice_number']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Invoice Date</label>
                        <p class="mb-0"><?= !empty($i['invoice_date']) ? date('d M Y', strtotime($i['invoice_date'])) : '&mdash;' ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0"><?= $i['po_id'] ? 'Purchase Order' : 'Service Contract' ?></label>
                        <p class="mb-0">
                            <?php if ($i['po_id']): ?>
                            <a href="/po/view.php?po_id=<?= (int)$i['po_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($i['po_number']) ?>
                            </a>
                            <?php elseif ($i['contract_number']): ?>
                            <a href="/contracts/view.php?id=<?= (int)$i['sc_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($i['contract_number']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0"><?= $i['po_id'] ? 'PO Total' : 'Contract' ?></label>
                        <p class="mb-0 fw-semibold"><?= $i['po_id'] ? money((float)$i['po_total']) : htmlspecialchars($i['contract_title'] ?? '—') ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Invoice Amount</label>
                        <p class="mb-0 fs-5 fw-bold text-success"><?= money((float)$i['invoice_amount']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Status</label>
                        <p class="mb-0">
                            <span class="badge <?= $sBadge[0] ?>">
                                <i class="bi <?= $sBadge[1] ?> me-1"></i><?= htmlspecialchars($i['status']) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions</h5>
            </div>
            <div class="card-body">
                <?php if ($balance > 0): ?>
                <div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light border mb-3">
                    <i class="bi bi-cash-stack fs-4 text-warning"></i>
                    <div>
                        <strong class="d-block small">Next Step</strong>
                        <span class="small"><?= money($balance) ?> outstanding — record payment</span>
                    </div>
                </div>
                <?php elseif ($balance <= 0): ?>
                <div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-success bg-opacity-10 border border-success mb-3">
                    <i class="bi bi-check-circle fs-4 text-success"></i>
                    <div>
                        <strong class="d-block small">Fully Paid</strong>
                        <span class="small text-muted">All payments received</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <?php if ($balance > 0 && has_permission('create_payment')): ?>
                        <a href="/payment/add.php?invoice_id=<?= (int)$i['invoice_id'] ?>" class="btn btn-success">
                            <i class="bi bi-plus-circle me-1"></i>Add Payment
                        </a>
                    <?php endif; ?>

                    <a href="/reports/print_invoice.php?invoice_id=<?= $id ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-printer me-1"></i>Print Invoice PDF
                    </a>

                    <?php if ($i['po_id']): ?>
                    <a href="/po/view.php?po_id=<?= (int)$i['po_id'] ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-ruled me-1"></i>View Purchase Order
                    </a>
                    <?php elseif ($i['sc_id']): ?>
                    <a href="/contracts/view.php?id=<?= (int)$i['sc_id'] ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-text me-1"></i>View Contract
                    </a>
                    <?php endif; ?>

                    <a href="<?= auditUrl('invoices', $i['invoice_id']) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-journal-text me-1"></i>Audit Trail
                    </a>
                    <?php if (has_permission('upload_invoice_attachment')): ?>
                    <a href="#attachments-section" class="btn btn-outline-dark">
                        <i class="bi bi-paperclip me-1"></i>Manage Attachments
                        <?php if (count($attachments) > 0): ?>
                        <span class="badge bg-secondary ms-1"><?= count($attachments) ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PAYMENTS TABLE
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Payment History</h5>
        <span class="badge bg-light text-dark"><?= count($payments) ?> payment<?= count($payments) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa; color: #000;">
                        <th class="ps-3"><i class="bi bi-calendar-event me-1"></i>Date</th>
                        <th><i class="bi bi-hash me-1"></i>Reference</th>
                        <th class="text-end"><i class="bi bi-currency-dollar me-1"></i>Amount</th>
                        <th class="text-center pe-3" style="width:90px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-4">
                            <i class="bi bi-inbox text-muted fs-1"></i>
                            <p class="text-muted mt-2 mb-0">No payments recorded for this invoice.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="ps-3"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td>
                                <span class="badge bg-info text-dark"><?= htmlspecialchars($p['payment_reference']) ?></span>
                            </td>
                            <td class="text-end fw-semibold text-success"><?= money((float)$p['payment_amount']) ?></td>
                            <td class="text-center pe-3">
                                <a href="/payment/view.php?id=<?= (int)$p['payment_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View payment & vouchers">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Total row -->
                        <tr class="table-dark">
                            <td class="ps-3 fw-bold" colspan="2">
                                <i class="bi bi-calculator me-1"></i>Total Paid
                            </td>
                            <td class="text-end fw-bold text-white"><?= money($totalPaid) ?></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     INVOICE ATTACHMENTS
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4" id="attachments-section">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Attachments</h5>
        <span class="badge bg-light text-dark"><?= count($attachments) ?> file<?= count($attachments) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body">

        <?php if (has_permission('upload_invoice_attachment')): ?>
        <form method="post" action="/invoice/upload_attachment.php" enctype="multipart/form-data" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <input type="hidden" name="invoice_id" value="<?= $id ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small fw-bold text-muted mb-1">Upload Invoice Document</label>
                    <input type="file" name="attachment_file" class="form-control form-control-sm"
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <small class="text-muted">Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX &mdash; Max 10 MB</small>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark btn-sm w-100">
                        <i class="bi bi-upload me-1"></i>Upload Invoice
                    </button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php if (empty($attachments)): ?>
        <div class="text-center py-4">
            <i class="bi bi-folder2-open text-muted fs-1"></i>
            <p class="text-muted mt-2 mb-0">No attachments uploaded for this invoice.</p>
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
                                <a href="/invoice/download_attachment.php?id=<?= (int)$att['id'] ?>&action=view"
                                   class="btn btn-outline-primary" target="_blank" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php endif; ?>
                                <a href="/invoice/download_attachment.php?id=<?= (int)$att['id'] ?>&action=download"
                                   class="btn btn-outline-secondary" title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                                <?php if (has_permission('delete_invoice_attachment')): ?>
                                <form method="post" action="/invoice/delete_attachment.php" class="d-inline"
                                      onsubmit="return confirm('Delete this attachment? This cannot be undone.')">
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
    <a href="/invoice/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Invoices
    </a>
    <a href="javascript:history.back()" class="btn btn-outline-secondary">
        Cancel
    </a>
</div>

</div><!-- /.container -->

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
