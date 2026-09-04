<?php
$REQUIRE_PERMISSION = 'upload_purchase_order';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

/* ================================
   Validate commitment_id
================================ */
$commitment_id = $_GET['commitment_id'] ?? null;

if (!is_numeric($commitment_id) || (int)$commitment_id <= 0) {
    pop("Missing Commitment ID.", "/procurement/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

$commitment_id = (int)$commitment_id;

/* Check if PO already exists for this commitment */
$stmt = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE commitment_id = ? LIMIT 1");
$stmt->execute([$commitment_id]);
$existing_po_id = $stmt->fetchColumn();

if ($existing_po_id) {
    pop("A Purchase Order already exists for this commitment.", "/po/view.php?po_id=$existing_po_id");
    exit;
}

/* ================================
   Fetch commitment + request
================================ */
$stmt = $pdo->prepare("
   SELECT 
        c.commitment_id,
        c.commitment_number,
        c.commitment_total,
        c.commitment_file,
        CASE
            WHEN EXISTS (
                SELECT 1 FROM request_approvals ra
                WHERE ra.entity_type = 'COMMITMENT'
                  AND ra.entity_id = c.commitment_id
                  AND ra.role = 'Finance Officer'
                  AND ra.status = 'approved'
            ) THEN 1
            ELSE 0
        END AS finance_approved,
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.estimated_value,
        pr.status AS request_status,
        pr.currency
   FROM commitments c
   JOIN procurement_requests pr ON c.request_id = pr.request_id
   WHERE c.commitment_id = ?
");
$stmt->execute([$commitment_id]);
$commitment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$commitment) {
    pop("Commitment not found.", "/procurement/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

$currency = 'JMD'; // Commitments/POs are always stored in JMD
$request_id = (int)$commitment['request_id'];

/* ================================
   Generate PO Number
================================ */
$year = date('Y');
$seqStmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_id DESC LIMIT 1");
$seqStmt->execute(["PO-$year-%"]);
$lastPo = $seqStmt->fetchColumn();
$nextNumber = $lastPo ? (int)substr($lastPo, -4) + 1 : 1;
$po_number = sprintf("PO-%s-%04d", $year, $nextNumber);

/* ================================
   Handle POST - Upload PO
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/po/upload.php?commitment_id=' . $commitment_id);
    $uploadedDocumentPath = null;

    try {
        $po_date  = $_POST['po_date'] ?? '';
        $po_total = (float)($_POST['po_total'] ?? 0);
        $gfmsPoNumber = trim($_POST['gfms_po_number'] ?? '');

        if ($po_date === '') {
            throw new Exception("PO date is required.");
        }
        if ($po_total <= 0) {
            throw new Exception("PO total must be greater than zero.");
        }
        if ($po_total > (float)$commitment['commitment_total']) {
            throw new Exception("PO total cannot exceed the committed amount.");
        }

        // Validate GFMS PO number if provided
        if (!empty($gfmsPoNumber)) {
            // Check for uniqueness
            $checkGfms = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE gfms_po_number = ?");
            $checkGfms->execute([$gfmsPoNumber]);
            if ($checkGfms->fetchColumn()) {
                throw new Exception("GFMS PO Number '$gfmsPoNumber' already exists in the system.");
            }
            // Validate format: should be alphanumeric, no special characters
            if (!preg_match('/^[a-zA-Z0-9\-\/\.]+$/', $gfmsPoNumber)) {
                throw new Exception("GFMS PO Number can only contain letters, numbers, hyphens, slashes, and periods.");
            }
            if (strlen($gfmsPoNumber) > 50) {
                throw new Exception("GFMS PO Number cannot exceed 50 characters.");
            }
        }

        // Handle file upload
        if (!isset($_FILES['po_file']) || $_FILES['po_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("PO document upload is required.");
        }

        $storedFile = SecureFileStorage::storeUploadedFile(
            $_FILES['po_file'],
            'po',
            'PO_' . $commitment_id,
            [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            ],
            50 * 1024 * 1024
        );
        $uploadedDocumentPath = $storedFile['storage_path'];

        $pdo->beginTransaction();

        // Check duplicate
        $check = $pdo->prepare("SELECT po_id FROM purchase_orders WHERE commitment_id = ?");
        $check->execute([$commitment_id]);
        if ($check->fetchColumn()) {
            throw new Exception("This commitment already has a Purchase Order.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders
            (commitment_id, po_number, po_date, po_total, po_file, document_path, uploaded_by, upload_date, status, gfms_po_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Open', ?)
        ");
        $stmt->execute([
            $commitment_id,
            $po_number,
            $po_date,
            $po_total,
            $uploadedDocumentPath,
            $uploadedDocumentPath,
            $_SESSION['user_id'],
            !empty($gfmsPoNumber) ? $gfmsPoNumber : null
        ]);

        $po_id = $pdo->lastInsertId();

        // Copy items from procurement request to PO items
        $stmt = $pdo->prepare("
            INSERT INTO po_items (po_id, description, qty, unit_price)
            SELECT ?, CONCAT(item_name, IF(specification IS NOT NULL AND specification != '', CONCAT(' - ', specification), '')),
                   quantity, 0
            FROM procurement_request_items WHERE request_id = ?
        ");
        $stmt->execute([$po_id, $request_id]);

        // Create approval chain: HOD → Finance Officer (consistent with po/add.php)
        $pdo->prepare("
            INSERT INTO request_approvals (entity_type, entity_id, role, stage_order, status) VALUES
            ('PO', ?, 'HOD', 1, 'pending'),
            ('PO', ?, 'Finance Officer', 2, 'pending')
        ")->execute([$po_id, $po_id]);

        // Close commitment
        $pdo->prepare("UPDATE commitments SET status = 'closed' WHERE commitment_id = ?")->execute([$commitment_id]);

        $pdo->commit();

        logAudit($pdo, 'purchase_orders', $po_id, 'UPLOAD', 'Purchase Order uploaded');
        logRequestTimeline($pdo, $request_id, 'PO_UPLOADED', "PO $po_number uploaded, pending approval");

        // Notify about PO creation
        require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
        notifyPOAction($request_id, $po_number, 'CREATED', 'Purchase Order uploaded from GFMS. Pending HOD and Finance approval.');

        header("Location: /procurement/view.php?id=" . $request_id);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($uploadedDocumentPath !== null) {
            SecureFileStorage::deleteStoredFile($uploadedDocumentPath, 'po');
        }
        pop(extractDbMessage($e), "/po/upload.php?commitment_id=" . $commitment_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

/* ================================
   Render page
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
$commitmentTotal = (float)$commitment['commitment_total'];
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h3 class="section-title">📤 Upload Purchase Order</h3>
            <p class="text-muted">Upload the signed purchase order document for this commitment</p>
        </div>
    </div>

    <!-- Context Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📌 Procurement Context</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">Request Number</small>
                    <h6 class="fw-bold text-primary"><?= htmlspecialchars($commitment['request_number']) ?></h6>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Commitment Number</small>
                    <h6 class="fw-bold text-primary"><?= htmlspecialchars($commitment['commitment_number']) ?></h6>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Request Type</small>
                    <h6 class="fw-bold">
                        <?= match($commitment['request_type'] ?? 'REGULAR') {
                            'PETTY_CASH' => '💰 Petty Cash',
                            'REIMBURSEMENT' => '💵 Reimbursement',
                            default => '📋 Regular'
                        } ?>
                    </h6>
                </div>
                <div class="col-12">
                    <div class="p-2 bg-light rounded">
                        <small class="text-muted d-block">Committed Amount (Approved Budget)</small>
                        <p class="mb-0 fs-6 fw-bold text-success"><?= htmlspecialchars($currency) ?> <?= number_format($commitmentTotal, 2) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="card border-secondary mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">📄 Purchase Order Upload</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" id="poUploadForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">

                <div class="mb-4">
                    <label class="form-label"><i class="bi bi-hash"></i> PO Number</label>
                    <div class="input-group">
                        <span class="input-group-text">🔒</span>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($po_number) ?>" readonly>
                    </div>
                    <small class="text-muted">Auto-generated by the system</small>
                </div>

                <div class="mb-4">
                    <label for="gfms_po_number" class="form-label">
                        <i class="bi bi-bank"></i> GFMS PO Number (Optional)
                    </label>
                    <input type="text" id="gfms_po_number" name="gfms_po_number"
                           class="form-control form-control-lg"
                           placeholder="e.g., GC/2026/PO/00001 or GFMS-PO-123456"
                           maxlength="50">
                    <small class="text-muted">
                        Optional: Enter the unique PO number from the GFMS system. If provided, this will enable tracking against GFMS records.
                        <br><strong>Note:</strong> GFMS numbers must be unique across the system.
                    </small>
                </div>

                <div class="mb-4">
                    <label for="po_date" class="form-label">
                        <span class="text-danger">*</span> Purchase Order Date
                    </label>
                    <input type="date" id="po_date" name="po_date" class="form-control form-control-lg" required>
                </div>

                <div class="mb-4">
                    <label for="po_total" class="form-label">
                        <span class="text-danger">*</span> Purchase Order Total (<?= htmlspecialchars($currency) ?>)
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><?= htmlspecialchars($currency) ?></span>
                        <input type="number" id="po_total" name="po_total"
                               class="form-control form-control-lg" step="0.01"
                               value="<?= htmlspecialchars($commitment['commitment_total']) ?>"
                               max="<?= htmlspecialchars($commitment['commitment_total']) ?>" required>
                    </div>
                    <small class="text-muted">Cannot exceed committed amount: <?= htmlspecialchars($currency) ?> <?= number_format($commitmentTotal, 2) ?></small>
                </div>

                <div class="mb-4">
                    <label for="po_file" class="form-label">
                        <span class="text-danger">*</span> PO Document (PDF, DOC, DOCX, XLS, XLSX)
                    </label>
                    <input type="file" id="po_file" name="po_file"
                           class="form-control form-control-lg"
                           accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                    <small class="text-muted">Maximum file size: 10MB</small>
                </div>

                <div class="alert alert-info">
                    <strong>ℹ️ What Happens Next:</strong>
                    <ul class="mb-0 mt-2">
                        <li>PO will be uploaded and sent for HOD and Director HRM&A approval</li>
                        <li>Commitment status will be closed after PO upload</li>
                        <li>Approvers: HOD → Director HRM&A</li>
                    </ul>
                </div>

                <div class="d-grid gap-2 d-sm-flex justify-content-between">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-upload"></i> Upload Purchase Order
                    </button>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>