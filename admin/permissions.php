<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/column_modules.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/ColumnPreferenceService.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/ColumnManagerWidget.php';

/* ─── Only Admin / SuperAdmin may manage permissions ────────────────── */
$canManage = in_array($_SESSION['role_name'] ?? '', ['Admin', 'SuperAdmin'], true);

/* ─── POST handlers ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        modalPop('Access Denied', 'You do not have permission to manage permissions.', '/admin/permissions.php', 'error');
        exit;
    }

    $action = $_POST['action'] ?? '';

    /* ── Create a new permission ── */
    if ($action === 'create') {
        $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['name'] ?? '')));
        $desc = trim($_POST['description'] ?? '');

        if ($name === '') {
            modalPop('Validation Error', 'Permission name is required.', '/admin/permissions.php', 'error');
            exit;
        }

        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                modalPop('Duplicate', "A permission named '{$name}' already exists.", '/admin/permissions.php', 'error');
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO permissions (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $desc ?: null]);
            $newId = (int)$pdo->lastInsertId();

            try { logAudit($pdo, 'permissions', $newId, 'CREATE', "Permission '{$name}' created"); } catch (Throwable $e) { error_log('logAudit error: ' . $e->getMessage()); }

            pop("Permission '{$name}' created successfully.", '/admin/permissions.php', 1200, 'success');
        } catch (Throwable $e) {
            error_log('permissions.php create error: ' . $e->getMessage());
            modalPop('Error', 'Failed to create permission. Please try again.', '/admin/permissions.php', 'error');
        }
        exit;
    }

    /* ── Delete a permission ── */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            modalPop('Error', 'Invalid permission ID.', '/admin/permissions.php', 'error');
            exit;
        }

        try {
            $nameStmt = $pdo->prepare("SELECT name FROM permissions WHERE id = ?");
            $nameStmt->execute([$id]);
            $permName = $nameStmt->fetchColumn();

            if (!$permName) {
                modalPop('Error', 'Permission not found.', '/admin/permissions.php', 'error');
                exit;
            }

            /* Remove role and user associations first */
            $pdo->prepare("DELETE FROM role_permissions WHERE permission_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM user_permissions WHERE permission_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM permissions WHERE id = ?")->execute([$id]);

            try { logAudit($pdo, 'permissions', $id, 'DELETE', "Permission '{$permName}' deleted"); } catch (Throwable $e) { error_log('logAudit error: ' . $e->getMessage()); }

            pop("Permission '{$permName}' deleted.", '/admin/permissions.php', 1200, 'success');
        } catch (Throwable $e) {
            error_log('permissions.php delete error: ' . $e->getMessage());
            modalPop('Error', 'Failed to delete permission. Please try again.', '/admin/permissions.php', 'error');
        }
        exit;
    }

    /* ── Update permission description ── */
    if ($action === 'update_desc') {
        $id   = (int)($_POST['id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid ID.']);
            exit;
        }

        try {
            $pdo->prepare("UPDATE permissions SET description = ? WHERE id = ?")
                ->execute([$desc ?: null, $id]);

            try { logAudit($pdo, 'permissions', $id, 'UPDATE', "Description updated"); } catch (Throwable $e) { error_log('logAudit error: ' . $e->getMessage()); }
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            error_log('permissions.php update_desc error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
        }
        exit;
    }

    /* ── Toggle role assignment ── */
    if ($action === 'toggle_role') {
        $permId = (int)($_POST['perm_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);

        if ($permId <= 0 || $roleId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid IDs.']);
            exit;
        }

        try {
            /* Check current state */
            $chk = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
            $chk->execute([$roleId, $permId]);
            $exists = (bool)$chk->fetchColumn();

            if ($exists) {
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?")
                    ->execute([$roleId, $permId]);
                $granted = false;
            } else {
                $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                    ->execute([$roleId, $permId]);
                $granted = true;
            }

            try {
                logAudit($pdo, 'role_permissions', $permId, 'TOGGLE',
                         "Role #{$roleId} " . ($granted ? 'granted' : 'revoked') . " permission #{$permId}");
            } catch (Throwable $e) { error_log('logAudit error: ' . $e->getMessage()); }

            echo json_encode(['ok' => true, 'granted' => $granted]);
        } catch (Throwable $e) {
            error_log('permissions.php toggle_role error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
    exit;
}

/* ─── Pagination & search ────────────────────────────────────────────── */
$search = trim($_GET['search'] ?? '');

$searchWhere  = '';
$searchParams = [];
if ($search !== '') {
    $searchWhere  = ' WHERE name LIKE ? OR description LIKE ?';
    $searchParams = ["%$search%", "%$search%"];
}

$totalPerms    = 0;
$permissions   = [];
$roles         = [];
$rpMap         = [];
$overrideStats = [];
$pageError     = null;
$columnState   = [];
$visibleColumns = [];
$visibleKeys = [];
$columnOrder = [];

try {
    $roles = $pdo->query("
        SELECT id, name
        FROM roles
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $columnState = (new ColumnPreferenceService($pdo))->resolveState(
        (int) ($_SESSION['user_id'] ?? 0),
        getColumnPreferenceModuleConfig('admin_permissions', $pdo)
    );
    $visibleColumns = $columnState['visible_columns'];
    $visibleKeys = $columnState['visible_keys'];
    $columnOrder = $columnState['column_order'];
} catch (Throwable $e) {
    $pageError = 'Permission data is temporarily unavailable. Please try again or contact your administrator.';
    error_log('admin/permissions.php setup error: ' . $e->getMessage());
}

['perPage' => $perPage, 'page' => $page, 'offset' => $offset] = getPaginationParams(
    (int)($columnState['page_size'] ?? 25)
);

try {
    /* ─── Total permission count ─────────────────────────────────────────── */
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM permissions" . $searchWhere);
    $cntStmt->execute($searchParams);
    $totalPerms = (int)$cntStmt->fetchColumn();

    /* ─── Paginated permission list ─────────────────────────────────────── */
    /* Use direct integer interpolation for LIMIT/OFFSET to avoid driver
       binding issues; values are already validated integers. */
    $limitSql = ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $permStmt = $pdo->prepare(
        "SELECT id, name, description FROM permissions" .
        $searchWhere .
        " ORDER BY name" . $limitSql
    );
    $permStmt->execute($searchParams);
    $permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);

    $permIds = array_column($permissions, 'id');

    /* ─── Build role_permissions map for current-page permissions ────────── */
    if (!empty($permIds)) {
        $holders = implode(',', array_fill(0, count($permIds), '?'));
        $rpStmt  = $pdo->prepare(
            "SELECT role_id, permission_id FROM role_permissions WHERE permission_id IN ($holders)"
        );
        $rpStmt->execute($permIds);
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rpMap[$row['permission_id']][$row['role_id']] = true;
        }
    }

    /* ─── Per-permission override stats for current-page permissions ─────── */
    if (!empty($permIds)) {
        $holders = implode(',', array_fill(0, count($permIds), '?'));
        $oStmt   = $pdo->prepare(
            "SELECT permission_id, COUNT(*) AS cnt
             FROM user_permissions
             WHERE is_granted = 1 AND permission_id IN ($holders)
             GROUP BY permission_id"
        );
        $oStmt->execute($permIds);
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $overrideStats[$r['permission_id']] = (int)$r['cnt'];
        }
    }
} catch (Throwable $e) {
    $pageError = 'Permission data is temporarily unavailable. Please try again or contact your administrator.';
    error_log('admin/permissions.php load error: ' . $e->getMessage());
}

$roleColumns = [];
foreach ($roles as $role) {
    $roleColumns['role_' . (int)$role['id']] = $role;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container-fluid">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-key me-2"></i>Permissions</h2>
            <p class="text-muted small mb-0">
                Create permissions and assign them to roles. Individual user overrides can be managed from the
                <a href="/users/list.php">Users</a> page.
            </p>
        </div>
        <?php if ($canManage): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPermModal">
            <i class="bi bi-plus-lg me-1"></i>New Permission
        </button>
        <?php endif; ?>
    </div>

    <?php if ($pageError !== null): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div><?= htmlspecialchars($pageError) ?></div>
    </div>
    <?php endif; ?>

    <!-- Info alert -->
    <div class="alert alert-info d-flex gap-2 align-items-start mb-4">
        <i class="bi bi-info-circle-fill fs-5 mt-1"></i>
        <div>
            <strong>How it works:</strong>
            Tick a cell to grant a role that permission. Untick to revoke it. Changes save instantly.
            User-level overrides (granted or denied individually) always take precedence over role defaults.
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <form method="get" class="row g-2 flex-grow-1 align-items-end mb-0">
            <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            <div class="col-auto flex-grow-1">
                <input type="text" name="search" class="form-control"
                       placeholder="&#128269; Search permissions…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary" type="submit">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                <?php if ($search !== ''): ?>
                <a href="?" class="btn btn-outline-danger ms-1">
                    <i class="bi bi-x-lg me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-muted small">Rows:</label>
            <select id="perPageSelect" class="form-select form-select-sm" style="width:auto">
                <?php foreach (($columnState['page_size_options'] ?? [10, 25, 50, 100]) as $size): ?>
                <option value="<?= (int)$size ?>" <?= $perPage === (int)$size ? 'selected' : '' ?>><?= (int)$size ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($pageError === null): ?>
                <?php ColumnManagerWidget::render([
                    'module' => $columnState['module'] ?? 'admin_permissions',
                    'title' => $columnState['title'] ?? 'Manage Permission Columns',
                    'description' => $columnState['description'] ?? '',
                    'button_label' => $columnState['button_label'] ?? 'Manage Columns',
                    'button_class' => 'btn btn-sm btn-outline-secondary',
                    'columns_by_key' => $columnState['columns_by_key'] ?? [],
                    'column_order' => $columnOrder,
                    'visible_keys' => $visibleKeys,
                    'save_endpoint' => $columnState['save_endpoint'] ?? '/api/column_preferences.php',
                    'current_sort_column' => $columnState['sort_column'] ?? '',
                    'current_sort_direction' => $columnState['sort_direction'] ?? 'ASC',
                    'current_page_size' => $perPage,
                    'per_page_selector_id' => 'perPageSelect',
                ]); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pageError === null && $totalPerms === 0 && $search === ''): ?>
    <div class="alert alert-warning">No permissions found. Create one to get started.</div>
    <?php elseif ($pageError === null && $totalPerms === 0): ?>
    <div class="alert alert-warning">No permissions match "<strong><?= htmlspecialchars($search) ?></strong>".</div>
    <?php elseif ($pageError === null): ?>

    <!-- Role×Permission Matrix -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-grid-3x3-gap me-2"></i>Role Assignment Matrix</span>
            <span class="badge bg-secondary"><?= $totalPerms ?> permissions &nbsp;·&nbsp; <?= count($roles) ?> roles</span>
        </div>
        <div class="card-body p-0">
            <div class="permissions-matrix-wrap">
                <table class="table table-bordered table-sm mb-0 align-middle permissions-matrix" id="matrixTable">
                    <thead  class="table-dark">
                        <tr>
                            <?php foreach ($visibleColumns as $column): ?>
                                <?php
                                $style = 'min-width:110px;';
                                if ($column['key'] === 'permission') {
                                    $style = 'min-width:220px;';
                                } elseif ($column['key'] === 'description') {
                                    $style = 'min-width:260px;';
                                } elseif (in_array($column['key'], ['user_overrides', 'actions'], true)) {
                                    $style = 'min-width:80px;';
                                }
                                ?>
                                <th class="<?= ($column['align'] ?? '') === 'center' ? 'text-center' : '' ?>" style="<?= $style ?> font-size:0.8rem;">
                                    <?= htmlspecialchars($column['label']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions as $perm): ?>
                        <tr data-perm-id="<?= $perm['id'] ?>">
                            <?php foreach ($visibleColumns as $column): ?>
                                <?php
                                $columnKey = $column['key'];
                                if ($columnKey === 'permission'):
                                ?>
                                    <td><code class="text-primary fw-semibold"><?= htmlspecialchars($perm['name']) ?></code></td>
                                <?php elseif ($columnKey === 'description'): ?>
                                    <td>
                                        <?php if (!empty($perm['description'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($perm['description']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php elseif (isset($roleColumns[$columnKey])): ?>
                                    <?php $role = $roleColumns[$columnKey]; ?>
                                    <?php $checked = !empty($rpMap[$perm['id']][$role['id']]); ?>
                                    <td class="text-center">
                                        <?php if ($canManage): ?>
                                            <div class="form-check form-switch d-flex justify-content-center m-0">
                                                <input class="form-check-input role-toggle" type="checkbox"
                                                       style="cursor:pointer;"
                                                       data-perm-id="<?= $perm['id'] ?>"
                                                       data-role-id="<?= $role['id'] ?>"
                                                       <?= $checked ? 'checked' : '' ?>>
                                            </div>
                                        <?php else: ?>
                                            <?php if ($checked): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash text-muted"></i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php elseif ($columnKey === 'user_overrides'): ?>
                                    <td class="text-center">
                                        <?php $uc = $overrideStats[$perm['id']] ?? 0; ?>
                                        <?php if ($uc > 0): ?>
                                            <span class="badge bg-info text-dark"><?= $uc ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php elseif ($columnKey === 'actions'): ?>
                                    <td class="text-center">
                                        <?php if ($canManage): ?>
                                            <button class="btn btn-sm btn-outline-danger delete-perm"
                                                    data-perm-id="<?= $perm['id'] ?>"
                                                    data-perm-name="<?= htmlspecialchars($perm['name']) ?>"
                                                    title="Delete permission">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /.card -->

    <div class="mt-3">
        <?php renderShowingInfo($page, $perPage, $totalPerms); ?>
        <?php renderPagination($totalPerms, $perPage, $page, array_filter(['search' => $search, 'per_page' => $perPage])); ?>
    </div>

    <?php endif; ?>

</div><!-- /.container-fluid -->

<?php if ($canManage): ?>
<!-- Create Permission Modal -->
<div class="modal fade" id="createPermModal" tabindex="-1" aria-labelledby="createPermModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="createPermModalLabel"><i class="bi bi-plus-circle me-2"></i>New Permission</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Permission Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               placeholder="e.g. export_reports"
                               pattern="[a-zA-Z0-9_]+"
                               title="Letters, numbers and underscores only"
                               required>
                        <div class="form-text">Lowercase letters, digits, and underscores only (auto-sanitised).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" name="description" class="form-control"
                               placeholder="e.g. Allow exporting report files">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Permission Form (hidden, submitted via JS) -->
<form method="post" id="deletePermForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deletePermId">
</form>
<?php endif; ?>

<script>
(function () {
    'use strict';

    /* ── Role toggle (AJAX) ─────────────────────────────────── */
    document.querySelectorAll('.role-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var permId = this.dataset.permId;
            var roleId = this.dataset.roleId;
            var self   = this;
            self.disabled = true;

            var fd = new FormData();
            fd.append('action',  'toggle_role');
            fd.append('perm_id', permId);
            fd.append('role_id', roleId);

            fetch('', {method: 'POST', body: fd})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        alert('Error: ' + data.message);
                        self.checked = !self.checked; // revert
                    }
                    self.disabled = false;
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                    self.checked = !self.checked;
                    self.disabled = false;
                });
        });
    });

    /* ── Delete permission ──────────────────────────────────── */
    document.querySelectorAll('.delete-perm').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var permName = this.dataset.permName;
            var permId   = this.dataset.permId;

            if (!confirm('Delete permission "' + permName + '"?\n\nThis will also remove it from all roles and user overrides. This cannot be undone.')) {
                return;
            }

            document.getElementById('deletePermId').value = permId;
            document.getElementById('deletePermForm').submit();
        });
    });
})();
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
