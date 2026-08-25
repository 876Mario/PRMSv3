<?php
if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}

$pipeline = $pdo->query("
    SELECT UPPER(status) AS status, COUNT(*) AS cnt, SUM(estimated_value) AS total_value
    FROM procurement_requests
    GROUP BY UPPER(status)
    ORDER BY FIELD(UPPER(status), 'DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'PO_APPROVED', 'INVOICE_RECEIVED', 'AWARDED', 'COMPLETED', 'DECLINED')
")->fetchAll(PDO::FETCH_ASSOC);

$totalCount = array_sum(array_column($pipeline, 'cnt'));

$stageMeta = [
    'DRAFT'                 => ['icon' => '📝', 'color' => '#6c757d', 'label' => 'Draft'],
    'SUBMITTED'             => ['icon' => '📤', 'color' => '#ffc107', 'label' => 'Submitted'],
    'HOD_APPROVED'          => ['icon' => '👤', 'color' => '#0dcaf0', 'label' => 'HOD Approved'],
    'FUNDS_VERIFIED'        => ['icon' => '💰', 'color' => '#0d6efd', 'label' => 'Funds Verified'],
    'DIRECTOR_APPROVED'     => ['icon' => '👤', 'color' => '#198754', 'label' => 'Director Approved'],
    'PROCUREMENT_STAGE'     => ['icon' => '📑', 'color' => '#343a40', 'label' => 'Procurement Stage'],
    'EVALUATION_STAGE'      => ['icon' => '📊', 'color' => '#6610f2', 'label' => 'Evaluation Stage'],
    'COMMITTEE_RECOMMENDED' => ['icon' => '🧾', 'color' => '#0dcaf0', 'label' => 'Committee Recommended'],
    'GC_APPROVED'           => ['icon' => '🏛', 'color' => '#198754', 'label' => 'GC Approved'],
    'RFQ_LETTER_AVAILABLE'  => ['icon' => '✉️', 'color' => '#6f42c1', 'label' => 'RFQ Letters'],
    'QUOTE_REVIEW_PENDING'  => ['icon' => '💬', 'color' => '#fd7e14', 'label' => 'Quote Review'],
    'QUOTE_REQUESTOR_REVIEW_PENDING' => ['icon' => '👤', 'color' => '#e67e22', 'label' => 'Requestor Review'],
    'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['icon' => '✅', 'color' => '#20c997', 'label' => 'Requestor Approved'],
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['icon' => '🛡️', 'color' => '#8e44ad', 'label' => 'Branch Head Approval'],
    'QUOTE_APPROVED'        => ['icon' => '✔️', 'color' => '#20c997', 'label' => 'Quote Approved'],
    'COMMITMENTS_PENDING'   => ['icon' => '⏳', 'color' => '#ffc107', 'label' => 'Commitments Pending'],
    'COMMITMENT_APPROVED'   => ['icon' => '💵', 'color' => '#0d6efd', 'label' => 'Commitment Approved'],
    'COMMITMENT_DECLINED'   => ['icon' => '🚫', 'color' => '#dc3545', 'label' => 'Commitment Declined'],
    'PO_PENDING'            => ['icon' => '📄', 'color' => '#6610f2', 'label' => 'PO Created'],
    'PO_APPROVED'           => ['icon' => '📋', 'color' => '#198754', 'label' => 'PO Approved'],
    'INVOICE_RECEIVED'      => ['icon' => '🧾', 'color' => '#0dcaf0', 'label' => 'Invoice Received'],
    'AWARDED'               => ['icon' => '🏆', 'color' => '#198754', 'label' => 'Awarded'],
    'COMPLETED'             => ['icon' => '✅', 'color' => '#198754', 'label' => 'Completed'],
    'DECLINED'              => ['icon' => '❌', 'color' => '#dc3545', 'label' => 'Declined'],
];
?>

<style>
    .pipeline-widget {
        background: white;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        margin-bottom: 1rem;
    }
    .pipeline-stage {
        display: flex;
        align-items: center;
        margin-bottom: 1rem;
        gap: 1rem;
    }
    .pipeline-stage:last-child {
        margin-bottom: 0;
    }
    .stage-label {
        min-width: 140px;
        text-align: right;
    }
    .stage-label small {
        display: block;
        color: #666;
        font-size: 0.8rem;
        font-weight: 600;
        margin-top: 0.2rem;
    }
    .stage-bar {
        flex: 1;
        height: 2rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 1rem;
        color: white;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.3s ease;
        min-width: 60px;
    }
    .stage-bar:hover {
        transform: scaleX(1.02);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }
</style>

<div class="pipeline-widget">
    <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h5 style="margin: 0; font-weight: 600; color: #1a1a1a;"><span style="font-size: 1.3rem;">🔀</span> Procurement Pipeline</h5>
    </div>

    <?php if (empty($pipeline)): ?>
        <div style="text-align: center; padding: 2rem; color: #999;">
            <div style="font-size: 2rem; margin-bottom: 0.5rem;">😕</div>
            <div>No pipeline data available</div>
        </div>
    <?php else: ?>
        <!-- Funnel visualization -->
        <div style="margin-bottom: 2rem;">
            <?php foreach ($pipeline as $stage): ?>
                <?php
                $meta = $stageMeta[$stage['status']] ?? ['icon' => '📋', 'color' => '#6c757d', 'label' => $stage['status']];
                $pct = $totalCount > 0 ? round(($stage['cnt'] / $totalCount) * 100) : 0;
                $barWidth = max($pct, 8);
                
                // Use gradient based on status
                $gradientColor = match($stage['status']) {
                    'AWARDED', 'GC_APPROVED', 'COMPLETED' => 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
                    'SUBMITTED', 'FUNDS_VERIFIED' => 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
                    'HOD_APPROVED', 'DIRECTOR_APPROVED' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                    'DECLINED' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                    default => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
                };
                ?>
                <div class="pipeline-stage">
                    <div class="stage-label">
                        <span style="font-size: 1.2rem;"><?= $meta['icon'] ?></span>
                        <small><?= htmlspecialchars($meta['label']) ?></small>
                    </div>
                    <div class="stage-bar" style="background: <?= $gradientColor ?>; width: <?= $barWidth ?>%; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                        <span><?= $stage['cnt'] ?> • $<?= number_format((float)$stage['total_value'], 0) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Summary row -->
        <div style="padding-top: 1rem; border-top: 2px solid #e0e0e0; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div style="text-align: center;">
                <div style="font-size: 1.8rem; font-weight: 700; color: #1a1a1a; margin-bottom: 0.3rem;"><?= $totalCount ?></div>
                <small style="color: #999; font-size: 0.85rem;">Total Requests</small>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 1.8rem; font-weight: 700; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 0.3rem;">
                    $<?= number_format((float)array_sum(array_column($pipeline, 'total_value')), 0) ?>
                </div>
                <small style="color: #999; font-size: 0.85rem;">Total Value</small>
            </div>
        </div>
    <?php endif; ?>
</div>
