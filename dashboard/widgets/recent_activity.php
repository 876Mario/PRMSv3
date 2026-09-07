<?php
if (!isset($pdo)) {
require_once __DIR__.'/_init.php';
}

$activityVisibilityWhere = '';
if (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole()
) {
    $activityVisibilityWhere = "
        WHERE (
            table_name <> 'procurement_requests'
            OR EXISTS (
                SELECT 1
                FROM procurement_requests pr
                WHERE pr.request_id = audit_log.record_id
                  AND " . getProcurementVisibilitySqlCondition('pr') . "
            )
        )
    ";
}

$recent = $pdo->query("
    SELECT table_name, action, notes, change_date
    FROM audit_log
    {$activityVisibilityWhere}
    ORDER BY change_date DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .activity-widget {
        background: white;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    .activity-scroll {
        max-height: 400px;
        overflow-y: auto;
        padding-right: 0.5rem;
    }
    .activity-scroll::-webkit-scrollbar {
        width: 6px;
    }
    .activity-scroll::-webkit-scrollbar-track {
        background: #f0f0f0;
        border-radius: 10px;
    }
    .activity-scroll::-webkit-scrollbar-thumb {
        background: #c0c0c0;
        border-radius: 10px;
    }
    .activity-item {
        display: flex;
        gap: 1rem;
        padding: 1rem;
        border-left: 3px solid #e0e0e0;
        margin-bottom: 0.75rem;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    .activity-item:hover {
        background: #f8f9fa;
        border-left-color: #667eea;
    }
    .activity-item:last-child {
        margin-bottom: 0;
    }
    .activity-icon {
        font-size: 1.5rem;
        min-width: 2rem;
    }
    .activity-content {
        flex: 1;
    }
    .activity-action {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 0.3rem;
    }
    .activity-meta {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 0.3rem;
    }
    .activity-note {
        font-size: 0.85rem;
        color: #999;
        font-style: italic;
    }
    .activity-time {
        font-size: 0.8rem;
        color: #999;
    }
</style>

<div class="activity-widget">
    <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
        <h5 style="margin: 0; font-weight: 600; color: #1a1a1a;"><span style="font-size: 1.3rem;">🕒</span> Recent Activity</h5>
        <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 5px; font-size: 0.85rem; font-weight: 500;"><?= count($recent) ?> entries</span>
    </div>

    <div class="activity-scroll">
        <?php if ($recent): ?>
            <?php foreach ($recent as $r): ?>
                <?php
                $iconMap = [
                    'CREATE' => '🟢',
                    'STATUS_CHANGE' => '🔄',
                    'PASSWORD_CHANGE' => '🔑',
                    'ADMIN_PASSWORD_RESET' => '🔑',
                    'BACKDATED_REQUEST_AT' => '⚠️',
                    'UPDATE' => '✏️',
                    'DELETE' => '🗑️'
                ];
                $icon = $iconMap[$r['action']] ?? '📝';
                ?>
                <div class="activity-item">
                    <div class="activity-icon"><?= $icon ?></div>
                    <div class="activity-content">
                        <div class="activity-action"><?= strtoupper($r['action']) ?></div>
                        <div class="activity-meta">on <strong><?= htmlspecialchars($r['table_name']) ?></strong></div>
                        <?php if (!empty($r['notes'])): ?>
                            <div class="activity-note"><?= htmlspecialchars($r['notes']) ?></div>
                        <?php endif; ?>
                        <div class="activity-time"><?= date('d M Y, h:i A', strtotime($r['change_date'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 2rem; color: #999;">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">😕</div>
                <div>No recent activity</div>
            </div>
        <?php endif; ?>
    </div>
</div>
