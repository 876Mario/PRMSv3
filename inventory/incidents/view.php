<?php
$REQUIRE_PERMISSION = 'manage_incidents';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_compliance_setup.php';

$incidentId = (int) ($_GET['id'] ?? 0);
if ($incidentId <= 0) { pop("Invalid incident.", "/inventory/incidents/list.php", 1800, 'warning'); exit; }

$incident = $pdo->prepare("
    SELECT inc.*, u.full_name AS reported_by_name, ui.full_name AS investigator_name,
           l.location_code, l.site_name
    FROM inv_incidents inc
    LEFT JOIN users u ON inc.reported_by = u.user_id
    LEFT JOIN users ui ON inc.investigator_id = ui.user_id
    LEFT JOIN inv_locations l ON inc.location_id = l.location_id
    WHERE inc.incident_id = ?
");
$incident->execute([$incidentId]);
$incident = $incident->fetch(PDO::FETCH_ASSOC);
if (!$incident) { pop("Incident not found.", "/inventory/incidents/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT ii.*, i.item_code, i.item_name, i.unit_of_measure
    FROM inv_incident_items ii
    JOIN inv_items i ON ii.item_id = i.item_id
    WHERE ii.incident_id = ?
");
$lineItems->execute([$incidentId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

// Users for assigning investigator
$users = $pdo->query("SELECT user_id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/incidents/view.php?id=' . $incidentId);
        $pdo->beginTransaction();

        if ($action === 'start_investigation' && $incident['status'] === 'REPORTED') {
            $investigatorId = (int) ($_POST['investigator_id'] ?? 0);
            if ($investigatorId <= 0) throw new Exception("Investigator is required.");
            $pdo->prepare("UPDATE inv_incidents SET status = 'UNDER_INVESTIGATION', investigator_id = ? WHERE incident_id = ?")
                ->execute([$investigatorId, $incidentId]);
            logInventoryAudit($pdo, 'inv_incidents', $incidentId, 'INVESTIGATION_STARTED', "Investigation assigned");

        } elseif ($action === 'update_investigation' && $incident['status'] === 'UNDER_INVESTIGATION') {
            $invNotes = trim($_POST['investigation_notes'] ?? '');
            $policeRef = trim($_POST['police_reference'] ?? '') ?: null;
            $insuranceRef = trim($_POST['insurance_reference'] ?? '') ?: null;
            $insuranceClaim = (float) ($_POST['insurance_claim_amount'] ?? 0) ?: null;
            $pdo->prepare("UPDATE inv_incidents SET investigation_notes = ?, police_reference = ?, insurance_reference = ?, insurance_claim_amount = ? WHERE incident_id = ?")
                ->execute([$invNotes, $policeRef, $insuranceRef, $insuranceClaim, $incidentId]);
            logInventoryAudit($pdo, 'inv_incidents', $incidentId, 'INVESTIGATION_UPDATED', "Investigation notes updated");

        } elseif ($action === 'resolve' && $incident['status'] === 'UNDER_INVESTIGATION') {
            requireOpenPeriod($pdo);

            // Create stock adjustment for lost items
            $adjustmentId = null;
            if (!empty($lineItems) && $incident['location_id']) {
                $adjNumber = generateInventoryNumber($pdo, 'ADJ-', 'inv_adjustments', 'adjustment_number');
                $pdo->prepare("INSERT INTO inv_adjustments (adjustment_number, location_id, adjustment_type, reason, status, created_by, approved_by, approved_at)
                    VALUES (?, ?, 'LOSS', ?, 'APPROVED', ?, ?, NOW())")
                    ->execute([$adjNumber, $incident['location_id'],
                        "Incident {$incident['incident_number']}: {$incident['incident_type']}",
                        $incident['reported_by'], $_SESSION['user_id']]);
                $adjustmentId = (int) $pdo->lastInsertId();

                $adjLine = $pdo->prepare("INSERT INTO inv_adjustment_items (adjustment_id, item_id, system_quantity, physical_quantity, variance_quantity, unit_cost) VALUES (?,?,?,?,?,?)");
                foreach ($lineItems as $li) {
                    $systemQty = InventoryService::getStockLevel($pdo, $li['item_id'], $incident['location_id']);
                    if ((float) $li['quantity_lost'] > $systemQty + 0.0001) {
                        throw new Exception("Incident loss quantity cannot exceed current stock on hand for item {$li['item_code']}.");
                    }
                    $physQty = $systemQty - $li['quantity_lost'];
                    $variance = -$li['quantity_lost'];
                    $adjLine->execute([$adjustmentId, $li['item_id'], $systemQty, max(0, $physQty), $variance, $li['unit_cost']]);

                    // Deduct stock
                    InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $incident['location_id'], (float) $li['quantity_lost'], 'subtract');
                    InventoryService::recordTransaction($pdo, (int) $li['item_id'], (int) $incident['location_id'], 'ADJUSTMENT_OUT', (float) $li['quantity_lost'],
                        $adjustmentId, 'inv_adjustments', "Incident loss: {$incident['incident_number']}", $_SESSION['user_id'],
                        $li['batch_lot_number'] ?? null, null, $li['serial_number'] ?? null, null);
                }
            }

            $pdo->prepare("UPDATE inv_incidents SET status = 'RESOLVED', investigation_completed_at = NOW(), adjustment_id = ? WHERE incident_id = ?")
                ->execute([$adjustmentId, $incidentId]);
            logInventoryAudit($pdo, 'inv_incidents', $incidentId, 'RESOLVED', "Incident resolved" . ($adjustmentId ? ", adjustment $adjustmentId created" : ""));

        } elseif ($action === 'close' && $incident['status'] === 'RESOLVED') {
            $pdo->prepare("UPDATE inv_incidents SET status = 'CLOSED' WHERE incident_id = ?")->execute([$incidentId]);
            logInventoryAudit($pdo, 'inv_incidents', $incidentId, 'CLOSED', "Incident closed");

        } else {
            throw new Exception("Invalid action for current status.");
        }

        $pdo->commit();
        pop("Incident updated.", "/inventory/incidents/view.php?id=$incidentId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-exclamation-triangle"></i> Incident <?= htmlspecialchars($incident['incident_number']) ?></h2>
    <a href="/inventory/incidents/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Type:</strong>
                        <?php $tc = match($incident['incident_type']) { 'THEFT' => 'danger', 'FIRE' => 'danger', 'FLOOD' => 'warning', 'VANDALISM' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $tc ?>"><?= $incident['incident_type'] ?></span>
                    </div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($incident['status']) { 'REPORTED' => 'danger', 'UNDER_INVESTIGATION' => 'warning', 'RESOLVED' => 'info', 'CLOSED' => 'success', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $incident['status']) ?></span>
                    </div>
                    <div class="col-md-4"><strong>Date:</strong> <?= $incident['incident_date'] ?></div>
                    <div class="col-md-4"><strong>Location:</strong> <?= htmlspecialchars(($incident['location_code'] ?? '') . ' ' . ($incident['site_name'] ?? '-')) ?></div>
                    <div class="col-md-4"><strong>Reported By:</strong> <?= htmlspecialchars($incident['reported_by_name']) ?></div>
                    <div class="col-md-4"><strong>Est. Loss:</strong> <span class="text-danger fw-bold">$<?= number_format($incident['total_estimated_loss'], 2) ?></span></div>
                    <div class="col-md-4"><strong>Police Ref:</strong> <?= htmlspecialchars($incident['police_reference'] ?: '-') ?></div>
                    <div class="col-md-4"><strong>Insurance Ref:</strong> <?= htmlspecialchars($incident['insurance_reference'] ?: '-') ?></div>
                    <div class="col-md-4"><strong>Insurance Claim:</strong> <?= $incident['insurance_claim_amount'] ? '$' . number_format($incident['insurance_claim_amount'], 2) : '-' ?></div>
                    <div class="col-12"><strong>Description:</strong> <?= nl2br(htmlspecialchars($incident['description'])) ?></div>
                    <?php if ($incident['investigator_name']): ?>
                    <div class="col-md-6"><strong>Investigator:</strong> <?= htmlspecialchars($incident['investigator_name']) ?></div>
                    <?php endif; ?>
                    <?php if ($incident['investigation_notes']): ?>
                    <div class="col-12"><strong>Investigation Notes:</strong> <?= nl2br(htmlspecialchars($incident['investigation_notes'])) ?></div>
                    <?php endif; ?>
                    <?php if ($incident['adjustment_id']): ?>
                    <div class="col-12"><strong>Stock Adjustment:</strong> <a href="/inventory/adjustments/view.php?id=<?= $incident['adjustment_id'] ?>">View Adjustment #<?= $incident['adjustment_id'] ?></a></div>
                    <?php endif; ?>
                    <?php if ($incident['notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($incident['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($incident['status'] === 'REPORTED'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6>Assign Investigator</h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
                    <select name="investigator_id" class="form-select mb-2" required>
                        <option value="">Select investigator...</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="start_investigation" class="btn btn-warning w-100"><i class="bi bi-search"></i> Start Investigation</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($incident['status'] === 'UNDER_INVESTIGATION'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6>Update Investigation</h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
                    <div class="mb-2">
                        <textarea name="investigation_notes" class="form-control" rows="3" placeholder="Investigation notes..."><?= htmlspecialchars($incident['investigation_notes'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2"><input type="text" name="police_reference" class="form-control" placeholder="Police ref" value="<?= htmlspecialchars($incident['police_reference'] ?? '') ?>"></div>
                    <div class="mb-2"><input type="text" name="insurance_reference" class="form-control" placeholder="Insurance ref" value="<?= htmlspecialchars($incident['insurance_reference'] ?? '') ?>"></div>
                    <div class="mb-2"><input type="number" step="0.01" name="insurance_claim_amount" class="form-control" placeholder="Claim $" value="<?= $incident['insurance_claim_amount'] ?? '' ?>"></div>
                    <button type="submit" name="action" value="update_investigation" class="btn btn-info w-100 mb-2"><i class="bi bi-save"></i> Save Notes</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
                    <button type="submit" name="action" value="resolve" class="btn btn-success w-100"
                            onclick="return confirm('Resolve incident? Stock adjustments will be created for lost items.')">
                        <i class="bi bi-check-circle"></i> Resolve &amp; Create Adjustment
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($incident['status'] === 'RESOLVED'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="close" class="btn btn-dark w-100 btn-lg"><i class="bi bi-lock"></i> Close Incident</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Affected Items -->
<?php if (!empty($lineItems)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-danger text-white"><i class="bi bi-list-ul"></i> Affected Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item</th><th>UoM</th><th class="text-end">Qty Lost</th><th class="text-end">Unit Cost</th><th class="text-end">Total Value</th><th>Batch</th><th>Serial</th><th>Condition</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code> <?= htmlspecialchars($li['item_name']) ?></td>
                        <td><?= htmlspecialchars($li['unit_of_measure'] ?? '-') ?></td>
                        <td class="text-end fw-bold text-danger"><?= number_format($li['quantity_lost'], 2) ?></td>
                        <td class="text-end">$<?= number_format($li['unit_cost'], 2) ?></td>
                        <td class="text-end">$<?= number_format($li['total_value'], 2) ?></td>
                        <td><?= htmlspecialchars($li['batch_lot_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($li['serial_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($li['condition_notes'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr><td colspan="4" class="text-end fw-bold">Total Loss:</td><td class="text-end fw-bold text-danger">$<?= number_format($incident['total_estimated_loss'], 2) ?></td><td colspan="3"></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>