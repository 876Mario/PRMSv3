<?php
$REQUIRE_PERMISSION = 'view_director_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <span style="font-size: 1.75em; margin-right: 1rem;">🏢</span>
            <div>
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #333;">Director Procurement Dashboard</h2>
                <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">Oversight of all procurement requests, commitments, and purchase orders.</small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/kpis.php'; ?>
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/alerts.php'; ?>
        </div>

        <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
            <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
                <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📋 Recent Procurement Requests</h6>
            </div>
            
            <?php
            $stmt = $pdo->prepare("SELECT request_id, request_number, request_type, estimated_value, status, request_date
                FROM procurement_requests
                ORDER BY request_date DESC LIMIT 20");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (empty($rows)): ?>
                <div style="text-align: center; color: #999; padding: 2rem 0;">
                    <span style="font-size: 1.5em;">📄</span><br>
                    <span style="display: block; margin-top: 0.5rem;">No recent requests</span>
                </div>
            <?php else: ?>
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
                        <?php foreach ($rows as $row): ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($row['request_number']) ?></td>
                                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($row['request_type']) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: right; color: #666;">JMD <?= number_format((float)$row['estimated_value'], 2) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: center;">
                                    <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block;">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($row['request_date'])) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: center;">
                                    <a href="/procurement/view.php?id=<?= $row['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-block; transition: transform 0.3s ease;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
