<?php
$REQUIRE_PERMISSION = 'create_service_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
if ($contract_id <= 0) {
    pop('Invalid contract reference.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===============================
   Fetch Contract
================================ */
try {
    $stmt = $pdo->prepare("
        SELECT sc.*, v.vendor_name
        FROM service_contracts sc
        JOIN vendors v ON sc.vendor_id = v.vendor_id
        WHERE sc.contract_id = ? AND sc.status = 'ACTIVE'
    ");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('contracts/request.php: contract fetch failed: ' . $e->getMessage());
    pop('Unable to load contract details. Please try again.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (!$contract) {
    pop('Contract not found or not active.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$remaining = (float)$contract['total_value'] - (float)$contract['consumed_value'];

/* Get user's branch */
try {
    $branchStmt = $pdo->prepare("SELECT branch_id FROM users WHERE user_id = ?");
    $branchStmt->execute([$_SESSION['user_id']]);
    $userBranch = (int)$branchStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('contracts/request.php: branch fetch failed: ' . $e->getMessage());
    $userBranch = (int)($contract['branch_id'] ?? 0);
}

$nextRequestNumber = previewRequestNumber($pdo);

/* ===============================
   Handle POST
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $description     = trim($_POST['description'] ?? '');
    $estimated_value = (float)($_POST['estimated_value'] ?? 0);
    $service_period  = trim($_POST['service_period'] ?? '');
    $request_date    = trim($_POST['request_date'] ?? date('Y-m-d'));

    $errors = [];
    if ($description === '') $errors[] = 'Description is required.';
    if ($estimated_value <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($estimated_value > $remaining) $errors[] = 'Amount exceeds remaining contract balance (' . money($remaining) . ').';

    // Date validation
    $tz = new DateTimeZone(date_default_timezone_get());
    $reqDate = DateTimeImmutable::createFromFormat('Y-m-d', $request_date, $tz);
    $today = new DateTimeImmutable('today', $tz);
    if (!$reqDate) $errors[] = 'Invalid request date.';
    elseif ($reqDate < $today) $errors[] = 'Request date cannot be in the past.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $nextRequestNumber = generateRequestNumber($pdo);

            // Insert procurement request with SERVICE_CONTRACT type
            $stmt = $pdo->prepare("
                INSERT INTO procurement_requests
                (branch_id, request_number, request_date, description, request_type,
                 status, estimated_value, currency, contract_id, created_by)
                VALUES (?, ?, ?, ?, 'SERVICE_CONTRACT', 'DRAFT', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $contract['branch_id'],
                $nextRequestNumber,
                $request_date,
                $description . ($service_period ? "\n\nService Period: $service_period" : ''),
                $estimated_value,
                $contract['currency'],
                $contract_id,
                $_SESSION['user_id']
            ]);

            $request_id = $pdo->lastInsertId();

            // Add a single item for the service
            $pdo->prepare("
                INSERT INTO procurement_request_items
                (request_id, item_name, specification, quantity, remarks)
                VALUES (?, ?, ?, 1, ?)
            ")->execute([
                $request_id,
                'Service Payment - ' . $contract['contract_title'],
                'Contract: ' . $contract['contract_number'] . ' | Vendor: ' . $contract['vendor_name'],
                $service_period ?: 'As per contract terms'
            ]);

            // Seed approval chain
            $approvalChain = getServiceContractApprovalChain($estimated_value, $contract['branch_id'], $pdo);
            $stageOrder = 1;
            foreach ($approvalChain as $role) {
                $pdo->prepare("
                    INSERT INTO request_approvals
                    (entity_type, entity_id, request_id, role, stage_order, status)
                    VALUES ('REQUEST', ?, ?, ?, ?, 'pending')
                ")->execute([$request_id, $request_id, $role, $stageOrder]);
                $stageOrder++;
            }

            logAudit($pdo, 'procurement_requests', $request_id, 'CREATE',
                "Service contract payment request created against contract {$contract['contract_number']}");

            logRequestTimeline($pdo, $request_id, 'CREATED',
                "Service contract payment request created. Contract: {$contract['contract_number']}, Amount: " . money($estimated_value));

            $pdo->commit();

            pop(
                "Payment request $nextRequestNumber created successfully. Submit it for approval.",
                "/procurement/view.php?id=$request_id",
                2500,
                'success'
            );
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = extractDbMessage($e);
        }
    }

    $error = implode('<br>', $errors);
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
$todayStr = date('Y-m-d');
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="section-title">💳 New Payment Request</h3>
            <p class="text-muted mb-0">Create a payment request against service contract</p>
        </div>
        <a href="/contracts/view.php?id=<?= $contract_id ?>" class="btn btn-secondary btn-sm">← Back to Contract</a>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>⚠️ Error:</strong> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Contract Summary -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Contract Summary</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">Contract</small>
                    <strong><?= htmlspecialchars($contract['contract_number']) ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Vendor</small>
                    <strong><?= htmlspecialchars($contract['vendor_name']) ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Contract Value</small>
                    <span class="badge bg-primary fs-6"><?= money((float)$contract['total_value']) ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Remaining Balance</small>
                    <span class="badge bg-warning text-dark fs-6"><?= money($remaining) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Request Form -->
            <div class="card border-secondary mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Payment Request Details</h5>
                </div>
                <div class="card-body">
                    <form method="post" id="requestForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Request Number</label>
                            <input type="text" value="<?= htmlspecialchars($nextRequestNumber) ?>" class="form-control" readonly>
                        </div>

                        <div class="mb-3">
                            <label for="request_date" class="form-label fw-bold">
                                <span class="text-danger">*</span> Request Date
                            </label>
                            <input type="date" name="request_date" id="request_date"
                                   value="<?= htmlspecialchars($_POST['request_date'] ?? $todayStr) ?>"
                                   class="form-control" required min="<?= $todayStr ?>">
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-bold">
                                <span class="text-danger">*</span> Description / Purpose
                            </label>
                            <textarea name="description" id="description" rows="4" class="form-control" required
                                      placeholder="Describe the service being paid for..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            <small class="text-muted">What service is being invoiced? Include any relevant details.</small>
                        </div>

                        <div class="mb-3">
                            <label for="service_period" class="form-label fw-bold">Service Period</label>
                            <input type="text" name="service_period" id="service_period"
                                   value="<?= htmlspecialchars($_POST['service_period'] ?? '') ?>"
                                   class="form-control" placeholder="e.g., July 2026, Q3 2026, 1-15 July 2026">
                            <small class="text-muted">The period the service covers</small>
                        </div>

                        <div class="mb-4">
                            <label for="estimated_value" class="form-label fw-bold">
                                <span class="text-danger">*</span> Payment Amount (<?= htmlspecialchars($contract['currency']) ?>)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><?= htmlspecialchars($contract['currency']) ?></span>
                                <input type="number" step="0.01" name="estimated_value" id="estimated_value"
                                       value="<?= htmlspecialchars($_POST['estimated_value'] ?? '') ?>"
                                       class="form-control form-control-lg" min="0.01"
                                       max="<?= number_format($remaining, 2, '.', '') ?>" required
                                       placeholder="0.00">
                            </div>
                            <small class="text-muted">Cannot exceed remaining contract balance (<?= money($remaining) ?>)</small>
                        </div>

                        <div class="d-grid gap-2 d-sm-flex justify-content-between">
                            <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Create this payment request?')">
                                <i class="bi bi-check-circle me-1"></i>Create Request
                            </button>
                            <a href="/contracts/view.php?id=<?= $contract_id ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-arrow-left me-1"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm bg-light mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">💡 Workflow</h5>
                </div>
                <div class="card-body small">
                    <ol class="mb-0 ps-3">
                        <li><strong>Create</strong> this payment request</li>
                        <li><strong>Submit</strong> for Branch Head approval</li>
                        <li><strong>Branch Head approves</strong></li>
                        <li><strong>Finance verifies funds</strong> & creates commitment</li>
                        <li><strong>Invoice submitted</strong></li>
                        <li><strong>Payment recorded</strong> → Complete</li>
                    </ol>
                    <hr>
                    <p class="mb-0 text-muted"><strong>No PO required</strong> for service contract payments.</p>
                </div>
            </div>

            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Note:</strong> The payment amount cannot exceed the remaining contract balance. If more funds are needed, the contract must be amended.
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
