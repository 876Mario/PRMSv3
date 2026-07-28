<?php
/**
 * View the active depreciation schedule for an asset and post historical records.
 */
$REQUIRE_PERMISSION = 'view_asset_depreciation';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if ($itemId <= 0) {
    pop('Invalid item ID.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch item
try {
    $stmt = $pdo->prepare("SELECT item_id, item_name, item_code FROM inv_items WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    pop('Unable to load asset.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}
if (!$item) {
    pop('Asset not found.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch active schedule
$schedule = null;
$periods  = [];
try {
    $sStmt = $pdo->prepare("
        SELECT * FROM asset_depreciation_schedules
        WHERE item_id = ? AND is_active = 1
        ORDER BY schedule_id DESC LIMIT 1
    ");
    $sStmt->execute([$itemId]);
    $schedule = $sStmt->fetch(PDO::FETCH_ASSOC);

    if ($schedule) {
        $pStmt = $pdo->prepare("
            SELECT p.*, r.record_id
            FROM asset_depreciation_periods p
            LEFT JOIN asset_depreciation_records r ON r.period_id = p.period_id
            WHERE p.schedule_id = ?
            ORDER BY p.period_number ASC
        ");
        $pStmt->execute([$schedule['schedule_id']]);
        $periods = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Table may not exist — show empty state
    error_log('schedule.php: ' . $e->getMessage());
}

// Fetch historical records
$records = [];
try {
    $rStmt = $pdo->prepare("
        SELECT r.*, u.full_name AS recorded_by_name
        FROM asset_depreciation_records r
        LEFT JOIN users u ON u.user_id = r.created_by
        WHERE r.item_id = ?
        ORDER BY r.charge_date DESC
    ");
    $rStmt->execute([$itemId]);
    $records = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Table may not exist
}

// Handle POST: record a depreciation charge for a period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('manage_asset_depreciation')) {
    $periodId = (int)($_POST['period_id'] ?? 0);
    $chargeDate = trim($_POST['charge_date'] ?? date('Y-m-d'));
    $notes = trim($_POST['notes'] ?? '');

    $period = null;
    foreach ($periods as $p) {
        if ((int)$p['period_id'] === $periodId) { $period = $p; break; }
    }

    $postError = null;
    if (!$period) {
        $postError = 'Invalid period selected.';
    } elseif ($period['is_recorded']) {
        $postError = 'This period has already been recorded.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $chargeDate)) {
        $postError = 'Invalid charge date.';
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO asset_depreciation_records
                  (item_id, schedule_id, period_id, financial_year, charge_date,
                   depreciation_amount, accumulated_total, book_value_after,
                   method, units_consumed, notes, created_by)
                VALUES (?, ?, ?, YEAR(?), ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $itemId,
                $schedule['schedule_id'],
                $periodId,
                $chargeDate,
                $chargeDate,
                $period['depreciation_charge'],
                $period['accumulated_depreciation'],
                $period['book_value_end'],
                $schedule['method'],
                $period['units_consumed'],
                $notes ?: null,
                $_SESSION['user_id'] ?? null,
            ]);

            // Mark period as recorded
            $pdo->prepare("
                UPDATE asset_depreciation_periods
                SET is_recorded = 1, recorded_by = ?, recorded_at = NOW()
                WHERE period_id = ?
            ")->execute([$_SESSION['user_id'] ?? null, $periodId]);

            // Update inv_asset_details accumulated_depreciation and carrying_value
            $pdo->prepare("
                UPDATE inv_asset_details
                SET accumulated_depreciation = ?,
                    carrying_value           = ?
                WHERE item_id = ?
            ")->execute([
                $period['accumulated_depreciation'],
                $period['book_value_end'],
                $itemId,
            ]);

            logAudit($pdo, 'asset_depreciation_records', 0, 'RECORD_DEPRECIATION',
                "Year {$period['period_number']} depreciation posted for item {$item['item_code']}: JMD " . number_format($period['depreciation_charge'], 2));

            $pdo->commit();
            pop('Depreciation charge recorded.', "/inventory/depreciation/schedule.php?item_id={$itemId}", 1400, 'success');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Record depreciation failed: ' . $e->getMessage());
            $postError = extractDbMessage($e);
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container mt-4">
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="section-title">📊 Depreciation Schedule</h3>
        <p class="text-muted mb-0">
            Asset: <strong><?= htmlspecialchars($item['item_name']) ?></strong>
            &nbsp;<small><?= htmlspecialchars($item['item_code']) ?></small>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (has_permission('manage_asset_depreciation')): ?>
        <a href="/inventory/depreciation/add.php?item_id=<?= $itemId ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i><?= $schedule ? 'Regenerate' : 'Create' ?> Schedule
        </a>
        <?php endif; ?>
        <a href="/inventory/items/view.php?id=<?= $itemId ?>" class="btn btn-outline-secondary btn-sm">← Back to Asset</a>
    </div>
</div>

<?php if (!empty($postError)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($postError) ?></div>
<?php endif; ?>

<?php if (!$schedule): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    No active depreciation schedule found for this asset.
    <?php if (has_permission('manage_asset_depreciation')): ?>
    <a href="/inventory/depreciation/add.php?item_id=<?= $itemId ?>">Create one now →</a>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- Schedule summary -->
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">Method</small>
            <strong><?= str_replace('_', ' ', $schedule['method']) ?></strong>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">Cost Basis</small>
            <strong>JMD <?= number_format((float)$schedule['cost_basis'], 2) ?></strong>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">Salvage Value</small>
            <strong>JMD <?= number_format((float)$schedule['salvage_value'], 2) ?></strong>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">Useful Life</small>
            <strong><?= $schedule['useful_life_years'] ? $schedule['useful_life_years'] . ' yrs' : '—' ?></strong>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">Start Date</small>
            <strong><?= date('d M Y', strtotime($schedule['start_date'])) ?></strong>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm text-center p-3">
            <small class="text-muted d-block">End Date</small>
            <strong><?= $schedule['end_date'] ? date('d M Y', strtotime($schedule['end_date'])) : '—' ?></strong>
        </div>
    </div>
</div>

<!-- Periods table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-table me-2"></i>Depreciation Schedule</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Year</th>
                        <th>Period</th>
                        <?php if ($schedule['method'] === 'UNITS_OF_PRODUCTION'): ?>
                        <th class="text-end">Units</th>
                        <?php endif; ?>
                        <th class="text-end">Depreciation</th>
                        <th class="text-end">Accumulated</th>
                        <th class="text-end">Book Value</th>
                        <th class="text-center">Status</th>
                        <?php if (has_permission('manage_asset_depreciation')): ?>
                        <th class="text-center">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($periods as $p): ?>
                    <tr class="<?= $p['is_recorded'] ? 'table-success' : '' ?>">
                        <td class="ps-3 fw-bold"><?= $p['period_number'] ?></td>
                        <td>
                            <?= date('M Y', strtotime($p['period_start_date'])) ?>
                            – <?= date('M Y', strtotime($p['period_end_date'])) ?>
                        </td>
                        <?php if ($schedule['method'] === 'UNITS_OF_PRODUCTION'): ?>
                        <td class="text-end"><?= $p['units_consumed'] !== null ? number_format((float)$p['units_consumed']) : '—' ?></td>
                        <?php endif; ?>
                        <td class="text-end text-danger">JMD <?= number_format((float)$p['depreciation_charge'], 2) ?></td>
                        <td class="text-end">JMD <?= number_format((float)$p['accumulated_depreciation'], 2) ?></td>
                        <td class="text-end fw-bold">JMD <?= number_format((float)$p['book_value_end'], 2) ?></td>
                        <td class="text-center">
                            <?php if ($p['is_recorded']): ?>
                                <span class="badge bg-success">Recorded</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Pending</span>
                            <?php endif; ?>
                        </td>
                        <?php if (has_permission('manage_asset_depreciation')): ?>
                        <td class="text-center">
                            <?php if (!$p['is_recorded']): ?>
                            <button type="button" class="btn btn-sm btn-outline-success"
                                    data-bs-toggle="modal" data-bs-target="#recordModal"
                                    data-period-id="<?= $p['period_id'] ?>"
                                    data-period-num="<?= $p['period_number'] ?>"
                                    data-charge="<?= number_format((float)$p['depreciation_charge'], 2) ?>">
                                <i class="bi bi-check-circle"></i> Post
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Historical Records -->
<?php if (!empty($records)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historical Depreciation Records</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th class="ps-3">FY</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th class="text-end">Charge</th>
                        <th class="text-end">Accumulated</th>
                        <th class="text-end">Book Value</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?= htmlspecialchars((string)$r['financial_year']) ?></td>
                        <td><?= date('d M Y', strtotime($r['charge_date'])) ?></td>
                        <td><span class="badge bg-secondary"><?= str_replace('_', ' ', $r['method']) ?></span></td>
                        <td class="text-end text-danger">JMD <?= number_format((float)$r['depreciation_amount'], 2) ?></td>
                        <td class="text-end">JMD <?= number_format((float)$r['accumulated_total'], 2) ?></td>
                        <td class="text-end fw-bold">JMD <?= number_format((float)$r['book_value_after'], 2) ?></td>
                        <td><?= htmlspecialchars($r['recorded_by_name'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- .container -->

<!-- Record Charge Modal -->
<?php if ($schedule && has_permission('manage_asset_depreciation')): ?>
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Post Depreciation Charge</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="period_id" id="modalPeriodId">
                <p>Post depreciation charge for <strong>Year <span id="modalPeriodNum"></span></strong>.</p>
                <p class="text-muted small">Charge amount: <strong class="text-danger">JMD <span id="modalCharge"></span></strong></p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Charge Date</label>
                    <input type="date" name="charge_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-bold">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="3"
                              placeholder="e.g. End of financial year posting..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Post Charge
                </button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('recordModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('modalPeriodId').value  = btn.dataset.periodId;
    document.getElementById('modalPeriodNum').textContent = btn.dataset.periodNum;
    document.getElementById('modalCharge').textContent    = btn.dataset.charge;
});
</script>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
