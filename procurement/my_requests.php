<?php
$REQUIRE_PERMISSION = 'view_own_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
$userId = $_SESSION['user_id'];
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Header Card -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; margin-bottom: 0;">
            <span style="font-size: 1.75em; margin-right: 1rem;">📋</span>
            <div>
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #333;">My Procurement Requests</h2>
                <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">View and track your submitted procurement requests.</small>
            </div>
        </div>
    </div>

    <!-- Requests Card -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 2rem;">
        <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📝 Request List</h6>
        </div>

        <?php
        $stmt = $pdo->prepare("
            SELECT request_id, request_number, request_type, estimated_value, currency, status, request_date
            FROM procurement_requests
            WHERE created_by = ?
            ORDER BY request_date DESC LIMIT 50
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead style="background: #f5f5f5;">
                    <tr>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Type</th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Value</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Status</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #999; padding: 2rem 0;">
                            <i style="font-size: 2rem; opacity: 0.3;">📭</i>
                            <div style="margin-top: 0.5rem;">No requests found.</div>
                        </td>
                    </tr>
                <?php else: foreach ($rows as $row): 
                    // Status color mapping
                    $statusGradients = [
                        'DRAFT' => '#667eea',
                        'SUBMITTED' => '#4facfe',
                        'HOD_APPROVED' => '#43e97b',
                        'DIRECTOR_APPROVED' => '#38f9d7',
                        'GC_APPROVED' => '#1a7e7e',
                        'PROCUREMENT_STAGE' => '#fa709a',
                        'EVALUATION_STAGE' => '#fee140',
                        'RFQ_LETTER_AVAILABLE' => '#fa8231',
                        'QUOTE_REVIEW_PENDING' => '#ff9ff3',
                        'QUOTE_REQUESTOR_REVIEW_PENDING' => '#f6b93b',
                        'QUOTE_REQUESTOR_REVIEW_APPROVED' => '#9b59b6',
                        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => '#8e44ad',
                        'QUOTE_APPROVED' => '#54a0ff',
                        'COMMITMENTS_PENDING' => '#48dbfb',
                        'COMMITMENT_APPROVED' => '#1dd1a1',
                        'PO_PENDING' => '#5f27cd',
                        'PO_APPROVED' => '#00d2d3',
                        'INVOICE_RECEIVED' => '#ff6348',
                        'COMPLETED' => '#26de81',
                        'AWARDED' => '#ffa502',
                    ];
                    $statusColor = $statusGradients[$row['status']] ?? '#667eea';
                ?>
                    <tr style="border-bottom: 1px solid #f0f0f0; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor='#fafafa';" onmouseout="this.style.backgroundColor='transparent';">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;">
                            <a href="/procurement/view.php?id=<?= $row['request_id'] ?>" style="text-decoration: none; color: #333; transition: color 0.2s ease;" onmouseover="this.style.color='#667eea';" onmouseout="this.style.color='#333';">
                                <?= htmlspecialchars($row['request_number']) ?>
                            </a>
                        </td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($row['request_type']) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars($row['currency'] ?? 'JMD') ?> <?= number_format((float)$row['estimated_value'], 2) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <span style="background: linear-gradient(135deg, <?= $statusColor ?> 0%, <?= $statusColor ?>dd 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                        <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($row['request_date'])) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <a href="/procurement/view.php?id=<?= $row['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-block; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)';">View</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
