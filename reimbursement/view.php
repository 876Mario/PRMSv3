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

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
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
      <div class="row g-2">
        <?php
        // Get reimbursement pipeline stages from centralized workflow config
        $pipelineStages = getReimbursementPipeline();
        $currentStatusIdx = -1;
        
        // Find current status index in pipeline
        foreach ($pipelineStages as $idx => $stage) {
            if ($stage['status'] === $request['status']) {
                $currentStatusIdx = $idx;
                break;
            }
        }
        
        // Display each stage
        foreach ($pipelineStages as $idx => $stage):
            $isCompleted = ($currentStatusIdx !== -1 && $idx < $currentStatusIdx);
            $isCurrent = ($stage['status'] === $request['status']);
            
            if ($isCompleted) {
                $borderClass = 'border-success bg-success bg-opacity-10';
                $circleClass = 'bg-success text-white';
                $circleContent = '<i class="bi bi-check-lg"></i>';
            } elseif ($isCurrent) {
                $borderClass = 'border-primary bg-primary bg-opacity-10';
                $circleClass = 'bg-primary text-white';
                $circleContent = '<i class="bi bi-arrow-right"></i>';
            } else {
                $borderClass = 'border-light bg-light';
                $circleClass = 'bg-secondary bg-opacity-25 text-muted';
                $circleContent = ($idx + 1);
            }
        ?>
        <div class="col-lg col-md-3 col-sm-4 col-6">
          <div class="text-center p-2 rounded-3 border <?= $borderClass ?> h-100">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold <?= $circleClass ?> mb-1"
                 style="width: 32px; height: 32px; font-size: .85rem;">
              <?= $circleContent ?>
            </div>
            <div class="small fw-semibold <?= !$isCompleted && !$isCurrent ? 'text-muted' : '' ?>" style="line-height:1.2">
              <?= htmlspecialchars($stage['label']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
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
                              <a href="/reimbursement/download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Download">
                                <i class="bi bi-download"></i>
                              </a>
                              <?php if (has_permission('delete_reimbursement_invoice_attachment') && $request['created_by'] == $_SESSION['user_id']): ?>
                                <form method="POST" action="/reimbursement/delete_attachment.php" style="display: inline;" onclick="return confirm('Are you sure you want to delete this attachment?');">
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
          
          <a href="/reimbursement/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to List
          </a>
        </div>
      </div>
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
