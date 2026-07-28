<?php
/**
 * Create / regenerate a depreciation schedule for an asset.
 */
$REQUIRE_PERMISSION = 'manage_asset_depreciation';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/calculate.php';

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if ($itemId <= 0) {
    pop('Invalid item ID.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch item + asset detail
try {
    $stmt = $pdo->prepare("
        SELECT i.item_id, i.item_name, i.item_code,
               ad.asset_detail_id, ad.purchase_cost, ad.bos_value, ad.acquired_date,
               ad.placed_in_service_date, ad.salvage_value, ad.useful_life_years,
               ad.total_production_units, ad.declining_balance_rate,
               ad.depreciation_method_type, ad.accumulated_depreciation, ad.carrying_value
        FROM inv_items i
        LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id
        WHERE i.item_id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    pop('Unable to load asset details.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (!$item) {
    pop('Asset not found.', '/inventory/items/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$assetDetailId = (int)($item['asset_detail_id'] ?? 0);
$errors = [];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method          = strtoupper(trim($_POST['method'] ?? ''));
    $cost            = (float)($_POST['cost']            ?? 0);
    $salvageValue    = (float)($_POST['salvage_value']   ?? 0);
    $usefulLife      = isset($_POST['useful_life_years']) && $_POST['useful_life_years'] !== ''
                        ? (float)$_POST['useful_life_years'] : null;
    $rate            = isset($_POST['declining_balance_rate']) && $_POST['declining_balance_rate'] !== ''
                        ? (float)$_POST['declining_balance_rate'] / 100.0 : null;   // input as %
    $totalUnits      = isset($_POST['total_production_units']) && $_POST['total_production_units'] !== ''
                        ? (float)$_POST['total_production_units'] : null;
    $startDate       = trim($_POST['start_date'] ?? date('Y-m-d'));
    $action          = $_POST['action'] ?? 'preview';

    // Parse units-per-period (JSON array from UI)
    $unitsPerPeriod = [];
    if ($method === 'UNITS_OF_PRODUCTION' && !empty($_POST['units_per_period'])) {
        $raw = json_decode($_POST['units_per_period'], true);
        if (is_array($raw)) {
            foreach ($raw as $idx => $u) {
                $unitsPerPeriod[(int)$idx + 1] = (float)$u;
            }
        }
    }

    // Validation
    $allowedMethods = ['STRAIGHT_LINE', 'DECLINING_BALANCE', 'UNITS_OF_PRODUCTION'];
    if (!in_array($method, $allowedMethods, true)) {
        $errors[] = 'Invalid depreciation method selected.';
    }
    if ($cost <= 0) {
        $errors[] = 'Cost basis must be greater than zero.';
    }
    if ($salvageValue < 0) {
        $errors[] = 'Salvage value cannot be negative.';
    }
    if ($salvageValue >= $cost) {
        $errors[] = 'Salvage value must be less than cost basis.';
    }
    if (in_array($method, ['STRAIGHT_LINE', 'DECLINING_BALANCE']) && ($usefulLife === null || $usefulLife <= 0)) {
        $errors[] = 'Useful life (years) is required for this method.';
    }
    if ($method === 'DECLINING_BALANCE' && ($rate === null || $rate <= 0 || $rate >= 1)) {
        $errors[] = 'Declining balance rate must be between 1% and 99%.';
    }
    if ($method === 'UNITS_OF_PRODUCTION' && ($totalUnits === null || $totalUnits <= 0)) {
        $errors[] = 'Total production units is required for Units of Production method.';
    }
    if (!DateTime::createFromFormat('Y-m-d', $startDate)) {
        $errors[] = 'Invalid start date.';
    }
    if ($assetDetailId <= 0) {
        $errors[] = 'This item does not have an asset register record. Please create one first.';
    }

    if (empty($errors)) {
        $schedule = calculateDepreciationSchedule(
            $method, $cost, $salvageValue, $usefulLife, $rate, $totalUnits, $unitsPerPeriod, $startDate
        );

        if ($schedule['error']) {
            $errors[] = $schedule['error'];
        } elseif ($action === 'save') {
            try {
                $scheduleId = saveDepreciationSchedule($pdo, $itemId, $assetDetailId, [
                    'method'                  => $method,
                    'cost'                    => $cost,
                    'salvage_value'           => $salvageValue,
                    'useful_life_years'       => $usefulLife,
                    'rate'                    => $rate,
                    'total_production_units'  => $totalUnits,
                    'start_date'              => $startDate,
                ], $schedule);

                // Update asset detail with current depreciation parameters
                $pdo->prepare("
                    UPDATE inv_asset_details
                    SET depreciation_method_type   = ?,
                        useful_life_years          = ?,
                        salvage_value              = ?,
                        total_production_units     = ?,
                        declining_balance_rate     = ?
                    WHERE asset_detail_id = ?
                ")->execute([
                    $method, $usefulLife, $salvageValue, $totalUnits,
                    $rate ? $rate * 100.0 : null,  // store as %
                    $assetDetailId
                ]);

                logAudit($pdo, 'asset_depreciation_schedules', $scheduleId, 'CREATE',
                    "Depreciation schedule generated for item {$item['item_code']} using {$method}");

                pop(
                    'Depreciation schedule saved successfully.',
                    "/inventory/depreciation/schedule.php?item_id={$itemId}",
                    1500,
                    'success'
                );
                exit;
            } catch (Throwable $e) {
                error_log('saveDepreciationSchedule failed: ' . $e->getMessage());
                $errors[] = 'Failed to save schedule: ' . extractDbMessage($e);
            }
        } else {
            $preview = $schedule;
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container mt-4">
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="section-title">📉 Create Depreciation Schedule</h3>
        <p class="text-muted mb-0">
            Asset: <strong><?= htmlspecialchars($item['item_name']) ?></strong>
            &nbsp;<small class="text-muted"><?= htmlspecialchars($item['item_code']) ?></small>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/inventory/depreciation/schedule.php?item_id=<?= $itemId ?>" class="btn btn-outline-info btn-sm">
            <i class="bi bi-table me-1"></i>View Schedule
        </a>
        <a href="/inventory/items/view.php?id=<?= $itemId ?>" class="btn btn-outline-secondary btn-sm">← Back to Asset</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong>⚠️ Please fix the following:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
<div class="col-lg-5">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Depreciation Parameters</h5>
    </div>
    <div class="card-body">
        <form method="post" id="depForm">
            <div class="mb-3">
                <label class="form-label fw-bold">Depreciation Method <span class="text-danger">*</span></label>
                <select name="method" id="methodSelect" class="form-select" required onchange="updateMethodFields()">
                    <option value="">— Select method —</option>
                    <option value="STRAIGHT_LINE"      <?= ($_POST['method'] ?? $item['depreciation_method_type'] ?? '') === 'STRAIGHT_LINE'      ? 'selected' : '' ?>>Straight-Line</option>
                    <option value="DECLINING_BALANCE"  <?= ($_POST['method'] ?? $item['depreciation_method_type'] ?? '') === 'DECLINING_BALANCE'  ? 'selected' : '' ?>>Declining Balance</option>
                    <option value="UNITS_OF_PRODUCTION"<?= ($_POST['method'] ?? $item['depreciation_method_type'] ?? '') === 'UNITS_OF_PRODUCTION'? 'selected' : '' ?>>Units of Production</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Cost Basis (JMD) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" name="cost" class="form-control" required
                       value="<?= htmlspecialchars((string)($_POST['cost'] ?? $item['purchase_cost'] ?? $item['bos_value'] ?? '')) ?>">
                <small class="text-muted">Acquisition cost used as the depreciable base.</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Salvage Value (JMD)</label>
                <input type="number" step="0.01" min="0" name="salvage_value" class="form-control"
                       value="<?= htmlspecialchars((string)($_POST['salvage_value'] ?? $item['salvage_value'] ?? '0')) ?>">
                <small class="text-muted">Estimated residual value at end of useful life.</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Date Placed in Service <span class="text-danger">*</span></label>
                <input type="date" name="start_date" class="form-control" required
                       value="<?= htmlspecialchars($_POST['start_date'] ?? $item['placed_in_service_date'] ?? $item['acquired_date'] ?? date('Y-m-d')) ?>">
            </div>

            <!-- Straight-Line / Declining Balance: useful life -->
            <div id="usefulLifeField" class="mb-3">
                <label class="form-label fw-bold">Useful Life (years)</label>
                <input type="number" step="0.5" min="0.5" name="useful_life_years" class="form-control"
                       value="<?= htmlspecialchars((string)($_POST['useful_life_years'] ?? $item['useful_life_years'] ?? '')) ?>">
            </div>

            <!-- Declining Balance: rate -->
            <div id="rateField" class="mb-3" style="display:none;">
                <label class="form-label fw-bold">Annual Depreciation Rate (%)</label>
                <div class="input-group">
                    <input type="number" step="0.01" min="1" max="99" name="declining_balance_rate" class="form-control"
                           value="<?= htmlspecialchars((string)($_POST['declining_balance_rate'] ?? ($item['declining_balance_rate'] ?? ''))) ?>">
                    <span class="input-group-text">%</span>
                </div>
                <small class="text-muted">e.g. 20 for 20% per year.</small>
            </div>

            <!-- Units of Production: total units -->
            <div id="totalUnitsField" class="mb-3" style="display:none;">
                <label class="form-label fw-bold">Total Lifetime Production Units</label>
                <input type="number" step="1" min="1" name="total_production_units" class="form-control"
                       value="<?= htmlspecialchars((string)($_POST['total_production_units'] ?? $item['total_production_units'] ?? '')) ?>">
                <small class="text-muted">Total expected units over the asset's life.</small>
            </div>

            <!-- Units per period (UoP only) — dynamic JSON field -->
            <div id="unitsPerPeriodSection" style="display:none;" class="mb-3">
                <label class="form-label fw-bold">Units Per Year</label>
                <div id="unitsPerPeriodRows"></div>
                <input type="hidden" name="units_per_period" id="unitsPerPeriodJson" value="[]">
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addUoPRow()">
                    <i class="bi bi-plus"></i> Add Year
                </button>
            </div>

            <div class="d-grid gap-2 mt-4">
                <button type="submit" name="action" value="preview" class="btn btn-outline-primary">
                    <i class="bi bi-eye me-1"></i>Preview Schedule
                </button>
                <?php if ($preview): ?>
                <button type="submit" name="action" value="save" class="btn btn-success"
                        onclick="return confirm('Save this depreciation schedule?')">
                    <i class="bi bi-save me-1"></i>Save Schedule
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
</div>

<?php if ($preview): ?>
<div class="col-lg-7">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-table me-2"></i>Preview</h5>
        <span class="badge bg-white text-dark">
            Final Book Value: JMD <?= number_format($preview['final_book_value'], 2) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Year</th>
                        <th>Start</th>
                        <th>End</th>
                        <?php if (!empty($preview['periods'][0]['units_consumed'])): ?>
                        <th class="text-end">Units</th>
                        <?php endif; ?>
                        <th class="text-end">Depreciation</th>
                        <th class="text-end">Accumulated</th>
                        <th class="text-end">Book Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['periods'] as $p): ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?= $p['period_number'] ?></td>
                        <td><?= date('d M Y', strtotime($p['period_start'])) ?></td>
                        <td><?= date('d M Y', strtotime($p['period_end'])) ?></td>
                        <?php if (isset($p['units_consumed'])): ?>
                        <td class="text-end"><?= $p['units_consumed'] !== null ? number_format($p['units_consumed']) : '—' ?></td>
                        <?php endif; ?>
                        <td class="text-end text-danger">JMD <?= number_format($p['depreciation_charge'], 2) ?></td>
                        <td class="text-end">JMD <?= number_format($p['accumulated_depreciation'], 2) ?></td>
                        <td class="text-end fw-bold">JMD <?= number_format($p['book_value_end'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-secondary fw-bold">
                    <tr>
                        <td colspan="<?= !empty($preview['periods'][0]['units_consumed']) ? 4 : 3 ?>" class="ps-3">Total</td>
                        <td class="text-end text-danger">JMD <?= number_format($preview['total_depreciation'], 2) ?></td>
                        <td class="text-end">—</td>
                        <td class="text-end">JMD <?= number_format($preview['final_book_value'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
</div>
<?php endif; ?>
</div><!-- .row -->
</div><!-- .container -->

<script>
function updateMethodFields() {
    const m = document.getElementById('methodSelect').value;
    document.getElementById('usefulLifeField').style.display    = (m === 'UNITS_OF_PRODUCTION') ? 'none' : '';
    document.getElementById('rateField').style.display          = (m === 'DECLINING_BALANCE')   ? '' : 'none';
    document.getElementById('totalUnitsField').style.display    = (m === 'UNITS_OF_PRODUCTION') ? '' : 'none';
    document.getElementById('unitsPerPeriodSection').style.display = (m === 'UNITS_OF_PRODUCTION') ? '' : 'none';
}
updateMethodFields();

let uopRows = [];
function addUoPRow() {
    uopRows.push(0);
    renderUoPRows();
}
function renderUoPRows() {
    const container = document.getElementById('unitsPerPeriodRows');
    container.innerHTML = '';
    uopRows.forEach((v, i) => {
        const row = document.createElement('div');
        row.className = 'input-group mb-1';
        row.innerHTML = `<span class="input-group-text">Year ${i+1}</span>
            <input type="number" min="0" class="form-control" value="${v}"
                   onchange="uopRows[${i}]=parseFloat(this.value)||0;updateUoPJson()">
            <button type="button" class="btn btn-outline-danger" onclick="uopRows.splice(${i},1);renderUoPRows()">×</button>`;
        container.appendChild(row);
    });
    updateUoPJson();
}
function updateUoPJson() {
    document.getElementById('unitsPerPeriodJson').value = JSON.stringify(uopRows);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
