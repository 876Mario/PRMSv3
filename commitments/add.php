<?php
$REQUIRE_PERMISSION = 'create_commitment';  // Finance Officers & Procurement Officers
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";

// Allow Finance Officers AND Procurement Officers
$allowedCommitmentRoles = ['Finance Officer', 'Procurement Officer', 'Admin', 'SuperAdmin'];
if (!in_array(($_SESSION['role'] ?? ''), $allowedCommitmentRoles)) {
    pop("Only Finance and Procurement Officers can manage commitments.", "/procurement/list.php", 2500, "warning");
    exit;
}

$userRole = $_SESSION['role'] ?? '';
$isFinance = in_array($userRole, ['Finance Officer', 'Admin', 'SuperAdmin']);
$isProcurement = in_array($userRole, ['Procurement Officer', 'Admin', 'SuperAdmin']);

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($request_id <= 0) {
    pop("Invalid Request", "/procurement/list.php", 2500, "warning");
    exit;
}

// Shared MIME-to-extension mappings for file upload security
$mimeToExtDocuments = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];

$mimeToExtForms = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/* ===== Get Request & Quote Details ===== */
$stmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.status,
        pr.estimated_value,
        pr.currency,
        pr.usd_rate,
        pr.requires_rfq,
        pr.request_type,
        pr.commitment_form_path,
        pr.work_performed,
        pr.goods_delivered,
        pr.po_requirement_notes,
        rf.rfq_id,
        rq.quote_id,
        rq.quote_amount,
        rq.gct_amount,
        rq.validity_days,
        rq.currency AS quote_currency,
        rq.usd_rate AS quote_usd_rate,
        rv.vendor_name
    FROM procurement_requests pr
    LEFT JOIN rfqs rf ON pr.request_id = rf.request_id
    LEFT JOIN rfq_quotes rq ON rq.is_selected = 1 
        AND rq.rfq_vendor_id IN (SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = rf.rfq_id)
    LEFT JOIN rfq_vendors rv ON rv.rfq_vendor_id = rq.rfq_vendor_id
    WHERE pr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Request not found", "/procurement/list.php", 2500, "warning");
    exit;
}

// Check if funds already verified but commitment not yet uploaded
$existingCommitment = null;
$fundsVerified = false;
$checkStmt = $pdo->prepare("SELECT * FROM commitments WHERE request_id = ? AND commitment_type = 'ORIGINAL'");
$checkStmt->execute([$request_id]);
$existingCommitment = $checkStmt->fetch(PDO::FETCH_ASSOC);

// Determine current step based on request status, role, and existing commitment
$currentStep = 'verify_funds'; // Step 1: Finance verifies funds
$requestStatus = strtoupper($request['status']);

// "Proceed Without RFQ" path: the funds-verification stage is bypassed entirely.
// The request goes straight from Branch Head/HOD approval (via AWARDED) to the
// optional commitment form and then commitment creation — no FUNDS_VERIFIED
// status transition, approval, task, or notification is triggered.
$isSkipRfqPath = isSkipRfqPath((string)($request['request_type'] ?? ''), $request['rfq_id'] ?? null, $requestStatus);
if ($isSkipRfqPath && $requestStatus === 'AWARDED') {
    $currentStep = 'upload_form';
}

if ($requestStatus === 'FUNDS_VERIFIED') {
    // Both Finance and Procurement can upload an optional commitment form
    $currentStep = 'upload_form';
}
if ($requestStatus === 'COMMITMENTS_PENDING') {
    // Commitment form submitted (or skipped) — Finance should create the commitment
    $currentStep = $isFinance ? 'create_commitment' : 'upload_form';
}
if ($existingCommitment && !empty($existingCommitment['document_path']) && $existingCommitment['status'] === 'closed') {
    $currentStep = 'completed'; // Already done
}

// Role access control per step
if ($currentStep === 'verify_funds' && !$isFinance) {
    if ($requestStatus === 'AWARDED') {
        // For RFQ-path requests awarded pending funds verification, Procurement Officers
        // can upload the optional commitment form while Finance verifies funds.
        $currentStep = 'upload_form';
    } else {
        pop("Funds have not been verified yet. Finance Officers must verify funds first.", "/procurement/view.php?id=" . $request_id, 2500, "warning");
        exit;
    }
}
if ($currentStep === 'upload_form' && !$isProcurement && !$isFinance) {
    // Only Procurement and Finance can upload commitment forms
    $currentStep = 'create_commitment';
}

// Verify request is in correct status for commitment creation
$allowedStatuses = ['QUOTE_APPROVED', 'COMMITMENT_REVIEW_PENDING', 'AWARDED', 'FUNDS_VERIFIED', 'COMMITMENTS_PENDING', 'HOD_APPROVED', 'GC_APPROVED', 'DIRECTOR_APPROVED'];
if (!in_array(strtoupper($request['status']), $allowedStatuses)) {
    pop(
        "This request is not ready for commitment creation. Current status: " . $request['status'],
        "/procurement/view.php?id=" . $request_id,
        2500,
        "warning"
    );
    exit;
}

// Calculate JMD amount for commitment if currency is USD
$requestCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
$requestUsdRate = (float)($request['usd_rate'] ?? 0);
$quoteForCommitment = (float)($request['quote_amount'] ?? $request['estimated_value']);
$quoteCurrency = normalizeCurrency($request['quote_currency'] ?? $requestCurrency);
$quoteUsdRate = (float)($request['quote_usd_rate'] ?? $requestUsdRate);

// Get system USD rate as fallback
$sysRateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
$sysRateStmt->execute();
$systemUsdRate = (float)($sysRateStmt->fetchColumn() ?: 155.00);

// Auto-convert to JMD if quote/request is in USD
$commitmentDefaultAmount = $quoteForCommitment;
if ($quoteCurrency === 'USD' || $requestCurrency === 'USD') {
    $rateToUse = $quoteUsdRate > 0 ? $quoteUsdRate : ($requestUsdRate > 0 ? $requestUsdRate : $systemUsdRate);
    $commitmentDefaultAmount = $quoteForCommitment * $rateToUse;
}

/* ===== Handle POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    try {
        if ($action === 'decline') {
            /* ===== DECLINE COMMITMENT ===== */
            $declineReason = trim($_POST['decline_reason'] ?? '');
            
            if (empty($declineReason) || strlen($declineReason) < 10) {
                throw new Exception("Please provide a detailed reason for declining (minimum 10 characters).");
            }
            
            if (strlen($declineReason) > 1000) {
                throw new Exception("Reason must not exceed 1000 characters.");
            }
            
            $pdo->beginTransaction();
            
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'COMMITMENT_DECLINED'
                WHERE request_id = ?
            ")->execute([$request_id]);
            
            logAudit($pdo, 'procurement_requests', $request_id, 'COMMITMENT_DECLINED', 
                     "Finance declined - Reason: " . substr($declineReason, 0, 100));
            
            logRequestTimeline($pdo, $request_id, 'COMMITMENT_DECLINED',
                              "Finance Officer: Funds not available. Reason: $declineReason");
            
            $pdo->commit();
            
            require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
            notifyCommitmentAction($request_id, '', 'DECLINED', 'Finance Officer declined commitment. Reason: ' . $declineReason);
            
            pop(
                "Commitment declined. The request has been returned to the requestor for revision.",
                "/procurement/view.php?id=" . $request_id,
                2500,
                "success"
            );
            exit;
            
        } elseif ($action === 'verify_funds') {
            /* ===== STEP 1: VERIFY FUNDS (Finance) ===== */
            if (!$isFinance) {
                throw new Exception("Only Finance Officers can verify funds.");
            }
            if ($isSkipRfqPath) {
                // Business rule: "Proceed Without RFQ" bypasses the funds-verification
                // stage completely — the request must not transition to FUNDS_VERIFIED.
                throw new Exception("Funds verification is bypassed for requests that proceeded without an RFQ. Create the commitment directly.");
            }
            
            $pdo->beginTransaction();
            
            $pdo->prepare("
                UPDATE procurement_requests
                SET status = 'FUNDS_VERIFIED', funds_available = 1,
                    finance_reviewed_by = ?, finance_reviewed_at = NOW()
                WHERE request_id = ?
            ")->execute([$_SESSION['user_id'], $request_id]);
            
            logAudit($pdo, 'procurement_requests', $request_id, 'FUNDS_VERIFIED',
                    "Funds verified by Finance Officer");
            logRequestTimeline($pdo, $request_id, 'FUNDS_VERIFIED',
                              "Finance Officer verified funds are available. Procurement Officer to fill commitment form.");
            
            $pdo->commit();

            // Notify Procurement Officers to fill commitment form
            require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
            notifyProcurementCommitmentFormNeeded($request_id);
            
            pop(
                "Funds verified successfully. Procurement has been notified to fill out the commitment form.",
                "/procurement/view.php?id=" . $request_id,
                2500,
                "success"
            );
            exit;
            
        } elseif ($action === 'submit_commitment_form') {
            /* ===== STEP 2: PROCUREMENT/FINANCE UPLOADS OPTIONAL COMMITMENT FORM ===== */
            if (!$isProcurement && !$isFinance) {
                throw new Exception("Only Procurement or Finance Officers can upload the commitment form.");
            }
            
            // Handle optional commitment form upload
            $formDocPath = null;
            if (isset($_FILES['commitment_form_doc']) && $_FILES['commitment_form_doc']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['commitment_form_doc'];
                
                $allowedTypes = array_keys($mimeToExtForms);
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception("Invalid file type. Only PDF, Word, Excel, JPEG and PNG files are allowed.");
                }
                if ($file['size'] > 50 * 1024 * 1024) {
                    throw new Exception("File size exceeds 50 MB limit.");
                }
                
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/commitments/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = $mimeToExtForms[$mimeType] ?? 'bin';
                $safeFilename = 'COMMIT_FORM_' . time() . '_' . uniqid() . '.' . $ext;
                $uploadPath = $uploadDir . $safeFilename;
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    throw new Exception("Failed to save commitment form document.");
                }
                $formDocPath = '/uploads/commitments/' . $safeFilename;
            }
            
            $pdo->beginTransaction();
            
            $uploaderRole = $isFinance ? 'Finance Officer' : 'Procurement Officer';
            
            // Store the uploaded form path
            if ($formDocPath) {
                $stmt = $pdo->prepare("
                    UPDATE procurement_requests
                    SET commitment_form_path = ?
                    WHERE request_id = ?
                ");
                $stmt->execute([$formDocPath, $request_id]);
                
                logAudit($pdo, 'procurement_requests', $request_id, 'FORM_UPLOADED',
                        "Commitment form uploaded by $uploaderRole: $formDocPath");
            } else {
                logAudit($pdo, 'procurement_requests', $request_id, 'FORM_SKIPPED',
                        "$uploaderRole skipped commitment form upload (optional step).");
            }
            
            // Advance status to COMMITMENTS_PENDING so Finance knows to create
            // the commitment. This applies to both skip-RFQ (AWARDED) and
            // normal (FUNDS_VERIFIED) paths — ensures the pipeline progresses.
            if (in_array($requestStatus, ['AWARDED', 'FUNDS_VERIFIED'])) {
                $pdo->prepare("
                    UPDATE procurement_requests
                    SET status = 'COMMITMENTS_PENDING'
                    WHERE request_id = ?
                ")->execute([$request_id]);

                if ($isSkipRfqPath && $requestStatus === 'AWARDED') {
                    // Business rule: record that the RFQ/funds-verification path was
                    // intentionally bypassed due to the "Proceed Without RFQ" selection.
                    logAudit($pdo, 'procurement_requests', $request_id, 'FUNDS_VERIFICATION_BYPASSED',
                            'Funds verification stage intentionally bypassed — request proceeded without RFQ.');
                }
            }
            
            $timelineMsg = $formDocPath
                ? "$uploaderRole uploaded commitment form. Finance to create commitment in GFMS and upload document."
                : "$uploaderRole proceeded without uploading commitment form. Finance to create commitment in GFMS.";
            logRequestTimeline($pdo, $request_id, 'COMMITMENTS_PENDING', $timelineMsg);
            
            $pdo->commit();
            
            // Notify Finance Officers to create commitment in GFMS and upload document
            require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
            notifyFinanceCommitmentUploadNeeded($request_id, "");
            
            if ($isFinance) {
                // Finance goes directly to Step 3 (create commitment)
                pop(
                    $formDocPath ? "Commitment form uploaded successfully. Proceed to create the commitment." : "Proceeding to create commitment in GFMS.",
                    "/commitments/add.php?request_id=" . $request_id,
                    2500,
                    "success"
                );
            } else {
                pop(
                    $formDocPath ? "Commitment form uploaded successfully. Finance has been notified to create the commitment in GFMS." : "Ready for commitment creation. Finance will create the commitment in GFMS.",
                    "/procurement/view.php?id=" . $request_id,
                    2500,
                    "success"
                );
            }
            exit;
            
        } elseif ($action === 'upload_commitment') {
            /* ===== STEP 3: FINANCE CREATES COMMITMENT & UPLOADS DOCUMENT ===== */
            if (!$isFinance) {
                throw new Exception("Only Finance Officers can create commitments.");
            }
            
            $commitmentDate  = trim($_POST['commitment_date'] ?? '');
            $commitmentTotal = $_POST['commitment_total'] ?? null;
            $gfmsNumber      = trim($_POST['gfms_commitment_number'] ?? '');
            
            // UPDATED: Use derived PO requirement from request flags
            // If both work_performed AND goods_delivered are true, PO not required
            // Otherwise, default to requiring PO unless explicitly overridden
            $derivedPoRequired = getDerivedPoRequired($request); // Uses shouldRequirePoAtCreation()
            $poRequired = strtoupper(trim($_POST['po_required'] ?? $derivedPoRequired)); // Allow user to override but default to derived
            
            if (empty($commitmentDate)) {
                throw new Exception("Commitment date is required.");
            }
            
            if (empty($commitmentTotal) || (float)$commitmentTotal <= 0) {
                throw new Exception("Commitment amount must be greater than zero.");
            }

            if (!in_array($poRequired, ['YES', 'NO'], true)) {
                throw new Exception("Please indicate whether a Purchase Order is required (Yes or No).");
            }
            
            // Get GFMS number if provided
            $gfmsNumber = trim($_POST['gfms_commitment_number'] ?? '');
            
            if (!in_array($poRequired, ['YES', 'NO'], true)) {
               throw new Exception("Please indicate whether a Purchase Order is required (Yes or No).");
            }
            
            // Validate GFMS number if provided
            if (!empty($gfmsNumber)) {
               $checkGfms = $pdo->prepare("SELECT commitment_id FROM commitments WHERE gfms_commitment_number = ? LIMIT 1");
               $checkGfms->execute([$gfmsNumber]);
               if ($checkGfms->fetchColumn()) {
                   throw new Exception("This GFMS Commitment Number already exists in the system.");
               }
               if (!preg_match('/^[a-zA-Z0-9\-\/\.]+$/', $gfmsNumber)) {
                   throw new Exception("Invalid GFMS number format.");
               }
               if (strlen($gfmsNumber) > 50) {
                   throw new Exception("GFMS number too long (max 50 chars).");
               }
            }
            
            $pdo->beginTransaction();
            
            // Always create new commitment record (Procurement no longer creates commitments)
            $commitmentNumber = generateCommitmentNumber($pdo);
            
            // Get contract_id if this is a SERVICE_CONTRACT request
            $contractIdForCommitment = null;
            $reqTypeStmt = $pdo->prepare("SELECT request_type, contract_id FROM procurement_requests WHERE request_id = ?");
            $reqTypeStmt->execute([$request_id]);
            $reqTypeData = $reqTypeStmt->fetch(PDO::FETCH_ASSOC);
            if ($reqTypeData && $reqTypeData['request_type'] === 'SERVICE_CONTRACT') {
                $contractIdForCommitment = $reqTypeData['contract_id'] ? (int)$reqTypeData['contract_id'] : null;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO commitments
                (request_id, commitment_number, commitment_date, commitment_total, gfms_commitment_number, document_path, status, approved_at, commitment_type, contract_id, po_required)
                VALUES (?, ?, ?, ?, ?, ?, 'closed', NOW(), 'ORIGINAL', ?, ?)
            ");
            $stmt->execute([
                $request_id,
                $commitmentNumber,
                $commitmentDate,
                (float)$commitmentTotal,
                !empty($gfmsNumber) ? $gfmsNumber : null,
                $documentPath,
                $contractIdForCommitment,
                $poRequired
            ]);
            $commitment_id = $pdo->lastInsertId();
            
            // Update request status based on workflow path
            if ($poRequired === 'NO') {
                // Non-PO workflow: Set workflow_path flag and keep status at COMMITMENT_APPROVED
                applyNonPoWorkflow($pdo, $request_id);
            } else {
                // Standard path: Set workflow_path = STANDARD and status = COMMITMENT_APPROVED
                $pdo->prepare("
                    UPDATE procurement_requests
                    SET status        = 'COMMITMENT_APPROVED',
                        workflow_path = 'STANDARD'
                    WHERE request_id = ?
                ")->execute([$request_id]);
            }
            
            // Store scanned commitment form if uploaded
            if ($formDocPath) {
                $pdo->prepare("
                    UPDATE procurement_requests
                    SET commitment_form_path = ?
                    WHERE request_id = ?
                ")->execute([$formDocPath, $request_id]);
            }
            
            $isServiceContract = ($reqTypeData && $reqTypeData['request_type'] === 'SERVICE_CONTRACT');
            if ($poRequired === 'NO') {
                $nextStepMsg = "Finance Officer created commitment $commitmentNumber. PO not required — request routed to Skip-RFQ workflow for payment/disbursement.";
            } elseif ($isServiceContract) {
                $nextStepMsg = "Finance Officer created commitment $commitmentNumber. Ready for invoice submission.";
            } else {
                $nextStepMsg = "Finance Officer created commitment $commitmentNumber in GFMS and uploaded commitment document. Ready for PO creation.";
            }
            
            logAudit($pdo, 'commitments', $commitment_id, 'CREATE',
                    "Commitment created by Finance Officer from GFMS and document uploaded");
            
            logRequestTimeline($pdo, $request_id, 'COMMITMENT_APPROVED', $nextStepMsg);
            
            $pdo->commit();
            
            // Notify about commitment creation — email Procurement Officers
            require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
            if ($poRequired === 'NO') {
                notifyCommitmentAction($request_id, $commitmentNumber, 'APPROVED', 'Commitment created. No PO required — request routed to Skip-RFQ disbursement workflow.');
            } else {
                notifyCommitmentAction($request_id, $commitmentNumber, 'APPROVED', 'Funds verified and commitment created from GFMS. Ready for PO creation.');
                notifyProcurementOfCommitment($request_id, $commitmentNumber);
            }

            $successMsg = $poRequired === 'NO'
                ? "Commitment created successfully. No PO required — request has been routed to the Skip-RFQ disbursement workflow."
                : "Commitment created in GFMS and document uploaded successfully. Procurement has been notified. Request moves to PO creation stage.";
            
            pop(
                $successMsg,
                "/commitments/view.php?commitment_id=" . $commitment_id,
                2500,
                "success"
            );
            exit;
            
        } else {
            throw new Exception("Invalid action.");
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        pop(extractDbMessage($e), "/commitments/add.php?request_id=" . $request_id, 2500, "error");
        exit;
    }
}

/* ===== Render Page ===== */
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-lg-10">
            <h3 class="section-title">💰 Commitment Workflow</h3>
            <p class="text-muted">
                <?php if ($isFinance && $currentStep === 'verify_funds'): ?>
                    Step 1: Verify funds availability
                <?php elseif (($isProcurement || $isFinance) && $currentStep === 'upload_form'): ?>
                    Step 2: Upload commitment form (optional)
                <?php elseif ($isFinance && $currentStep === 'create_commitment'): ?>
                    Step 3: Create commitment in GFMS and upload document
                <?php else: ?>
                    Commitment creation complete
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Step Progress Indicator -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-center gap-4">
                <div class="text-center">
                    <?php
                    $step1Done = in_array($currentStep, ['upload_form', 'create_commitment', 'completed']);
                    $step1Active = $currentStep === 'verify_funds';
                    ?>
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $step1Active ? 'bg-primary text-white' : ($step1Done ? 'bg-success text-white' : 'bg-light text-muted') ?>" style="width:48px;height:48px;font-size:1.2rem;font-weight:bold;"><?= $isSkipRfqPath ? '<i class="bi bi-skip-forward" role="img" aria-label="Step bypassed" title="Step bypassed — proceeded without RFQ"></i>' : '1' ?></div>
                    <div class="small fw-bold">Verify Funds<?= $isSkipRfqPath ? ' (Bypassed)' : '' ?></div>
                    <div class="small text-muted"><?= $isSkipRfqPath ? 'No RFQ — not required' : 'Finance' ?></div>
                </div>
                <div class="d-flex align-items-center mb-4"><i class="bi bi-arrow-right fs-4 text-muted"></i></div>
                <div class="text-center">
                    <?php
                    $step2Done = in_array($currentStep, ['create_commitment', 'completed']);
                    $step2Active = $currentStep === 'upload_form';
                    ?>
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $step2Active ? 'bg-primary text-white' : ($step2Done ? 'bg-success text-white' : 'bg-light text-muted') ?>" style="width:48px;height:48px;font-size:1.2rem;font-weight:bold;">2</div>
                    <div class="small fw-bold">Upload Form</div>
                    <div class="small text-muted">Procurement/Finance (Optional)</div>
                </div>
                <div class="d-flex align-items-center mb-4"><i class="bi bi-arrow-right fs-4 text-muted"></i></div>
                <div class="text-center">
                    <?php
                    $step3Done = $currentStep === 'completed';
                    $step3Active = $currentStep === 'create_commitment';
                    ?>
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $step3Active ? 'bg-primary text-white' : ($step3Done ? 'bg-success text-white' : 'bg-light text-muted') ?>" style="width:48px;height:48px;font-size:1.2rem;font-weight:bold;">3</div>
                    <div class="small fw-bold">Create Commitment</div>
                    <div class="small text-muted">Finance</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Request & Quote Details Card -->
    <div class="card mb-4 border-start border-info border-3">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📋 Request & Selected Quote Details</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Request Number</small>
                        <h6 class="fw-bold text-primary"><?= htmlspecialchars($request['request_number']) ?></h6>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Request Amount</small>
                        <h6 class="fw-bold text-success">
                            <?= $requestCurrency ?> <?= number_format((float)$request['estimated_value'], 2) ?>
                            <?php if ($requestCurrency === 'USD' && $requestUsdRate > 0): ?>
                                <small class="text-muted">(≈ JMD <?= number_format((float)$request['estimated_value'] * $requestUsdRate, 2) ?>)</small>
                            <?php endif; ?>
                        </h6>
                    </div>
                </div>
                <?php if (!empty($request['vendor_name'])): ?>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Selected Vendor</small>
                        <h6 class="fw-bold"><?= htmlspecialchars($request['vendor_name']) ?></h6>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($request['quote_amount'])): ?>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Quote Amount</small>
                        <h6 class="fw-bold text-success">
                            <?= ($quoteCurrency ?? 'JMD') ?> <?= number_format((float)$request['quote_amount'], 2) ?>
                            <?php if (($quoteCurrency ?? 'JMD') === 'USD'): ?>
                                <small class="text-muted">(≈ JMD <?= number_format($commitmentDefaultAmount, 2) ?>)</small>
                            <?php endif; ?>
                        </h6>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($request['gct_amount'])): ?>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">GCT Amount</small>
                        <h6><?= ($quoteCurrency ?? 'JMD') ?> <?= number_format((float)$request['gct_amount'], 2) ?></h6>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($request['validity_days'])): ?>
                <div class="col-md-6">
                    <div class="mb-3">
                        <small class="text-muted d-block">Quote Validity</small>
                        <h6><?= (int)$request['validity_days'] ?> days</h6>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($currentStep === 'completed'): ?>
        <!-- Already completed -->
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Commitment already created.</strong> 
            <a href="/commitments/view.php?commitment_id=<?= $existingCommitment['commitment_id'] ?>" class="alert-link">View Commitment</a>
        </div>
    <?php elseif ($currentStep === 'verify_funds'): ?>
        <!-- STEP 1: VERIFY FUNDS (Finance) -->
        <div class="card border-success mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">✅ Step 1: Verify Funds Availability</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Confirm that sufficient funds are available in the appropriate cost center for this request.
                    Once verified, Procurement will be notified to fill out the commitment form.
                </div>
                
                <div class="d-flex gap-2">
                    <form method="post" class="flex-grow-1">
                        <input type="hidden" name="action" value="verify_funds">
                        <button type="submit" class="btn btn-success btn-lg w-100" onclick="return confirm('Confirm that funds are available for this request?')">
                            <i class="bi bi-check-circle me-1"></i> Verify Funds Available
                        </button>
                    </form>
                </div>
                
                <hr>
                
                <!-- Decline Option -->
                <div class="mt-3">
                    <h6 class="text-danger"><i class="bi bi-x-circle me-1"></i> Or Decline</h6>
                    <form method="post">
                        <input type="hidden" name="action" value="decline">
                        <div class="mb-3">
                            <textarea name="decline_reason" class="form-control" rows="4" 
                                      placeholder="Explain why funds cannot be committed..." minlength="10" maxlength="1000" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle me-1"></i> Decline & Return Request
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php elseif ($currentStep === 'upload_form'): ?>
        <!-- STEP 2: PROCUREMENT UPLOADS OPTIONAL COMMITMENT FORM -->
        <div class="card border-warning mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">📝 Step 2: Upload Commitment Form (Optional)</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-4">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php if ($requestStatus === 'AWARDED' && $isSkipRfqPath): ?>
                        <strong>Request proceeded without RFQ.</strong> Funds verification is not required for this request. You may upload the optional commitment form now, or proceed without one.
                        <?= $isFinance ? 'You can then create the commitment in GFMS directly.' : 'Finance will create the commitment in GFMS and upload the commitment document.' ?>
                    <?php elseif ($requestStatus === 'AWARDED'): ?>
                        <strong>Request is in Awarded stage.</strong> You may upload the optional commitment form now, or proceed without one.
                        <?= $isFinance ? 'You can then proceed to verify funds and create the commitment in GFMS.' : 'Finance will verify funds, create the commitment in GFMS, and upload the commitment document.' ?>
                    <?php else: ?>
                        <strong>Funds verified by Finance!</strong> You can now upload a scanned copy of the commitment form (optional), or proceed without uploading.
                        <?= $isFinance ? 'You can then proceed to create the commitment in GFMS.' : 'Finance will create the commitment in GFMS and upload the commitment document.' ?>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_commitment_form">
                    
                    <!-- Optional: Upload scanned commitment form -->
                    <div class="mb-4">
                        <label for="commitment_form_doc" class="form-label">
                            <i class="bi bi-paperclip"></i> Commitment Form (Optional)
                        </label>
                        <input type="file" id="commitment_form_doc" name="commitment_form_doc"
                               class="form-control form-control-lg" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        <small class="text-muted d-block mt-2">
                            Optional: Upload a scanned copy of the commitment form (PDF, Word, Excel, JPEG, PNG). Max 50 MB.
                        </small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-warning btn-lg text-dark">
                            <i class="bi bi-check-circle me-1"></i> <?= $isFinance ? 'Proceed to Create Commitment' : 'Proceed to Finance Commitment' ?>
                        </button>
                        <a href="/procurement/view.php?id=<?= $request_id ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- STEP 3: FINANCE CREATES COMMITMENT & UPLOADS DOCUMENT -->
        <div class="card border-primary mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">📄 Step 3: Create Commitment & Upload Document</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-success mb-4">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Ready to create commitment in GFMS!</strong> 
                    <?php if (!empty($request['commitment_form_path'])): ?>
                        Procurement has uploaded a commitment form. Review it below, then fill in the commitment details, create the commitment in GFMS, and upload the commitment document here.
                    <?php else: ?>
                        Procurement did not upload a commitment form. Fill in the commitment details below, create the commitment in GFMS, and upload the commitment document here.
                    <?php endif; ?>
                </div>

                <?php if (!empty($request['commitment_form_path'])): ?>
                <div class="alert alert-info mb-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark me-1"></i> Uploaded Commitment Form</h6>
                    <a href="<?= htmlspecialchars($request['commitment_form_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-file-earmark me-1"></i> View Uploaded Form
                    </a>
                </div>
                <?php endif; ?>

                <?php if (empty($request['commitment_form_path'])): ?>
                <!-- Optional: Upload scanned commitment form -->
                <div class="mb-4 p-3 border rounded bg-light">
                    <label for="commitment_form_doc" class="form-label">
                        <i class="bi bi-paperclip"></i> Scanned Commitment Form (Optional)
                    </label>
                    <input type="file" id="commitment_form_doc" name="commitment_form_doc"
                           class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                    <small class="text-muted d-block mt-2">
                        Optional: Upload a scanned copy of the commitment form (PDF, Word, Excel, JPEG, PNG). Max 50 MB.
                    </small>
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_commitment">
                    
                    <!-- NEW: Display Request PO Flags -->
                    <div class="mb-4 p-3 border rounded bg-info bg-opacity-10">
                        <h6 class="fw-bold mb-3"><i class="bi bi-info-circle text-info"></i> PO Requirement Analysis</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="work_perf_display" disabled 
                                           <?= $request['work_performed'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="work_perf_display">
                                        Work has been performed
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="goods_deliv_display" disabled 
                                           <?= $request['goods_delivered'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="goods_deliv_display">
                                        Goods have been delivered
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($request['po_requirement_notes'])): ?>
                        <div class="mt-2">
                            <small class="text-muted">
                                <strong>Notes:</strong> <?= htmlspecialchars($request['po_requirement_notes']) ?>
                            </small>
                        </div>
                        <?php endif; ?>
                        <div class="mt-3 p-2 bg-light rounded">
                            <small class="fw-bold" id="derived_po_info">
                                <?php 
                                    require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";
                                    $derivedPoReq = getDerivedPoRequired($request);
                                    if ($derivedPoReq === 'NO') {
                                        echo '✓ Derived PO Requirement: <span class="text-success">NO PO REQUIRED</span>';
                                    } else {
                                        echo '⚠ Derived PO Requirement: <span class="text-warning">PO REQUIRED</span>';
                                    }
                                ?>
                            </small>
                        </div>
                    </div>
                    
                    <!-- Is PO Required? -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-question-circle text-primary"></i>
                            <span class="text-danger">*</span> Is Purchase Order (PO) Required?
                        </label>
                        <select name="po_required" id="po_required_select" class="form-select form-select-lg" required onchange="updatePoWorkflowInfo(this.value)">
                            <option value="">— Select —</option>
                            <option value="YES" <?= $derivedPoReq === 'YES' ? 'selected' : '' ?>>Yes – Standard Procurement Workflow (RFQ → PO → Invoice)</option>
                            <option value="NO" <?= $derivedPoReq === 'NO' ? 'selected' : '' ?>>No – Skip RFQ / Non-PO Workflow (Direct to disbursement)</option>
                        </select>
                        <small class="text-muted d-block mt-2">
                            Selecting <strong>No</strong> will automatically bypass RFQ and PO creation steps and route the request directly to payment/disbursement.
                        </small>
                        <!-- Dynamic info panel -->
                        <div id="po_workflow_info" class="mt-3" style="display:none;"></div>
                    </div>

                    <!-- Commitment Date -->
                    <div class="mb-4">
                        <label for="commitment_date" class="form-label">
                            <i class="bi bi-calendar-event"></i>
                            <span class="text-danger">*</span> Commitment Date
                        </label>
                        <input type="date" id="commitment_date" name="commitment_date"
                               class="form-control form-control-lg"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <!-- Commitment Amount (always in JMD) -->
                    <div class="mb-4">
                        <label for="commitment_total" class="form-label">
                            <i class="bi bi-currency-dollar"></i>
                            <span class="text-danger">*</span> Commitment Amount (JMD)
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">JMD</span>
                            <input type="number" id="commitment_total" name="commitment_total"
                                   class="form-control form-control-lg" step="0.01" placeholder="0.00"
                                   value="<?= htmlspecialchars(number_format($commitmentDefaultAmount, 2, '.', '')) ?>"
                                   required>
                        </div>
                        <?php if ($quoteCurrency === 'USD' || $requestCurrency === 'USD'): ?>
                        <small class="text-info d-block mt-2">
                            <i class="bi bi-info-circle"></i>
                            Auto-converted from USD to JMD using rate: 1 USD = <?= number_format($rateToUse ?? $systemUsdRate, 2) ?> JMD
                        </small>
                        <?php else: ?>
                        <small class="text-muted d-block mt-2">Amount being committed (in JMD)</small>
                        <?php endif; ?>
                    </div>

                    <!-- GFMS Commitment Number -->
                    <div class="mb-4">
                        <label for="gfms_commitment_number" class="form-label">
                            <i class="bi bi-bank"></i> GFMS Commitment Number
                        </label>
                        <input type="text" id="gfms_commitment_number" name="gfms_commitment_number"
                               class="form-control form-control-lg" placeholder="e.g., GC/2026/CM/00001" maxlength="50">
                        <small class="text-muted d-block mt-2">Optional: Enter the commitment number from GFMS if already created there.</small>
                    </div>

                    <!-- Commitment Document Upload -->
                    <div class="mb-4">
                        <label for="commitment_document" class="form-label">
                            <i class="bi bi-file-pdf text-danger"></i>
                            Commitment Document from GFMS
                        </label>
                        <input type="file" id="commitment_document" name="commitment_document"
                               class="form-control form-control-lg" accept=".pdf,.doc,.docx,.xls,.xlsx">
                        <small class="text-muted d-block mt-2">
                            <strong>Optional:</strong> Upload the commitment document from GFMS (PDF, DOC, DOCX, XLS, XLSX). Max 50 MB.
                        </small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-cloud-upload me-1"></i> Create Commitment
                        </button>
                        <a href="/procurement/view.php?id=<?= $request_id ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Cancel
                        </a>
                    </div>
                </form>

                <?php if ($isSkipRfqPath): ?>
                <hr>
                <!-- Decline Option (skip-RFQ path never passes through Step 1, so offer it here) -->
                <div class="mt-3">
                    <h6 class="text-danger"><i class="bi bi-x-circle me-1"></i> Or Decline (Funds Not Available)</h6>
                    <form method="post">
                        <input type="hidden" name="action" value="decline">
                        <div class="mb-3">
                            <textarea name="decline_reason" class="form-control" rows="3"
                                      placeholder="Explain why funds cannot be committed..." minlength="10" maxlength="1000" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle me-1"></i> Decline & Return Request
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Information -->
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-lightbulb"></i>
        <strong>Commitment Workflow:</strong>
        <ul class="mb-0 ms-3 mt-2">
            <?php if ($isSkipRfqPath): ?>
            <li><strong>Step 1 (Bypassed):</strong> Funds verification is not required — this request proceeded without an RFQ</li>
            <?php else: ?>
            <li><strong>Step 1 (Finance):</strong> Verify that sufficient funds are available</li>
            <?php endif; ?>
            <li><strong>Step 2 (Procurement/Finance):</strong> Optionally upload a scanned copy of the commitment form. This step can be skipped — you can proceed without it.</li>
            <li><strong>Step 3 (Finance):</strong> Create the commitment in GFMS system, then optionally upload the commitment document here to complete the process</li>
            <li><strong>After creation:</strong> If PO Required = Yes, Procurement will be notified to create the PO. If No, the request is routed directly to the disbursement workflow.</li>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>

<script>
function updatePoWorkflowInfo(val) {
    var panel = document.getElementById('po_workflow_info');
    if (!panel) return;
    if (val === 'YES') {
        panel.style.display = 'block';
        panel.innerHTML = '<div class="alert alert-primary mb-0">'
            + '<strong>📋 Standard Procurement Path selected.</strong><br>'
            + 'After commitment creation the request will advance to <strong>Commitment Approved</strong>. '
            + 'Procurement will be notified to create a Purchase Order, then upload the vendor invoice, and record payment.'
            + '</div>';
    } else if (val === 'NO') {
        panel.style.display = 'block';
        panel.innerHTML = '<div class="alert alert-warning mb-0">'
            + '<strong>⚡ Non-PO / Skip RFQ Path selected.</strong><br>'
            + 'After commitment creation the following steps are <strong>automatically bypassed</strong>: '
            + 'RFQ Process, Supplier Selection, Purchase Order Creation, PO Approval.<br>'
            + 'The request will be routed directly to <strong>Awarded</strong> status for payment/disbursement processing. '
            + 'A full audit entry will be recorded for compliance.'
            + '</div>';
    } else {
        panel.style.display = 'none';
        panel.innerHTML = '';
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>