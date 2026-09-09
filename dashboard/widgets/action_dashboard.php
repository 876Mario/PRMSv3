<?php
if (!function_exists('dashboardActionTableSortLink')) {
    function dashboardActionTableSortLink(array $state, string $column, string $label): string
    {
        $params = $_GET;
        $prefix = $state['prefix'];
        $currentSort = (string)($state['sort_key'] ?? '');
        $currentDir = (string)($state['sort_dir'] ?? 'DESC');
        $params[$prefix . 'sort'] = $column;
        $params[$prefix . 'dir'] = $currentSort === $column && $currentDir === 'ASC' ? 'DESC' : 'ASC';
        $params[$prefix . 'page'] = 1;
        $arrow = $currentSort === $column ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
        return '<a href="?' . htmlspecialchars(http_build_query($params)) . '" class="dashboard-link-sort">' . htmlspecialchars($label) . $arrow . '</a>';
    }
}

if (!function_exists('dashboardActionBadgeClass')) {
    function dashboardActionBadgeClass(string $variant): string
    {
        return match ($variant) {
            'red' => 'dashboard-badge dashboard-badge-red',
            'amber' => 'dashboard-badge dashboard-badge-amber',
            'green' => 'dashboard-badge dashboard-badge-green',
            default => 'dashboard-badge dashboard-badge-blue',
        };
    }
}

if (!function_exists('dashboardActionButtonClass')) {
    function dashboardActionButtonClass(string $variant): string
    {
        return match ($variant) {
            'danger' => 'dashboard-pill dashboard-pill-danger',
            'primary' => 'dashboard-pill dashboard-pill-primary',
            default => 'dashboard-pill dashboard-pill-secondary',
        };
    }
}
?>

<div class="dashboard-action-shell">
    <div class="dashboard-action-header">
        <div class="dashboard-action-heading">
            <span class="dashboard-action-icon"><?= htmlspecialchars($dashboardModel['config']['icon']) ?></span>
            <div>
                <h2><?= htmlspecialchars($dashboardModel['config']['title']) ?></h2>
                <p>
                    Welcome back, <?= htmlspecialchars($dashboardModel['context']['full_name']) ?>
                    <span aria-hidden="true">•</span>
                    <?= htmlspecialchars($dashboardModel['context']['role_name']) ?>
                    <span aria-hidden="true">•</span>
                    <?= htmlspecialchars($dashboardModel['context']['today']) ?>
                </p>
            </div>
        </div>
        <div class="dashboard-action-subtitle"><?= htmlspecialchars($dashboardModel['config']['description']) ?></div>
    </div>

    <div class="dashboard-summary-grid">
        <?php foreach ($dashboardModel['summary_cards'] as $card): ?>
            <div class="dashboard-summary-card dashboard-summary-<?= htmlspecialchars($card['variant']) ?>">
                <div class="dashboard-summary-label"><?= htmlspecialchars($card['label']) ?></div>
                <div class="dashboard-summary-value"><?= (int)$card['value'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3><?= htmlspecialchars($dashboardModel['config']['quick_links_title']) ?></h3>
        </div>
        <div class="dashboard-quick-links">
            <?php foreach ($dashboardModel['config']['quick_links'] as $link): ?>
                <a class="<?= dashboardActionButtonClass($link['variant']) ?>" href="<?= htmlspecialchars($link['href']) ?>">
                    <?= htmlspecialchars($link['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h3><?= htmlspecialchars($dashboardModel['config']['primary_widget_title']) ?> (<?= (int)$dashboardModel['action_queue']['total'] ?>)</h3>
        </div>

        <?php $queueState = $dashboardModel['action_queue']['state']; ?>
        <form method="get" class="dashboard-filter-grid">
            <?php foreach ($_GET as $key => $value): ?>
                <?php if (strpos((string)$key, $queueState['prefix']) !== 0): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars(is_array($value) ? json_encode($value) : (string)$value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="text" name="<?= htmlspecialchars($queueState['prefix'] . 'q') ?>" value="<?= htmlspecialchars($queueState['search']) ?>" placeholder="Search reference, requestor, stage or action">
            <select name="<?= htmlspecialchars($queueState['prefix'] . 'risk') ?>">
                <option value="">All risks</option>
                <option value="critical" <?= $queueState['risk'] === 'critical' ? 'selected' : '' ?>>Critical</option>
                <option value="overdue" <?= $queueState['risk'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="warning" <?= $queueState['risk'] === 'warning' ? 'selected' : '' ?>>Near SLA</option>
                <option value="normal" <?= $queueState['risk'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            </select>
            <select name="<?= htmlspecialchars($queueState['prefix'] . 'priority') ?>">
                <option value="">All priorities</option>
                <option value="urgent" <?= $queueState['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                <option value="high" <?= $queueState['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                <option value="normal" <?= $queueState['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            </select>
            <select name="<?= htmlspecialchars($queueState['prefix'] . 'category') ?>">
                <option value="">All sections</option>
                <?php foreach ($dashboardModel['action_queue']['workflow_summary'] as $summary): ?>
                    <option value="<?= htmlspecialchars($summary['label']) ?>" <?= $queueState['category'] === $summary['label'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($summary['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="<?= htmlspecialchars($queueState['prefix'] . 'per_page') ?>">
                <?php foreach ($queueState['page_size_options'] as $pageSize): ?>
                    <option value="<?= (int)$pageSize ?>" <?= $queueState['per_page'] === $pageSize ? 'selected' : '' ?>><?= (int)$pageSize ?> per page</option>
                <?php endforeach; ?>
            </select>
            <div class="dashboard-filter-actions">
                <button type="submit" class="dashboard-pill dashboard-pill-primary">Apply</button>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="dashboard-pill dashboard-pill-secondary">Reset</a>
            </div>
        </form>

        <div class="dashboard-chip-row">
            <?php foreach ($dashboardModel['action_queue']['workflow_summary'] as $summary): ?>
                <a class="dashboard-summary-chip" href="<?= htmlspecialchars($summary['href']) ?>">
                    <?= htmlspecialchars($summary['label']) ?> (<?= (int)$summary['count'] ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <?php renderShowingInfo($queueState['page'], $queueState['per_page'], $dashboardModel['action_queue']['total']); ?>

        <div class="dashboard-table-wrap">
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th><?= dashboardActionTableSortLink($queueState, 'request_number', 'Reference No.') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'requestor_name', 'Requestor') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'department', 'Department') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'current_stage', 'Current Stage') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'action_required', 'Action Required') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'age_days', 'Age') ?></th>
                        <th><?= dashboardActionTableSortLink($queueState, 'priority_rank', 'Priority') ?></th>
                        <th>Badges</th>
                        <th>Links</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($dashboardModel['action_queue']['rows'])): ?>
                    <tr><td colspan="9" class="dashboard-empty"><?= htmlspecialchars($dashboardModel['config']['empty_text']) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($dashboardModel['action_queue']['rows'] as $row): ?>
                        <tr class="dashboard-clickable-row" onclick="window.location.href='<?= htmlspecialchars($row['action_url']) ?>'">
                            <td><?= htmlspecialchars($row['request_number']) ?></td>
                            <td><?= htmlspecialchars($row['requestor_name']) ?></td>
                            <td><?= htmlspecialchars($row['department']) ?></td>
                            <td><?= htmlspecialchars($row['current_stage']) ?></td>
                            <td><?= htmlspecialchars($row['action_required']) ?></td>
                            <td><?= (int)$row['age_days'] ?> day<?= (int)$row['age_days'] === 1 ? '' : 's' ?></td>
                            <td>
                                <span class="<?= dashboardActionBadgeClass($row['priority'] === 'urgent' ? 'red' : ($row['priority'] === 'high' ? 'amber' : 'green')) ?>">
                                    <?= htmlspecialchars(strtoupper($row['priority'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="dashboard-badge-stack">
                                    <?php foreach ($row['badges'] as $badge): ?>
                                        <span class="<?= dashboardActionBadgeClass($badge['variant']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="dashboard-link-group" onclick="event.stopPropagation()">
                                <a class="dashboard-pill dashboard-pill-secondary" href="<?= htmlspecialchars($row['view_url']) ?>">View Record</a>
                                <a class="dashboard-pill dashboard-pill-primary" href="<?= htmlspecialchars($row['action_url']) ?>">Take Action</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php renderPagination($dashboardModel['action_queue']['total'], $queueState['per_page'], $queueState['page'], $dashboardModel['action_queue']['pagination_params'], $queueState['prefix'] . 'page'); ?>
    </div>

    <div class="dashboard-two-column">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><?= htmlspecialchars($dashboardModel['config']['notification_title']) ?> (<?= (int)$dashboardModel['notifications']['unread_count'] ?>)</h3>
            </div>
            <?php if (empty($dashboardModel['notifications']['rows'])): ?>
                <div class="dashboard-empty">No recent notifications.</div>
            <?php else: ?>
                <div class="dashboard-notification-list">
                    <?php foreach ($dashboardModel['notifications']['rows'] as $notification): ?>
                        <div class="dashboard-notification-item">
                            <div class="dashboard-notification-meta">
                                <strong><?= htmlspecialchars($notification['title']) ?></strong>
                                <span><?= htmlspecialchars((string)($notification['priority'] ?? 'normal')) ?></span>
                            </div>
                            <?php if (!empty($notification['body'])): ?>
                                <p><?= htmlspecialchars($notification['body']) ?></p>
                            <?php endif; ?>
                            <div class="dashboard-link-group">
                                <?php if (!empty($notification['view_url'])): ?>
                                    <a class="dashboard-pill dashboard-pill-secondary" href="<?= htmlspecialchars($notification['view_url']) ?>">View Record</a>
                                <?php endif; ?>
                                <?php if (!empty($notification['action_url'])): ?>
                                    <a class="dashboard-pill dashboard-pill-primary" href="<?= htmlspecialchars($notification['action_url']) ?>">Take Action</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><?= htmlspecialchars($dashboardModel['config']['summary_title']) ?></h3>
            </div>
            <div class="dashboard-chip-column">
                <?php foreach ($dashboardModel['action_queue']['workflow_summary'] as $summary): ?>
                    <a class="dashboard-summary-chip" href="<?= htmlspecialchars($summary['href']) ?>">
                        <span><?= htmlspecialchars($summary['label']) ?></span>
                        <strong><?= (int)$summary['count'] ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($dashboardModel['monitoring_queue'])): ?>
        <?php $monitorState = $dashboardModel['monitoring_queue']['state']; ?>
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><?= htmlspecialchars($dashboardModel['monitoring_queue']['title']) ?> (<?= (int)$dashboardModel['monitoring_queue']['total'] ?>)</h3>
            </div>
            <form method="get" class="dashboard-filter-grid">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (strpos((string)$key, $monitorState['prefix']) !== 0): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars(is_array($value) ? json_encode($value) : (string)$value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="text" name="<?= htmlspecialchars($monitorState['prefix'] . 'q') ?>" value="<?= htmlspecialchars($monitorState['search']) ?>" placeholder="Search officer">
                <select name="<?= htmlspecialchars($monitorState['prefix'] . 'per_page') ?>">
                    <?php foreach ($monitorState['page_size_options'] as $pageSize): ?>
                        <option value="<?= (int)$pageSize ?>" <?= $monitorState['per_page'] === $pageSize ? 'selected' : '' ?>><?= (int)$pageSize ?> per page</option>
                    <?php endforeach; ?>
                </select>
                <div class="dashboard-filter-actions">
                    <button type="submit" class="dashboard-pill dashboard-pill-primary">Apply</button>
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="dashboard-pill dashboard-pill-secondary">Reset</a>
                </div>
            </form>
            <p class="dashboard-muted-note">Workload is branch-based because workflow records are not assigned to a named officer in the request table.</p>
            <?php renderShowingInfo($monitorState['page'], $monitorState['per_page'], $dashboardModel['monitoring_queue']['total']); ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th><?= dashboardActionTableSortLink($monitorState, 'officer', 'Officer') ?></th>
                            <th><?= dashboardActionTableSortLink($monitorState, 'pending_items', 'Pending Items') ?></th>
                            <th><?= dashboardActionTableSortLink($monitorState, 'overdue_count', 'Overdue') ?></th>
                            <th><?= dashboardActionTableSortLink($monitorState, 'near_sla', 'Near SLA Breach') ?></th>
                            <th><?= dashboardActionTableSortLink($monitorState, 'escalations', 'Escalations') ?></th>
                            <th><?= dashboardActionTableSortLink($monitorState, 'requires_intervention', 'Requires Intervention') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dashboardModel['monitoring_queue']['rows'])): ?>
                        <tr><td colspan="6" class="dashboard-empty">No officers found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dashboardModel['monitoring_queue']['rows'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['officer']) ?></td>
                                <td><?= (int)$row['pending_items'] ?></td>
                                <td><?= (int)$row['overdue_count'] ?></td>
                                <td><?= (int)$row['near_sla'] ?></td>
                                <td><?= (int)$row['escalations'] ?></td>
                                <td>
                                    <span class="<?= dashboardActionBadgeClass($row['requires_intervention'] === 'Yes' ? 'red' : 'green') ?>">
                                        <?= htmlspecialchars($row['requires_intervention']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination($dashboardModel['monitoring_queue']['total'], $monitorState['per_page'], $monitorState['page'], $dashboardModel['monitoring_queue']['pagination_params'], $monitorState['prefix'] . 'page'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($dashboardModel['active_requests'])): ?>
        <?php $requestsState = $dashboardModel['active_requests']['state']; ?>
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <h3><?= htmlspecialchars($dashboardModel['active_requests']['title']) ?> (<?= (int)$dashboardModel['active_requests']['total'] ?>)</h3>
            </div>
            <form method="get" class="dashboard-filter-grid">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (strpos((string)$key, $requestsState['prefix']) !== 0): ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$key) ?>" value="<?= htmlspecialchars(is_array($value) ? json_encode($value) : (string)$value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="text" name="<?= htmlspecialchars($requestsState['prefix'] . 'q') ?>" value="<?= htmlspecialchars($requestsState['search']) ?>" placeholder="Search request number or status">
                <select name="<?= htmlspecialchars($requestsState['prefix'] . 'category') ?>">
                    <option value="">All statuses</option>
                    <?php foreach ($dashboardModel['active_requests']['workflow_summary'] as $summary): ?>
                        <option value="<?= htmlspecialchars((string)($summary['filter_value'] ?? $summary['label'])) ?>" <?= $requestsState['category'] === (string)($summary['filter_value'] ?? $summary['label']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($summary['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="<?= htmlspecialchars($requestsState['prefix'] . 'per_page') ?>">
                    <?php foreach ($requestsState['page_size_options'] as $pageSize): ?>
                        <option value="<?= (int)$pageSize ?>" <?= $requestsState['per_page'] === $pageSize ? 'selected' : '' ?>><?= (int)$pageSize ?> per page</option>
                    <?php endforeach; ?>
                </select>
                <div class="dashboard-filter-actions">
                    <button type="submit" class="dashboard-pill dashboard-pill-primary">Apply</button>
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="dashboard-pill dashboard-pill-secondary">Reset</a>
                </div>
            </form>
            <div class="dashboard-chip-row">
                <?php foreach ($dashboardModel['active_requests']['workflow_summary'] as $summary): ?>
                    <a class="dashboard-summary-chip" href="<?= htmlspecialchars($summary['href']) ?>">
                        <?= htmlspecialchars($summary['label']) ?> (<?= (int)$summary['count'] ?>)
                    </a>
                <?php endforeach; ?>
            </div>
            <?php renderShowingInfo($requestsState['page'], $requestsState['per_page'], $dashboardModel['active_requests']['total']); ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th><?= dashboardActionTableSortLink($requestsState, 'request_number', 'Request Number') ?></th>
                            <th><?= dashboardActionTableSortLink($requestsState, 'status', 'Status') ?></th>
                            <th><?= dashboardActionTableSortLink($requestsState, 'current_approver', 'Current Approver') ?></th>
                            <th><?= dashboardActionTableSortLink($requestsState, 'days_open', 'Days Open') ?></th>
                            <th><?= dashboardActionTableSortLink($requestsState, 'action_needed', 'Action Needed') ?></th>
                            <th>Badges</th>
                            <th>Links</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dashboardModel['active_requests']['rows'])): ?>
                        <tr><td colspan="7" class="dashboard-empty">No active requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dashboardModel['active_requests']['rows'] as $row): ?>
                            <tr class="dashboard-clickable-row" onclick="window.location.href='<?= htmlspecialchars($row['action_url']) ?>'">
                                <td><?= htmlspecialchars($row['request_number']) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                                <td><?= htmlspecialchars($row['current_approver']) ?></td>
                                <td><?= (int)$row['days_open'] ?></td>
                                <td><?= htmlspecialchars($row['action_needed']) ?></td>
                                <td>
                                    <div class="dashboard-badge-stack">
                                        <?php foreach ($row['badges'] as $badge): ?>
                                            <span class="<?= dashboardActionBadgeClass($badge['variant']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="dashboard-link-group" onclick="event.stopPropagation()">
                                    <a class="dashboard-pill dashboard-pill-secondary" href="<?= htmlspecialchars($row['view_url']) ?>">View Record</a>
                                    <a class="dashboard-pill dashboard-pill-primary" href="<?= htmlspecialchars($row['action_url']) ?>">Take Action</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination($dashboardModel['active_requests']['total'], $requestsState['per_page'], $requestsState['page'], $dashboardModel['active_requests']['pagination_params'], $requestsState['prefix'] . 'page'); ?>
        </div>
    <?php endif; ?>
</div>
