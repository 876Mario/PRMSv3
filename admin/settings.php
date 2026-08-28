<?php
$REQUIRE_PERMISSION = 'manage_system_settings';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SignedRequestNoticeService.php';

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $enable_notifications = isset($_POST['enable_notifications']) ? 1 : 0;
        $enable_rfq_auto_email = isset($_POST['enable_rfq_auto_email']) ? 1 : 0;
        $signedRequestPrintNoticeEnabled = isset($_POST['signed_request_print_notice_enabled']) ? 1 : 0;
        $signedDocumentUploadNoticeEnabled = isset($_POST['signed_document_upload_notice_enabled']) ? 1 : 0;
        
        // Update notification setting
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES ('enable_notifications', ?, 'Enable/disable email notifications', NOW())
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$enable_notifications]);

        // Update RFQ auto-email setting
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES ('enable_rfq_auto_email', ?, 'Enable/disable automatic RFQ email distribution to vendors', NOW())
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$enable_rfq_auto_email]);


        // Update signed request print notice setting
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES ('signed_request_print_notice_enabled', ?, 'Enable/disable signed request document handling popup after printing (1=enabled, 0=disabled)', NOW())
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$signedRequestPrintNoticeEnabled]);

        // Update signed document upload notice setting
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES ('signed_document_upload_notice_enabled', ?, 'Enable/disable signed document upload confirmation popup (1=enabled, 0=disabled)', NOW())
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$signedDocumentUploadNoticeEnabled]);

        // Update procurement threshold if provided
        if (isset($_POST['direct_procurement_threshold'])) {
            $newThreshold = max(0, (float)$_POST['direct_procurement_threshold']);
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES ('direct_procurement_threshold', ?, 'Threshold for simplified vs full RFQ workflow (JMD)', NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$newThreshold]);
        }

        // Update petty cash limit if provided
        if (isset($_POST['petty_cash_limit'])) {
            $newPCLimit = max(0, (float)$_POST['petty_cash_limit']);
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES ('petty_cash_limit', ?, 'Maximum amount for petty cash requests (JMD)', NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$newPCLimit]);
        }

        // Update USD to JMD exchange rate
        if (isset($_POST['usd_to_jmd_rate'])) {
            $newUsdRate = max(0.01, (float)$_POST['usd_to_jmd_rate']);
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES ('usd_to_jmd_rate', ?, 'Current USD to JMD exchange rate for currency conversion', NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$newUsdRate]);
        }

        // Update HOD approval threshold if provided
        if (isset($_POST['hod_approval_threshold'])) {
            $newHODThreshold = max(0, (float)$_POST['hod_approval_threshold']);
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES ('hod_approval_threshold', ?, 'Procurement requests above this amount require HOD approval (JMD)', NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$newHODThreshold]);
        }

        // Update committee review threshold if provided
        if (isset($_POST['committee_review_threshold'])) {
            $newCommitteeThreshold = max(0, (float)$_POST['committee_review_threshold']);
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES ('committee_review_threshold', ?, 'Procurement requests above this amount require committee review (JMD)', NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$newCommitteeThreshold]);
        }
        
        // ── Asset Register Field Requirement Toggles ──────────────────────────
        $arFieldKeys = [
            'ar_require_inventory_number',
            'ar_require_condition',
            'ar_require_status',
            'ar_require_acquired_date',
            'ar_require_custodian',
            'ar_require_location',
            'ar_require_purchase_cost',
            'ar_require_disposal_date',
        ];
        foreach ($arFieldKeys as $arKey) {
            $arVal = isset($_POST[$arKey]) ? '1' : '0';
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, description, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
            ");
            $stmt->execute([$arKey, $arVal, 'Asset Register field requirement toggle']);
        }

        // ── Document Control Settings ─────────────────────────────────────────
        if (isset($_POST['doc_ctrl_form_revision'])) {
            $dcFormRevision   = trim($_POST['doc_ctrl_form_revision']);
            $dcEffectiveDate  = !empty($_POST['doc_ctrl_effective_date']) ? $_POST['doc_ctrl_effective_date'] : null;
            $dcDcrNumber      = trim($_POST['doc_ctrl_dcr_number']);

            $stmt = $pdo->prepare("
                INSERT INTO doc_ctrl_settings
                    (id, form_revision, effective_date, dcr_number, updated_by_id, updated_by_name)
                VALUES
                    (1, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    form_revision   = VALUES(form_revision),
                    effective_date  = VALUES(effective_date),
                    dcr_number      = VALUES(dcr_number),
                    updated_by_id   = VALUES(updated_by_id),
                    updated_by_name = VALUES(updated_by_name)
            ");
            $stmt->execute([
                $dcFormRevision,
                $dcEffectiveDate,
                $dcDcrNumber,
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? null,
            ]);
        }

        // Log audit
        logAudit(
            $pdo,
            'system_config',
            0,
            'UPDATE',
            'System settings updated: enable_notifications=' . ($enable_notifications ? 'ON' : 'OFF')
                . ', enable_rfq_auto_email=' . ($enable_rfq_auto_email ? 'ON' : 'OFF')
                . ', signed_request_print_notice_enabled=' . ($signedRequestPrintNoticeEnabled ? 'ON' : 'OFF')
                . ', signed_document_upload_notice_enabled=' . ($signedDocumentUploadNoticeEnabled ? 'ON' : 'OFF')
                . (isset($newThreshold) ? ', threshold=' . number_format($newThreshold, 2) : '')
                . (isset($newPCLimit)  ? ', petty_cash_limit=' . number_format($newPCLimit, 2) : '')
                . (isset($newUsdRate)  ? ', usd_to_jmd_rate=' . number_format($newUsdRate, 4) : '')
                . (isset($newHODThreshold) ? ', hod_approval_threshold=' . number_format($newHODThreshold, 2) : '')
                . (isset($newCommitteeThreshold) ? ', committee_review_threshold=' . number_format($newCommitteeThreshold, 2) : '')
        );
        
        $_SESSION['toast'] = [
            'message' => 'Notification settings updated successfully!',
            'type' => 'success'
        ];
        
        header('Location: /admin/settings.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = [
            'message' => 'Error updating settings: ' . extractDbMessage($e),
            'type' => 'danger'
        ];
    }
}

// Get current settings
try {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->execute(['enable_notifications']);
    $value = $stmt->fetchColumn();
    $notificationsEnabled = $value !== false ? (bool)(int)$value : true;
} catch (Exception $e) {
    $notificationsEnabled = true;
}

// Get current RFQ auto-email setting
try {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->execute(['enable_rfq_auto_email']);
    $value = $stmt->fetchColumn();
    $rfqAutoEmailEnabled = $value !== false ? (bool)(int)$value : true;
} catch (Exception $e) {
    $rfqAutoEmailEnabled = true;
}

SignedRequestNoticeService::seedDefaultSettings($pdo);
$signedRequestPrintNoticeEnabled = SignedRequestNoticeService::isPrintNoticeEnabled($pdo);
$signedDocumentUploadNoticeEnabled = SignedRequestNoticeService::isUploadNoticeEnabled($pdo);

// Get current threshold settings
$currentThreshold = getDirectProcurementThreshold($pdo);
$currentPettyCashLimit = getPettyCashLimit($pdo);
$currentHODThreshold = getHODApprovalThreshold($pdo);
$currentCommitteeThreshold = getCommitteeReviewThreshold($pdo);

// Get current USD exchange rate
try {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $stmt->execute(['usd_to_jmd_rate']);
    $currentUsdRate = (float)($stmt->fetchColumn() ?: 155.00);
} catch (Exception $e) {
    $currentUsdRate = 155.00;
}

// Get current Asset Register field requirement toggles
$arFieldSettings = [];
$arFieldKeys = [
    'ar_require_inventory_number',
    'ar_require_condition',
    'ar_require_status',
    'ar_require_acquired_date',
    'ar_require_custodian',
    'ar_require_location',
    'ar_require_purchase_cost',
    'ar_require_disposal_date',
];
foreach ($arFieldKeys as $arKey) {
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$arKey]);
        $val = $stmt->fetchColumn();
        $arFieldSettings[$arKey] = $val !== false ? (bool)(int)$val : true; // default required
    } catch (Exception $e) {
        $arFieldSettings[$arKey] = true;
    }
}

// Get current Document Control Settings
try {
    $stmt = $pdo->query("SELECT * FROM doc_ctrl_settings WHERE id = 1 LIMIT 1");
    $docCtrl = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $docCtrl = [];
}

// Now include header AFTER form processing and headers are sent
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-gear me-2"></i> System Settings</h4>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>

                        <!-- ═══ Workflow Thresholds ═══ -->
                        <h5 class="fw-bold mb-3"><i class="bi bi-sliders me-2"></i> Workflow Thresholds</h5>
                        <div class="mb-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold" for="direct_procurement_threshold">
                                                Procurement Threshold (JMD)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">JMD</span>
                                                <input type="number" class="form-control" id="direct_procurement_threshold"
                                                       name="direct_procurement_threshold" step="0.01" min="0"
                                                       value="<?= htmlspecialchars($currentThreshold) ?>">
                                            </div>
                                            <small class="text-muted">
                                                Requests <strong>at or below</strong> this amount use simplified RFQ (branch supervisor approval).
                                                Requests <strong>above</strong> this amount use full RFQ with committee evaluation (HOD approval).
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold" for="petty_cash_limit">
                                                Petty Cash Limit (JMD)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">JMD</span>
                                                <input type="number" class="form-control" id="petty_cash_limit"
                                                       name="petty_cash_limit" step="0.01" min="0"
                                                       value="<?= htmlspecialchars($currentPettyCashLimit) ?>">
                                            </div>
                                            <small class="text-muted">
                                                Maximum amount for petty cash requests.
                                            </small>
                                        </div>
                                        <div class="col-md-6 mt-3">
                                            <label class="form-label fw-bold" for="usd_to_jmd_rate">
                                                <i class="bi bi-currency-exchange me-1"></i> USD to JMD Exchange Rate
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">1 USD =</span>
                                                <input type="number" class="form-control" id="usd_to_jmd_rate"
                                                       name="usd_to_jmd_rate" step="0.01" min="0.01"
                                                       value="<?= htmlspecialchars($currentUsdRate) ?>">
                                                <span class="input-group-text">JMD</span>
                                            </div>
                                            <small class="text-muted">
                                                Used to auto-convert USD amounts to JMD for commitments.
                                            </small>
                                        </div>
                                        <div class="col-md-6 mt-3">
                                            <label class="form-label fw-bold" for="hod_approval_threshold">
                                                <i class="bi bi-person-check me-1"></i> HOD Approval Threshold (JMD)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">JMD</span>
                                                <input type="number" class="form-control" id="hod_approval_threshold"
                                                       name="hod_approval_threshold" step="0.01" min="0"
                                                       value="<?= htmlspecialchars($currentHODThreshold) ?>">
                                            </div>
                                            <small class="text-muted">
                                                Requests <strong>above</strong> this amount require HOD approval.
                                            </small>
                                        </div>
                                        <div class="col-md-6 mt-3">
                                            <label class="form-label fw-bold" for="committee_review_threshold">
                                                <i class="bi bi-people me-1"></i> Committee Review Threshold (JMD)
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">JMD</span>
                                                <input type="number" class="form-control" id="committee_review_threshold"
                                                       name="committee_review_threshold" step="0.01" min="0"
                                                       value="<?= htmlspecialchars($currentCommitteeThreshold) ?>">
                                            </div>
                                            <small class="text-muted">
                                                Requests <strong>above</strong> this amount require committee review.
                                            </small>
                                        </div>
                                    </div>
                                    <div class="alert alert-info mt-3 mb-0 small">
                                        <strong>How thresholds control workflow routing:</strong>
                                        <ul class="mb-0 mt-1">
                                            <li><strong>Under threshold:</strong> Branch supervisor approves &rarr; Simplified RFQ (no committee evaluation)</li>
                                            <li><strong>Over HOD threshold:</strong> HOD approval required</li>
                                            <li><strong>Over Committee threshold:</strong> Procurement Committee review required</li>
                                            <li><strong>Petty Cash / Reimbursement:</strong> Always use their dedicated direct workflows</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- ═══ Notification Settings ═══ -->
                        <h5 class="fw-bold mb-3"><i class="bi bi-envelope me-2"></i> Email Notification Settings</h5>
                        <!-- Notification Status -->
                        <div class="mb-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <div class="form-check form-switch py-2">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="enable_notifications" 
                                            name="enable_notifications"
                                            value="1"
                                            <?= $notificationsEnabled ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label fw-bold" for="enable_notifications">
                                            Enable Email Notifications
                                        </label>
                                        <p class="text-muted small mt-2 mb-0">
                                            When enabled, the system will send automated email notifications to stakeholders at key workflow stages.
                                        </p>
                                    </div>
                                    <div class="form-check form-switch py-2">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="enable_rfq_auto_email" 
                                            name="enable_rfq_auto_email"
                                            value="1"
                                            <?= $rfqAutoEmailEnabled ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label fw-bold" for="enable_rfq_auto_email">
                                            Enable RFQ Auto-Email Distribution
                                        </label>
                                        <p class="text-muted small mt-2 mb-0">
                                            When enabled, RFQ details are automatically emailed to vendors. Disable to suppress RFQ vendor emails without affecting other notifications.
                                        </p>
                                    </div>

                                    <hr class="my-3">
                                    <h6 class="fw-bold mb-2"><i class="bi bi-exclamation-octagon me-1"></i> Signed Document Handling Notice</h6>
                                    <div class="form-check form-switch py-2">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="signed_request_print_notice_enabled" 
                                            name="signed_request_print_notice_enabled"
                                            value="1"
                                            <?= $signedRequestPrintNoticeEnabled ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label fw-bold" for="signed_request_print_notice_enabled">
                                            Enable popup after signed request printing
                                        </label>
                                        <p class="text-muted small mt-2 mb-0">
                                            Shows the Procurement-first original-document handling reminder after users open the print form.
                                        </p>
                                    </div>
                                    <div class="form-check form-switch py-2">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="signed_document_upload_notice_enabled" 
                                            name="signed_document_upload_notice_enabled"
                                            value="1"
                                            <?= $signedDocumentUploadNoticeEnabled ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label fw-bold" for="signed_document_upload_notice_enabled">
                                            Enable popup during signed document upload
                                        </label>
                                        <p class="text-muted small mt-2 mb-0">
                                            Requires users to confirm the Procurement-first original-document handling reminder before upload completion.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notification Features -->
                        <div class="mb-4">
                            <h5 class="fw-bold mb-3">📬 Active Notification Types</h5>
                            <div class="row g-3">
                                <!-- Request Submitted -->
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge bg-success rounded-circle" style="font-size: 14px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">✓</span>
                                                <div>
                                                    <h6 class="mb-1">Request Submitted</h6>
                                                    <small class="text-muted">Notifies branch head when a new request is created</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Approval Needed -->
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge bg-success rounded-circle" style="font-size: 14px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">✓</span>
                                                <div>
                                                    <h6 class="mb-1">Approval Needed</h6>
                                                    <small class="text-muted">Alerts approvers when action is required</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Request Finalized -->
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge bg-success rounded-circle" style="font-size: 14px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">✓</span>
                                                <div>
                                                    <h6 class="mb-1">Request Finalized</h6>
                                                    <small class="text-muted">Notifies requestor when status changes to final (Awarded/Declined)</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Next Stage Alert -->
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge bg-success rounded-circle" style="font-size: 14px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">✓</span>
                                                <div>
                                                    <h6 class="mb-1">Next Stage Alert</h6>
                                                    <small class="text-muted">Notifies next approver when current approval stage completes</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status Message -->
                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <span>ℹ️</span>
                            <div>
                                <strong>Current Status:</strong> Notifications are currently <strong class="<?= $notificationsEnabled ? 'text-success' : 'text-danger' ?>">
                                    <?= $notificationsEnabled ? 'ENABLED' : 'DISABLED' ?>
                                </strong>
                            </div>
                        </div>

                        <hr>

                        <!-- ═══ Asset Register Field Requirements ═══ -->
                        <h5 class="fw-bold mb-3"><i class="bi bi-clipboard2-check me-2"></i> Asset Register Field Requirements</h5>
                        <div class="mb-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <p class="text-muted small mb-3">
                                        Toggle which fields are <strong>mandatory</strong> when adding or editing assets in the Asset Register Details section.
                                        Disabled fields become optional (users can still fill them in).
                                    </p>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_inventory_number"
                                                       name="ar_require_inventory_number" value="1"
                                                       <?= $arFieldSettings['ar_require_inventory_number'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_inventory_number">Inventory Number</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_condition"
                                                       name="ar_require_condition" value="1"
                                                       <?= $arFieldSettings['ar_require_condition'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_condition">Asset Condition</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_status"
                                                       name="ar_require_status" value="1"
                                                       <?= $arFieldSettings['ar_require_status'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_status">Asset Status</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_acquired_date"
                                                       name="ar_require_acquired_date" value="1"
                                                       <?= $arFieldSettings['ar_require_acquired_date'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_acquired_date">Date of Acquisition</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_custodian"
                                                       name="ar_require_custodian" value="1"
                                                       <?= $arFieldSettings['ar_require_custodian'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_custodian">Custodian</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_location"
                                                       name="ar_require_location" value="1"
                                                       <?= $arFieldSettings['ar_require_location'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_location">Location (at least one field)</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_purchase_cost"
                                                       name="ar_require_purchase_cost" value="1"
                                                       <?= $arFieldSettings['ar_require_purchase_cost'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_purchase_cost">Cost / Purchase Price</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch py-1">
                                                <input class="form-check-input" type="checkbox" id="ar_require_disposal_date"
                                                       name="ar_require_disposal_date" value="1"
                                                       <?= $arFieldSettings['ar_require_disposal_date'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ar_require_disposal_date">Disposal Date (when asset is disposed)</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-warning mt-3 mb-0 small">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Note:</strong> Disabling a field requirement means assets can be saved without that data.
                                        This may affect asset register completeness and compliance reporting.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ═══ Document Control Settings ═══ -->
                        <hr>
                        <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-2"></i> Document Control Settings</h5>
                        <div class="mb-4">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <p class="text-muted small mb-3">
                                        These values are automatically embedded in every <strong>Print Request for Signing</strong>.
                                        All three fields must be set before a print request can be generated.
                                    </p>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold" for="doc_ctrl_form_revision">
                                                <i class="bi bi-pencil-square me-1"></i> Form Revision
                                            </label>
                                            <input type="text" class="form-control" id="doc_ctrl_form_revision"
                                                   name="doc_ctrl_form_revision" maxlength="100"
                                                   placeholder="e.g. Rev. 3"
                                                   value="<?= htmlspecialchars($docCtrl['form_revision'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold" for="doc_ctrl_effective_date">
                                                <i class="bi bi-calendar3 me-1"></i> Effective Date
                                            </label>
                                            <input type="date" class="form-control" id="doc_ctrl_effective_date"
                                                   name="doc_ctrl_effective_date"
                                                   value="<?= htmlspecialchars($docCtrl['effective_date'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold" for="doc_ctrl_dcr_number">
                                                <i class="bi bi-hash me-1"></i> DCR Number
                                            </label>
                                            <input type="text" class="form-control" id="doc_ctrl_dcr_number"
                                                   name="doc_ctrl_dcr_number" maxlength="100"
                                                   placeholder="e.g. DCR-2024-001"
                                                   value="<?= htmlspecialchars($docCtrl['dcr_number'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <?php if (!empty($docCtrl['updated_at']) && $docCtrl['updated_at'] !== '0000-00-00 00:00:00'): ?>
                                    <div class="alert alert-secondary mt-3 mb-0 small d-flex align-items-center gap-2">
                                        <i class="bi bi-clock-history"></i>
                                        <span>
                                            Last updated
                                            <strong><?= htmlspecialchars(date('d M Y, g:i A', strtotime($docCtrl['updated_at']))) ?></strong>
                                            <?php if (!empty($docCtrl['updated_by_name'])): ?>
                                                by <strong><?= htmlspecialchars($docCtrl['updated_by_name']) ?></strong>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning mt-3 mb-0 small">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Not yet configured.</strong> Print requests for signing cannot be generated until all three fields are saved.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="/dashboard/index.php" class="btn btn-secondary">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="card mt-4 border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0">ℹ️ About Notifications</h5>
                </div>
                <div class="card-body small">
                    <p class="mb-2">
                        <strong>Email Configuration:</strong>
                        The system sends notifications using the configured mail server settings. 
                        Ensure your mail server credentials are properly configured in the application settings.
                    </p>
                    <p class="mb-2">
                        <strong>Recipients:</strong>
                        Notifications are automatically sent to appropriate stakeholders based on their role:
                    </p>
                    <ul class="mb-3">
                        <li><strong>Request Submitted:</strong> Branch Head (HOD) of the requesting branch</li>
                        <li><strong>Approval Needed:</strong> The user with the required approval role</li>
                        <li><strong>Request Finalized:</strong> The user who created the request</li>
                    </ul>
                    <p class="mb-0">
                        <strong>User Emails:</strong> Make sure all users have valid email addresses in their profiles for notifications to be delivered successfully.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>