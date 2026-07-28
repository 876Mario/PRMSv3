<?php
$REQUIRE_PERMISSION = 'view_contracts';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($contract_id <= 0) {
    pop('Invalid contract reference.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===============================
   Fetch Contract
================================ */
$stmt = $pdo->prepare("
    SELECT sc.*, v.vendor_name, v.email AS vendor_email, v.phone AS vendor_phone,
           b.branch_name, u.full_name AS created_by_name
    FROM service_contracts sc
    JOIN vendors v ON sc.vendor_id = v.vendor_id
    JOIN branches b ON sc.branch_id = b.branch_id
    LEFT JOIN users u ON sc.created_by = u.user_id
    WHERE sc.contract_id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    pop('Contract not found.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===============================
   Fetch related requests/invoices
================================ */
$requestsStmt = $pdo->prepare("
    SELECT pr.request_id, pr.request_number, pr.status, pr.estimated_value, pr.created_at
    FROM procurement_requests pr
    WHERE pr.contract_id = ?
    ORDER BY pr.created_at DESC
");
$requestsStmt->execute([$contract_id]);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch invoices linked to this contract */
$invoicesStmt = $pdo->prepare("
    SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.invoice_amount, i.status
    FROM invoices i
    WHERE i.contract_id = ?
    ORDER BY i.invoice_date DESC
");
$invoicesStmt->execute([$contract_id]);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

/* Calculate totals */
$totalInvoiced = array_sum(array_column($invoices, 'invoice_amount'));
$utilPct = $contract['total_value'] > 0 ? min(100, ($contract['consumed_value'] / $contract['total_value']) * 100) : 0;
$remaining = (float)$contract['total_value'] - (float)$contract['consumed_value'];

/* ===============================
   Handle Status Change (POST)
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('manage_contracts')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'activate' && $contract['status'] === 'DRAFT') {
        $pdo->prepare("UPDATE service_contracts SET status = 'ACTIVE' WHERE contract_id = ?")->execute([$contract_id]);
        logAudit($pdo, 'service_contracts', $contract_id, 'ACTIVATE', 'Contract activated');
        header("Location: /contracts/view.php?id=$contract_id");
        exit;
    }

    if ($action === 'suspend' && $contract['status'] === 'ACTIVE') {
        $pdo->prepare("UPDATE service_contracts SET status = 'SUSPENDED' WHERE contract_id = ?")->execute([$contract_id]);
        logAudit($pdo, 'service_contracts', $contract_id, 'SUSPEND', 'Contract suspended');
        header("Location: /contracts/view.php?id=$contract_id");
        exit;
    }

    if ($action === 'reactivate' && $contract['status'] === 'SUSPENDED') {
        $pdo->prepare("UPDATE service_contracts SET status = 'ACTIVE' WHERE contract_id = ?")->execute([$contract_id]);
        logAudit($pdo, 'service_contracts', $contract_id, 'REACTIVATE', 'Contract reactivated');
        header("Location: /contracts/view.php?id=$contract_id");
        exit;
    }

    if ($action === 'terminate' && in_array($contract['status'], ['ACTIVE', 'SUSPENDED'])) {
        $pdo->prepare("UPDATE service_contracts SET status = 'TERMINATED' WHERE contract_id = ?")->execute([$contract_id]);
        logAudit($pdo, 'service_contracts', $contract_id, 'TERMINATE', 'Contract terminated');
        header("Location: /contracts/view.php?id=$contract_id");
        exit;
    }
}

$statusColors = [
    'ACTIVE' => '#4caf50', 'DRAFT' => '#ff9800', 'EXPIRED' => '#9e9e9e',
    'TERMINATED' => '#f44336', 'SUSPENDED' => '#ff5722'
];
$statusColor = $statusColors[$contract['status']] ?? '#9e9e9e';

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="mb-5">

<!-- PAGE HEADER -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">
            📄 <?= htmlspecialchars($contract['contract_number']) ?>
        </h2>
        <p class="text-muted mb-0"><?= htmlspecialchars($contract['contract_title']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge" style="background-color: <?= $statusColor ?>; color: white; padding: 0.5rem 1rem; font-size: 0.9rem;">
            <?= htmlspecialchars($contract['status']) ?>
        </span>
        <a href="/contracts/list.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    </div>
</div>

<!-- KPI CARDS -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body">
                <p class="mb-1 small" style="opacity: 0.9;">Contract Value</p>
                <h4 class="mb-0" style="font-weight: 700;"><?= money((float)$contract['total_value']) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
            <div class="card-body">
                <p class="mb-1 small" style="opacity: 0.9;">Consumed</p>
                <h4 class="mb-0" style="font-weight: 700;"><?= money((float)$contract['consumed_value']) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
            <div class="card-body">
                <p class="mb-1 small" style="opacity: 0.9;">Remaining</p>
                <h4 class="mb-0" style="font-weight: 700;"><?= money($remaining) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
            <div class="card-body">
                <p class="mb-1 small" style="opacity: 0.9;">Utilization</p>
                <h4 class="mb-0" style="font-weight: 700;"><?= number_format($utilPct, 1) ?>%</h4>
            </div>
        </div>
    </div>
</div>

<!-- CONTRACT DETAILS -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h6 class="mb-0" style="font-weight: 600;">📋 Contract Details</h6>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr><td class="text-muted" style="width: 40%;">Vendor</td><td><strong><?= htmlspecialchars($contract['vendor_name']) ?></strong></td></tr>
                    <tr><td class="text-muted">Department</td><td><?= htmlspecialchars($contract['branch_name']) ?></td></tr>
                    <tr><td class="text-muted">Contract Type</td><td><?= htmlspecialchars(str_replace('_', ' ', $contract['contract_type'])) ?></td></tr>
                    <tr><td class="text-muted">Currency</td><td><?= htmlspecialchars($contract['currency']) ?></td></tr>
                    <tr><td class="text-muted">Start Date</td><td><?= date('d M Y', strtotime($contract['start_date'])) ?></td></tr>
                    <tr><td class="text-muted">End Date</td><td><?= date('d M Y', strtotime($contract['end_date'])) ?></td></tr>
                    <tr><td class="text-muted">Payment Terms</td><td><?= (int)$contract['payment_terms'] ?> days</td></tr>
                    <tr><td class="text-muted">Billing Frequency</td><td><?= htmlspecialchars(str_replace('_', ' ', $contract['billing_frequency'])) ?></td></tr>
                    <?php if ($contract['description']): ?>
                    <tr><td class="text-muted">Description</td><td><?= nl2br(htmlspecialchars($contract['description'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['notes']): ?>
                    <tr><td class="text-muted">Notes</td><td><?= nl2br(htmlspecialchars($contract['notes'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($contract['document_path']): ?>
                    <tr><td class="text-muted">Document</td><td><a href="<?= htmlspecialchars($contract['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>View Document</a></td></tr>
                    <?php endif; ?>
                    <tr><td class="text-muted">Created By</td><td><?= htmlspecialchars($contract['created_by_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">Created</td><td><?= formatJamaicanDateTime($contract['created_at']) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Utilization Progress -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h6 class="mb-0" style="font-weight: 600;">📊 Contract Utilization</h6>
            </div>
            <div class="card-body">
                <div class="progress mb-2" style="height: 20px; border-radius: 10px;">
                    <div class="progress-bar <?= $utilPct > 90 ? 'bg-danger' : ($utilPct > 70 ? 'bg-warning' : 'bg-success') ?>"
                         style="width: <?= number_format($utilPct, 1) ?>%; font-weight: 600;">
                        <?= number_format($utilPct, 1) ?>%
                    </div>
                </div>
                <div class="row text-center mt-3">
                    <div class="col-4">
                        <small class="text-muted d-block">Total Value</small>
                        <strong><?= money((float)$contract['total_value']) ?></strong>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block">Used</small>
                        <strong class="text-danger"><?= money((float)$contract['consumed_value']) ?></strong>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block">Available</small>
                        <strong class="text-success"><?= money($remaining) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ACTIONS & HISTORY -->
    <div class="col-lg-5">
        <!-- Actions -->
        <?php if (has_permission('manage_contracts') || has_permission('create_service_request')): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h6 class="mb-0" style="font-weight: 600;">⚡ Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if ($contract['status'] === 'ACTIVE' && has_permission('create_service_request')): ?>
                    <a href="/contracts/request.php?contract_id=<?= $contract_id ?>" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i>New Payment Request
                    </a>
                    <?php endif; ?>

                    <?php if ($contract['status'] === 'DRAFT'): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="activate">
                        <button type="submit" class="btn btn-success w-100" onclick="return confirm('Activate this contract?')">
                            <i class="bi bi-check-circle me-1"></i>Activate Contract
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($contract['status'] === 'ACTIVE'): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="suspend">
                        <button type="submit" class="btn btn-warning w-100" onclick="return confirm('Suspend this contract?')">
                            <i class="bi bi-pause-circle me-1"></i>Suspend Contract
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($contract['status'] === 'SUSPENDED'): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="reactivate">
                        <button type="submit" class="btn btn-success w-100" onclick="return confirm('Reactivate this contract?')">
                            <i class="bi bi-play-circle me-1"></i>Reactivate Contract
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if (in_array($contract['status'], ['ACTIVE', 'SUSPENDED'])): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="terminate">
                        <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Terminate this contract? This cannot be undone.')">
                            <i class="bi bi-x-circle me-1"></i>Terminate Contract
                        </button>
                    </form>
                    <?php endif; ?>

                    <a href="/contracts/edit.php?id=<?= $contract_id ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i>Edit Contract
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vendor Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h6 class="mb-0" style="font-weight: 600;">🏢 Vendor</h6>
            </div>
            <div class="card-body">
                <h6><?= htmlspecialchars($contract['vendor_name']) ?></h6>
                <?php if ($contract['vendor_email']): ?>
                <small class="text-muted d-block"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($contract['vendor_email']) ?></small>
                <?php endif; ?>
                <?php if ($contract['vendor_phone']): ?>
                <small class="text-muted d-block"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($contract['vendor_phone']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- PAYMENT REQUESTS -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <h6 class="mb-0" style="font-weight: 600;">📋 Payment Requests (<?= count($requests) ?>)</h6>
    </div>
    <div style="overflow: auto;">
        <table class="table table-hover mb-0">
            <thead style="background-color: #f8f9fa;">
                <tr>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Request #</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Amount</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Status</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="4" class="text-center py-3 text-muted" style="border: none;">No payment requests yet</td></tr>
            <?php else: ?>
                <?php foreach ($requests as $r): ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 0.75rem; border: none;">
                        <a href="/procurement/view.php?id=<?= $r['request_id'] ?>" style="color: #667eea; font-weight: 600;">
                            <?= htmlspecialchars($r['request_number']) ?>
                        </a>
                    </td>
                    <td style="padding: 0.75rem; border: none;"><?= money((float)$r['estimated_value']) ?></td>
                    <td style="padding: 0.75rem; border: none;"><span class="badge bg-secondary"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td style="padding: 0.75rem; border: none;"><small><?= date('d M Y', strtotime($r['created_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- INVOICES -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <h6 class="mb-0" style="font-weight: 600;">🧾 Invoices (<?= count($invoices) ?>)</h6>
    </div>
    <div style="overflow: auto;">
        <table class="table table-hover mb-0">
            <thead style="background-color: #f8f9fa;">
                <tr>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Invoice #</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Date</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none; text-align: right;">Amount</th>
                    <th style="padding: 0.75rem; font-weight: 600; border: none;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($invoices)): ?>
                <tr><td colspan="4" class="text-center py-3 text-muted" style="border: none;">No invoices yet</td></tr>
            <?php else: ?>
                <?php foreach ($invoices as $inv): ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 0.75rem; border: none;">
                        <a href="/invoice/view.php?id=<?= $inv['invoice_id'] ?>" style="color: #667eea; font-weight: 600;">
                            <?= htmlspecialchars($inv['invoice_number']) ?>
                        </a>
                    </td>
                    <td style="padding: 0.75rem; border: none;"><small><?= date('d M Y', strtotime($inv['invoice_date'])) ?></small></td>
                    <td style="padding: 0.75rem; border: none; text-align: right; font-weight: 600;"><?= money((float)$inv['invoice_amount']) ?></td>
                    <td style="padding: 0.75rem; border: none;">
                        <?php
                        $invStatusColor = match($inv['status']) {
                            'Paid' => '#4caf50', 'Partially Paid' => '#ff9800', default => '#f44336'
                        };
                        ?>
                        <span class="badge" style="background-color: <?= $invStatusColor ?>; color: white;"><?= htmlspecialchars($inv['status']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
