<?php
$REQUIRE_PERMISSION = 'view_vendors';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* ===============================
   Fetch Vendor
================================ */
$id = $_GET['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
    pop('Invalid vendor ID', '/vendors/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT vendor_id, vendor_name, contact_person, email, phone, address, status, total_awards, performance_rating
    FROM vendors
    WHERE vendor_id = ?
");
$stmt->execute([$id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    pop('Vendor not found', '/vendors/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===============================
   Fetch Related Data
================================ */
/* Vendor's RFQ Participations */
$rfqStmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM rfq_vendors
    WHERE vendor_id = ?
");
$rfqStmt->execute([$id]);
$rfqInfo = $rfqStmt->fetch(PDO::FETCH_ASSOC);

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="section-title">🏢 Vendor Details</h3>
    <div class="d-flex gap-2">
        <a href="/vendors/edit.php?id=<?= (int)$vendor['vendor_id'] ?>" class="btn btn-warning btn-sm">
            ✏️ Edit
        </a>
        <a href="/vendors/delete.php?id=<?= (int)$vendor['vendor_id'] ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Are you sure you want to delete <?= htmlspecialchars($vendor['vendor_name'], ENT_QUOTES) ?>? This cannot be undone.');">
            🗑️ Delete
        </a>
        <a href="/vendors/list.php" class="btn btn-secondary btn-sm">
            ← Back
        </a>
    </div>
</div>

<!-- Vendor Info Card -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📋 Vendor Information</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Vendor Name</label>
                        <p class="h5 mb-0"><?= htmlspecialchars($vendor['vendor_name']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Status</label>
                        <p class="mb-0">
                            <span class="badge bg-<?= $vendor['status'] === 'ACTIVE' ? 'success' : 'danger' ?> fs-6">
                                <?= $vendor['status'] === 'ACTIVE' ? '✅ Active' : '❌ Inactive' ?>
                            </span>
                        </p>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Contact Person</label>
                        <p class="mb-0"><?= htmlspecialchars($vendor['contact_person'] ?? '—') ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Email</label>
                        <p class="mb-0">
                            <?php if ($vendor['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($vendor['email']) ?>">
                                    💌 <?= htmlspecialchars($vendor['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Phone</label>
                        <p class="mb-0">
                            <?php if ($vendor['phone']): ?>
                                <a href="tel:<?= htmlspecialchars($vendor['phone']) ?>">
                                    📱 <?= htmlspecialchars($vendor['phone']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Total Awards</label>
                        <p class="mb-0">
                            <span class="badge bg-primary fs-6"><?= (int)$vendor['total_awards'] ?></span>
                        </p>
                    </div>
                </div>

                <?php if ($vendor['address']): ?>
                <div class="row">
                    <div class="col-12">
                        <label class="form-label text-muted small fw-bold">Address</label>
                        <p class="mb-0 text-secondary"><?= nl2br(htmlspecialchars($vendor['address'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Stats Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-primary text-white mb-3">
            <div class="card-body text-center">
                <h5 class="card-title mb-2">📑 RFQ Participations</h5>
                <h3 class="mb-0"><?= (int)$rfqInfo['count'] ?></h3>
                <small class="text-white-50">Active Proposals</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm bg-success text-white mb-3">
            <div class="card-body text-center">
                <h5 class="card-title mb-2">📊 Total Awards</h5>
                <h3 class="mb-0"><?= (int)$vendor['total_awards'] ?></h3>
                <small class="text-white-50">Procurement Wins</small>
            </div>
        </div>

        <div class="card border-0 shadow-sm bg-info text-white">
            <div class="card-body text-center">
                <h5 class="card-title mb-2">⭐ Performance</h5>
                <h3 class="mb-0"><?= number_format($vendor['performance_rating'] ?? 0, 2) ?>/5</h3>
                <small class="text-white-50">Vendor Rating</small>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
