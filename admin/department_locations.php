<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$message = '';
$error   = '';

/* ── Helpers: check tables exist ─────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

$hasMappings  = tableExists($pdo, 'department_location_mappings');
$hasLocations = tableExists($pdo, 'inv_locations');
$hasBranches  = tableExists($pdo, 'branches');

/* ── Handle POST actions ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasMappings) {
    $action      = $_POST['action'] ?? '';
    $branch_id   = (int)($_POST['branch_id']   ?? 0);
    $location_id = (int)($_POST['location_id'] ?? 0);

    if ($action === 'add') {
        if ($branch_id <= 0 || $location_id <= 0) {
            $error = 'Please select both a department and a location.';
        } else {
            try {
                $pdo->prepare("INSERT IGNORE INTO department_location_mappings (branch_id, location_id) VALUES (?, ?)")
                    ->execute([$branch_id, $location_id]);
                logAudit($pdo, 'department_location_mappings', $pdo->lastInsertId(), 'CREATE',
                    "Mapping branch_id={$branch_id} → location_id={$location_id} added.");
                $message = "Mapping added successfully.";
            } catch (Exception $e) {
                $error = 'Error: ' . htmlspecialchars(extractDbMessage($e));
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error = 'Invalid ID.';
        } else {
            try {
                $pdo->prepare("DELETE FROM department_location_mappings WHERE id = ?")
                    ->execute([$id]);
                logAudit($pdo, 'department_location_mappings', $id, 'DELETE', "Mapping id={$id} removed.");
                $message = "Mapping removed.";
            } catch (Exception $e) {
                $error = 'Error: ' . htmlspecialchars(extractDbMessage($e));
            }
        }
    }
}

/* ── Fetch data ───────────────────────────────────────────────────────── */
$branches  = ($hasBranches)
    ? $pdo->query("SELECT branch_id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$locations = ($hasLocations)
    ? $pdo->query("SELECT location_id, location_code, site_name, building, room_storage_area
                   FROM inv_locations WHERE is_active = 1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$mappings  = ($hasMappings && $hasBranches && $hasLocations)
    ? $pdo->query("
        SELECT dlm.id,
               b.branch_name,
               l.location_code,
               CONCAT_WS(' / ', l.site_name, l.building, l.room_storage_area) AS location_label
        FROM department_location_mappings dlm
        JOIN branches      b ON dlm.branch_id   = b.branch_id
        JOIN inv_locations l ON dlm.location_id = l.location_id
        ORDER BY b.branch_name, l.location_code
      ")->fetchAll(PDO::FETCH_ASSOC)
    : [];

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<h3 class="section-title">Department → Location Mappings</h3>
<p class="text-muted mb-4">
    Configure which locations correspond to each department. When a department is selected on a form,
    its mapped locations can be pre-filled or filtered automatically.
</p>

<?php if (!$hasMappings): ?>
<div class="alert alert-warning">
    <strong>⚠ Setup required:</strong> The <code>department_location_mappings</code> table does not exist yet.
    Please run the migration <code>migrations/2026_07_29_job_titles_and_dept_location.sql</code>.
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Add Mapping ────────────────────────────────────────────────── -->
    <?php if ($hasMappings): ?>
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white">➕ Add Mapping</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">— Select Department —</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['branch_id'] ?>">
                                <?= htmlspecialchars($b['branch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Location <span class="text-danger">*</span></label>
                        <select name="location_id" class="form-select" required>
                            <option value="">— Select Location —</option>
                            <?php foreach ($locations as $l): ?>
                            <?php
                            $label = $l['location_code'];
                            $extra = trim(implode(' / ', array_filter([$l['site_name'], $l['building'], $l['room_storage_area']])));
                            if ($extra) $label .= ' — ' . $extra;
                            ?>
                            <option value="<?= (int)$l['location_id'] ?>">
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($locations)): ?>
                        <small class="text-warning">No active locations found. Add locations first.</small>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-success w-100"
                            <?= (empty($branches) || empty($locations)) ? 'disabled' : '' ?>>
                        ✓ Add Mapping
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Current Mappings ───────────────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-secondary text-white">
                📋 Current Mappings (<?= count($mappings) ?>)
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Department</th>
                            <th>Location Code</th>
                            <th>Location Details</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['branch_name']) ?></td>
                        <td><code><?= htmlspecialchars($m['location_code']) ?></code></td>
                        <td class="text-muted small"><?= htmlspecialchars($m['location_label']) ?></td>
                        <td class="text-center">
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Remove this mapping?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">🗑️ Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mappings)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            No mappings defined yet.
                        </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($mappings)): ?>
        <!-- Group view by department -->
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-info text-white">📊 Grouped by Department</div>
            <div class="card-body">
                <?php
                $grouped = [];
                foreach ($mappings as $m) {
                    $grouped[$m['branch_name']][] = $m['location_code'];
                }
                foreach ($grouped as $dept => $locs): ?>
                <div class="mb-2">
                    <strong><?= htmlspecialchars($dept) ?></strong>
                    →
                    <?php foreach ($locs as $lc): ?>
                    <span class="badge bg-primary me-1"><?= htmlspecialchars($lc) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
