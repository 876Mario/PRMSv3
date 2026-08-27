<?php
$REQUIRE_PERMISSION = 'view_reimbursement_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($request_id <= 0) {
    pop('Invalid reimbursement request', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Fetch request details */
$stmt = $pdo->prepare("
    SELECT 
        pr.*,
        b.branch_name,
        u.full_name,
        u.email,
        pa.authorization_amount,
        pa.authorized_by,
        pa.authorization_date,
        pat.full_name as authorizer_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    LEFT JOIN pre_authorizations pa ON pr.request_id = pa.request_id
    LEFT JOIN users pat ON pa.authorized_by = pat.user_id
    WHERE pr.request_id = ? AND (pr.request_type = 'REIMBURSEMENT' OR pa.request_id IS NOT NULL)
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Reimbursement request not found', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Fetch invoices submitted for this reimbursement */
$invStmt = $pdo->prepare("
    SELECT 
        ri.*,
        u.full_name as submitted_by_name,
        v.full_name as verified_by_name
    FROM reimbursement_invoices ri
    LEFT JOIN users u ON ri.submitted_by = u.user_id
    LEFT JOIN users v ON ri.verified_by = v.user_id
    WHERE ri.request_id = ?
    ORDER BY ri.submitted_date DESC
");
$invStmt->execute([$request_id]);
$invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch attachments for all invoices */
$invoiceIds = array_column($invoices, 'reimb_invoice_id');
$attachmentsByInvoice = [];
if (!empty($invoiceIds)) {
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $attStmt = $pdo->prepare("
        SELECT 
            a.*
        FROM reimbursement_invoice_attachments a
        WHERE a.reimb_invoice_id IN ($placeholders) AND a.is_deleted = 0
        ORDER BY a.uploaded_date DESC
    ");
    $attStmt->execute($invoiceIds);
    $allAttachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allAttachments as $att) {
        if (!isset($attachmentsByInvoice[$att['reimb_invoice_id']])) {
            $attachmentsByInvoice[$att['reimb_invoice_id']] = [];
        }
        $attachmentsByInvoice[$att['reimb_invoice_id']][] = $att;
    }
}

/* Fetch verification record if exists */
$verifyStmt = $pdo->prepare("
    SELECT pv.*
    FROM procurement_verifications pv
    WHERE pv.request_id = ? AND pv.verification_type = 'GOODS_RECEIVED'
    ORDER BY pv.verification_date DESC LIMIT 1
");
$verifyStmt->execute([$request_id]);
$verification = $verifyStmt->fetch(PDO::FETCH_ASSOC);

/* Fetch status history */
$histStmt = $pdo->prepare("
    SELECT rsh.*, u.full_name
    FROM reimbursement_status_history rsh
    LEFT JOIN users u ON rsh.changed_by = u.user_id
    WHERE rsh.request_id = ?
    ORDER BY rsh.change_date DESC
");
$histStmt->execute([$request_id]);
$statusHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch approval records for responsibility tooltip */
$approvalsStmt = $pdo->prepare(
    'SELECT id, role, stage_order, status, approved_by
       FROM request_approvals
      WHERE request_id = ?
      ORDER BY stage_order ASC'
);
$approvalsStmt->execute([$request_id]);
$approvals = $approvalsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

// Initialize SignedRequestService
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestService.php';
$signedRequestService = new SignedRequestService($pdo);
$signedRequestPending = $signedRequestService->isUploadPending($request_id, 'REIMBURSEMENT');
$activeSignedDoc = $signedRequestService->getActiveDocument($request_id);
$signedDocHistory = $signedRequestService->getDocumentHistory($request_id);

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
            💵 Reimbursement Request <?= htmlspecialchars($request['request_number']) ?>
          </h3>
          <small class="text-muted">Created on <?= formatJamaicanDateTime($request['created_at'], 'd M Y \\a\\t g:i A') ?></small>
        </div>
        <div>
          <h4 class="text-end"><?= getReimbursementStatusLabel($request['status']) ?></h4>
        </div>
      </div>
    </div>
  </div>

  <!-- Workflow Pipeline -->
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
      <h5 class="mb-0">📊 Workflow Progress</h5>
    </div>
    <div class="card-body">
      <div class="row g-2 pipeline-stages-row">
        <?php
        require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowResponsibilityService.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/workflow_pipeline.php';

        // Get reimbursement pipeline stages and convert to keyed format
        $pipelineRaw = getReimbursementPipeline();
        $pipelineStagesKeyed = [];
        foreach ($pipelineRaw as $s) {
            $pipelineStagesKeyed[$s['status']] = ['label' => $s['label'], 'icon' => $s['icon']];
        }

        $wfRespService = new WorkflowResponsibilityService($pdo);
        $wfResponsibilities = $wfRespService->getPipelineResponsibility(
            $pipelineStagesKeyed,
            $request,
            $request['status'],
            $approvals,
            $_SESSION['role_name'] ?? ''
        );

        $stageKeys = array_keys($pipelineStagesKeyed);
        $currentIdx = array_search($request['status'], $stageKeys, true);
        $totalStages = count($stageKeys);

        foreach ($stageKeys as $idx => $stageKey):
            echo renderWorkflowPipelineStage(
                $stageKey,
                $pipelineStagesKeyed[$stageKey],
                $idx,
                $totalStages,
                $currentIdx !== false ? (int)$currentIdx : -1,
                $wfResponsibilities[$stageKey] ?? []
            );
        endforeach;
        ?>
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
              <small class="text-muted d-block">Request Date</small>
              <strong><?= date('M d, Y', strtotime($request['request_date'])) ?></strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Invoice Amount</small>
              <strong class="text-success"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($request['estimated_value'], 2) ?></strong>
            </div>
            <div class="col-12">
              <small class="text-muted d-block">Description</small>
              <p class="mb-0"><?= htmlspecialchars($request['description']) ?></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Pre-Authorization -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
          <h5 class="mb-0">✅ Step 1: Prior Authorization</h5>
        </div>
        <div class="card-body">
          <?php if ($request['authorization_amount']): ?>
            <div class="alert alert-success">
              <strong>✓ Authorized</strong>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <small class="text-muted d-block">Authorized By</small>
                <strong><?= htmlspecialchars($request['authorizer_name']) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Authorization Date</small>
                <strong><?= date('M d, Y', strtotime($request['authorization_date'])) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Authorized Amount</small>
                <strong class="text-success"><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($request['authorization_amount'], 2) ?></strong>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-warning">
              <strong>⚠️ Pending Prior Authorization</strong> - Awaiting Branch Head authorization
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Invoices Section -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
          <h5 class="mb-0">📄 Step 2 & 3: Invoice Submission & Verification</h5>
        </div>
        <div class="card-body">
          <?php if (empty($invoices)): ?>
            <div class="alert alert-info">
              No invoices have been submitted yet.
              <?php if ($request['status'] === 'FUNDS_VERIFIED' && $_SESSION['user_id'] == $request['created_by']): ?>
                <br>
                <a href="/reimbursement/submit_invoice.php?request_id=<?= $request_id ?>" class="btn btn-sm btn-primary mt-2">
                  <i class="bi bi-upload"></i> Submit Invoice Copy
                </a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>Stage</th>
                    <th>Amount</th>
                    <th>Submitted By</th>
                    <th>Submitted Date</th>
                    <th>Verified By</th>
                    <th>Status</th>
                    <?php if (has_permission('verify_reimbursement_goods')): ?>
                      <th>Actions</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($invoices as $inv): ?>
                    <tr>
                      <td><?= $inv['invoice_stage'] === 'COPY_TO_PROCUREMENT' ? '📋 Copy to Procurement (GC2)' : '📄 Original to Finance (GC10A)' ?></td>
                      <td><?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?> <?= number_format($inv['invoice_amount'], 2) ?></td>
                      <td><?= htmlspecialchars($inv['submitted_by_name']) ?></td>
                      <td><?= date('M d, Y', strtotime($inv['submitted_date'])) ?></td>
                      <td><?= $inv['verified_by_name'] ? htmlspecialchars($inv['verified_by_name']) : '<em class="text-muted">Pending</em>' ?></td>
                      <td>
                        <?php if ($inv['goods_service_verified']): ?>
                          <span class="badge bg-success">✓ Verified</span>
                        <?php else: ?>
                          <span class="badge bg-warning">Pending Verification</span>
                        <?php endif; ?>
                      </td>
                      <?php if (has_permission('verify_reimbursement_goods')): ?>
                        <td>
                          <?php if (!$inv['goods_service_verified']): ?>
                            <a href="/reimbursement/verify_invoice.php?id=<?= (int)$inv['reimb_invoice_id'] ?>" class="btn btn-sm btn-outline-success">
                              <i class="bi bi-check2-circle"></i> Verify
                            </a>
                          <?php elseif ($inv['invoice_stage'] === 'COPY_TO_PROCUREMENT' && canReimbursementTransition($request['status'], 'INVOICE_VERIFIED')): ?>
                            <a href="/reimbursement/verify_invoice.php?id=<?= (int)$inv['reimb_invoice_id'] ?>" class="btn btn-sm btn-outline-primary">
                              <i class="bi bi-arrow-right-circle"></i> Advance Pipeline
                            </a>
                          <?php else: ?>
                            <span class="text-muted small">—</span>
                          <?php endif; ?>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Attachments Section -->
            <?php if (!empty($attachmentsByInvoice)): ?>
              <div class="mt-4 border-top pt-4">
                <h6 class="mb-3"><i class="bi bi-file-earmark-arrow-down me-2"></i>Attached Documents</h6>
                <?php foreach ($invoices as $inv): ?>
                  <?php if (!empty($attachmentsByInvoice[$inv['reimb_invoice_id']])): ?>
                    <div class="mb-4">
                      <div class="fw-semibold small text-muted mb-2">
                        <?= $inv['invoice_stage'] === 'COPY_TO_PROCUREMENT' ? '📋 Copy to Procurement (GC2)' : '📄 Original to Finance (GC10A)' ?>
                      </div>
                      <div class="ps-3">
                        <?php foreach ($attachmentsByInvoice[$inv['reimb_invoice_id']] as $att): ?>
                          <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded mb-2">
                            <div class="d-flex align-items-center gap-2" style="flex: 1;">
                              <i class="bi bi-file-earmark text-secondary"></i>
                              <div style="flex: 1; min-width: 0;">
                                <div class="small fw-semibold text-truncate">
                                  <?= htmlspecialchars($att['original_file_name']) ?>
                                </div>
                                <small class="text-muted">
                                  <?= formatFileSize($att['file_size']) ?> • <?= date('M d, Y', strtotime($att['uploaded_date'])) ?>
                                </small>
                              </div>
                            </div>
                            <div class="ms-2">
                              <?php if ($request['created_by'] == $_SESSION['user_id'] || has_permission('manage_users') || has_permission('verify_reimbursement_goods')): ?>
                                <a href="/reimbursement/download_attachment.php?id=<?= (int)$att['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download">
                                  <i class="bi bi-download"></i>
                                </a>
                              <?php endif; ?>
                              <?php if (has_permission('delete_reimbursement_invoice_attachment') && ($request['created_by'] == $_SESSION['user_id'] || has_permission('manage_users'))): ?>
                                <form method="POST" action="/reimbursement/delete_attachment.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this attachment?');">
                                  <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
                                  <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                  </button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Verification Status -->
      <?php if ($verification): ?>
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">🔍 Procurement Verification</h5>
          </div>
          <div class="card-body">
            <div class="alert alert-success">
              <strong>✓ Verified</strong> - Goods/services verified as satisfactory
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <small class="text-muted d-block">Verified By</small>
                <strong><?= htmlspecialchars($verification['verified_by_name'] ?? 'N/A') ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Verification Date</small>
                <strong><?= date('M d, Y', strtotime($verification['verification_date'])) ?></strong>
              </div>
              <div class="col-md-6">
                <small class="text-muted d-block">Condition</small>
                <strong><?= htmlspecialchars($verification['condition_status']) ?></strong>
              </div>
              <div class="col-12">
                <small class="text-muted d-block">Notes</small>
                <p class="mb-0"><?= htmlspecialchars($verification['verification_notes'] ?? '') ?></p>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
      <!-- Status Timeline -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
          <h5 class="mb-0">Status Timeline</h5>
        </div>
        <div class="card-body">
          <div class="timeline">
            <?php foreach ($statusHistory as $hist): ?>
              <div class="timeline-item mb-3">
                <div class="d-flex gap-2">
                  <div class="timeline-marker"></div>
                  <div class="flex-grow-1">
                    <small class="text-muted d-block"><?= formatJamaicanDateTime($hist['change_date']) ?></small>
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
              <strong>Action Required:</strong> The signed approval form must be uploaded before this request can proceed through the approval workflow.
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <!-- Print Section -->
            <div class="col-md-6">
              <div class="card bg-light">
                <div class="card-body">
                  <h6 class="card-title">Step 1: Print Form</h6>
                  <p class="small text-muted mb-3">Print the approval form, review all information, and sign it.</p>
                  <a href="/reimbursement/print_for_signing.php?request_id=<?= $request_id ?>" 
                     class="btn btn-primary btn-sm w-100" target="_blank">
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
                  <?php if ($signedRequestService->canUserUpload($request_id, 'REIMBURSEMENT', $_SESSION['user_id'], $_SESSION['role_name'])): ?>
                    <button class="btn btn-success btn-sm w-100" data-bs-toggle="collapse" data-bs-target="#uploadForm">
                      <i class="bi bi-upload"></i> Upload Signed Form
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Upload Form (Collapsible) -->
          <?php if ($signedRequestService->canUserUpload($request_id, 'REIMBURSEMENT', $_SESSION['user_id'], $_SESSION['role_name'])): ?>
            <div class="collapse mt-3" id="uploadForm">
              <div class="card card-body">
                <form method="post" action="/reimbursement/upload_signed_request.php" enctype="multipart/form-data">
                  <input type="hidden" name="request_id" value="<?= $request_id ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  
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
      <div class="card shadow-sm">
        <div class="card-header bg-light">
          <h5 class="mb-0">Actions</h5>
        </div>
        <div class="card-body d-flex flex-column gap-2">
          <?php if ($request['status'] === 'DRAFT' && $_SESSION['user_id'] == $request['created_by']): ?>
            <a href="/reimbursement/add.php?edit=<?= $request_id ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-pencil"></i> Edit Request
            </a>
            <form method="post" action="/reimbursement/submit.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <button type="submit" class="btn btn-success btn-sm w-100">
                <i class="bi bi-send"></i> Submit for Approval
              </button>
            </form>
          <?php endif; ?>
          
          <?php 
          // Finance approval actions
          $isFinanceOfficer = ($_SESSION['role_name'] ?? '') === 'Finance Officer';
          $canApprove = in_array($request['status'], ['SUBMITTED']) && $isFinanceOfficer;
          // Final approval once the invoice has cleared verification (or the
          // request bypassed the invoice stages while funds were verified).
          $canFinalApprove = in_array($request['status'], ['FUNDS_VERIFIED', 'INVOICE_VERIFIED']) && $isFinanceOfficer;
          ?>
          
          <?php if ($canApprove): ?>
            <div class="alert alert-info py-2 mb-2">
              <small><strong>Action Required:</strong> Verify funds and approve this reimbursement request.</small>
            </div>
            <form method="post" action="/reimbursement/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                <i class="bi bi-check-circle"></i> Verify Funds & Approve
              </button>
            </form>
            <form method="post" action="/reimbursement/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="decline">
              <button type="submit" class="btn btn-danger btn-sm w-100">
                <i class="bi bi-x-circle"></i> Decline
              </button>
            </form>
          <?php endif; ?>

          <?php if ($canFinalApprove): ?>
            <div class="alert alert-info py-2 mb-2">
              <small><strong>Action Required:</strong> Approve this reimbursement request for payment.</small>
            </div>
            <form method="post" action="/reimbursement/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success btn-sm w-100 mb-2">
                <i class="bi bi-check-circle"></i> Approve Reimbursement
              </button>
            </form>
            <form method="post" action="/reimbursement/approve.php" class="d-inline">
              <input type="hidden" name="request_id" value="<?= $request_id ?>">
              <input type="hidden" name="action" value="decline">
              <button type="submit" class="btn btn-danger btn-sm w-100">
                <i class="bi bi-x-circle"></i> Decline
              </button>
            </form>
          <?php endif; ?>

          <?php 
          // Finance marks payment as disbursed/reimbursed
          $canMarkReimbursed = ($request['status'] === 'APPROVED') && $isFinanceOfficer;
          ?>
          
          <?php if ($canMarkReimbursed): ?>
            <div class="alert alert-info py-2 mb-2">
              <small><strong>Action Required:</strong> Mark this reimbursement as paid/disbursed.</small>
            </div>
            <button type="button" class="btn btn-success btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#markReimbursedModal">
              <i class="bi bi-cash-coin"></i> Mark as Reimbursed
            </button>
          <?php endif; ?>

          <?php 
          // Requestor confirms receipt of reimbursement
          $canConfirmReceipt = ($request['status'] === 'REIMBURSED') && ($_SESSION['user_id'] == $request['created_by']);
          ?>
          
          <?php if ($canConfirmReceipt): ?>
            <div class="alert alert-success py-2 mb-2">
              <small><strong>Action Required:</strong> Please confirm you have received the reimbursement payment.</small>
            </div>
            <button type="button" class="btn btn-primary btn-sm w-100 mb-2" data-bs-toggle="modal" data-bs-target="#confirmReceiptModal">
              <i class="bi bi-check-circle-fill"></i> Confirm Receipt of Reimbursement
            </button>
          <?php endif; ?>
          
          <a href="/reimbursement/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to List
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Mark Reimbursed Modal -->
<div class="modal fade" id="markReimbursedModal" tabindex="-1" aria-labelledby="markReimbursedModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="markReimbursedModalLabel">Mark Reimbursement as Paid</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/reimbursement/mark_reimbursed.php">
        <div class="modal-body">
          <input type="hidden" name="request_id" value="<?= $request_id ?>">
          <div class="mb-3">
            <label for="payment_reference" class="form-label">Payment Reference (Optional)</label>
            <input type="text" class="form-control" id="payment_reference" name="payment_reference" placeholder="e.g., Check #12345 or Bank Transfer Ref">
          </div>
          <div class="mb-3">
            <label for="payment_notes" class="form-label">Notes (Optional)</label>
            <textarea class="form-control" id="payment_notes" name="payment_notes" rows="3" placeholder="Any additional notes about the payment"></textarea>
          </div>
          <div class="alert alert-info mb-0">
            <small><i class="bi bi-info-circle"></i> After marking as reimbursed, the requestor will be notified to confirm receipt of payment.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-cash-coin"></i> Mark as Reimbursed
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Confirm Receipt Modal -->
<div class="modal fade" id="confirmReceiptModal" tabindex="-1" aria-labelledby="confirmReceiptModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmReceiptModalLabel">Confirm Receipt of Reimbursement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="/reimbursement/confirm_receipt.php">
        <div class="modal-body">
          <input type="hidden" name="request_id" value="<?= $request_id ?>">
          <p>Please confirm that you have received the reimbursement payment for this request.</p>
          <div class="mb-3">
            <label for="confirmation_notes" class="form-label">Notes (Optional)</label>
            <textarea class="form-control" id="confirmation_notes" name="confirmation_notes" rows="3" placeholder="Any additional notes or confirmation details"></textarea>
          </div>
          <div class="alert alert-success mb-0">
            <small><i class="bi bi-check-circle"></i> Confirming receipt will mark this request as completed.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle-fill"></i> Confirm Receipt
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

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

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
