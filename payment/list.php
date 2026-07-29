<?php
$REQUIRE_PERMISSION = 'view_payments';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/pagination.php";

/* ================================
   Filters
================================ */
$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(p.payment_reference LIKE :q 
                 OR i.invoice_number LIKE :q 
                 OR po.po_number LIKE :q
                 OR sc.contract_number LIKE :q)";
    $params[':q'] = '%'.$_GET['q'].'%';
}

if (!empty($_GET['from'])) {
    $where[] = "p.payment_date >= :from";
    $params[':from'] = $_GET['from'];
}

if (!empty($_GET['to'])) {
    $where[] = "p.payment_date <= :to";
    $params[':to'] = $_GET['to'];
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ================================
   Pagination params
================================ */
extract(getPaginationParams(20));

/* ================================
   Data query
================================ */
$sql = "
    SELECT 
        p.payment_id,
        p.invoice_id,
        p.payment_date,
        p.payment_reference,
        p.payment_amount,
        i.invoice_number,
        COALESCE(po.po_number, sc.contract_number) AS po_number,
        u.full_name AS entered_by
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.invoice_id
    LEFT JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN service_contracts sc ON i.contract_id = sc.contract_id
    LEFT JOIN users u ON p.created_by = u.user_id
    $whereSQL
    ORDER BY p.payment_date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Count query
================================ */
$countSql = "
    SELECT COUNT(*)
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.invoice_id
    LEFT JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN service_contracts sc ON i.contract_id = sc.contract_id
    LEFT JOIN users u ON p.created_by = u.user_id
    $whereSQL
";

$countStmt = $pdo->prepare($countSql);

foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}

$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

/* ================================
   Render page
================================ */
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

// Calculate total payment amount for current filtered results
$totalSql = "
    SELECT COALESCE(SUM(p.payment_amount), 0) as total_amount
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.invoice_id
    LEFT JOIN purchase_orders po ON i.po_id = po.po_id
    LEFT JOIN service_contracts sc ON i.contract_id = sc.contract_id
    LEFT JOIN users u ON p.created_by = u.user_id
    $whereSQL
";

$totalStmt = $pdo->prepare($totalSql);
foreach ($params as $k => $v) {
    $totalStmt->bindValue($k, $v);
}
$totalStmt->execute();
$totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
$totalAmount = (float)($totals['total_amount'] ?? 0);
?>

<style>
    .card {
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }
    
    .form-control, .form-select {
        transition: all 0.2s ease;
        box-shadow: none !important;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
    }
    
    .btn {
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    table tbody tr:hover td {
        background-color: #f9f9f9 !important;
    }
</style>

<div class="mb-5">

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">💰 Payment Register</h2>
        <p class="text-muted mb-0">View and manage all payment transactions</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI SUMMARY CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Payments</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 1.5rem;"><?= money($totalAmount) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">💰</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Records</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= number_format($totalRows) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">📊</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     FILTERS
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <div class="d-flex align-items-center gap-2 py-2">
            <i class="bi bi-funnel" style="font-size: 1.2rem; color: #667eea;"></i>
            <h6 class="mb-0" style="font-weight: 600; color: #1a1a1a;">Search & Filter</h6>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label small text-muted" style="font-weight: 600;">Search</label>
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       class="form-control"
                       placeholder="Payment ref, invoice #, PO #..."
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted" style="font-weight: 600;">From Date</label>
                <input type="date" 
                       name="from" 
                       value="<?= htmlspecialchars($_GET['from'] ?? '') ?>" 
                       class="form-control"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted" style="font-weight: 600;">To Date</label>
                <input type="date" 
                       name="to" 
                       value="<?= htmlspecialchars($_GET['to'] ?? '') ?>" 
                       class="form-control"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-2 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/payment/list.php" class="btn btn-outline-secondary" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PAYMENT TABLE
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead style="background-color: #f8f9fa; border-bottom: 2px solid #e0e0e0;">
                <tr>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Date</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Payment Ref</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Invoice #</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">PO / Contract</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: right;">Amount</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Entered By</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: center; width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody>

                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5" style="border: none;">
                            <p style="color: #999; font-size: 1rem;">
                                <i class="bi bi-inbox" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 0.5rem;"></i>
                                No payment records found
                            </p>
                        </td>
                    </tr>
                <?php else: ?>

                <?php foreach ($rows as $p): ?>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 1rem; border: none;">
                            <small style="font-weight: 600;"><?= date('d M Y', strtotime($p['payment_date'])) ?></small>
                        </td>
                        <td style="padding: 1rem; border: none;">
                            <span class="badge" style="background-color: #667eea; color: white; padding: 0.35rem 0.75rem;">
                                <?= htmlspecialchars($p['payment_reference']) ?>
                            </span>
                        </td>
                        <td style="padding: 1rem; border: none;">
                            <a href="/invoice/view.php?id=<?= (int)$p['invoice_id'] ?>" class="text-decoration-none" style="color: #667eea; font-weight: 600;">
                                <?= htmlspecialchars($p['invoice_number']) ?>
                            </a>
                        </td>
                        <td style="padding: 1rem; border: none;">
                            <small class="text-muted"><?= htmlspecialchars($p['po_number']) ?></small>
                        </td>
                        <td style="padding: 1rem; border: none; text-align: right; font-weight: 600; color: #1a1a1a;">
                            <?= money((float)$p['payment_amount']) ?>
                        </td>
                        <td style="padding: 1rem; border: none;">
                            <small class="text-muted"><?= htmlspecialchars($p['entered_by'] ?? '—') ?></small>
                        </td>
                        <td style="padding: 1rem; border: none; text-align: center;">
                            <a href="/payment/view.php?id=<?= (int)$p['payment_id'] ?>" 
                               class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem;"
                               title="View Payment">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
</div>

<div style="text-align: center; margin-top: 2rem; padding: 1rem; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e0e0e0;">
    <small style="color: #666; font-weight: 500;">
        📊 Total: <strong><?= number_format($totalRows) ?></strong> payment(s)
        | Amount: <strong><?= money($totalAmount) ?></strong>
    </small>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>
<?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
