<?php
/**
 * Inventory → Asset Item Types
 * Manage the four group registries (OF, OM, E, ITEM) and their specific types.
 * Supports inline add and deactivate/reactivate.
 */
$REQUIRE_PERMISSION = 'manage_inventory_items';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryService.php';

/* ── Table existence guard ──────────────────────────────────────────────── */
function aitTableExists(PDO $pdo): bool
{
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_asset_item_types'"
    );
    $s->execute();
    return (int) $s->fetchColumn() > 0;
}

if (!aitTableExists($pdo)) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
    ?>
    <div class="container py-5">
        <div class="card border-warning shadow">
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="bi bi-exclamation-triangle"></i> Migration Required
            </div>
            <div class="card-body">
                <p>The asset item type tables have not been created yet. Run the migration:</p>
                <pre class="bg-light p-2 rounded">mysql -u USERNAME -p DATABASE_NAME &lt; migrations/2026_07_24_asset_item_types.sql</pre>
                <a href="/inventory/dashboard.php" class="btn btn-primary mt-2">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
    <?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit;
}

$errors   = [];
$success  = '';

/* ── POST handling ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Add a new type to a group */
    if ($action === 'add_type') {
        $groupId  = (int) ($_POST['group_id'] ?? 0);
        $typeName = trim($_POST['type_name'] ?? '');

        if ($groupId <= 0) {
            $errors[] = 'Please select a group.';
        } elseif ($typeName === '') {
            $errors[] = 'Type name is required.';
        } else {
            try {
                $pdo->beginTransaction();

                /* Determine next code number within the group */
                $grpRow = $pdo->prepare(
                    "SELECT group_code FROM inv_asset_item_type_groups WHERE group_id = ? AND is_active = 1"
                );
                $grpRow->execute([$groupId]);
                $grpCode = $grpRow->fetchColumn();
                if (!$grpCode) throw new Exception('Invalid group selected.');

                /* Find the highest existing numeric suffix for this group */
                $maxStmt = $pdo->prepare(
                    "SELECT type_code FROM inv_asset_item_types WHERE group_id = ? ORDER BY sort_order DESC, item_type_id DESC LIMIT 1"
                );
                if (dbSupportsSelectForUpdate($pdo)) {
                    $maxStmt = $pdo->prepare(
                        "SELECT type_code FROM inv_asset_item_types WHERE group_id = ? ORDER BY sort_order DESC, item_type_id DESC LIMIT 1 FOR UPDATE"
                    );
                }
                $maxStmt->execute([$groupId]);
                $lastCode = $maxStmt->fetchColumn() ?: '';
                preg_match('/(\d+)$/', $lastCode, $m);
                $nextNum = (int) ($m[1] ?? 0) + 1;

                $newCode = $grpCode . ' ' . $nextNum;

                /* Ensure uniqueness (edge case) */
                $dupChk = $pdo->prepare(
                    "SELECT COUNT(*) FROM inv_asset_item_types WHERE type_code = ?"
                );
                $dupChk->execute([$newCode]);
                while ((int) $dupChk->fetchColumn() > 0) {
                    $newCode = $grpCode . ' ' . (++$nextNum);
                    $dupChk->execute([$newCode]);
                }

                $ins = $pdo->prepare(
                    "INSERT INTO inv_asset_item_types (group_id, type_code, type_name, sort_order)
                     VALUES (?, ?, ?, ?)"
                );
                $ins->execute([$groupId, $newCode, strtoupper($typeName), $nextNum]);
                $pdo->commit();
                $success = "Added <strong>" . htmlspecialchars($newCode) . " — " . htmlspecialchars(strtoupper($typeName)) . "</strong>.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }

    /* Add a new group */
    if ($action === 'add_group') {
        $groupCode = strtoupper(trim($_POST['group_code'] ?? ''));
        $groupName = trim($_POST['group_name'] ?? '');
        $groupDesc = trim($_POST['group_description'] ?? '');

        if ($groupCode === '') {
            $errors[] = 'Group code is required.';
        } elseif ($groupName === '') {
            $errors[] = 'Group name is required.';
        } else {
            try {
                $dupChk = $pdo->prepare(
                    "SELECT COUNT(*) FROM inv_asset_item_type_groups WHERE group_code = ?"
                );
                $dupChk->execute([$groupCode]);
                if ((int) $dupChk->fetchColumn() > 0) {
                    $errors[] = "Group code '$groupCode' already exists.";
                } else {
                    $pdo->beginTransaction();
                    $maxOrd = nextSortOrderValue($pdo, 'inv_asset_item_type_groups', 'group_id');
                    $ins = $pdo->prepare(
                        "INSERT INTO inv_asset_item_type_groups (group_code, group_name, description, sort_order)
                         VALUES (?, ?, ?, ?)"
                    );
                    $ins->execute([$groupCode, $groupName, $groupDesc ?: null, $maxOrd]);
                    $pdo->commit();
                    $success = "Group <strong>" . htmlspecialchars($groupCode) . " — " . htmlspecialchars($groupName) . "</strong> created.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }

    /* Toggle active status on a type */
    if ($action === 'toggle_type') {
        $typeId = (int) ($_POST['item_type_id'] ?? 0);
        if ($typeId > 0) {
            $pdo->prepare(
                "UPDATE inv_asset_item_types SET is_active = 1 - is_active WHERE item_type_id = ?"
            )->execute([$typeId]);
        }
    }

    /* Toggle active status on a group */
    if ($action === 'toggle_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            $pdo->prepare(
                "UPDATE inv_asset_item_type_groups SET is_active = 1 - is_active WHERE group_id = ?"
            )->execute([$groupId]);
        }
    }

    /* Redirect-after-post to avoid duplicate submissions */
    if (empty($errors)) {
        $qs = $success ? '?success=' . urlencode($success) : '';
        header('Location: /inventory/asset-item-types/list.php' . $qs);
        exit;
    }
}

/* ── GET — success flash from redirect ──────────────────────────────────── */
if (!$success && isset($_GET['success'])) {
    $success = $_GET['success']; // already HTML from previous urlencode
}

/* ── Load data ──────────────────────────────────────────────────────────── */
$showInactive = isset($_GET['show_inactive']);

$groups = getAssetItemTypeGroups($pdo, !$showInactive);

// Load all types keyed by group_id
$allTypes = getAssetItemTypes($pdo, null, !$showInactive);
$typesByGroup = [];
foreach ($allTypes as $t) {
    $typesByGroup[$t['group_id']][] = $t;
}

// Active groups for the add-type dropdown (always active)
$activeGroups = getAssetItemTypeGroups($pdo, true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-tags me-2"></i>Asset Item Types</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="/inventory/dashboard.php">Inventory</a></li>
                    <li class="breadcrumb-item active">Asset Item Types</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="?<?= $showInactive ? '' : 'show_inactive=1' ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-eye<?= $showInactive ? '-slash' : '' ?>"></i>
                <?= $showInactive ? 'Hide Inactive' : 'Show Inactive' ?>
            </a>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                <i class="bi bi-plus-circle"></i> Add Type
            </button>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
                <i class="bi bi-folder-plus"></i> New Group
            </button>
        </div>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Groups and their types -->
    <?php if (empty($groups)): ?>
        <div class="alert alert-info">No groups found. Run the migration or add a new group above.</div>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header d-flex align-items-center justify-content-between
                <?= $group['is_active'] ? 'bg-dark text-white' : 'bg-secondary text-white' ?>">
                <span>
                    <i class="bi bi-folder me-1"></i>
                    <strong><?= htmlspecialchars($group['group_code']) ?></strong>
                    &mdash; <?= htmlspecialchars($group['group_name']) ?>
                    <?php if (!$group['is_active']): ?>
                        <span class="badge bg-warning text-dark ms-2">Inactive</span>
                    <?php endif; ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_group">
                    <input type="hidden" name="group_id" value="<?= $group['group_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-light">
                        <?= $group['is_active'] ? '<i class="bi bi-toggle-on"></i> Deactivate' : '<i class="bi bi-toggle-off"></i> Activate' ?>
                    </button>
                </form>
            </div>
            <?php if ($group['description']): ?>
            <div class="card-body py-2 text-muted small border-bottom">
                <?= htmlspecialchars($group['description']) ?>
            </div>
            <?php endif; ?>
            <div class="card-body p-0">
                <?php $types = $typesByGroup[$group['group_id']] ?? []; ?>
                <?php if (empty($types)): ?>
                    <div class="p-3 text-muted small fst-italic">No types in this group yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:120px">Code</th>
                                <th>Type Name</th>
                                <th style="width:100px" class="text-center">Status</th>
                                <th style="width:100px" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $t): ?>
                            <tr class="<?= $t['is_active'] ? '' : 'text-muted' ?>">
                                <td><code><?= htmlspecialchars($t['type_code']) ?></code></td>
                                <td><?= htmlspecialchars($t['type_name']) ?></td>
                                <td class="text-center">
                                    <?php if ($t['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_type">
                                        <input type="hidden" name="item_type_id" value="<?= $t['item_type_id'] ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-<?= $t['is_active'] ? 'danger' : 'success' ?> btn-sm py-0 px-2">
                                            <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div><!-- /container-fluid -->

<!-- ── Add Type Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="addTypeModal" tabindex="-1" aria-labelledby="addTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add_type">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="addTypeModalLabel"><i class="bi bi-plus-circle me-2"></i>Add Asset Item Type</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Group <span class="text-danger">*</span></label>
                    <select name="group_id" class="form-select" required>
                        <option value="">— Select group —</option>
                        <?php foreach ($activeGroups as $g): ?>
                        <option value="<?= $g['group_id'] ?>"
                            <?= ($_POST['group_id'] ?? '') == $g['group_id'] && !empty($errors) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['group_code']) ?> — <?= htmlspecialchars($g['group_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Type Name <span class="text-danger">*</span></label>
                    <input type="text" name="type_name" class="form-control"
                           placeholder="e.g. DESK (WOOD) CORNER UNIT"
                           value="<?= htmlspecialchars($_POST['type_name'] ?? '') ?>"
                           required>
                    <div class="form-text">The code will be auto-generated based on the group (e.g. OF 61).</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check2"></i> Add Type</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Add Group Modal ────────────────────────────────────────────────── -->
<div class="modal fade" id="addGroupModal" tabindex="-1" aria-labelledby="addGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add_group">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addGroupModalLabel"><i class="bi bi-folder-plus me-2"></i>New Asset Item Type Group</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Group Code <span class="text-danger">*</span></label>
                    <input type="text" name="group_code" class="form-control text-uppercase"
                           placeholder="e.g. VE" maxlength="20"
                           value="<?= htmlspecialchars($_POST['group_code'] ?? '') ?>"
                           required>
                    <div class="form-text">Short uppercase prefix used for type codes (e.g. VE 1, VE 2…).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Group Name <span class="text-danger">*</span></label>
                    <input type="text" name="group_name" class="form-control"
                           placeholder="e.g. Vehicles"
                           value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>"
                           required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="group_description" class="form-control" rows="2"
                              placeholder="Optional description of this group"><?= htmlspecialchars($_POST['group_description'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Create Group</button>
            </div>
        </form>
    </div>
</div>

<?php
/* Re-open the error modal if there are validation errors */
if (!empty($errors)):
    $modal = strpos(($_POST['action'] ?? ''), 'type') !== false ? 'addTypeModal' : 'addGroupModal';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var m = new bootstrap.Modal(document.getElementById('<?= $modal ?>'));
    m.show();
});
</script>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
