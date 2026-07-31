<?php
$REQUIRE_PERMISSION = 'submit_stock_requisition';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$locations = getActiveLocations($pdo);
$branches = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'] ?? 'draft';
        $reqNumber = generateRequisitionNumber($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO inv_requisitions (requisition_number, requester_user_id, department_id, cost_centre,
                intended_use, destination_location_id, urgency, justification, emergency_reason_code, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = ($action === 'submit') ? 'SUBMITTED' : 'DRAFT';

        $stmt->execute([
            $reqNumber,
            $_SESSION['user_id'],
            ($_POST['department_id'] ?? null) ?: null,
            trim($_POST['cost_centre'] ?? '') ?: null,
            trim($_POST['intended_use'] ?? '') ?: null,
            ($_POST['destination_location_id'] ?? null) ?: null,
            $_POST['urgency'] ?? 'NORMAL',
            trim($_POST['justification'] ?? '') ?: null,
            trim($_POST['emergency_reason_code'] ?? '') ?: null,
            $status,
        ]);

        $reqId = (int) $pdo->lastInsertId();
        $duplicateFound = false;

        // Save items
        if (!empty($_POST['items'])) {
            $itemStmt = $pdo->prepare("
                INSERT INTO inv_requisition_items (requisition_id, item_id, quantity_requested, remarks, stock_available_at_request)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($_POST['items'] as $lineItem) {
                $liItemId = (int) ($lineItem['item_id'] ?? 0);
                $liQty = (float) ($lineItem['quantity'] ?? 0);
                if ($liItemId <= 0 || $liQty <= 0) continue;

                $available = getItemAvailableStock($pdo, $liItemId);

                // Duplicate check: same item requested in last 7 days by same user
                $dupCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM inv_requisitions rq
                    JOIN inv_requisition_items ri ON rq.requisition_id = ri.requisition_id
                    WHERE rq.requester_user_id = ? AND ri.item_id = ?
                      AND rq.status NOT IN ('CANCELLED','REJECTED')
                      AND rq.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND rq.requisition_id != ?
                ");
                $dupCheck->execute([$_SESSION['user_id'], $liItemId, $reqId]);
                if ($dupCheck->fetchColumn() > 0) $duplicateFound = true;

                $itemStmt->execute([
                    $reqId, $liItemId, $liQty,
                    trim($lineItem['remarks'] ?? '') ?: null,
                    $available,
                ]);
            }
        }

        if ($duplicateFound) {
            $pdo->prepare("UPDATE inv_requisitions SET is_duplicate_flagged = 1 WHERE requisition_id = ?")
                ->execute([$reqId]);
        }

        // Create document record
        createInvDocument($pdo, 'REQUISITION', 'inv_requisitions', $reqId);
        logInventoryAudit($pdo, 'inv_requisitions', $reqId, 'CREATE', "Requisition $reqNumber created ($status)");

        $pdo->commit();
        pop("Requisition $reqNumber created.", "/inventory/requisitions/view.php?id=$reqId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard-plus"></i> New Stock Requisition</h2>
    <a href="/inventory/requisitions/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" id="reqForm">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-dark"><i class="bi bi-info-circle"></i> Requisition Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cost Centre</label>
                    <input type="text" name="cost_centre" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Urgency</label>
                    <select name="urgency" class="form-select">
                        <option value="NORMAL">Normal</option>
                        <option value="URGENT">Urgent</option>
                        <option value="EMERGENCY">Emergency</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Destination Location</label>
                    <select name="destination_location_id" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['location_id'] ?>"><?= htmlspecialchars($l['location_code'] . ' - ' . ($l['building'] ?? '') . ' ' . ($l['room_storage_area'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Intended Use</label>
                    <input type="text" name="intended_use" class="form-control" placeholder="Purpose of requisition">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Justification</label>
                    <input type="text" name="justification" class="form-control">
                </div>
                <div class="col-md-4" id="emergencyReasonDiv" style="display:none;">
                    <label class="form-label">Emergency Reason Code</label>
                    <input type="text" name="emergency_reason_code" class="form-control">
                </div>
            </div>
        </div>
    </div>

    <!-- Line Items -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-dark d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-check"></i> Items Requested</span>
            <button type="button" class="btn btn-sm btn-light" id="addItemRow"><i class="bi bi-plus"></i> Add Item</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="itemsTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40%">Item</th>
                            <th style="width:15%">Available Stock</th>
                            <th style="width:15%">Quantity Required</th>
                            <th style="width:25%">Remarks</th>
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <select name="items[0][item_id]" class="form-select item-select" required>
                                    <option value="">-- Search by code or name --</option>
                                </select>
                            </td>
                            <td><span class="stock-display text-muted">-</span></td>
                            <td><input type="number" step="0.01" min="0.01" name="items[0][quantity]" class="form-control" required></td>
                            <td><input type="text" name="items[0][remarks]" class="form-control"></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-end mb-4">
        <button type="submit" name="action" value="draft" class="btn btn-outline-secondary btn-lg me-2">
            <i class="bi bi-save"></i> Save Draft
        </button>
        <button type="submit" name="action" value="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-send"></i> Submit Requisition
        </button>
    </div>
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let rowIdx = 1;

$(document).ready(function() {
    initializeSelect2();
    document.getElementById('addItemRow').addEventListener('click', addItemRow);
    document.querySelector('[name="urgency"]').addEventListener('change', function() {
        document.getElementById('emergencyReasonDiv').style.display = this.value === 'EMERGENCY' ? 'block' : 'none';
    });
});

function initializeSelect2() {
    $('#itemsTable .item-select').select2({
        theme: 'bootstrap-5',
        placeholder: '-- Search by code or name --',
        allowClear: true,
        ajax: {
            url: '/inventory/items/search_api.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    limit: 50
                };
            },
            processResults: function(data) {
                return {
                    results: data.results || [],
                    pagination: data.pagination || {}
                };
            },
            cache: true
        },
        minimumInputLength: 1
    }).on('change', function() {
        updateStockDisplay($(this));
    });
}

function addItemRow() {
    const tbody = document.querySelector('#itemsTable tbody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select name="items[${rowIdx}][item_id]" class="form-select item-select" required></select></td>
        <td><span class="stock-display text-muted">-</span></td>
        <td><input type="number" step="0.01" min="0.01" name="items[${rowIdx}][quantity]" class="form-control" required></td>
        <td><input type="text" name="items[${rowIdx}][remarks]" class="form-control"></td>
        <td><button type="button" class="btn btn-sm btn-danger removeRow">×</button></td>
    `;
    tbody.appendChild(row);
    
    // Initialize Select2 on the new select
    $(row).find('.item-select').select2({
        theme: 'bootstrap-5',
        placeholder: '-- Search by code or name --',
        allowClear: true,
        ajax: {
            url: '/inventory/items/search_api.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    limit: 50
                };
            },
            processResults: function(data) {
                return {
                    results: data.results || [],
                    pagination: data.pagination || {}
                };
            },
            cache: true
        },
        minimumInputLength: 1
    }).on('change', function() {
        updateStockDisplay($(this));
    });
    
    rowIdx++;
}

function updateStockDisplay(selectElement) {
    const itemId = selectElement.val();
    const row = selectElement.closest('tr');
    const badge = row.find('.stock-display');
    
    if (!itemId) {
        badge.text('-').removeClass('text-success text-danger').addClass('text-muted');
        return;
    }
    
    fetch('/inventory/items/get_stock_level.php?item_id=' + encodeURIComponent(itemId))
        .then(r => r.json())
        .then(d => {
            const stock = d.available !== undefined ? parseFloat(d.available) : 0;
            badge.text(stock.toFixed(2))
                 .removeClass('text-muted text-success text-danger')
                 .addClass(stock > 0 ? 'text-success' : 'text-danger');
        })
        .catch(() => {
            badge.text('-').removeClass('text-success text-danger').addClass('text-muted');
        });
}

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('removeRow')) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>