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
if ($disbursement) {
    $reconcileStmt->execute([$disbursement['disburse_id']]);
    $reconciliation = $reconcileStmt->fetch(PDO::FETCH_ASSOC);
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

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

// Calculate deadline status
$deadlineStatus = null;
if ($disbursement) {
    $now = new DateTime();
    $deadline = new DateTime($disbursement['disbursement_deadline']);
    $interval = $now->diff($deadline);
    $deadlineStatus = [
        'is_overdue' => $now > $deadline,
        'deadline' => $deadline,
        'time_remaining' => $interval
    ];
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
          <div class="list-group list-group-flush">
           <?php
           // Get pipeline stages from centralized workflow config
           $pipelineStages = getPettyCashPipeline();
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
               $stageNum = $idx + 1;
           ?>
           <div class="list-group-item d-flex justify-content-between align-items-center">
             <span><?= $stageNum ?>. <?= htmlspecialchars($stage['label']) ?></span>
             <i class="bi <?= $isCompleted ? 'bi-check-circle-fill text-success' : ($isCurrent ? 'bi-arrow-right text-primary' : 'bi-circle text-muted') ?>"></i>
           </div>
           <?php endforeach; ?>
         </div>
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
          
          <a href="/petty_cash/list.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to List
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>

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
