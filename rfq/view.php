<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';


$rfq_id = (int)($_GET['id'] ?? 0);

if ($rfq_id <= 0) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM rfq_votes
    WHERE rfq_id = ? AND user_id = ?
");
$stmt->execute([$rfq_id, $_SESSION['user_id']]);
$hasVoted = $stmt->fetchColumn() > 0;


/* Fetch RFQ - UPDATED: Include estimated_value and created_by */
$stmt = $pdo->prepare("
    SELECT r.*, pr.request_number, pr.estimated_value, pr.status as request_status, pr.created_by
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// UPDATED: Determine if under-threshold (skip committee evaluation)
$estimatedValue = (float)($rfq['estimated_value'] ?? 0);
$isUnderThreshold = $estimatedValue <= getDirectProcurementThreshold($pdo);

/* Fetch Vendors */
$stmt = $pdo->prepare("
    SELECT 
        rv.*, 
        v.vendor_name,
        v.email
    FROM rfq_vendors rv
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND rv.is_deleted = 0
");

$stmt->execute([$rfq_id]);
$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch Quotes */
$quoteStmt = $pdo->prepare("
    SELECT 
        q.quote_id,
        q.quote_amount,
        q.gct_amount,
        q.quote_file,
        q.is_selected,
        q.review_status,
        q.review_comments,
        rv.rfq_vendor_id,
        v.vendor_name
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND q.is_deleted = 0 AND rv.is_deleted = 0
    ORDER BY q.quote_amount ASC
");

$quoteStmt->execute([$rfq_id]);
$quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);



$isAwarded = ($rfq['status'] === 'AWARDED');

/* Total Committee Members */
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM rfq_evaluation_committee 
    WHERE rfq_id = ?
");
$stmt->execute([$rfq_id]);
$committeeCount = (int)$stmt->fetchColumn();

/* Total Votes Submitted */
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id)
    FROM rfq_votes
    WHERE rfq_id = ?
");
$stmt->execute([$rfq_id]);
$votesSubmitted = (int)$stmt->fetchColumn();

/* Calculate Percentage */
$votePercentage = 0;
if ($committeeCount > 0) {
    $votePercentage = round(($votesSubmitted / $committeeCount) * 100);
}

/* Fetch Committee Members */
$stmt = $pdo->prepare("
    SELECT u.user_id, u.full_name
    FROM rfq_evaluation_committee ec
    JOIN users u ON ec.user_id = u.user_id
    WHERE ec.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$committeeMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isCommitteeMember = false;
foreach ($committeeMembers as $member) {
    if ($member['user_id'] == $_SESSION['user_id']) {
        $isCommitteeMember = true;
        break;
    }
}

/* Get user's actual role from database (more reliable than session) */
$stmt = $pdo->prepare("
    SELECT r.name FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$userRoleName = $stmt->fetchColumn() ?: $_SESSION['role_name'] ?? 'Unknown';

/* Check if current user is the request creator (submitter) */
$isRequestCreator = (int)($rfq['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);

/* Pre-compute award eligibility (needed in header buttons and quotes card) */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM rfq_evaluation_reports WHERE rfq_id = ?");
$stmt->execute([$rfq_id]);
$reportCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT rfq_vendor_id, COUNT(*) as votes
    FROM rfq_votes WHERE rfq_id = ?
    GROUP BY rfq_vendor_id ORDER BY votes DESC LIMIT 1
");
$stmt->execute([$rfq_id]);
$topVote = $stmt->fetch(PDO::FETCH_ASSOC);

$majorityMet = false;
if ($topVote && $topVote['votes'] > ($committeeCount / 2)) {
    $majorityMet = true;
}

$canAward = ($committeeCount >= 3 && $reportCount > 0 && $majorityMet);

?>

<!-- Page Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <div>
        <a href="list.php" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Back to RFQ List
        </a>
        <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
            <i class="bi bi-file-earmark-text"></i> RFQ: <?= htmlspecialchars($rfq['rfq_number']) ?>
        </h4>
        <p class="text-muted mb-0 small">Request: <?= htmlspecialchars($rfq['request_number']) ?></p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="/procurement/view.php?id=<?= $rfq['request_id'] ?>"
           class="btn btn-outline-secondary rounded-pill btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i>View Request
        </a>
        <?php if (!$isAwarded): ?>
        <a href="add_vendor.php?rfq_id=<?= $rfq_id ?>"
           class="btn btn-outline-success rounded-pill btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Vendor
        </a>
        <a href="send_rfq_emails.php?rfq_id=<?= $rfq_id ?>"
           class="btn btn-outline-info rounded-pill btn-sm">
            <i class="bi bi-send me-1"></i>Send RFQ Emails
        </a>
        <?php endif; ?>
        <?php if (in_array($rfq['request_status'], ['RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE']) && ($isRequestCreator || in_array($userRoleName, ['HOD', 'Branch Head', 'Procurement Officer']))): ?>
            <?php if ($isUnderThreshold): ?>
                <!-- Under-threshold: Move to quote review (skip committee) -->
                <a href="/rfq/start_evaluation.php?id=<?= $rfq['rfq_id'] ?>"
                   class="btn text-white rounded-pill btn-sm" style="background:#28a745;"
                   onclick="return confirm('Move this RFQ to quote review stage?')">
                    <i class="bi bi-chat-dots me-1"></i>Move to Quote Review
                </a>
            <?php else: ?>
                <!-- Over-threshold: Start committee evaluation -->
                <a href="/rfq/start_evaluation.php?id=<?= $rfq['rfq_id'] ?>"
                   class="btn text-white rounded-pill btn-sm" style="background:#1a1a2e;"
                   onclick="return confirm('Start committee evaluation for this RFQ?')">
                    <i class="bi bi-bar-chart me-1"></i>Start Evaluation
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        // Over-threshold: After committee evaluation completes, Procurement Officer submits for GC Approval (SOP Step 10)
        if (!$isUnderThreshold
            && in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'])
            && in_array($userRoleName, ['Procurement Officer'])
            && $canAward
            && !$isAwarded
        ): ?>
            <a href="/rfq/advance_evaluation.php?id=<?= $rfq['rfq_id'] ?>"
               class="btn text-white rounded-pill btn-sm" style="background:#0b5e2b;"
               onclick="return confirm('Committee evaluation is complete. Submit for GC Approval (SOP Step 10)?')">
                <i class="bi bi-shield-check me-1"></i>Submit for GC Approval
            </a>
        <?php endif; ?>
        <?php
        // Over-threshold: Deputy GC approves at COMMITTEE_RECOMMENDED stage (SOP Step 10)
        if (!$isUnderThreshold
            && $rfq['request_status'] === 'COMMITTEE_RECOMMENDED'
            && $userRoleName === 'Deputy Government Chemist'
            && !$isAwarded
        ): ?>
            <a href="/rfq/gc_approve.php?id=<?= $rfq['rfq_id'] ?>"
               class="btn text-white rounded-pill btn-sm" style="background:#1a1a2e;">
                <i class="bi bi-shield-check me-1"></i>GC Approve (SOP 10)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<?php
    $statusMap = [
        'OPEN'       => ['bg' => '#0d6efd', 'icon' => 'bi-envelope-open'],
        'PUBLISHED'  => ['bg' => '#0dcaf0', 'icon' => 'bi-send'],
        'EVALUATION' => ['bg' => '#ffc107', 'icon' => 'bi-clipboard-check'],
        'AWARDED'    => ['bg' => '#198754', 'icon' => 'bi-trophy'],
        'CANCELLED'  => ['bg' => '#dc3545', 'icon' => 'bi-x-circle'],
        'CLOSED'     => ['bg' => '#6c757d', 'icon' => 'bi-lock'],
    ];
    $sm = $statusMap[$rfq['status']] ?? ['bg' => '#6c757d', 'icon' => 'bi-question-circle'];

    $acceptMap = [
        'ACCEPTED' => ['bg' => 'bg-success', 'icon' => 'bi-check-circle', 'color' => '#198754'],
        'DECLINED' => ['bg' => 'bg-danger',  'icon' => 'bi-x-circle', 'color' => '#dc3545'],
        'PENDING'  => ['bg' => 'bg-warning text-dark', 'icon' => 'bi-hourglass-split', 'color' => '#c9a227'],
    ];
    $am = $acceptMap[$rfq['acceptance_status'] ?? 'PENDING'] ?? $acceptMap['PENDING'];
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid <?= $sm['bg'] ?> !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">Status</div>
                <span class="badge rounded-pill text-white px-3 py-2" style="background:<?= $sm['bg'] ?>;">
                    <i class="bi <?= $sm['icon'] ?> me-1"></i><?= htmlspecialchars($rfq['status']) ?>
                </span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #0dcaf0 !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">RFQ Date</div>
                <div class="fw-bold"><?= !empty($rfq['rfq_date']) && $rfq['rfq_date'] !== '0000-00-00' ? date('d M Y', strtotime($rfq['rfq_date'])) : '—' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #ffc107 !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">Deadline</div>
                <div class="fw-bold <?= (!empty($rfq['submission_deadline']) && $rfq['submission_deadline'] !== '0000-00-00 00:00:00' && strtotime($rfq['submission_deadline']) < time()) ? 'text-danger' : 'text-success' ?>">
                    <?= !empty($rfq['submission_deadline']) && $rfq['submission_deadline'] !== '0000-00-00 00:00:00' ? date('d M Y, g:i A', strtotime($rfq['submission_deadline'])) : '—' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid <?= $am['color'] ?> !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">Acceptance</div>
                <span class="badge <?= $am['bg'] ?> rounded-pill px-3 py-2">
                    <i class="bi <?= $am['icon'] ?> me-1"></i><?= htmlspecialchars($rfq['acceptance_status'] ?? 'PENDING') ?>
                </span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #0b5e2b !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">Vendors</div>
                <div class="fs-3 fw-bold" style="color:#0b5e2b;"><?= count($vendors) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #c9a227 !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">Quotes</div>
                <div class="fs-3 fw-bold" style="color:#c9a227;"><?= count($quotes) ?></div>
            </div>
        </div>
    </div>
    <?php if (!empty($rfq['rfq_letter_file'])): ?>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #0d6efd !important;">
            <div class="card-body py-3 text-center">
                <div class="text-muted small mb-1">RFQ Letter</div>
                <a href="<?= htmlspecialchars($rfq['rfq_letter_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">
                    <i class="bi bi-file-earmark-pdf me-1"></i>View Letter
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- RFQ Letter Upload (for Procurement Officer if no letter uploaded yet) -->
<?php if (empty($rfq['rfq_letter_file']) && in_array($userRoleName, ['Procurement Officer', 'Admin', 'SuperAdmin']) && !$isAwarded): ?>
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-upload me-1"></i> Upload RFQ Letter</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="/rfq/upload_rfq_letter.php" enctype="multipart/form-data">
            <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <input type="file" name="rfq_letter" class="form-control" accept=".pdf,.doc,.docx" required>
                    <small class="text-muted">Upload formal RFQ letter (PDF/Word, max 50 MB)</small>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-cloud-upload me-1"></i>Upload
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- LEFT COLUMN -->
    <div class="col-lg-8">

        <!-- Vendors Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="bi bi-building me-1"></i> Vendors</h6>
                <span class="badge rounded-pill" style="background:#0b5e2b;"><?= count($vendors) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                     <thead class="table-dark">
                            <tr >
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vendors as $vendor): ?>
                            <?php
                            $status = $vendor['response_status'] ?? 'PENDING';
                            $vBadge = match($status) {
                                'SUBMITTED' => 'bg-success',
                                'DECLINED'  => 'bg-danger',
                                'SELECTED'  => 'bg-primary',
                                default     => 'bg-warning text-dark'
                            };
                            ?>
                            <tr>
                                <td class="ps-4 fw-semibold"><?= htmlspecialchars($vendor['vendor_name']) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($vendor['email'] ?? '') ?></td>
                                <td>
                                    <span class="badge <?= $vBadge ?> rounded-pill">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                                        <?php if (!$isAwarded): ?>
                                        <a href="upload_quote.php?vendor_id=<?= $vendor['rfq_vendor_id'] ?>"
                                           class="btn btn-sm btn-outline-success rounded-pill">
                                            <i class="bi bi-cloud-arrow-up me-1"></i>Upload Quote
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($userRoleName === 'Procurement Officer'): ?>
                                        <a href="/rfq/generate_rtf.php?id=<?= $rfq_id ?>&vendor_id=<?= $vendor['rfq_vendor_id'] ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-primary rounded-pill"
                                           title="Download RFQ Letter for <?= htmlspecialchars($vendor['vendor_name']) ?>">
                                            <i class="bi bi-file-earmark-text me-1"></i>RFQ Letter
                                        </a>
                                        <?php endif; ?>
                                        <?php if (!$isAwarded && has_permission('procurement_delete_vendor')): ?>
                                        <a href="/rfq/delete_vendor.php?rfq_id=<?= $rfq_id ?>&rfq_vendor_id=<?= $vendor['rfq_vendor_id'] ?>"
                                           class="btn btn-sm btn-outline-danger rounded-pill"
                                          title="Delete <?= htmlspecialchars($vendor['vendor_name']) ?> from RFQ"
                                          onclick="return confirm('Delete <?= htmlspecialchars($vendor['vendor_name'], ENT_QUOTES) ?> and all associated quotes? This vendor and quotes will be hidden from view.');">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($isAwarded && !($userRoleName === 'Procurement Officer')): ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($vendors)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-1 opacity-25"></i>
                                    No vendors assigned yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quotes Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> Quotes Received</h6>
                <span class="badge rounded-pill" style="background:#c9a227;"><?= count($quotes) ?></span>
            </div>
            <div class="card-body p-0">

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                       <thead class="table-dark">
                            <tr >
                                <th >Vendor</th>
                                <th>Amount</th>
                                <th>GCT</th>
                                <th>Review</th>
                                <th >File</th>
                                <th >Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($quotes as $quote): ?>
                            <tr>
                                <td class="ps-4 fw-semibold"><?= htmlspecialchars($quote['vendor_name'] ?? '') ?></td>
                                <td class="text-end">
                                    <span class="fw-semibold" style="color:#0b5e2b;">$<?= number_format($quote['quote_amount'], 2) ?></span>
                                </td>
                                <td class="text-end text-muted">$<?= number_format((float)($quote['gct_amount'] ?? 0), 2) ?></td>
                                <td class="text-center">
                                    <?php
                                    $reviewStatus = $quote['review_status'] ?? 'PENDING';
                                    $reviewBadge = match($reviewStatus) {
                                        'MEETS_REQUIREMENTS' => ['bg' => 'bg-success', 'icon' => 'bi-check-circle', 'text' => 'Approved'],
                                        'DOES_NOT_MEET' => ['bg' => 'bg-danger', 'icon' => 'bi-x-circle', 'text' => 'Rejected'],
                                        default => ['bg' => 'bg-warning text-dark', 'icon' => 'bi-hourglass-split', 'text' => 'Pending'],
                                    };
                                    ?>
                                    <span class="badge <?= $reviewBadge['bg'] ?> rounded-pill">
                                        <i class="bi <?= $reviewBadge['icon'] ?>"></i> <?= htmlspecialchars($reviewBadge['text']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($quote['quote_file'])): ?>
                                    <a href="/uploads/quotes/<?= htmlspecialchars($quote['quote_file']) ?>"
                                       target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">
                                        <i class="bi bi-file-earmark-pdf me-1"></i>View
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                                        <!-- Quote review is only available before a quote is selected. -->
                                        <?php if ($rfq['request_status'] === 'QUOTE_REVIEW_PENDING' && ($isRequestCreator || in_array($userRoleName, ['HOD', 'Branch Head', 'Procurement Officer']))): ?>
                                        <a href="/rfq/review_quote.php?quote_id=<?= $quote['quote_id'] ?>&rfq_id=<?= $rfq_id ?>"
                                           class="btn btn-sm btn-outline-dark rounded-pill">
                                            <i class="bi bi-chat-dots me-1"></i>Review
                                        </a>
                                        <?php elseif (
                                            ($isRequestCreator || in_array($userRoleName, ['HOD', 'Branch Head', 'Procurement Officer']))
                                            && $rfq['request_status'] !== 'QUOTE_REVIEW_PENDING'
                                            && !empty($quote['quote_file'])
                                            /* Don't show 'Not Yet' for award-eligible roles on over-threshold RFQs in evaluation/award stages */
                                            && !(!$isUnderThreshold && in_array($userRoleName, ['HOD', 'Branch Head', 'Deputy Government Chemist', 'Director HRM&A']) && in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'GC_APPROVED']))
                                        ): ?>
                                        <!-- Show disabled button with tooltip when not in review stage -->
                                        <button class="btn btn-sm btn-outline-secondary rounded-pill" disabled title="RFQ must be in quote review stage. Procurement Officer: Click 'Move to Quote Review' button above.">
                                            <i class="bi bi-lock me-1"></i>Not Yet
                                        </button>
                                        <?php endif; ?>

                                        <?php if ($rfq['request_status'] === 'QUOTE_REQUESTOR_REVIEW_PENDING' && $isRequestCreator): ?>
                                            <a href="/rfq/requestor_spec_review.php?id=<?= $rfq_id ?>"
                                               class="btn btn-sm btn-outline-primary rounded-pill">
                                                <i class="bi bi-person-check me-1"></i>Confirm Specifications
                                            </a>
                                        <?php elseif ($rfq['request_status'] === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
                                            && in_array($userRoleName, ['Branch Head', 'HOD', 'Director HRM&A'])): ?>
                                            <a href="/rfq/branch_head_approve.php?id=<?= $rfq_id ?>"
                                               class="btn btn-sm btn-outline-primary rounded-pill">
                                                <i class="bi bi-shield-check me-1"></i>Branch Head Approval
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- SELECT QUOTE BUTTON: Branch Head/HOD at QUOTE_REVIEW_PENDING -->
                                        <?php if ($rfq['request_status'] === 'QUOTE_REVIEW_PENDING' && in_array($userRoleName, ['Branch Head', 'HOD'])): ?>
                                            <?php if ($quote['is_selected']): ?>
                                            <button class="btn btn-sm btn-success rounded-pill" disabled>
                                                <i class="bi bi-check-circle me-1"></i>Selected
                                            </button>
                                            <?php elseif (($quote['review_status'] ?? 'PENDING') === 'DOES_NOT_MEET'): ?>
                                            <button class="btn btn-sm btn-outline-secondary rounded-pill" disabled title="Quote marked as not meeting requirements">
                                                <i class="bi bi-lock me-1"></i>N/A
                                            </button>
                                            <?php else: ?>
                                            <a href="/rfq/select_quote.php?quote_id=<?= $quote['quote_id'] ?>&rfq_id=<?= $rfq_id ?>"
                                               class="btn btn-sm btn-success rounded-pill"
                                               onclick="return confirm('Select this quote and proceed to commitment creation?')">
                                                <i class="bi bi-hand-thumbs-up me-1"></i>Select
                                            </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- AWARD BUTTON: Deputy GC, HOD, Director HRM&A, or Branch Head for OVER-THRESHOLD RFQs after evaluation complete -->
                                        <?php if (in_array($userRoleName, ['Deputy Government Chemist', 'HOD', 'Director HRM&A', 'Branch Head']) && !$isUnderThreshold && !$isAwarded): ?>
                                            <?php if ($canAward && in_array($rfq['request_status'], ['GC_APPROVED', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'])): ?>
                                            <a href="/rfq/award.php?rfq_id=<?= $rfq_id ?>&quote_id=<?= $quote['quote_id'] ?>"
                                               class="btn btn-sm btn-warning rounded-pill"
                                               onclick="return confirm('Award this vendor for the over-threshold RFQ?')">
                                                <i class="bi bi-trophy me-1"></i>Award
                                            </a>
                                            <?php elseif (!$canAward && in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'])): ?>
                                            <button class="btn btn-sm btn-outline-secondary rounded-pill" disabled title="Evaluation must be complete: 3+ committee members, majority vote, and evaluation report required">
                                                <i class="bi bi-hourglass-split me-1"></i>Evaluation Pending
                                            </button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($isCommitteeMember && !$hasVoted && $votePercentage < 100): ?>
                                        <a href="vote.php?rfq_id=<?= $rfq_id ?>&rfq_vendor_id=<?= $quote['rfq_vendor_id'] ?>"
                                           class="btn btn-sm btn-outline-dark rounded-pill">
                                            <i class="bi bi-check2-square me-1"></i>Vote
                                        </a>
                                        <?php endif; ?>

                                        <?php if (!$isAwarded && has_permission('procurement_delete_quote')): ?>
                                        <a href="/rfq/delete_quote.php?rfq_id=<?= $rfq_id ?>&quote_id=<?= $quote['quote_id'] ?>"
                                          class="btn btn-sm btn-outline-danger rounded-pill"
                                          title="Delete this quote"
                                          onclick="return confirm('Delete this quote? The quote will be hidden from view.');">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($quotes)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-3 d-block mb-1 opacity-25"></i>
                                    No quotes received yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($isAwarded || $userRoleName === 'Procurement Officer'): ?>
            <div class="card-footer bg-white border-0 rounded-bottom-4 py-3 d-flex flex-wrap gap-2">
                <a href="/rfq/generate_rtf.php?id=<?= $rfq_id ?>"
                   target="_blank"
                   class="btn btn-outline-primary rounded-pill btn-sm">
                    <i class="bi bi-file-earmark-text me-1"></i>Download All RFQ Letters
                </a>
                <?php if ($isAwarded): ?>
                <a href="/rfq/generate_loa.php?id=<?= $rfq_id ?>"
                   class="btn btn-outline-success rounded-pill btn-sm">
                    <i class="bi bi-award me-1"></i>Download Letter of Award
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quote Review Summary Card -->
        <?php
        // Check if there are any reviewed quotes
        $stmt = $pdo->prepare("
            SELECT q.review_status, q.review_comments, v.vendor_name
            FROM rfq_quotes q
            JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
            JOIN vendors v ON rv.vendor_id = v.vendor_id
            WHERE rv.rfq_id = ? AND q.review_status IS NOT NULL AND q.review_status != 'PENDING'
            ORDER BY q.quote_amount ASC
        ");
        $stmt->execute([$rfq_id]);
        $reviewedQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($reviewedQuotes)): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-chat-left-quote me-1"></i> Review Comments</h6>
            </div>
            <div class="card-body pt-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($reviewedQuotes as $rq): ?>
                    <div class="list-group-item d-flex gap-3 px-0 py-3 border-bottom-0">
                        <div style="flex-shrink:0;">
                            <?php
                            $icon = ($rq['review_status'] === 'MEETS_REQUIREMENTS') ? 'bi-check-circle' : 'bi-x-circle';
                            $color = ($rq['review_status'] === 'MEETS_REQUIREMENTS') ? '#0b5e2b' : '#dc3545';
                            ?>
                            <i class="bi <?= $icon ?>" style="font-size:1.5rem;color:<?= $color ?>;"></i>
                        </div>
                        <div style="flex-grow:1;">
                            <div class="fw-semibold"><?= htmlspecialchars($rq['vendor_name']) ?></div>
                            <div class="small text-muted mb-2">
                                <?= ($rq['review_status'] === 'MEETS_REQUIREMENTS') ? 'Approved' : 'Rejected' ?>
                            </div>
                            <?php if (!empty($rq['review_comments'])): ?>
                            <div class="small" style="background:#f8f9fa;padding:8px 10px;border-radius:6px;border-left:3px solid <?= $color ?>;">
                                <?= htmlspecialchars($rq['review_comments']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isAwarded): ?>
        <div class="alert alert-success border-0 rounded-3 d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div>
                <strong>RFQ Awarded</strong> — This RFQ has been awarded and is now locked. No further edits are allowed.
            </div>
        </div>
        <?php endif; ?>

        <!-- Workflow Status Indicator -->
        <?php
        $stageMap = [
            'PROCUREMENT_STAGE'      => ['icon' => 'bi-cart-check',       'color' => '#6f42c1', 'label' => 'Procurement'],
            'RFQ_LETTER_AVAILABLE'   => ['icon' => 'bi-envelope-paper',   'color' => '#0d6efd', 'label' => 'RFQ Letter Available'],
            'QUOTE_REVIEW_PENDING'   => ['icon' => 'bi-chat-dots',        'color' => '#fd7e14', 'label' => 'Quote Review'],
            'QUOTE_REQUESTOR_REVIEW_PENDING' => ['icon' => 'bi-person-check', 'color' => '#e67e22', 'label' => 'Pending Requestor Review'],
            'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['icon' => 'bi-check2-circle', 'color' => '#20c997', 'label' => 'Requestor Review Approved'],
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['icon' => 'bi-shield-check', 'color' => '#0dcaf0', 'label' => 'Pending Branch Head Approval'],
            'EVALUATION'             => ['icon' => 'bi-clipboard-check',  'color' => '#ffc107', 'label' => 'Evaluation'],
            'EVALUATION_STAGE'       => ['icon' => 'bi-clipboard-check',  'color' => '#ffc107', 'label' => 'Committee Evaluation'],
            'COMMITTEE_RECOMMENDED'  => ['icon' => 'bi-hand-thumbs-up',   'color' => '#198754', 'label' => 'Committee Recommended'],
            'GC_APPROVED'            => ['icon' => 'bi-shield-check',     'color' => '#0b5e2b', 'label' => 'GC Approved'],
            'AWARDED'                => ['icon' => 'bi-trophy-fill',      'color' => '#198754', 'label' => 'Awarded'],
            'COMMITMENT_CREATED'     => ['icon' => 'bi-journal-check',    'color' => '#20c997', 'label' => 'Commitment Created'],
        ];
        $currentStage = $rfq['request_status'];
        $stageInfo = $stageMap[$currentStage] ?? ['icon' => 'bi-arrow-right-circle', 'color' => '#6c757d', 'label' => $currentStage];
        $thresholdColor = $isUnderThreshold ? '#20c997' : '#e63946';
        $thresholdIcon  = $isUnderThreshold ? 'bi-arrow-down-circle-fill' : 'bi-arrow-up-circle-fill';
        ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="overflow:hidden;">
            <div class="row g-0">
                <!-- Workflow Stage -->
                <div class="col-6 col-lg-3 border-end" style="border-color:#eee !important;">
                    <div class="px-3 py-3 text-center h-100 d-flex flex-column justify-content-center">
                        <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.65rem;letter-spacing:.05em;">Workflow Stage</div>
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:30px;height:30px;background:<?= $stageInfo['color'] ?>15;flex-shrink:0;">
                                <i class="bi <?= $stageInfo['icon'] ?>" style="color:<?= $stageInfo['color'] ?>;font-size:.85rem;"></i>
                            </div>
                            <span class="fw-bold small" style="color:<?= $stageInfo['color'] ?>;"><?= htmlspecialchars($stageInfo['label']) ?></span>
                        </div>
                    </div>
                </div>
                <!-- RFQ Status -->
                <div class="col-6 col-lg-3 border-end" style="border-color:#eee !important;">
                    <div class="px-3 py-3 text-center h-100 d-flex flex-column justify-content-center">
                        <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.65rem;letter-spacing:.05em;">RFQ Status</div>
                        <span class="badge rounded-pill text-white px-3 py-1 mx-auto" style="background:<?= $sm['bg'] ?>;font-size:.75rem;">
                            <i class="bi <?= $sm['icon'] ?> me-1"></i><?= htmlspecialchars($rfq['status'] ?: '—') ?>
                        </span>
                    </div>
                </div>
                <!-- Your Role -->
                <div class="col-6 col-lg-3 border-end" style="border-color:#eee !important;">
                    <div class="px-3 py-3 text-center h-100 d-flex flex-column justify-content-center">
                        <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.65rem;letter-spacing:.05em;">Your Role</div>
                        <div class="d-flex align-items-center justify-content-center gap-1">
                            <i class="bi bi-person-badge" style="color:#1a1a2e;font-size:.9rem;"></i>
                            <span class="fw-semibold small" style="color:#1a1a2e;"><?= htmlspecialchars($userRoleName) ?></span>
                        </div>
                    </div>
                </div>
                <!-- Threshold -->
                <div class="col-6 col-lg-3">
                    <div class="px-3 py-3 text-center h-100 d-flex flex-column justify-content-center">
                        <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:.65rem;letter-spacing:.05em;">Threshold</div>
                        <div class="d-flex align-items-center justify-content-center gap-1">
                            <i class="bi <?= $thresholdIcon ?>" style="color:<?= $thresholdColor ?>;font-size:.9rem;"></i>
                            <span class="fw-bold small" style="color:<?= $thresholdColor ?>;"><?= $isUnderThreshold ? 'Under' : 'Over' ?></span>
                        </div>
                        <div class="text-muted" style="font-size:.7rem;">$<?= number_format($estimatedValue, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quote Review Stage Alert -->
        <?php if ($rfq['request_status'] === 'QUOTE_REVIEW_PENDING' && ($isRequestCreator || in_array($userRoleName, ['HOD', 'Branch Head', 'Procurement Officer']))): ?>
        <div class="alert alert-info border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-lightbulb-fill fs-5 flex-shrink-0 mt-1" style="color:#0066cc;"></i>
            <div>
                <strong>Review Quotes Now</strong> — Vendors have submitted their quotes. Review each quote to determine whether it meets the request requirements. After review, the Branch Head/HOD should select the preferred quote to trigger requestor specification confirmation.
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Not Yet in Quote Review Stage -->
        <?php if (in_array($rfq['request_status'], ['RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE']) && ($isRequestCreator || in_array($userRoleName, ['HOD', 'Branch Head', 'Procurement Officer']))): ?>
        <div class="alert alert-warning border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" style="color:#ff9800;"></i>
            <div>
                <strong>Action Required</strong> — Click the <strong><?= $isUnderThreshold ? 'Move to Quote Review' : 'Start Evaluation' ?></strong> button above to begin the quote review process. Quotes have been received and are ready for assessment.
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Branch Head Quote Selection Alert -->
        <?php if ($rfq['request_status'] === 'QUOTE_REVIEW_PENDING' && in_array($userRoleName, ['Branch Head', 'HOD'])): ?>
        <div class="alert alert-success border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-star-fill fs-5 flex-shrink-0 mt-1" style="color:#198754;"></i>
            <div>
                <strong>Approve &amp; Select Best Quote</strong> — As Branch Head, review the quotes and quote recommendations, then select the one that offers the best value. Click <strong>Select</strong> to route the preferred quote to the original requestor for specification confirmation.
            </div>
        </div>
        <?php endif; ?>

        <!-- Procurement Options After Quote Upload -->
        <?php if ($rfq['request_status'] === 'QUOTE_REVIEW_PENDING' && in_array($userRoleName, ['Procurement Officer', 'Admin', 'SuperAdmin'])): ?>
        <div class="alert alert-primary border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-signpost-split fs-5 flex-shrink-0 mt-1"></i>
            <div>
                <strong>Procurement Reminder</strong> — The direct funds-verification shortcut has been retired. Procurement must support quote review, then wait for the Branch Head/HOD to select a quote, the original requestor to confirm specifications, and the Branch Head to record final approval.
            </div>
        </div>
        <?php endif; ?>

        <!-- Under-Threshold RFQ Workflow Alert (Deputy GC / HOD / Director HRM&A / Branch Head) -->
        <?php if ($isUnderThreshold && in_array($userRoleName, ['Deputy Government Chemist', 'HOD', 'Director HRM&A', 'Branch Head']) && !$isAwarded): ?>
        <div class="alert alert-info border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(13, 202, 240, 0.1) 0%, rgba(31, 194, 111, 0.1) 100%); border-left: 4px solid #0dcaf0;">
            <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1" style="color:#0dcaf0;"></i>
            <div>
                <strong>Under-Threshold RFQ Workflow</strong> — This is an under-threshold RFQ (Est. value: $<?= number_format($estimatedValue, 2) ?>). The Branch Head will select the best quote directly without committee evaluation. No award action is needed for this RFQ.
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold: Committee Evaluation Complete Alert (Procurement Officer) -->
        <?php if (!$isUnderThreshold && in_array($userRoleName, ['Procurement Officer']) && in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED']) && $canAward && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(11, 94, 43, 0.08) 0%, rgba(25, 135, 84, 0.08) 100%); border-left: 4px solid #0b5e2b;">
            <i class="bi bi-check-circle-fill fs-5 flex-shrink-0 mt-1" style="color:#0b5e2b;"></i>
            <div>
                <strong>Committee Evaluation Complete</strong> — All conditions met: <?= $committeeCount ?> committee members, majority vote reached, and evaluation report submitted. Click <strong>Submit for GC Approval</strong> above to send to Deputy Government Chemist for final approval (SOP Step 10).
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold: Pending GC Approval (Procurement Officer) -->
        <?php if (!$isUnderThreshold && $userRoleName === 'Procurement Officer' && $rfq['request_status'] === 'COMMITTEE_RECOMMENDED' && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(26, 26, 46, 0.06) 0%, rgba(11, 94, 43, 0.06) 100%); border-left: 4px solid #1a1a2e;">
            <i class="bi bi-hourglass-split fs-5 flex-shrink-0 mt-1" style="color:#1a1a2e;"></i>
            <div>
                <strong>Awaiting GC Approval</strong> — Committee evaluation has been submitted. Waiting for Deputy Government Chemist to review and approve (SOP Step 10) before award can proceed.
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold: GC Approved — Ready to Award -->
        <?php if (!$isUnderThreshold && in_array($userRoleName, ['Procurement Officer']) && $rfq['request_status'] === 'GC_APPROVED' && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(25, 135, 84, 0.08) 0%, rgba(11, 94, 43, 0.12) 100%); border-left: 4px solid #198754;">
            <i class="bi bi-shield-fill-check fs-5 flex-shrink-0 mt-1" style="color:#198754;"></i>
            <div>
                <strong>GC Approved</strong> — Deputy Government Chemist has approved this over-threshold RFQ (SOP Step 10 complete). The Deputy GC, HOD, or Branch Head can now award the RFQ.
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold RFQ: GC Approved — Ready to Award (Deputy GC / HOD / Director HRM&A / Branch Head) -->
        <?php if (!$isUnderThreshold && in_array($userRoleName, ['Deputy Government Chemist', 'HOD', 'Director HRM&A', 'Branch Head']) && in_array($rfq['request_status'], ['GC_APPROVED', 'PROCUREMENT_STAGE']) && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(25, 135, 84, 0.08) 0%, rgba(11, 94, 43, 0.08) 100%); border-left: 4px solid #198754;">
            <i class="bi bi-trophy-fill fs-5 flex-shrink-0 mt-1" style="color:#198754;"></i>
            <div>
                <strong>GC Approved — Ready to Award</strong> — GC approval granted (SOP Step 10 complete). You can now award this RFQ by clicking the <strong>Award</strong> button next to your chosen quote above.
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold RFQ: Pending GC Approval (Deputy GC) -->
        <?php if (!$isUnderThreshold && $userRoleName === 'Deputy Government Chemist' && $rfq['request_status'] === 'COMMITTEE_RECOMMENDED' && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(26, 26, 46, 0.06) 0%, rgba(11, 94, 43, 0.08) 100%); border-left: 4px solid #1a1a2e;">
            <i class="bi bi-shield-check fs-5 flex-shrink-0 mt-1" style="color:#1a1a2e;"></i>
            <div>
                <strong>GC Approval Required (SOP Step 10)</strong> — Committee evaluation is complete. <?= $committeeCount ?> members participated, majority vote reached, and evaluation report submitted. Click <strong>GC Approve (SOP 10)</strong> above to approve this RFQ for award.
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold: Awaiting Committee Evaluation (Deputy GC / HOD / Director HRM&A / Branch Head) -->
        <?php if (!$isUnderThreshold && in_array($userRoleName, ['Deputy Government Chemist', 'HOD', 'Director HRM&A', 'Branch Head']) && !$isAwarded): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-left: 4px solid #1a1a2e !important;">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-trophy me-1"></i> Award Readiness Checklist</h6>
            </div>
            <div class="card-body pt-0">
                <p class="text-muted small mb-3">All items must be complete before the Award button appears on each quote.</p>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Request in evaluation/award stage</span>
                        <?php if (in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'GC_APPROVED'])): ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($rfq['request_status']) ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($rfq['request_status']) ?></span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Committee members (minimum 3)</span>
                        <?php if ($committeeCount >= 3): ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-check-circle me-1"></i><?= $committeeCount ?> assigned</span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill"><i class="bi bi-x-circle me-1"></i><?= $committeeCount ?>/3</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Evaluation report uploaded</span>
                        <?php if ($reportCount > 0): ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-check-circle me-1"></i><?= $reportCount ?> uploaded</span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill"><i class="bi bi-x-circle me-1"></i>Not yet</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Majority vote reached (&gt;50% of committee)</span>
                        <?php if ($majorityMet): ?>
                            <span class="badge bg-success rounded-pill"><i class="bi bi-check-circle me-1"></i><?= $topVote['votes'] ?? 0 ?>/<?= $committeeCount ?> votes</span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill"><i class="bi bi-x-circle me-1"></i><?= $votesSubmitted ?>/<?= $committeeCount ?> voted</span>
                        <?php endif; ?>
                    </li>
                </ul>
                <?php if ($canAward && in_array($rfq['request_status'], ['GC_APPROVED', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'])): ?>
                <div class="alert alert-success border-0 rounded-3 py-2 mt-3 mb-0 small">
                    <i class="bi bi-trophy-fill me-1"></i> <strong>Ready to Award!</strong> Click the Award button next to your chosen quote above.
                </div>
                <?php elseif ($canAward && !in_array($rfq['request_status'], ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'GC_APPROVED'])): ?>
                <div class="alert alert-warning border-0 rounded-3 py-2 mt-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle me-1"></i> Evaluation criteria met, but request status is <strong><?= htmlspecialchars($rfq['request_status']) ?></strong>. Procurement Officer must click <strong>Start Evaluation</strong> to advance the workflow.
                </div>
                <?php elseif (!$canAward): ?>
                <div class="alert alert-warning border-0 rounded-3 py-2 mt-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle me-1"></i> Complete the items marked with <span class="badge bg-danger rounded-pill" style="font-size:.6rem;"><i class="bi bi-x-circle"></i></span> above before awarding.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Over-Threshold: HOD/Branch Head waiting for GC Approval -->
        <?php if (!$isUnderThreshold && in_array($userRoleName, ['HOD', 'Director HRM&A', 'Branch Head']) && $rfq['request_status'] === 'COMMITTEE_RECOMMENDED' && !$isAwarded): ?>
        <div class="alert border-0 rounded-3 d-flex align-items-start gap-2 mb-4" style="background: linear-gradient(135deg, rgba(26, 26, 46, 0.06) 0%, rgba(11, 94, 43, 0.06) 100%); border-left: 4px solid #1a1a2e;">
            <i class="bi bi-hourglass-split fs-5 flex-shrink-0 mt-1" style="color:#1a1a2e;"></i>
            <div>
                <strong>Awaiting GC Approval</strong> — Committee evaluation is complete. Waiting for Deputy Government Chemist to approve (SOP Step 10) before award can proceed.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isAwarded && ($rfq['acceptance_status'] ?? 'PENDING') === 'PENDING' && $userRoleName === 'Procurement Officer'): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clipboard-check me-1"></i> Vendor Acceptance</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Record the vendor's acceptance or decline of the award. This corresponds to SOP Step 12.
                </p>
                <div class="d-flex gap-2">
                    <a href="/rfq/accept_award.php?id=<?= $rfq_id ?>&action=accept"
                       class="btn btn-success rounded-pill"
                       onclick="return confirm('Confirm vendor has accepted the award?')">
                        <i class="bi bi-check-circle me-1"></i>Accept Award
                    </a>
                    <a href="/rfq/accept_award.php?id=<?= $rfq_id ?>&action=decline"
                       class="btn btn-outline-danger rounded-pill"
                       onclick="return confirm('Confirm vendor has declined the award?')">
                        <i class="bi bi-x-circle me-1"></i>Decline Award
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($hasVoted): ?>
        <div class="alert alert-info border-0 rounded-3 d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>You have already submitted your vote for this RFQ.</div>
        </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-lg-4">

        <!-- Voting Progress Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-clipboard2-check me-1"></i> Voting Progress</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted">Members voted</span>
                    <span class="fw-bold"><?= $votesSubmitted ?> / <?= $committeeCount ?></span>
                </div>
                <div class="progress rounded-pill" style="height:12px;">
                    <div class="progress-bar rounded-pill <?= $votePercentage == 100 ? 'bg-success' : '' ?>"
                         role="progressbar"
                         style="width:<?= $votePercentage ?>%;<?= $votePercentage < 100 ? 'background:#c9a227;' : '' ?>"
                         aria-valuenow="<?= $votePercentage ?>"
                         aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
                <div class="text-end mt-1">
                    <small class="fw-semibold <?= $votePercentage == 100 ? 'text-success' : 'text-muted' ?>">
                        <?= $votePercentage ?>%
                    </small>
                </div>
                <?php if ($votePercentage == 100): ?>
                <div class="alert alert-success border-0 rounded-3 py-2 mt-3 mb-0 small">
                    <i class="bi bi-check-circle me-1"></i> All committee members have voted.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vote Summary Card -->
        <?php
        $stmt = $pdo->prepare("
            SELECT rv.vendor_name, COUNT(v.vote_id) as total_votes
            FROM rfq_votes v
            JOIN rfq_vendors rv ON v.rfq_vendor_id = rv.rfq_vendor_id
            WHERE v.rfq_id = ?
            GROUP BY rv.rfq_vendor_id ORDER BY total_votes DESC
        ");
        $stmt->execute([$rfq_id]);
        $voteSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-bar-chart-line me-1"></i> Vote Summary</h6>
            </div>
            <div class="card-body">
                <?php if ($userRoleName === 'Evaluation Committee Member'): ?>
                    <div class="text-muted small text-center py-3">
                        <i class="bi bi-eye-slash d-block fs-4 mb-1 opacity-50"></i>
                        Vote details hidden for confidentiality.
                    </div>
                <?php elseif (!empty($voteSummary)): ?>
                    <?php
                    $maxVotes = $voteSummary[0]['total_votes'] ?? 1;
                    foreach ($voteSummary as $idx => $vote):
                        $pct = ($maxVotes > 0) ? round(($vote['total_votes'] / $maxVotes) * 100) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= htmlspecialchars($vote['vendor_name']) ?></span>
                            <span class="text-muted"><?= $vote['total_votes'] ?> vote<?= $vote['total_votes'] != 1 ? 's' : '' ?></span>
                        </div>
                        <div class="progress rounded-pill" style="height:8px;">
                            <div class="progress-bar rounded-pill <?= $idx === 0 ? '' : 'bg-secondary' ?>"
                                 style="width:<?= $pct ?>%;<?= $idx === 0 ? 'background:#0b5e2b;' : '' ?>"
                                 role="progressbar"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small text-center py-3">No votes submitted yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Evaluation Committee Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="bi bi-people me-1"></i> Evaluation Committee</h6>
                <span class="badge rounded-pill" style="background:#0b5e2b;"><?= count($committeeMembers) ?></span>
            </div>
            <div class="card-body pt-0">
                <?php if (!empty($committeeMembers)): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($committeeMembers as $member): ?>
                    <li class="list-group-item d-flex align-items-center justify-content-between px-0 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center"
                                 style="width:32px;height:32px;background:#d1e7dd;color:#0b5e2b;font-size:.8rem;flex-shrink:0;">
                                <i class="bi bi-person"></i>
                            </div>
                            <span class="small fw-semibold"><?= htmlspecialchars($member['full_name']) ?></span>
                        </div>
                        <?php if ($userRoleName === 'Procurement Officer'): ?>
                        <a href="remove_committee.php?rfq_id=<?= $rfq_id ?>&user_id=<?= $member['user_id'] ?>"
                           class="btn btn-sm btn-outline-danger rounded-circle"
                           style="width:28px;height:28px;padding:0;line-height:28px;"
                           onclick="return confirm('Remove this member?')">
                            <i class="bi bi-x"></i>
                        </a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="text-muted small text-center py-3">
                    <i class="bi bi-people fs-3 d-block mb-1 opacity-25"></i>
                    No committee members assigned.
                </div>
                <?php endif; ?>
            </div>
            <?php if ($userRoleName === 'Procurement Officer'): ?>
            <div class="card-footer bg-white border-0 rounded-bottom-4 py-2">
                <a href="add_committee.php?rfq_id=<?= $rfq_id ?>"
                   class="btn btn-sm btn-outline-success rounded-pill w-100">
                    <i class="bi bi-plus-lg me-1"></i>Add Member
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Evaluation Reports Card -->
        <?php
        $stmt = $pdo->prepare("
            SELECT report_id, report_file, created_at
            FROM rfq_evaluation_reports WHERE rfq_id = ?
        ");
        $stmt->execute([$rfq_id]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="bi bi-file-earmark-medical me-1"></i> Evaluation Reports</h6>
                <span class="badge rounded-pill bg-secondary"><?= count($reports) ?></span>
            </div>
            <div class="card-body pt-0">
                <?php if (!empty($reports)): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($reports as $r): ?>
                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark-pdf text-danger"></i>
                            <span class="small"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
                        </div>
                        <a href="view_report.php?id=<?= $r['report_id'] ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary rounded-pill">
                            <i class="bi bi-eye me-1"></i>View
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="text-muted small text-center py-3">
                    <i class="bi bi-file-earmark fs-3 d-block mb-1 opacity-25"></i>
                    No evaluation report uploaded.
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-0 rounded-bottom-4 py-2 d-flex flex-wrap gap-2">
                <?php if ($isCommitteeMember): ?>
                <a href="upload_report.php?rfq_id=<?= $rfq_id ?>"
                   class="btn btn-sm btn-outline-success rounded-pill flex-fill text-center">
                    <i class="bi bi-cloud-arrow-up me-1"></i>Upload Report
                </a>
                <?php endif; ?>
                <?php if ($userRoleName !== 'Evaluation Committee Member'): ?>
                <a href="/rfq/generate_evaluation_summary.php?id=<?= $rfq_id ?>"
                   class="btn btn-sm btn-outline-dark rounded-pill flex-fill text-center">
                    <i class="bi bi-download me-1"></i>Eval Summary
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vendor Evaluation Scores Card (Deputy GC, HOD, Procurement Officer) -->
        <?php if (in_array($userRoleName, ['Deputy Government Chemist', 'HOD', 'Procurement Officer'])): ?>
        <?php
        // Fetch formal scores first
        $scoreStmt = $pdo->prepare("
            SELECT v.vendor_name, rv.rfq_vendor_id,
                   SUM(s.total_score) as total_score
            FROM rfq_scores s
            JOIN rfq_vendors rv ON s.rfq_vendor_id = rv.rfq_vendor_id
            JOIN vendors v ON rv.vendor_id = v.vendor_id
            WHERE s.rfq_id = ?
            GROUP BY rv.rfq_vendor_id
            ORDER BY total_score DESC
        ");
        $scoreStmt->execute([$rfq_id]);
        $vendorScores = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);
        $scoreSource = 'formal';
        
        // If no formal scores, build from votes + quotes
        if (empty($vendorScores)) {
            $scoreSource = 'votes';
            $scoreStmt = $pdo->prepare("
                SELECT v.vendor_name, rv.rfq_vendor_id,
                       COALESCE(q.quote_amount, 0) as quote_amount,
                       COALESCE(q.review_status, 'PENDING') as review_status,
                       COUNT(DISTINCT votes.vote_id) as vote_count
                FROM rfq_vendors rv
                JOIN vendors v ON rv.vendor_id = v.vendor_id
                LEFT JOIN rfq_quotes q ON q.rfq_vendor_id = rv.rfq_vendor_id
                LEFT JOIN rfq_votes votes ON votes.rfq_vendor_id = rv.rfq_vendor_id AND votes.rfq_id = rv.rfq_id
                WHERE rv.rfq_id = ?
                GROUP BY rv.rfq_vendor_id, v.vendor_name, q.quote_amount, q.review_status
                ORDER BY vote_count DESC, q.quote_amount ASC
            ");
            $scoreStmt->execute([$rfq_id]);
            $vendorScores = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($vendorScores)) {
                $maxV = max(array_column($vendorScores, 'vote_count') ?: [1]) ?: 1;
                $amounts = array_filter(array_column($vendorScores, 'quote_amount'), fn($a) => $a > 0);
                $minAmt = !empty($amounts) ? min($amounts) : 1;
                foreach ($vendorScores as &$vs) {
                    $vScore = ($vs['vote_count'] / $maxV) * 60;
                    $pScore = ($vs['quote_amount'] > 0 && $minAmt > 0) ? ($minAmt / $vs['quote_amount']) * 40 : 0;
                    $vs['total_score'] = round($vScore + $pScore, 2);
                }
                unset($vs);
                usort($vendorScores, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
            }
        }
        
        $topScore = !empty($vendorScores) ? (float)$vendorScores[0]['total_score'] : 1;
        if ($topScore <= 0) $topScore = 1;
        ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
                <h6 class="fw-semibold mb-0"><i class="bi bi-graph-up me-1"></i> Vendor Scores</h6>
                <?php if ($scoreSource === 'votes'): ?>
                <span class="badge rounded-pill bg-secondary" title="Based on committee votes and quote amounts">Vote Based</span>
                <?php else: ?>
                <span class="badge rounded-pill" style="background:#0b5e2b;">Scored</span>
                <?php endif; ?>
            </div>
            <div class="card-body pt-0">
                <?php if (!empty($vendorScores)): ?>
                    <?php foreach ($vendorScores as $si => $vs):
                        $pctScore = ($topScore > 0) ? round(($vs['total_score'] / $topScore) * 100) : 0;
                        $barColor = $pctScore >= 80 ? '#198754' : ($pctScore >= 50 ? '#ffc107' : '#dc3545');
                        $isTop = ($si === 0 && $vs['total_score'] > 0);
                    ?>
                    <div class="mb-3 <?= $isTop ? 'p-2 rounded-3' : '' ?>" <?= $isTop ? 'style="background:#d1e7dd22;"' : '' ?>>
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <div class="d-flex align-items-center gap-1">
                                <?php if ($isTop): ?>
                                <i class="bi bi-trophy-fill" style="color:#c9a227;font-size:.75rem;"></i>
                                <?php endif; ?>
                                <span class="fw-semibold"><?= htmlspecialchars($vs['vendor_name']) ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($scoreSource === 'votes' && isset($vs['vote_count'])): ?>
                                <span class="text-muted" style="font-size:.7rem;">
                                    <?= (int)$vs['vote_count'] ?> vote<?= $vs['vote_count'] != 1 ? 's' : '' ?>
                                </span>
                                <?php endif; ?>
                                <span class="fw-bold" style="color:<?= $barColor ?>;"><?= number_format($vs['total_score'], 1) ?></span>
                            </div>
                        </div>
                        <div class="progress rounded-pill" style="height:6px;">
                            <div class="progress-bar rounded-pill" role="progressbar"
                                 style="width:<?= $pctScore ?>%;background:<?= $barColor ?>;"
                                 aria-valuenow="<?= $pctScore ?>"></div>
                        </div>
                        <?php if ($scoreSource === 'votes' && isset($vs['quote_amount']) && $vs['quote_amount'] > 0): ?>
                        <div class="d-flex justify-content-between mt-1" style="font-size:.65rem;">
                            <span class="text-muted">$<?= number_format($vs['quote_amount'], 2) ?></span>
                            <?php
                            $revLabel = match($vs['review_status'] ?? 'PENDING') {
                                'MEETS_REQUIREMENTS' => '<span style="color:#198754;">Approved</span>',
                                'DOES_NOT_MEET' => '<span style="color:#dc3545;">Rejected</span>',
                                default => '<span class="text-muted">Pending Review</span>',
                            };
                            ?>
                            <?= $revLabel ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($scoreSource === 'votes'): ?>
                    <div class="text-muted text-center mt-2" style="font-size:.65rem;">
                        <i class="bi bi-info-circle me-1"></i>Score = 60% votes + 40% price competitiveness
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted small text-center py-3">
                        <i class="bi bi-graph-up fs-3 d-block mb-1 opacity-25"></i>
                        No evaluation data yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>