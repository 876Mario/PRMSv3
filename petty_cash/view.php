<?php
$REQUIRE_PERMISSION = 'view_petty_cash_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($request_id <= 0) {
    pop('Invalid petty cash request', '/petty_cash/list.php', 3000, 'error');
    exit;
}

/* Fetch request details */
$stmt = $pdo->prepare("
    SELECT 
        pr.*,
        b.branch_name,
        u.full_name,
        u.email
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Petty cash request not found', '/petty_cash/list.php', 3000, 'error');
    exit;
}

/* Fetch disbursement details if exists */
$disbStmt = $pdo->prepare("
    SELECT pcd.*, u.full_name as disbursed_by_name
    FROM petty_cash_disbursements pcd
    LEFT JOIN users u ON pcd.disbursed_by = u.user_id
    WHERE pcd.request_id = ?
");
$disbStmt->execute([$request_id]);
$disbursement = $disbStmt->fetch(PDO::FETCH_ASSOC);

/* Fetch reconciliation if exists */
$reconcileStmt = $pdo->prepare("
    SELECT 
        pcr.*,
        u.full_name as submitted_by_name,
        v.full_name as verified_by_name
    FROM petty_cash_reconciliations pcr
    LEFT JOIN users u ON pcr.submitted_by = u.user_id
    LEFT JOIN users v ON pcr.verified_by = v.user_id
    WHERE pcr.disburse_id = ?
");
$deadlineStatus = null;
if ($disbursement) {
    $reconcileStmt->execute([$disbursement['disburse_id']]);
    $reconciliation = $reconcileStmt->fetch(PDO::FETCH_ASSOC);

    // Compute deadline countdown from the disbursement_deadline column
    if (!empty($disbursement['disbursement_deadline'])) {
        $now      = new DateTime();
        $deadline = new DateTime($disbursement['disbursement_deadline']);
        $diff     = $now->diff($deadline);
        $deadlineStatus = [
            'deadline'       => $deadline,
            'is_overdue'     => ($now > $deadline),
            'time_remaining' => $diff,
        ];
    }
} else {
    $reconciliation = null;
}

/* Fetch reconciliation documents if reconciliation exists */
$reconciliationDocuments = [];
if ($reconciliation) {
    $docStmt = $pdo->prepare("
        SELECT 
            pcd.*,
            u.full_name as uploaded_by_name
        FROM petty_cash_reconciliation_documents pcd
        LEFT JOIN users u ON pcd.uploaded_by = u.user_id
        WHERE pcd.reconcile_id = ? AND pcd.is_deleted = 0
        ORDER BY pcd.uploaded_date DESC
    ");
    try {
        $docStmt->execute([(int)$reconciliation['reconcile_id']]);
        $reconciliationDocuments = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table may not exist yet
        $reconciliationDocuments = [];
    }
}

/* Fetch approval records for responsibility tooltip */
$pcApprovalsStmt = $pdo->prepare(
    'SELECT id, role, stage_order, status, approved_by
       FROM request_approvals
      WHERE request_id = ?
      ORDER BY stage_order ASC'
);
$pcApprovalsStmt->execute([$request_id]);
$approvals = $pcApprovalsStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch status history */
$histStmt = $pdo->prepare("
    SELECT pch.*, u.full_name
    FROM petty_cash_status_history pch
    LEFT JOIN users u ON pch.changed_by = u.user_id
    WHERE pch.request_id = ?
    ORDER BY pch.change_date DESC
");
$histStmt->execute([$request_id]);
$statusHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

// Initialize SignedRequestService
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestNoticeService.php';
$signedRequestService = new SignedRequestService($pdo);
$signedRequestPending = $signedRequestService->isUploadPending($request_id, 'PETTY_CASH');
$activeSignedDoc = $signedRequestService->getActiveDocument($request_id);
$signedDocHistory = $signedRequestService->getDocumentHistory($request_id);

SignedRequestNoticeService::seedDefaultSettings($pdo);
$printNoticeEnabled = SignedRequestNoticeService::isPrintNoticeEnabled($pdo);
$uploadNoticeEnabled = SignedRequestNoticeService::isUploadNoticeEnabled($pdo);

// Generate CSRF token for uploads
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div class="container-fluid mt-4">
  <!-- Header -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h3 class="section-title mb-1">
            💰 Petty Cash Request <?= htmlspecialchars($request['request_number']) ?>
          </h3>
          <small class="text-muted">Created on <?= date('M d, Y \\a\\t g:i A', strtotime($request['created_at'])) ?></small>
        </div>
        <div>
          <h4 class="text-end"><?= getPettyCashStatusLabel($request['status']) ?></h4>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- Main Content -->
    <div class="col-lg-8">
      <!-- Request Information -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
          <h5 class="mb-0">📋 Request Information</h5>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <div class="col-md-6">
              <small class="text-muted d-block">Branch</small>
              <strong><?= htmlspecialchars($request['branch_name']) ?></strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Requestor</small>
              <strong><?= htmlspecialchars($request['full_name']) ?></strong>
              <br>
              <small><?= htmlspecialchars($request['email']) ?></small>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Requested Amount</small>
              <strong class="text-success"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($request['estimated_value'], 2) ?></strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Request Date</small>
              <strong><?= date('M d, Y', strtotime($request['request_date'])) ?></strong>
            </div>
            <div class="col-12">
              <small class="text-muted d-block">Purpose</small>
              <p class="mb-0"><?= htmlspecialchars($request['description']) ?></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Disbursement Status -->
      <?php if ($disbursement): ?>
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">💵 Disbursement & 24-Hour Accountability</h5>
          </div>
          <div class="card-body">
            <?php if ($deadlineStatus && $deadlineStatus['is_overdue']): ?>
              <div class="alert alert-danger">
                <strong>⚠️ OVERDUE!</strong> Reconciliation deadline has passed.
              </div>
            <?php elseif ($deadlineStatus): ?>
              <div class="alert alert-warning">
                <strong>⏱️ Time Remaining:</strong> 
                <?= $deadlineStatus['time_remaining']->format('%h hours %i minutes') ?>
                until <?= $deadlineStatus['deadline']->format('M d, Y g:i A') ?>
              </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <small class="text-muted d-block">Disbursed By</small>
                <strong><?= htmlspecialchars($disbursement['disbursed_by_name']) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Disbursement Date</small>
                <strong><?= date('M d, Y g:i A', strtotime($disbursement['disbursement_date'])) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Authorized Amount</small>
                <strong class="text-success"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($disbursement['amount_authorized'], 2) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Reconciliation Deadline</small>
                <strong class="<?= $deadlineStatus && $deadlineStatus['is_overdue'] ? 'text-danger' : 'text-dark' ?>">
                  <?= date('M d, Y g:i A', strtotime($disbursement['disbursement_deadline'])) ?>
                </strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Reconciliation Status -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">📝 Reconciliation (Due within 24 hours)</h5>
          </div>
          <div class="card-body">
            <?php if ($reconciliation): ?>
              <div class="alert alert-<?= $reconciliation['status'] === 'VERIFIED' || $reconciliation['status'] === 'APPROVED' ? 'success' : ($reconciliation['status'] === 'DISCREPANCY' ? 'danger' : 'info') ?>">
                <strong>Status:</strong> <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $reconciliation['status']))) ?><br>
                <strong>Submitted on:</strong> <?= date('M d, Y g:i A', strtotime($reconciliation['submission_date'])) ?>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <small class="text-muted d-block">Submitted By</small>
                  <strong><?= htmlspecialchars($reconciliation['submitted_by_name']) ?></strong>
                </div>
                <div class="col-md-6">
                  <small class="text-muted d-block">Hours from Disbursement</small>
                  <strong><?= $reconciliation['hours_from_disbursement'] ?? 'N/A' ?> hours</strong>
                </div>
                <div class="col-md-6">
                  <small class="text-muted d-block">Purchase Amount</small>
                  <strong class="text-success"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($reconciliation['purchase_amount'], 2) ?></strong>
                </div>
                <div class="col-md-6">
                  <small class="text-muted d-block">Change/Balance Returned</small>
                  <strong class="text-info"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($reconciliation['change_amount'], 2) ?></strong>
                </div>
                <?php if ($reconciliation['verified_by_name']): ?>
                  <div class="col-md-6">
                    <small class="text-muted d-block">Verified By</small>
                    <strong><?= htmlspecialchars($reconciliation['verified_by_name']) ?></strong>
                  </div>
                  <div class="col-md-6">
                    <small class="text-muted d-block">Verification Date</small>
                    <strong><?= date('M d, Y g:i A', strtotime($reconciliation['verification_date'])) ?></strong>
                  </div>
                <?php endif; ?>
                <div class="col-12">
                  <small class="text-muted d-block">Notes</small>
                  <div class="p-2 bg-light rounded" style="max-height: 200px; overflow-y: auto;">
                    <p class="mb-0 text-break"><?= nl2br(htmlspecialchars($reconciliation['reconciliation_notes'] ?? '')) ?></p>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-warning">
                <strong>Pending Reconciliation</strong> - Waiting for purchase documentation and change return
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Supporting Documents -->
        <?php if ($reconciliation): ?>
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
              <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">📎 Supporting Documents</h5>
                <?php if (in_array($_SESSION['role_name'] ?? '', ['Finance Officer', 'Admin', 'SuperAdmin'])): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="bi bi-cloud-upload me-1"></i>Upload Document
                  </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="card-body">
              <?php if (count($reconciliationDocuments) > 0): ?>
                <div class="table-responsive">
                  <table class="table table-sm table-hover">
                    <thead class="table-light">
                      <tr>
                        <th>Type</th>
                        <th>File Name</th>
                        <th>Uploaded By</th>
                        <th>Uploaded Date</th>
                        <th>Notes</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($reconciliationDocuments as $doc): ?>
                        <tr>
                          <td>
                            <span class="badge bg-info">
                              <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($doc['document_type'])))) ?>
                            </span>
                          </td>
                          <td><?= htmlspecialchars($doc['original_file_name']) ?></td>
                          <td>
                            <?= htmlspecialchars($doc['uploaded_by_name'] ?? 'Unknown') ?>
                          </td>
                          <td><?= date('M d, Y g:i A', strtotime($doc['uploaded_date'])) ?></td>
                          <td><?= htmlspecialchars(substr($doc['document_notes'] ?? '', 0, 50)) ?></td>
                          <td>
                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" class="btn btn-xs btn-outline-primary" download>
                              <i class="bi bi-download"></i> Download
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p class="text-muted mb-0">No supporting documents uploaded yet.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Discrepancy Review Actions (Finance Only) -->
        <?php if ($reconciliation && $request['status'] === 'RECONCILIATION_DISCREPANCY' && in_array($_SESSION['role_name'] ?? '', ['Finance Officer', 'Admin', 'SuperAdmin'])): ?>
          <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10">
              <h5 class="mb-0 text-danger"><i class="bi bi-exclamation-circle me-2"></i>Discrepancy Review</h5>
            </div>
            <div class="card-body">
              <p class="text-muted">A discrepancy has been found in this reconciliation. After the requestor provides corrections, review and approve.</p>
              <div class="btn-group w-100" role="group">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#resolveDiscrepancyModal">
                  <i class="bi bi-check-lg me-1"></i>Corrections Received - Approve
                </button>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#reopenDiscrepancyModal">
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen for More Corrections
                </button>
              </div>
            </div>
          </div>
        <?php elseif ($reconciliation && $request['status'] === 'REVIEWED' && in_array($_SESSION['role_name'] ?? '', ['Finance Officer', 'Admin', 'SuperAdmin'])): ?>
          <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-10">
              <h5 class="mb-0 text-warning"><i class="bi bi-clock-history me-2"></i>Final Review</h5>
            </div>
            <div class="card-body">
              <p class="text-muted">Corrections have been reviewed. Click below to finalize and complete the reconciliation.</p>
              <button type="button" class="btn btn-success btn-lg w-100" data-bs-toggle="modal" data-bs-target="#finalizeReconciliationModal">
                <i class="bi bi-check2-circle me-1"></i>Finalize & Complete Reconciliation
              </button>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
      <!-- Process Steps -->
      <div class="card shadow-sm">
        <div class="card-header bg-light">
          <h5 class="mb-0">📊 Process Steps</h5>
        </div>
        <div class="card-body">
          <div class="row g-2 pipeline-stages-row">
           <?php
           require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowResponsibilityService.php';
           require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/workflow_pipeline.php';

           // Convert petty cash pipeline to keyed format
           $pcPipelineRaw = getPettyCashPipeline();
           $pcPipelineKeyed = [];
           foreach ($pcPipelineRaw as $s) {
               $pcPipelineKeyed[$s['status']] = ['label' => $s['label'], 'icon' => $s['icon']];
           }

           $wfRespService = new WorkflowResponsibilityService($pdo);
           $wfResponsibilities = $wfRespService->getPipelineResponsibility(
               $pcPipelineKeyed,
               $request,
               $request['status'],
               $approvals,
               $_SESSION['role_name'] ?? ''
           );

           $pcStageKeys = array_keys($pcPipelineKeyed);
           $pcCurrentIdx = array_search($request['status'], $pcStageKeys, true);
           $pcTotalStages = count($pcStageKeys);

           foreach ($pcStageKeys as $idx => $stageKey):
               echo renderWorkflowPipelineStage(
                   $stageKey,
                   $pcPipelineKeyed[$stageKey],
                   $idx,
                   $pcTotalStages,
                   $pcCurrentIdx !== false ? (int)$pcCurrentIdx : -1,
                   $wfResponsibilities[$stageKey] ?? []
               );
           endforeach;
           ?>
          </div>
        </div>
      </div>

      <!-- Status Timeline -->
      <div class="card shadow-sm mt-3">
        <div class="card-header bg-light">
          <h5 class="mb-0">📊 Status Timeline</h5>
        </div>
        <div class="card-body">
          <?php if (empty($statusHistory)): ?>
            <p class="text-muted mb-0">No status changes recorded yet.</p>
          <?php else: ?>
            <div class="timeline">
              <?php foreach ($statusHistory as $hist): ?>
                <div class="timeline-item mb-3">
                  <div class="d-flex gap-2">
                    <div class="timeline-marker"></div>
                    <div class="flex-grow-1">
                      <small class="text-muted d-block"><?= date('M d, Y \\a\\t g:i A', strtotime($hist['change_date'])) ?></small>
                      <strong><?= htmlspecialchars($hist['new_status']) ?></strong>
                      <br>
                      <small><?= htmlspecialchars($hist['full_name'] ?? 'System') ?></small>
                      <?php if ($hist['change_notes']): ?>
                        <br>
                        <small class="text-muted"><?= htmlspecialchars($hist['change_notes']) ?></small>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Signed Request Management -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
          <h5 class="mb-0">🔏 Signed Request Management</h5>
          <?php if ($signedRequestPending): ?>
            <span class="badge bg-warning">Pending Signature</span>
          <?php elseif ($activeSignedDoc): ?>
            <span class="badge bg-success">Signed ✓</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($signedRequestPending && $request['status'] === 'SUBMITTED'): ?>
            <div class="alert alert-warning mb-3">
              <i class="bi bi-exclamation-triangle"></i>
              <strong>Action Required:</strong> The signed approval form must be uploaded before this request can proceed for disbursal.
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <!-- Print Section -->
            <div class="col-md-6">
              <div class="card bg-light">
                <div class="card-body">
                  <h6 class="card-title">Step 1: Print Form</h6>
                  <p class="small text-muted mb-3">Print the approval form, review all information, and sign it.</p>
                  <a href="/petty_cash/print_for_signing.php?request_id=<?= $request_id ?>" 
                     class="btn btn-primary btn-sm w-100 js-signed-print-btn" target="_blank"
                     data-request-id="<?= (int)$request_id ?>"
                     data-request-type="PETTY_CASH"
                     data-print-notice-enabled="<?= $printNoticeEnabled ? '1' : '0' ?>">
                    <i class="bi bi-printer"></i> Print Approval Form
                  </a>
                </div>
              </div>
            </div>

            <!-- Upload Section -->
            <div class="col-md-6">
              <div class="card bg-light">
                <div class="card-body">
                  <h6 class="card-title">Step 2: Upload Signed Copy</h6>
                  <p class="small text-muted mb-3">Scan or photograph the signed form and upload here.</p>
                  <?php if ($signedRequestService->canUserUpload($request_id, 'PETTY_CASH', $_SESSION['user_id'], $_SESSION['role_name'])): ?>
                    <button class="btn btn-success btn-sm w-100" data-bs-toggle="collapse" data-bs-target="#uploadForm">
                      <i class="bi bi-upload"></i> Upload Signed Form
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Upload Form (Collapsible) -->
          <?php if ($signedRequestService->canUserUpload($request_id, 'PETTY_CASH', $_SESSION['user_id'], $_SESSION['role_name'])): ?>
            <div class="collapse mt-3" id="uploadForm">
              <div class="card card-body">
                <form method="post" action="/petty_cash/upload_signed_request.php" enctype="multipart/form-data" class="js-signed-upload-form" data-request-id="<?= (int)$request_id ?>" data-request-type="PETTY_CASH" data-upload-notice-enabled="<?= $uploadNoticeEnabled ? '1' : '0' ?>">
                  <input type="hidden" name="request_id" value="<?= $request_id ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="signed_notice_upload_ack" value="0">
                  <input type="hidden" name="signed_notice_action_token" value="">
                  
                  <div class="mb-3">
                    <label for="signed_request_file" class="form-label">Select Signed Document (PDF, JPG, PNG, GIF, DOC, DOCX)</label>
                    <input type="file" class="form-control" id="signed_request_file" name="signed_request_file" 
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx" required>
                    <small class="text-muted d-block mt-2">Maximum file size: 25MB</small>
                  </div>

                  <div class="mb-3">
                    <small class="text-muted">
                      <strong>Requirements:</strong>
                      <ul class="mb-0 mt-2">
                        <li>Document must be clearly readable</li>
                        <li>All signature fields must be signed</li>
                        <li>Acceptable formats: PDF, JPG, PNG, GIF, DOC, DOCX</li>
                        <li>File size must not exceed 25MB</li>
                      </ul>
                    </small>
                  </div>

                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success btn-sm">
                      <i class="bi bi-check-circle"></i> Upload Document
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#uploadForm">
                      Cancel
                    </button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <!-- Active Document Display -->
          <?php if ($activeSignedDoc): ?>
            <div class="mt-3">
              <h6 class="mb-2">📄 Current Signed Document</h6>
              <div class="alert alert-info py-2 mb-2">
                <small>
                  <strong>Version <?= htmlspecialchars($activeSignedDoc['version_number']) ?></strong><br>
                  Uploaded by: <?= htmlspecialchars($activeSignedDoc['uploaded_by_name']) ?><br>
                  Date: <?= date('M d, Y H:i', strtotime($activeSignedDoc['uploaded_at'])) ?><br>
                  File: <?= htmlspecialchars($activeSignedDoc['original_file_name']) ?> (<?= number_format($activeSignedDoc['file_size'] / 1024, 2) ?> KB)
                </small>
              </div>
              <a href="<?= htmlspecialchars($activeSignedDoc['document_path']) ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                <i class="bi bi-download"></i> Download Signed Document
              </a>
            </div>
          <?php endif; ?>

          <!-- Document History -->
          <?php if (!empty($signedDocHistory)): ?>
            <div class="mt-3">
              <h6 class="mb-2">📋 Document Upload History</h6>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Version</th>
                      <th>Uploaded By</th>
                      <th>Date</th>
                      <th>File Name</th>
                      <th>Size</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($signedDocHistory as $doc): ?>
                      <tr>
                        <td><?= htmlspecialchars($doc['version_number']) ?></td>
                        <td><?= htmlspecialchars($doc['uploaded_by_name']) ?></td>
                        <td><?= date('M d, Y H:i', strtotime($doc['uploaded_at'])) ?></td>
                        <td><?= htmlspecialchars($doc['original_file_name']) ?></td>
                        <td><?= number_format($doc['file_size'] / 1024, 1) ?> KB</td>
                        <td>
                          <?php if ($doc['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Replaced</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick Actions -->
     <div class="card shadow-sm mt-3">
        <div class="card-header bg-light">
          <h5 class="mb-0">Actions</h5>
        </div>
        <div class="card-body d-flex flex-column gap-2">
          <?php if ($request['status'] === 'DRAFT' && $_SESSION['user_id'] == $request['created_by']): ?>
            <a href="/petty_cash/add.php?edit=<?= $request_id ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-pencil"></i> Edit Request
            </a>
            <form method="post" action="/petty_cash/submit.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <button type="submit" class="btn btn-success btn-sm w-100">
                <i class="bi bi-send"></i> Submit for Approval
              </button>
            </form>
          <?php endif; ?>
          
          <?php 
          // Role and permission checks
          $isFinanceOfficer = in_array($_SESSION['role_name'] ?? '', ['Finance Officer', 'Admin', 'SuperAdmin']);
          $isRequestor = (int)($_SESSION['user_id'] ?? 0) === (int)$request['created_by'];
          
          // Finance approval at submission stage
          $canApprove = in_array($request['status'], ['SUBMITTED']) && $isFinanceOfficer;
          
          // Finance disbursement at FUNDS_VERIFIED or FINANCE_AUTHORIZED
          $canDisburse = in_array($request['status'], ['FUNDS_VERIFIED', 'FINANCE_AUTHORIZED']) && $isFinanceOfficer && $disbursement;
          
          // Finance verification of reconciliation
          $canVerifyReconciliation = $request['status'] === 'PENDING_RECONCILIATION' && $isFinanceOfficer && $reconciliation;
          
          // Requestor reconciliation submission
          $reconciliationEligibleStatuses = ['FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'DISBURSED', 'PENDING_RECONCILIATION'];
          $canSubmitReconciliation = $disbursement && !$reconciliation && $isRequestor && in_array($request['status'], $reconciliationEligibleStatuses);
          ?>
          
          <!-- FINANCE OFFICER: Initial Approval -->
          <?php if ($canApprove): ?>
            <div class="alert alert-info py-2 mb-2">
              <small><strong>Action Required:</strong> Verify funds and authorize this petty cash request.</small>
            </div>
            <form method="post" action="/petty_cash/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                <i class="bi bi-check-circle"></i> Verify Funds & Authorize
              </button>
            </form>
            <form method="post" action="/petty_cash/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="decline">
              <button type="submit" class="btn btn-danger btn-sm w-100">
                <i class="bi bi-x-circle"></i> Decline
              </button>
            </form>
          <?php endif; ?>

          <!-- FINANCE OFFICER: Disbursement -->
          <?php if ($canDisburse): ?>
            <div class="alert alert-warning py-2 mb-2">
              <small><strong>Action Required:</strong> Disburse the authorized petty cash amount.</small>
            </div>
            <button type="button" class="btn btn-warning btn-sm w-100" data-bs-toggle="modal" data-bs-target="#disbursalModal">
              <i class="bi bi-cash-coin"></i> Record Cash Disbursement
            </button>
          <?php endif; ?>

          <!-- FINANCE OFFICER: Reconciliation Verification -->
          <?php if ($canVerifyReconciliation): ?>
            <div class="alert alert-warning py-2 mb-2">
              <small><strong>Action Required:</strong> Verify the reconciliation submission.</small>
            </div>
            <button type="button" class="btn btn-warning btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#verifyReconciliationModal">
              <i class="bi bi-check-lg"></i> Verify Reconciliation
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#rejectReconciliationModal">
              <i class="bi bi-exclamation-circle"></i> Report Discrepancy
            </button>
          <?php endif; ?>

          <!-- REQUESTOR: Reconciliation Submission -->
          <?php if ($canSubmitReconciliation): ?>
            <a href="/petty_cash/reconcile.php?id=<?= $request_id ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-receipt me-1"></i> Submit Reconciliation
            </a>
          <?php elseif ($disbursement && !$reconciliation && $isRequestor && $request['status'] === 'PENDING_RECONCILIATION'): ?>
            <div class="alert alert-danger py-2 mb-2">
              <small><strong>Note:</strong> Waiting for Finance Officer to verify your reconciliation submission.</small>
            </div>
          <?php elseif ($disbursement && !$reconciliation && $isRequestor && in_array($request['status'], ['FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'DISBURSED'])): ?>
            <div class="alert alert-warning py-2 mb-2">
              <small><strong>Action Required:</strong> Submit your reconciliation within 24 hours of disbursement.</small>
            </div>
            <a href="/petty_cash/reconcile.php?id=<?= $request_id ?>" class="btn btn-success btn-sm">
              <i class="bi bi-receipt me-1"></i> Submit Reconciliation
            </a>
          <?php endif; ?>

          <?php
          // Revert Stage — available to authorized roles when backward transitions exist
          require_once $_SERVER['DOCUMENT_ROOT'].'/services/WorkflowService.php';
          $workflowService = new WorkflowService($pdo);
          $current = $request['status'];
          $role = $_SESSION['role_name'] ?? '';
          
          $canRevertStage = $current !== 'PAUSED' 
              && $workflowService->canUserRevert($role, 'PETTY_CASH')
              && !in_array($current, ['DRAFT', 'COMPLETED', 'DECLINED'], true);
          
          $revertTargets = [];
          if ($canRevertStage) {
              $revertTargets = $workflowService->getValidRevertTargets('PETTY_CASH', $current);
              $canRevertStage = !empty($revertTargets);
          }
          ?>
          <?php if ($canRevertStage): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm w-100 mb-2"
                      data-bs-toggle="modal" data-bs-target="#revertStageModal">
                  <i class="bi bi-skip-backward me-1"></i>Revert Stage
              </button>
          <?php endif; ?>
          
          <a href="/petty_cash/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to List
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Revert Workflow Stage Modal -->
<?php if ($canRevertStage ?? false): ?>
<div class="modal fade" id="revertStageModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/petty_cash/revert_status.php" class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-skip-backward me-2"></i>Revert Workflow Stage</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $request_id ?>">
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Reverting a workflow stage moves the request backwards. The workflow will need to be
                    re-completed from the target stage.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Revert to Stage</label>
                    <select name="target_status" class="form-select" required>
                        <option value="">— Select target stage —</option>
                        <?php foreach ($revertTargets ?? [] as $target): ?>
                        <option value="<?= htmlspecialchars($target['status']) ?>">
                            <?= htmlspecialchars($target['label']) ?>
                            <?php if (!empty($target['stage_owners'])): ?>
                                (<?= htmlspecialchars(implode(', ', $target['stage_owners'])) ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">The request will be returned to the selected stage for correction.</small>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Explain why this request is being moved back to a prior stage..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-secondary"
                        onclick="return confirm('Revert this request to the selected stage?')">
                    <i class="bi bi-skip-backward me-1"></i>Revert Stage
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<div class="modal fade" id="signedRequestHandlingNoticeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Important Document Handling Notice</h5>
      </div>
      <div class="modal-body">
        <p id="signedRequestNoticeMessage" class="mb-0"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="signedRequestNoticeConfirmBtn">I Understand</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const modalEl = document.getElementById('signedRequestHandlingNoticeModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;

  const modalMessageEl = document.getElementById('signedRequestNoticeMessage');
  const confirmBtn = document.getElementById('signedRequestNoticeConfirmBtn');
  const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
  const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
  let onConfirm = null;

  function createActionToken(prefix, requestType, requestId) {
    return [prefix, requestType, requestId, Date.now(), Math.random().toString(36).slice(2, 10)].join('-');
  }

  function postPrintNoticeEvent(payload) {
    const body = new URLSearchParams(payload);
    body.append('csrf_token', csrfToken || '');
    return fetch('/api/signed_request_notice.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    }).catch(() => null);
  }

  function showNotice(message, buttonLabel, callback) {
    modalMessageEl.textContent = message;
    confirmBtn.textContent = buttonLabel;
    onConfirm = callback;
    modal.show();
  }

  confirmBtn.addEventListener('click', function () {
    if (typeof onConfirm === 'function') {
      onConfirm();
    }
    modal.hide();
  });

  document.querySelectorAll('.js-signed-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      const enabled = btn.dataset.printNoticeEnabled === '1';
      if (!enabled) return;

      event.preventDefault();
      if (btn.dataset.noticeInProgress === '1') return;
      btn.dataset.noticeInProgress = '1';

      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      const requestType = (btn.dataset.requestType || 'PETTY_CASH').toUpperCase();
      const actionToken = createActionToken('print', requestType, requestId);
      const printUrl = btn.getAttribute('href');
      const printWindow = window.open(printUrl, '_blank', 'noopener');
      if (!printWindow) {
        btn.dataset.noticeInProgress = '0';
        return;
      }

      postPrintNoticeEvent({
        request_id: String(requestId),
        request_type: requestType,
        notice_context: 'PRINT',
        event_type: 'DISPLAYED',
        action_token: actionToken,
        event_note: 'Print reminder displayed after print form launch'
      });

      showNotice(
        'After signing, the original document must be submitted to Procurement first. Procurement will keep the original document, make a copy, and send the copy to Finance for processing.',
        'I Understand',
        function () {
          postPrintNoticeEvent({
            request_id: String(requestId),
            request_type: requestType,
            notice_context: 'PRINT',
            event_type: 'ACKNOWLEDGED',
            action_token: actionToken,
            event_note: 'Print reminder acknowledged by user'
          }).finally(function () {
            btn.dataset.noticeInProgress = '0';
          });
        }
      );
    });
  });

  document.querySelectorAll('.js-signed-upload-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const enabled = form.dataset.uploadNoticeEnabled === '1';
      const ackInput = form.querySelector('input[name="signed_notice_upload_ack"]');
      const tokenInput = form.querySelector('input[name="signed_notice_action_token"]');

      if (!enabled || (ackInput && ackInput.value === '1')) return;

      event.preventDefault();
      if (form.dataset.noticeInProgress === '1') return;
      form.dataset.noticeInProgress = '1';

      const requestId = parseInt(form.dataset.requestId || '0', 10);
      const requestType = (form.dataset.requestType || 'PETTY_CASH').toUpperCase();
      if (tokenInput && tokenInput.value.trim() === '') {
        tokenInput.value = createActionToken('upload', requestType, requestId);
      }

      showNotice(
        'Please confirm that the original signed document will be submitted to Procurement first. Procurement will copy and forward the document to Finance.',
        'Continue Upload',
        function () {
          if (ackInput) ackInput.value = '1';
          form.dataset.noticeInProgress = '0';
          form.submit();
        }
      );
    });
  });
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>

<style>
.timeline-item {
  position: relative;
  padding-left: 20px;
}
.timeline-marker {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background-color: #0d6efd;
  position: absolute;
  left: 0;
  top: 2px;
}
</style>

<!-- MODALS for Finance Officer Actions -->

<!-- Disbursal Modal -->
<div class="modal fade" id="disbursalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Cash Disbursement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/disburse.php">
        <div class="modal-body">
          <p class="text-muted">Record that the authorized petty cash amount has been physically disbursed to the requestor.</p>
          <div class="mb-3">
            <label for="disbursal_notes" class="form-label">Disbursal Notes (optional)</label>
            <textarea class="form-control" id="disbursal_notes" name="disbursal_notes" rows="3" 
                      placeholder="E.g., Paid in cash by check #123, Handed to John Smith, etc."></textarea>
          </div>
          <input type="hidden" name="request_id" value="<?= $request_id ?>">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="bi bi-check-lg me-1"></i>Confirm Disbursement
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Verify Reconciliation Modal (Approve) -->
<div class="modal fade" id="verifyReconciliationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-check-lg me-2"></i>Verify Reconciliation - Approve</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/verify_reconciliation.php">
        <div class="modal-body">
          <div class="alert alert-info mb-3">
            <strong>Reconciliation Summary:</strong>
            <ul class="mb-0 mt-2">
              <li><strong>Amount Authorized:</strong> <?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($disbursement['amount_authorized'], 2) ?></li>
              <li><strong>Purchase Amount:</strong> <?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($reconciliation['purchase_amount'], 2) ?></li>
              <li><strong>Change Returned:</strong> <?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($reconciliation['change_amount'], 2) ?></li>
              <li><strong>Reconciliation Status:</strong> <?= ucfirst(strtolower($reconciliation['status'])) ?></li>
            </ul>
          </div>
          <div class="mb-3">
            <label for="verify_notes" class="form-label">Verification Notes (optional)</label>
            <textarea class="form-control" id="verify_notes" name="verification_notes" rows="3" 
                      placeholder="E.g., Reconciliation verified against receipts, all amounts match."></textarea>
          </div>
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
          <input type="hidden" name="action" value="approve">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i>Approve Reconciliation
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reject Reconciliation Modal (Report Discrepancy) -->
<div class="modal fade" id="rejectReconciliationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger bg-opacity-10">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-circle me-2"></i>Report Reconciliation Discrepancy</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/verify_reconciliation.php">
        <div class="modal-body">
          <p class="text-muted mb-3">Report a discrepancy found in this reconciliation. The requestor will be notified and given an opportunity to correct the issue.</p>
          
          <div class="mb-3">
            <label for="discrepancy_reason" class="form-label">Discrepancy Reason <span class="text-danger">*</span></label>
            <textarea class="form-control" id="discrepancy_reason" name="verification_notes" rows="3" required
                      placeholder="E.g., Missing receipts for JMD 500, Change amount doesn't match, etc."></textarea>
          </div>
          
          <div class="mb-3">
            <label for="discrepancy_amount" class="form-label">Discrepancy Amount (optional)</label>
            <input type="number" step="0.01" min="0" class="form-control" id="discrepancy_amount" name="discrepancy_amount" 
                   placeholder="Enter the amount of the discrepancy if applicable">
          </div>
          
          <div class="mb-3">
            <label for="required_action" class="form-label">Required Action (What the requestor must do)</label>
            <textarea class="form-control" id="required_action" name="required_action" rows="2" 
                      placeholder="E.g., Provide receipts for purchases, Resubmit corrected reconciliation, etc."></textarea>
          </div>
          
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
          <input type="hidden" name="action" value="reject">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">
            <i class="bi bi-exclamation-circle me-1"></i>Report Discrepancy
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Upload Supporting Document</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/upload_reconciliation_document.php" enctype="multipart/form-data">
        <div class="modal-body">
          <p class="text-muted mb-3">Attach supporting documentation such as receipts, invoices, proof of purchase, or change return documentation.</p>
          
          <div class="mb-3">
            <label for="document_type" class="form-label">Document Type <span class="text-danger">*</span></label>
            <select class="form-select" id="document_type" name="document_type" required>
              <option value="" disabled selected>-- Select Type --</option>
              <option value="RECEIPT">Receipt</option>
              <option value="INVOICE">Invoice</option>
              <option value="PROOF_OF_PURCHASE">Proof of Purchase</option>
              <option value="CHANGE_RETURN">Change Return Documentation</option>
              <option value="OTHER">Other</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label for="document_file" class="form-label">File <span class="text-danger">*</span></label>
            <input type="file" class="form-control" id="document_file" name="document_file" required 
                   accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
            <small class="text-muted">Accepted formats: PDF, images, Word documents, Excel spreadsheets (max 50MB)</small>
          </div>
          
          <div class="mb-3">
            <label for="document_notes" class="form-label">Notes (optional)</label>
            <textarea class="form-control" id="document_notes" name="document_notes" rows="2" 
                      placeholder="E.g., Receipt from ABC Store for office supplies"></textarea>
          </div>
          
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-cloud-upload me-1"></i>Upload Document
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Resolve Discrepancy Modal -->
<div class="modal fade" id="resolveDiscrepancyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success bg-opacity-10">
        <h5 class="modal-title text-success"><i class="bi bi-check-lg me-2"></i>Approve Corrections</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/review_discrepancy.php">
        <div class="modal-body">
          <p class="text-muted mb-3">The requestor has provided corrections to address the discrepancy. Review and approve to proceed to final reconciliation.</p>
          
          <div class="mb-3">
            <label for="resolution_notes_resolve" class="form-label">Resolution Notes (optional)</label>
            <textarea class="form-control" id="resolution_notes_resolve" name="resolution_notes" rows="3" 
                      placeholder="E.g., Corrections verified against new receipts, reconciliation now complete."></textarea>
          </div>
          
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
          <input type="hidden" name="action" value="resolve">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i>Approve Corrections
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reopen Discrepancy Modal -->
<div class="modal fade" id="reopenDiscrepancyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-10">
        <h5 class="modal-title text-warning"><i class="bi bi-arrow-counterclockwise me-2"></i>Reopen for More Corrections</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/review_discrepancy.php">
        <div class="modal-body">
          <p class="text-muted mb-3">The corrections provided do not fully address the discrepancy. Reopen the review to request additional corrections from the requestor.</p>
          
          <div class="mb-3">
            <label for="resolution_notes_reopen" class="form-label">Additional Issues <span class="text-danger">*</span></label>
            <textarea class="form-control" id="resolution_notes_reopen" name="resolution_notes" rows="3" required
                      placeholder="E.g., Still missing receipts for JMD 250, Change amount still doesn't reconcile."></textarea>
          </div>
          
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
          <input type="hidden" name="action" value="reopen">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen Review
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Finalize Reconciliation Modal -->
<div class="modal fade" id="finalizeReconciliationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success">
        <h5 class="modal-title text-white"><i class="bi bi-check2-circle me-2"></i>Finalize & Complete Reconciliation</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/petty_cash/review_discrepancy.php">
        <div class="modal-body">
          <p class="text-muted mb-3">Mark this reconciliation as completed after all corrections have been reviewed and approved.</p>
          
          <div class="mb-3">
            <label for="resolution_notes_finalize" class="form-label">Final Notes (optional)</label>
            <textarea class="form-control" id="resolution_notes_finalize" name="resolution_notes" rows="3" 
                      placeholder="E.g., Reconciliation complete, all discrepancies resolved and documented."></textarea>
          </div>
          
          <div class="alert alert-success mb-0">
            <i class="bi bi-info-circle me-2"></i>
            <strong>This action will mark the petty cash request as COMPLETED.</strong>
          </div>
          
          <input type="hidden" name="reconcile_id" value="<?= (int)$reconciliation['reconcile_id'] ?>">
          <input type="hidden" name="action" value="resolve">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check2-circle me-1"></i>Complete Reconciliation
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
