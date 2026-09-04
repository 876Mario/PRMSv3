<?php
/**
 * Review Quote
 * ============
 * Requestor, Branch Head, or HOD reviews a vendor quote
 * Marks as MEETS_REQUIREMENTS or DOES_NOT_MEET with comments
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$quote_id = (int)($_GET['quote_id'] ?? 0);
$rfq_id = (int)($_GET['rfq_id'] ?? 0);

if (!$quote_id || !$rfq_id) {
    pop('Invalid parameters', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch quote details
$stmt = $pdo->prepare("
    SELECT q.*, rv.rfq_vendor_id, v.vendor_name, r.request_id, pr.status as request_status, pr.created_by
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    JOIN rfqs r ON rv.rfq_id = r.rfq_id
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    WHERE q.quote_id = ? AND rv.rfq_id = ?
");
$stmt->execute([$quote_id, $rfq_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    pop('Quote not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if request is in QUOTE_REVIEW_PENDING status
if ($quote['request_status'] !== 'QUOTE_REVIEW_PENDING') {
    pop('This RFQ is not in quote review stage', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check user role - must be Requestor, HOD, or Branch Head
// Get user's role from database (more reliable than session)
$stmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetch(PDO::FETCH_ASSOC);

// Get role name from roles table
$stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
$stmt->execute([$userRole['role_id'] ?? 0]);
$roleName = $stmt->fetchColumn();

// Allow the request creator (submitter), HOD, Branch Head, or Procurement Officer
$isRequestCreator = (int)($quote['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);
$allowedRoles = ['HOD', 'Branch Head', 'Procurement Officer'];
if (!$isRequestCreator && !in_array($roleName, $allowedRoles)) {
    pop("You do not have permission to review quotes. Your role: {$roleName}", '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Handle POST - Save review
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_status = trim($_POST['review_status'] ?? '');
    $review_comments = trim($_POST['review_comments'] ?? '');
    
    // Validate
    if (!in_array($review_status, ['MEETS_REQUIREMENTS', 'DOES_NOT_MEET'])) {
        pop('Invalid review status', '/rfq/review_quote.php?quote_id=' . $quote_id . '&rfq_id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
    
    if (strlen($review_comments) < 5 && strlen($review_comments) > 0) {
        pop('Comments must be at least 5 characters or empty', '/rfq/review_quote.php?quote_id=' . $quote_id . '&rfq_id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    // When the original requestor (procurement request creator) marks a quote as meeting
    // requirements, treat it as both the quote selection and the requestor specification
    // confirmation, advancing the pipeline directly to Branch Head approval.
    if ($review_status === 'MEETS_REQUIREMENTS' && $isRequestCreator) {
        try {
            $pdo->beginTransaction();

            // Save the quote review status
            $pdo->prepare("UPDATE rfq_quotes SET review_status = ?, review_comments = ? WHERE quote_id = ?")
                ->execute([
                    $review_status,
                    !empty($review_comments) ? $review_comments : null,
                    $quote_id,
                ]);

            // Deselect all other quotes for this RFQ, then select this one
            $pdo->prepare(
                "UPDATE rfq_quotes
                    SET is_selected = 0
                  WHERE rfq_vendor_id IN (SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = ?)"
            )->execute([$rfq_id]);
            $pdo->prepare("UPDATE rfq_quotes SET is_selected = 1 WHERE quote_id = ?")
                ->execute([$quote_id]);

            // Record requestor specification confirmation on the RFQ
            $pdo->prepare(
                "UPDATE rfqs
                    SET requestor_spec_review_status = 'APPROVED',
                        requestor_reviewer_id = ?,
                        requestor_reviewed_at = CURRENT_TIMESTAMP,
                        requestor_review_comments = ?,
                        branch_head_approval_status = 'PENDING',
                        branch_head_approver_id = NULL,
                        branch_head_approved_at = NULL,
                        branch_head_comments = NULL
                  WHERE rfq_id = ?"
            )->execute([
                $_SESSION['user_id'],
                !empty($review_comments) ? $review_comments : null,
                $rfq_id,
            ]);

            // Insert into immutable requestor review history
            $pdo->prepare(
                "INSERT INTO rfq_requestor_reviews
                    (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
                 VALUES (?, ?, 'MEETS_SPECIFICATIONS', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            )->execute([
                $rfq_id,
                $_SESSION['user_id'],
                !empty($review_comments) ? $review_comments : null,
            ]);

            // Insert into quote approval audit trail
            // Both `comments` and `requestor_notes` capture the same value: the requestor's review comments.
            $pdo->prepare(
                "INSERT INTO rfq_quote_approvals
                    (rfq_id, quote_id, approval_stage, approver_id, approver_role, action, comments, requestor_notes, created_at)
                 VALUES (?, ?, 'REQUESTOR_REVIEW', ?, ?, 'APPROVED', ?, ?, CURRENT_TIMESTAMP)"
            )->execute([
                $rfq_id,
                $quote_id,
                $_SESSION['user_id'],
                $_SESSION['role_name'] ?? null,
                !empty($review_comments) ? $review_comments : null,
                !empty($review_comments) ? $review_comments : null,
            ]);

            // Advance the procurement request pipeline to Branch Head approval
            $pdo->prepare(
                "UPDATE procurement_requests
                    SET status = 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
                  WHERE request_id = ? AND status = 'QUOTE_REVIEW_PENDING'"
            )->execute([(int)$quote['request_id']]);

            // Audit log
            $pdo->prepare(
                "INSERT INTO audit_log (table_name, action, notes, change_date)
                 VALUES ('rfq_quotes', 'REVIEW', ?, NOW())"
            )->execute([
                "Quote {$quote_id} confirmed as MEETS_REQUIREMENTS by requestor {$_SESSION['full_name']}; RFQ routed to Branch Head for final approval.",
            ]);

            if (function_exists('logRequestTimeline')) {
                logRequestTimeline(
                    $pdo,
                    (int)$quote['request_id'],
                    'QUOTE_REQUESTOR_REVIEW_APPROVED',
                    'Requestor confirmed selected quotation meets specifications. Routed to Branch Head for final approval.'
                );
            }

            $pdo->commit();

            // Notify Branch Head that approval is pending (outside the transaction to avoid
            // holding the DB connection open; a notification failure is non-fatal).
            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
                if (function_exists('sendBranchHeadApprovalNotification')) {
                    sendBranchHeadApprovalNotification($rfq_id);
                }
            } catch (Throwable $notifyEx) {
                error_log('sendBranchHeadApprovalNotification failed for rfq_id=' . $rfq_id . ': ' . $notifyEx->getMessage());
            }

            pop(
                'Quotation confirmed as meeting your requirements. The RFQ has been routed to the Branch Head for final approval.',
                '/rfq/view.php?id=' . $rfq_id,
                POP_DEFAULT_DELAY_MS,
                'success'
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            pop(extractDbMessage($e), '/rfq/review_quote.php?quote_id=' . $quote_id . '&rfq_id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
            exit;
        }
    }
    
    // Update quote review
    try {
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET review_status = ?, review_comments = ?
        WHERE quote_id = ?
    ");
    $stmt->execute([
        $review_status,
        !empty($review_comments) ? $review_comments : null,
        $quote_id
    ]);
    
    // Audit log
    $pdo->prepare("
        INSERT INTO audit_log (table_name, action, notes, change_date)
        VALUES ('rfq_quotes', 'REVIEW', ?, NOW())
    ")->execute([
        "Quote {$quote_id} reviewed: {$review_status} by {$_SESSION['full_name']}"
    ]);
    
    pop('Quote review saved successfully. Select the preferred quote to route it for requestor specification confirmation.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
    exit;
    } catch (Throwable $e) {
        pop(extractDbMessage($e), '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<!-- Page Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <div>
        <a href="/rfq/view.php?id=<?= $rfq_id ?>" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Back to RFQ
        </a>
        <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
            <i class="bi bi-chat-dots"></i> Review Vendor Quote
        </h4>
        <p class="text-muted mb-0 small">Assess vendor compliance with requirements</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <!-- Quote Details Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-receipt me-1"></i> Quote Details</h6>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Vendor</label>
                        <div class="fw-semibold"><?= htmlspecialchars($quote['vendor_name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Quote Amount</label>
                        <div class="fw-semibold">$<?= number_format($quote['quote_amount'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">GCT</label>
                        <div class="fw-semibold">$<?= number_format($quote['gct_amount'], 2) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Total with GCT</label>
                        <div class="fw-semibold">$<?= number_format($quote['quote_amount'] + $quote['gct_amount'], 2) ?></div>
                    </div>
                </div>
                
                <?php if (!empty($quote['quote_file'])): ?>
                <div class="mb-3">
                    <label class="form-label text-muted small">Supporting Document</label>
                    <div>
                        <a href="/rfq/download_quote.php?id=<?= (int)$quote['quote_id'] ?>&action=view"
                           target="_blank"
                           class="btn btn-outline-primary btn-sm rounded-pill">
                            <i class="bi bi-file-earmark-pdf me-1"></i>View Quote Document
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Review Form Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 rounded-top-4 py-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-pencil-square me-1"></i> Your Review</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation" novalidate>
                    
                    <!-- Review Status -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Does this quote meet your requirements?</label>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="review_status" 
                                           id="meets" value="MEETS_REQUIREMENTS" required
                                           <?= ($quote['review_status'] === 'MEETS_REQUIREMENTS') ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="meets">
                                        <i class="bi bi-check-circle me-1" style="color:#0b5e2b;"></i>
                                        <strong>Yes, meets requirements</strong>
                                        <div class="small text-muted">This quote should be considered</div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="review_status" 
                                           id="notmeets" value="DOES_NOT_MEET" required
                                           <?= ($quote['review_status'] === 'DOES_NOT_MEET') ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="notmeets">
                                        <i class="bi bi-x-circle me-1" style="color:#dc3545;"></i>
                                        <strong>No, does not meet</strong>
                                        <div class="small text-muted">This quote should be rejected</div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Review Comments (Optional)</label>
                        <textarea name="review_comments" class="form-control rounded-3" rows="4" 
                                  placeholder="Explain your assessment. For example:&#10;- Does it meet technical specifications?&#10;- Are delivery terms acceptable?&#10;- Any concerns about the vendor?"><?= htmlspecialchars($quote['review_comments'] ?? '') ?></textarea>
                        <small class="text-muted d-block mt-2">
                            This helps other reviewers understand your assessment. Minimum 5 characters if provided.
                        </small>
                    </div>
                    
                    <!-- Actions -->
                    <div class="d-grid gap-2 d-sm-flex">
                        <button type="submit" class="btn btn-success rounded-pill">
                            <i class="bi bi-check2-square me-1"></i>Save Review
                        </button>
                        <a href="/rfq/view.php?id=<?= $rfq_id ?>" class="btn btn-outline-secondary rounded-pill">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Info Box -->
        <div class="alert alert-info border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
            <div>
                <strong>Review Guidelines:</strong>
                <ul class="mb-0 ms-3 mt-2 small">
                    <li>Review the quote document and vendor information</li>
                    <li>Verify it meets all technical requirements</li>
                    <li>Check delivery terms and payment conditions</li>
                    <li>Consider vendor experience and reliability</li>
                    <li>Your review helps Finance select the best quote</li>
                </ul>
            </div>
        </div>
        
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
