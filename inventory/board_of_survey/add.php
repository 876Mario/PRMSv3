<?php
$REQUIRE_PERMISSION = 'manage_board_of_survey';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

/* ── Schema guard ────────────────────────────────────────────────────────── */
$bosReady = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_board_of_survey'"
)->fetchColumn();

if (!$bosReady) {
    pop('Board of Survey tables not found. Run migration first.', '/inventory/dashboard.php', 1800, 'warning');
    exit;
}

/* ── Reference data ──────────────────────────────────────────────────────── */
$items     = $pdo->query(
    "SELECT i.item_id, i.item_code, i.item_name,
            COALESCE(MIN(ad.asset_code), '') AS asset_code,
            COALESCE(MIN(ad.serial_number), '') AS serial_number
     FROM inv_items i
     LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id
     WHERE i.item_status = 'ACTIVE'
     GROUP BY i.item_id, i.item_code, i.item_name
     ORDER BY i.item_name"
)->fetchAll(PDO::FETCH_ASSOC);

$locations = $pdo->query(
    "SELECT location_id, location_code, site_name
     FROM inv_locations WHERE is_active = 1 ORDER BY location_code"
)->fetchAll(PDO::FETCH_ASSOC);

$recommendations = [
    'DISPOSE'   => 'Dispose',
    'REPAIR'    => 'Repair',
    'TRANSFER'  => 'Transfer',
    'WRITE_OFF' => 'Write-Off',
    'RETAIN'    => 'Retain',
    'AUCTION'   => 'Auction',
    'DONATE'    => 'Donate',
    'OTHER'     => 'Other',
];

$conditions = ['Good', 'Fair', 'Poor', 'Damaged', 'Irreparable', 'Obsolete', 'Expired', 'Missing Parts'];

/* ── POST handler ────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $surveyDate   = trim($_POST['survey_date']           ?? '');
        $locationId   = (int)  ($_POST['location_id']        ?? 0);
        $reason       = trim($_POST['reason_for_survey']     ?? '');
        $recommendation = $_POST['board_recommendation']     ?? '';
        $notes        = trim($_POST['supporting_notes']      ?? '');
        $recNotes     = trim($_POST['recommendation_notes']  ?? '');

        if (empty($reason)) {
            throw new Exception('Reason for survey is required.');
        }

        $itemIds   = $_POST['item_id']            ?? [];
        $qtys      = $_POST['quantity']            ?? [];
        $conds     = $_POST['condition_at_survey'] ?? [];
        $itemRecs  = $_POST['item_recommendation'] ?? [];
        $estVals   = $_POST['estimated_value']     ?? [];
        $sNotes    = $_POST['surveyor_notes']      ?? [];
        $aCodes    = $_POST['asset_code']          ?? [];
        $serials   = $_POST['serial_number']       ?? [];

        $validItems = array_filter($itemIds, fn($id) => (int)$id > 0);
        if (empty($validItems)) {
            throw new Exception('At least one item is required.');
        }

        /* Duplicate-check: prevent same item appearing twice in one BOS */
        if (count(array_unique(array_filter($itemIds, fn($id) => (int)$id > 0)))
            !== count(array_filter($itemIds, fn($id) => (int)$id > 0))) {
            throw new Exception('Duplicate items detected. Each item can appear only once per Board of Survey.');
        }

        /* Conflict-check: item must not already be in an open BOS */
        $inItems = implode(',', array_fill(0, count($validItems), '?'));
        $conflict = $pdo->prepare("
            SELECT bi.item_id, b.bos_number
            FROM inv_bos_items bi
            JOIN inv_board_of_survey b ON b.bos_id = bi.bos_id
            WHERE bi.item_id IN ($inItems)
              AND b.status NOT IN ('COMPLETED','REJECTED','CANCELLED')
            LIMIT 1
        ");
        $conflict->execute(array_values(array_filter($itemIds, fn($id) => (int)$id > 0)));
        $conflictRow = $conflict->fetch(PDO::FETCH_ASSOC);
        if ($conflictRow) {
            throw new Exception(
                "Item ID {$conflictRow['item_id']} is already included in open Board of Survey {$conflictRow['bos_number']}."
            );
        }

        $bosNumber = InventoryService::generateDocNumber($pdo, 'BOS', 'inv_board_of_survey', 'bos_number');

        /* Determine action: save as DRAFT or submit immediately */
        $submitAction = ($_POST['submit_action'] ?? 'draft') === 'submit';
        $bosStatus    = $submitAction ? 'SUBMITTED' : 'DRAFT';
        $submittedAt  = $submitAction ? 'NOW()' : 'NULL';

        $pdo->prepare("
            INSERT INTO inv_board_of_survey
                (bos_number, survey_date, location_id, reason_for_survey,
                 board_recommendation, recommendation_notes, supporting_notes,
                 status, initiated_by, submitted_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, $submittedAt, NOW())
        ")->execute([
            $bosNumber,
            $surveyDate ?: null,
            $locationId > 0 ? $locationId : null,
            $reason,
            $recommendation ?: null,
            $recNotes ?: null,
            $notes ?: null,
            $bosStatus,
            $_SESSION['user_id'],
        ]);

        $bosId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare("
            INSERT INTO inv_bos_items
                (bos_id, item_id, asset_code, serial_number, quantity,
                 condition_at_survey, item_recommendation, estimated_value, surveyor_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        for ($i = 0; $i < count($itemIds); $i++) {
            $iid = (int) ($itemIds[$i] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            $qty = (float) ($qtys[$i] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $insertItem->execute([
                $bosId,
                $iid,
                trim($aCodes[$i] ?? ''),
                trim($serials[$i] ?? ''),
                $qty,
                trim($conds[$i] ?? ''),
                $itemRecs[$i] ?: null,
                (float) ($estVals[$i] ?? 0) > 0 ? (float) $estVals[$i] : null,
                trim($sNotes[$i] ?? '') ?: null,
            ]);
        }

        logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, $bosStatus,
            "Board of Survey $bosNumber created as $bosStatus by user {$_SESSION['user_id']}");

        $pdo->commit();
        $successMsg = $submitAction
            ? "Board of Survey $bosNumber submitted for review."
            : "Board of Survey $bosNumber saved as draft.";
        pop($successMsg, "/inventory/board_of_survey/view.php?id=$bosId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<!-- Select2 for searchable item dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard2-pulse"></i> New Board of Survey</h2>
    <a href="/inventory/board_of_survey/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" id="bosForm">
    <!-- Survey Header -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-info-circle"></i> Survey Details
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Survey Date</label>
                    <input type="date" name="survey_date" class="form-control"
                           value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-select select2-location">
                        <option value="">— Not location-specific —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>">
                            <?= htmlspecialchars($loc['location_code'] . ' — ' . $loc['site_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Reason for Survey <span class="text-danger">*</span></label>
                    <input type="text" name="reason_for_survey" class="form-control"
                           placeholder="e.g. Annual asset survey, condition deterioration…" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Board Recommendation</label>
                    <select name="board_recommendation" class="form-select">
                        <option value="">— To be determined —</option>
                        <?php foreach ($recommendations as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Recommendation Notes</label>
                    <input type="text" name="recommendation_notes" class="form-control"
                           placeholder="Brief rationale for the overall recommendation…">
                </div>
                <div class="col-12">
                    <label class="form-label">Supporting Notes / Observations</label>
                    <textarea name="supporting_notes" class="form-control" rows="2"
                              placeholder="Additional context, evidence, or observations…"></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Items for Survey -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ol"></i> Assets / Items for Survey</span>
            <button type="button" class="btn btn-sm btn-light" onclick="addBosRow()">
                <i class="bi bi-plus"></i> Add Row
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0 small">
                    <thead class="table-secondary">
                        <tr>
                            <th style="min-width:220px;">Item / Asset <span class="text-danger">*</span></th>
                            <th style="min-width:110px;">Asset Tag</th>
                            <th style="min-width:110px;">Serial Number</th>
                            <th style="min-width:80px;">Qty</th>
                            <th style="min-width:130px;">Condition</th>
                            <th style="min-width:130px;">Item Recommendation</th>
                            <th style="min-width:100px;">Est. Value ($)</th>
                            <th style="min-width:150px;">Surveyor Notes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="bosBody">
                        <?php
                        /* Build JSON data blob for JS cloning */
                        $itemsJson = json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                        $recsJson  = json_encode($recommendations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                        $condsJson = json_encode($conditions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                        <tr>
                            <td>
                                <select name="item_id[]" class="form-select form-select-sm bos-item-select" required>
                                    <option value="">— Select item —</option>
                                    <?php foreach ($items as $it): ?>
                                    <option value="<?= $it['item_id'] ?>"
                                            data-asset="<?= htmlspecialchars($it['asset_code']) ?>"
                                            data-serial="<?= htmlspecialchars($it['serial_number']) ?>">
                                        <?= htmlspecialchars($it['item_code'] . ' — ' . $it['item_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="asset_code[]" class="form-control form-control-sm"></td>
                            <td><input type="text" name="serial_number[]" class="form-control form-control-sm"></td>
                            <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control form-control-sm text-end" value="1"></td>
                            <td>
                                <select name="condition_at_survey[]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    <?php foreach ($conditions as $c): ?>
                                    <option value="<?= $c ?>"><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="item_recommendation[]" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    <?php foreach ($recommendations as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" step="0.01" min="0" name="estimated_value[]" class="form-control form-control-sm text-end" placeholder="0.00"></td>
                            <td><input type="text" name="surveyor_notes[]" class="form-control form-control-sm" placeholder="Item-level notes…"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="removeBosRow(this)" title="Remove row">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" name="submit_action" value="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-send"></i> Submit for Review
        </button>
        <button type="submit" name="submit_action" value="draft" class="btn btn-outline-secondary btn-lg">
            <i class="bi bi-floppy"></i> Save as Draft
        </button>
        <a href="/inventory/board_of_survey/list.php" class="btn btn-outline-danger btn-lg">
            Cancel
        </a>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
/* ── Select2 for location ─────────────────────────────────────────────────── */
$(document).ready(function () {
    $('.select2-location').select2({
        theme: 'bootstrap-5',
        placeholder: '— Not location-specific —',
        allowClear: true,
        width: '100%',
    });

    initRowSelect2(document.querySelector('#bosBody tr'));
});

/* ── Auto-fill asset tag / serial from selected item ─────────────────────── */
document.getElementById('bosBody').addEventListener('change', function (e) {
    if (!e.target.classList.contains('bos-item-select')) return;
    const row   = e.target.closest('tr');
    const opt   = e.target.options[e.target.selectedIndex];
    const asset  = opt.dataset.asset  || '';
    const serial = opt.dataset.serial || '';
    row.querySelector('[name="asset_code[]"]').value  = asset;
    row.querySelector('[name="serial_number[]"]').value = serial;
});

function initRowSelect2(row) {
    if (!row) return;
    $(row).find('.bos-item-select').select2({
        theme: 'bootstrap-5',
        placeholder: '— Select item —',
        width: '100%',
    }).on('change', function () {
        const opt   = this.options[this.selectedIndex];
        const r     = $(this).closest('tr');
        r.find('[name="asset_code[]"]').val(opt.dataset.asset  || '');
        r.find('[name="serial_number[]"]').val(opt.dataset.serial || '');
    });
}

function addBosRow() {
    const tbody    = document.getElementById('bosBody');
    const firstRow = tbody.querySelector('tr');
    const newRow   = firstRow.cloneNode(true);

    /* Reset all inputs in the cloned row */
    newRow.querySelectorAll('input').forEach(el => {
        el.value = el.type === 'number' && el.name.startsWith('quantity') ? '1' : '';
    });
    newRow.querySelectorAll('select').forEach(el => {
        /* Destroy Select2 on clone to re-init cleanly */
        if ($(el).hasClass('select2-hidden-accessible')) {
            $(el).select2('destroy');
        }
        el.selectedIndex = 0;
    });

    tbody.appendChild(newRow);
    initRowSelect2(newRow);
}

function removeBosRow(btn) {
    const tbody = document.getElementById('bosBody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('At least one item is required.');
        return;
    }
    btn.closest('tr').remove();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
