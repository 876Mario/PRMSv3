<?php
$REQUIRE_PERMISSION = 'create_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/policy.php";


$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
    pop('Invalid request', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===== Fetch request header ===== */
$stmt = $pdo->prepare("
    SELECT *
    FROM procurement_requests
    WHERE request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Allow Procurement Officers (and Admin/SuperAdmin) to edit requests beyond DRAFT
$isProcurementOrAdmin = in_array(($_SESSION['role_name'] ?? ''), ['Procurement Officer', 'Admin', 'SuperAdmin']);

// Procurement can edit at most stages; others can only edit DRAFT
$procurementEditableStatuses = ['DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 
    'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 
    'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'COMMITMENT_DECLINED'];

if ($isProcurementOrAdmin) {
    if (!in_array(strtoupper($request['status']), $procurementEditableStatuses)) {
        pop(
            "This request cannot be edited at its current status: " . $request['status'],
            "/procurement/view.php?id=" . $id,
            2000,
            "error"
        );
        exit;
    }
} else {
    if (strtoupper($request['status']) !== 'DRAFT') {
        pop(
            "Only draft procurement requests can be edited.",
            "/procurement/view.php?id=" . $id,
            2000,
            "error"
        );
        exit;
    }
}

/* ===== Fetch request items ===== */
$itemStmt = $pdo->prepare("
    SELECT *
    FROM procurement_request_items
    WHERE request_id = ?
");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== Handle form submission ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    // Estimated value update
    $estimatedValueRaw = $_POST['estimated_value'] ?? '';
    // Remove all characters except digits and decimal point
    $estimatedValueSanitized = preg_replace('/[^\d.]/', '', $estimatedValueRaw);
    $estimatedValue = floatval($estimatedValueSanitized);
    if ($estimatedValue <= 0) {
        pop('Estimated value must be greater than zero.', '/procurement/edit.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    $description = trim($_POST['description'] ?? '');

    if (empty($_POST['items']) || !is_array($_POST['items'])) {
        pop('At least one item is required', '/procurement/edit.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    $pdo->beginTransaction();

    try {
        // Update header timestamp, estimated value, and description
        $stmt = $pdo->prepare("
            UPDATE procurement_requests
            SET updated_at = NOW(), estimated_value = ?, description = ?
            WHERE request_id = ?
        ");
        $stmt->execute([$estimatedValue, $description, $id]);


/* ===== Capture OLD items for audit ===== */
$oldItemsStmt = $pdo->prepare("
    SELECT item_name, specification, quantity, remarks
    FROM procurement_request_items
    WHERE request_id = ?
");
$oldItemsStmt->execute([$id]);
$oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);


        // Remove old items
        $del = $pdo->prepare("
            DELETE FROM procurement_request_items
            WHERE request_id = ?
        ");
        $del->execute([$id]);

        // Insert new items
        $ins = $pdo->prepare("
            INSERT INTO procurement_request_items
            (request_id, item_name, specification, quantity, remarks)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        

$newItems = [];
$inserted = false;

foreach ($_POST['items'] as $item) {
    if (empty($item['name']) || empty($item['qty'])) {
        continue;
    }

    $inserted = true;

    $row = [
        'item_name'     => $item['name'],
        'specification' => $item['spec'] ?? null,
        'quantity'      => (int)$item['qty'],
        'remarks'       => $item['remarks'] ?? null
    ];

    $newItems[] = $row;

    $ins->execute([
        $id,
        $row['item_name'],
        $row['specification'],
        $row['quantity'],
        $row['remarks']
    ]);
}

if (!$inserted) {
    throw new Exception("At least one valid item must be entered.");
}

/* ===== Build audit log entry ===== */
$userId = $_SESSION['user_id'] ?? 'system';

$notes = "Procurement Request #{$id} edited.\n\n";
$notes .= "OLD ITEMS:\n";

foreach ($oldItems as $o) {
    $notes .= "- {$o['item_name']} | Qty: {$o['quantity']} | {$o['specification']}\n";
}

$notes .= "\nNEW ITEMS:\n";

foreach ($newItems as $n) {
    $notes .= "- {$n['item_name']} | Qty: {$n['quantity']} | {$n['specification']}\n";
}

$audit = $pdo->prepare("
    INSERT INTO audit_log (table_name, record_id, action, changed_by, change_date, notes)
    VALUES (?, ?, ?, ?, NOW(), ?)
");

$audit->execute([
    'procurement_requests',
    $id,
    'EDIT',
    $_SESSION['full_name'] ?? 'System',
    $notes
]);

        $pdo->commit();
        header("Location: /procurement/view.php?id=" . $id);
        exit;

    } catch (Exception $e) {
    $pdo->rollBack();
    error_log('Edit save failed: ' . $e->getMessage());
    pop('Error saving changes. Please try again.', '/procurement/edit.php?id='.$id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

}

// NOW include header.php after all POST logic is complete
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container-fluid mt-4">
    
    <!-- PAGE HEADER -->
    <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h2 class="mb-1">
                    <i class="bi bi-pencil-square me-2"></i>Edit Procurement Request
                </h2>
                <p class="text-muted mb-0">
                    Request #<?= htmlspecialchars($request['request_number']) ?> 
                    <span class="badge <?= !empty($request['decline_reason']) && strtoupper($request['status']) === 'DRAFT' ? 'bg-warning text-dark' : 'bg-info' ?> ms-2">
                        <?= !empty($request['decline_reason']) && strtoupper($request['status']) === 'DRAFT' ? 'Returned for Edit' : 'Draft Mode' ?>
                    </span>
                </p>
            </div>
            <div>
                <span class="badge bg-light text-dark fs-6">
                    <i class="bi bi-clock-history me-1"></i>Last updated: 
                    <?= date('d M Y H:i', strtotime($request['updated_at'] ?? 'now')) ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($request['decline_reason']) && strtoupper($request['status']) === 'DRAFT'): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-3 mb-4">
            <i class="bi bi-arrow-counterclockwise fs-3"></i>
            <div>
                <strong>This request was sent back for edits.</strong>
                <div class="mt-1"><?= nl2br(htmlspecialchars($request['decline_reason'])) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- REQUEST SUMMARY CARD -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Request Type</small>
                    <h6 class="mb-0 fw-semibold">
                        <?php 
                            $typeLabels = [
                                'REGULAR' => '📋 Regular Procurement',
                                'REIMBURSEMENT' => '💵 Reimbursement',
                                'PETTY_CASH' => '💰 Petty Cash'
                            ];
                            echo $typeLabels[$request['request_type']] ?? 'Regular';
                        ?>
                    </h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Estimated Value</small>
                    <div class="input-group">
                        <span class="input-group-text" id="currency_label_edit"><?= ($request['currency'] ?? 'JMD') ?></span>
                        <input type="text" name="estimated_value" id="estimated_value_edit"
                               class="form-control form-control-sm text-success fw-semibold"
                               value="<?= number_format((float)($request['estimated_value'] ?? 0), 2) ?>"
                               required autocomplete="off">
                    </div>
                    <small class="text-muted">You may adjust the estimated value.</small>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Branch</small>
                    <h6 class="mb-0 fw-semibold">
                        <?php 
                            $branchStmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
                            $branchStmt->execute([$request['branch_id']]);
                            $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($branch['branch_name'] ?? 'N/A');
                        ?>
                    </h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Total Items</small>
                    <h6 class="mb-0 fw-semibold">
                        <span id="itemCount"><?= count($items) ?></span> item(s)
                    </h6>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <label for="description" class="form-label fw-bold">Brief Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" maxlength="500"
                              placeholder="Briefly describe the purpose of this procurement request"
                              form="editForm"><?= htmlspecialchars($request['description'] ?? '') ?></textarea>
                    <small class="text-muted">Max 500 characters. This will be shown as a summary on the procurement list.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- ITEMS EDITOR CARD -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-dark text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-list-check me-2"></i>Procurement Items
                </h5>
                <button type="button" class="btn btn-sm btn-light" id="addItemBtn">
                    <i class="bi bi-plus-lg me-1"></i>Add Item
                </button>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" id="editForm">
                <!-- Hidden field to capture estimated value from summary card -->
                <input type="hidden" name="estimated_value" id="hiddenEstimatedValue">
                <div id="itemsContainer">
                    <?php if (empty($items)): ?>
                        <div class="alert alert-info border-0" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            No items added yet. Click "Add Item" to start.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th width="25%"><i class="bi bi-box me-1"></i>Item Name</th>
                                        <th width="25%"><i class="bi bi-pencil me-1"></i>Specification</th>
                                        <th width="15%" class="text-center"><i class="bi bi-123 me-1"></i>Quantity</th>
                                        <th width="25%"><i class="bi bi-chat me-1"></i>Remarks</th>
                                        <th width="10%" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <?php foreach ($items as $index => $i): ?>
                                    <tr class="item-row" data-index="<?= $index ?>">
                                        <td>
                                            <input type="text" name="items[<?= $index ?>][name]"
                                                   value="<?= htmlspecialchars($i['item_name']) ?>"
                                                   class="form-control form-control-sm" placeholder="Enter item name" required>
                                        </td>
                                        <td>
                                            <input type="text" name="items[<?= $index ?>][spec]"
                                                   value="<?= htmlspecialchars($i['specification'] ?? '') ?>"
                                                   class="form-control form-control-sm" placeholder="e.g., Color, Size">
                                        </td>
                                        <td>
                                            <input type="number" name="items[<?= $index ?>][qty]"
                                                   value="<?= (int)$i['quantity'] ?>"
                                                   min="1" class="form-control form-control-sm text-center" required>
                                        </td>
                                        <td>
                                            <input type="text" name="items[<?= $index ?>][remarks]"
                                                   value="<?= htmlspecialchars($i['remarks'] ?? '') ?>"
                                                   class="form-control form-control-sm" placeholder="Notes">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-danger removeBtn" title="Delete item">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ACTION BUTTONS -->
                <div class="d-flex gap-2 mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-2"></i>Save Changes
                    </button>
                    <a href="/procurement/view.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-x-circle me-2"></i>Discard
                    </a>
                </div>
            </form>
        </div>
    </div>

</div>

<style>
.item-row {
    transition: background-color 0.2s ease;
}

.item-row:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.form-control-sm:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
}

.table-hover tbody tr {
    cursor: pointer;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-format estimated value with commas
    const estValInput = document.getElementById('estimated_value_edit');
    if (estValInput) {
        estValInput.addEventListener('input', function(e) {
            let val = this.value.replace(/[^\d.]/g, '');
            if (val) {
                let parts = val.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                this.value = parts.length > 1 ? parts[0] + '.' + parts[1].slice(0,2) : parts[0];
            }
        });
    }
    const addItemBtn = document.getElementById('addItemBtn');
    const itemsContainer = document.getElementById('itemsContainer');
    const editForm = document.getElementById('editForm');
    let itemIndex = <?= count($items) ?>;

    // Add item button
    if (addItemBtn) {
        addItemBtn.addEventListener('click', addItem);
    }

    function addItem() {
        const newRow = document.createElement('tr');
        newRow.className = 'item-row new-item';
        newRow.dataset.index = itemIndex;
        newRow.innerHTML = `
            <td>
                <input type="text" name="items[${itemIndex}][name]"
                       class="form-control form-control-sm" placeholder="Enter item name" required>
            </td>
            <td>
                <input type="text" name="items[${itemIndex}][spec]"
                       class="form-control form-control-sm" placeholder="e.g., Color, Size">
            </td>
            <td>
                <input type="number" name="items[${itemIndex}][qty]"
                       value="1" min="1" class="form-control form-control-sm text-center" required>
            </td>
            <td>
                <input type="text" name="items[${itemIndex}][remarks]"
                       class="form-control form-control-sm" placeholder="Notes">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger removeBtn" title="Delete item">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        let itemsBody = document.getElementById('itemsBody');

        // Show table if it was hidden
        if (!itemsBody) {
            itemsContainer.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th width="25%"><i class="bi bi-box me-1"></i>Item Name</th>
                                <th width="25%"><i class="bi bi-pencil me-1"></i>Specification</th>
                                <th width="15%" class="text-center"><i class="bi bi-123 me-1"></i>Quantity</th>
                                <th width="25%"><i class="bi bi-chat me-1"></i>Remarks</th>
                                <th width="10%" class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            `;
            itemsBody = document.getElementById('itemsBody');
        }

        itemsBody.appendChild(newRow);

        itemIndex++;
        updateItemCount();
        attachRemoveListener(newRow.querySelector('.removeBtn'));
    }

    // Delete item
    document.addEventListener('click', function(e) {
        if (e.target.closest('.removeBtn')) {
            e.preventDefault();
            const row = e.target.closest('tr');
            row.style.opacity = '0.5';
            if (confirm('Remove this item?')) {
                row.remove();
                updateItemCount();
            } else {
                row.style.opacity = '1';
            }
        }
    });

    function attachRemoveListener(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            if (confirm('Remove this item?')) {
                row.remove();
                updateItemCount();
            }
        });
    }

    function updateItemCount() {
        const count = document.querySelectorAll('#itemsBody tr').length;
        const itemCountEl = document.getElementById('itemCount');
        if (itemCountEl) {
            itemCountEl.textContent = count;
        }
    }

    // Form validation and value capture
    editForm.addEventListener('submit', function(e) {
        // Capture estimated value from the visible input
        const estValInput = document.getElementById('estimated_value_edit');
        const hiddenEstVal = document.getElementById('hiddenEstimatedValue');
        if (estValInput && hiddenEstVal) {
            hiddenEstVal.value = estValInput.value;
        }
        
        const rows = document.querySelectorAll('#itemsBody tr');
        if (rows.length === 0) {
            e.preventDefault();
            alert('Please add at least one item before saving.');
            return false;
        }
    });

    // Initialize remove buttons for existing items
    document.querySelectorAll('.removeBtn').forEach(btn => {
        attachRemoveListener(btn);
    });
});
</script>
