<?php
$REQUIRE_PERMISSION = 'manage_quarantine';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_compliance_setup.php';

$quarantineId = (int) ($_GET['id'] ?? 0);
if ($quarantineId <= 0) { pop("Invalid quarantine.", "/inventory/quarantine/list.php", 1800, 'warning'); exit; }

$q = $pdo->prepare("
    SELECT q.*, i.item_code, i.item_name, l.location_code, l.site_name,
           u.full_name AS quarantined_by_name, ru.full_name AS released_by_name
    FROM inv_quarantine_log q
    JOIN inv_items i ON q.item_id = i.item_id
    LEFT JOIN inv_locations l ON q.location_id = l.location_id
    LEFT JOIN users u ON q.quarantined_by = u.user_id
    LEFT JOIN users ru ON q.released_by = ru.user_id
    WHERE q.quarantine_id = ?
");
$q->execute([$quarantineId]);
$qr = $q->fetch(PDO::FETCH_ASSOC);
if (!$qr) { pop("Quarantine record not found.", "/inventory/quarantine/list.php", 1800, 'warning'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/quarantine/view.php?id=' . $quarantineId);
        $pdo->beginTransaction();

        if ($action === 'start_inspection' && $qr['status'] === 'QUARANTINED') {
            $pdo->prepare("UPDATE inv_quarantine_log SET status = 'UNDER_INSPECTION' WHERE quarantine_id = ?")
                ->execute([$quarantineId]);
            logInventoryAudit($pdo, 'inv_quarantine_log', $quarantineId, 'UNDER_INSPECTION', "Inspection started");

        } elseif ($action === 'release' && in_array($qr['status'], ['QUARANTINED', 'UNDER_INSPECTION'], true)) {
            $decision = $_POST['release_decision'] ?? '';
            $notes = trim($_POST['decision_notes'] ?? '');
            if (empty($notes)) throw new Exception("Decision notes are required.");

            InventoryService::resolveQuarantineRelease($pdo, $quarantineId, $decision, $notes);
            logInventoryAudit($pdo, 'inv_quarantine_log', $quarantineId, 'RELEASED', "Decision: $decision — $notes");
        } else {
            throw new Exception("Invalid action for current quarantine state.");
        }

        $pdo->commit();
        pop("Quarantine updated.", "/inventory/quarantine/view.php?id=$quarantineId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shield-exclamation"></i> Quarantine Record</h2>
    <a href="/inventory/quarantine/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Item:</strong> <code><?= htmlspecialchars($qr['item_code']) ?></code> <?= htmlspecialchars($qr['item_name']) ?></div>
                    <div class="col-md-4"><strong>Location:</strong> <?= htmlspecialchars($qr['location_code'] . ' - ' . $qr['site_name']) ?></div>
                    <div class="col-md-4"><strong>Quantity:</strong> <span class="fw-bold"><?= number_format($qr['quantity'], 2) ?></span></div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($qr['status']) { 'QUARANTINED' => 'danger', 'UNDER_INSPECTION' => 'warning', 'RELEASED' => 'success', 'DISPOSED' => 'secondary', default => 'light' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $qr['status']) ?></span>
                    </div>
                    <div class="col-md-4"><strong>Quarantined By:</strong> <?= htmlspecialchars($qr['quarantined_by_name']) ?></div>
                    <div class="col-md-4"><strong>Date:</strong> <?= date('Y-m-d H:i', strtotime($qr['quarantined_at'])) ?></div>
                    <?php if ($qr['batch_lot_number']): ?>
                    <div class="col-md-4"><strong>Batch/Lot:</strong> <?= htmlspecialchars($qr['batch_lot_number']) ?></div>
                    <?php endif; ?>
                    <?php if ($qr['serial_number']): ?>
                    <div class="col-md-4"><strong>Serial:</strong> <?= htmlspecialchars($qr['serial_number']) ?></div>
                    <?php endif; ?>
                    <div class="col-12"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($qr['reason'])) ?></div>
                    <?php if ($qr['released_by']): ?>
                    <div class="col-md-4"><strong>Released By:</strong> <?= htmlspecialchars($qr['released_by_name']) ?></div>
                    <div class="col-md-4"><strong>Released:</strong> <?= $qr['released_at'] ?></div>
                    <div class="col-md-4"><strong>Decision:</strong> <?= str_replace('_', ' ', $qr['release_decision']) ?></div>
                    <?php endif; ?>
                    <?php if ($qr['decision_notes']): ?>
                    <div class="col-12"><strong>Decision Notes:</strong> <?= nl2br(htmlspecialchars($qr['decision_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($qr['status'] === 'QUARANTINED'): ?>
        <form method="POST" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="start_inspection" class="btn btn-warning w-100 btn-lg mb-3">
                <i class="bi bi-search"></i> Start Inspection
            </button>
        </form>
        <?php endif; ?>

        <?php if (in_array($qr['status'], ['QUARANTINED', 'UNDER_INSPECTION'])): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <div class="mb-2">
                <label class="form-label fw-bold">Release Decision</label>
                <select name="release_decision" class="form-select" required>
                    <option value="">Select decision...</option>
                    <option value="RETURN_TO_STOCK">Return to Usable Stock</option>
                    <option value="DISPOSE">Send for Disposal</option>
                    <option value="RETURN_TO_SUPPLIER">Return to Supplier</option>
                </select>
            </div>
            <div class="mb-2">
                <textarea name="decision_notes" class="form-control" rows="3" required placeholder="Decision notes / justification..."></textarea>
            </div>
            <button type="submit" name="action" value="release" class="btn btn-success w-100 btn-lg">
                <i class="bi bi-check-circle"></i> Record Decision
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>