<?php
$REQUIRE_PERMISSION = 'approve_as_director_hrma';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ═════════════════════════════════════════════════════════
   Get HRM&A Branch Approvals (All amounts - Director HRM&A is sole approver)
═════════════════════════════════════════════════════════ */

$stmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.created_at,
        b.branch_name,
        ra.role as required_role
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.status = 'pending'
    WHERE UPPER(pr.status) = 'SUBMITTED'
    AND b.branch_id = 5  /* HRM&A Branch — Director HRM&A is the designated approver */
    AND ra.role = 'Director HRM&A'
    AND ra.status = 'pending'
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$branchRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$branchPending = count($branchRequests);
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <span style="font-size: 1.75em; margin-right: 1rem;">🏢</span>
            <div>
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #333;">Director HRM&A Dashboard</h2>
                <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">Approve procurement requests for HRM&A branch and other procurement documents.</small>
            </div>
        </div>

        <!-- KPI Card -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem;">
            <h6 style="margin: 0; font-weight: 600; opacity: 0.9;">📊 HRM&A Branch Approvals</h6>
            <h3 style="margin: 0.5rem 0 0 0; font-size: 2.5rem; font-weight: 700;"><?= $branchPending ?></h3>
            <small style="opacity: 0.9;">HRM&A Branch Pending Approvals</small>
        </div>

        <!-- HRM&A Branch Specific Approvals -->
        <?php if ($branchPending > 0): ?>
        <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
            <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
                <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📋 HRM&A Branch Approvals <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= $branchPending ?></span></h6>
            </div>
            <p style="color: #666; font-size: 0.875rem; margin-bottom: 1rem;"><i class="bi bi-info-circle"></i> These are approvals for HRM&A branch requests. As Director HRM&A, you are the sole approver for this branch.</p>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead style="background: #f5f5f5;">
                        <tr>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
                            <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Value</th>
                            <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date Submitted</th>
                            <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($branchRequests as $row): ?>
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($row['request_number']) ?></td>
                            <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></td>
                            <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($row['currency'] ?? 'JMD')) ?> <?= number_format((float)$row['estimated_value'], 2) ?></td>
                            <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                            <td style="padding: 0.75rem 1rem; text-align: center;">
                                <a href="/procurement/view.php?id=<?= $row['request_id'] ?>" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-block; margin-right: 0.5rem; transition: all 0.3s ease;">Review</a>
                                <a href="/procurement/approve.php?id=<?= $row['request_id'] ?>" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-block; transition: all 0.3s ease;">Approve</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pending Actions Widget -->
        <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
            <?php
            /* ─── Organisation-wide monitoring panel (read-only) ─── */
            $monitorStmt = $pdo->prepare("
                SELECT
                    pr.request_id,
                    pr.request_number,
                    pr.request_type,
                    pr.status,
                    pr.estimated_value,
                    pr.currency,
                    pr.created_at,
                    b.branch_name,
                    u.full_name AS requestor_name
                FROM procurement_requests pr
                LEFT JOIN branches b ON pr.branch_id = b.branch_id
                LEFT JOIN users   u ON pr.created_by = u.user_id
                WHERE UPPER(pr.status) NOT IN ('COMPLETED','CANCELLED','DECLINED')
                ORDER BY pr.created_at DESC
                LIMIT 50
            ");
            $monitorStmt->execute();
            $monitorRows = $monitorStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (!empty($monitorRows)): ?>
            <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
                    <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">
                        <i class="bi bi-eye"></i> Organisation-wide Request Monitoring
                        <span style="background: #6c757d; color: white; padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.75rem; margin-left: 0.5rem;"><?= count($monitorRows) ?></span>
                        <small style="font-weight:400; color:#999; margin-left:0.5rem; font-size:0.75rem;">(Read-only — monitoring access only)</small>
                    </h6>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead style="background: #f5f5f5;">
                            <tr>
                                <th style="padding: 0.6rem 0.9rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Ref #</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Category</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Requestor</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Unit</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Stage</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Value</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
                                <th style="padding: 0.6rem 0.9rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">View</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($monitorRows as $mr): ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 0.6rem 0.9rem; font-weight: 600; color: #333;"><?= htmlspecialchars($mr['request_number']) ?></td>
                                <td style="padding: 0.6rem 0.9rem; color: #666;"><?= htmlspecialchars($mr['request_type']) ?></td>
                                <td style="padding: 0.6rem 0.9rem; color: #666;"><?= htmlspecialchars($mr['requestor_name'] ?? 'N/A') ?></td>
                                <td style="padding: 0.6rem 0.9rem; color: #666;"><?= htmlspecialchars($mr['branch_name'] ?? 'N/A') ?></td>
                                <td style="padding: 0.6rem 0.9rem;"><?= statusBadge($mr['status']) ?></td>
                                <td style="padding: 0.6rem 0.9rem; text-align: right; color: #333;"><?= htmlspecialchars(normalizeCurrency($mr['currency'] ?? 'JMD')) ?> <?= number_format((float)$mr['estimated_value'], 2) ?></td>
                                <td style="padding: 0.6rem 0.9rem; text-align: center; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($mr['created_at'])) ?></td>
                                <td style="padding: 0.6rem 0.9rem; text-align: center;">
                                    <a href="/procurement/view.php?id=<?= $mr['request_id'] ?>"
                                       style="background: #6c757d; color: white; padding: 0.3rem 0.65rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">
                                       View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
        </div>

        <!-- Widgets Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/kpis.php'; ?>
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/alerts.php'; ?>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
