<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$message = '';
$error   = '';

/* ── Handle POST actions ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ADD */
    if ($action === 'add') {
        $title_name = trim($_POST['title_name'] ?? '');
        if ($title_name === '') {
            $error = 'Title name is required.';
        } else {
            try {
                $pdo->beginTransaction();
                $nextSortOrder = nextSortOrderValue($pdo, 'job_titles');
                $pdo->prepare("INSERT INTO job_titles (title_name, sort_order) VALUES (?, ?)")
                    ->execute([$title_name, $nextSortOrder]);
                $pdo->commit();
                logAudit($pdo, 'job_titles', $pdo->lastInsertId(), 'CREATE', "Job title '{$title_name}' added.");
                $message = "Job title added successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error: ' . htmlspecialchars(extractDbMessage($e));
            }
        }
    }

    /* EDIT */
    if ($action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $title_name = trim($_POST['title_name'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $title_name === '') {
            $error = 'Invalid data.';
        } else {
            try {
                $pdo->prepare("UPDATE job_titles SET title_name = ?, is_active = ? WHERE id = ?")
                    ->execute([$title_name, $is_active, $id]);
                logAudit($pdo, 'job_titles', $id, 'UPDATE', "Job title updated to '{$title_name}'.");
                $message = "Job title updated.";
            } catch (Exception $e) {
                $error = 'Error: ' . htmlspecialchars(extractDbMessage($e));
            }
        }
    }

    /* DELETE */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error = 'Invalid ID.';
        } else {
            /* Check references in users */
            $inUse = $pdo->prepare("SELECT COUNT(*) FROM users WHERE job_title_id = ?");
            $inUse->execute([$id]);
            if ($inUse->fetchColumn() > 0) {
                $error = 'Cannot delete: this job title is assigned to one or more users.';
            } else {
                try {
                    $pdo->prepare("DELETE FROM job_titles WHERE id = ?")
                        ->execute([$id]);
                    logAudit($pdo, 'job_titles', $id, 'DELETE', "Job title id={$id} deleted.");
                    $message = "Job title deleted.";
                } catch (Exception $e) {
                    $error = 'Error: ' . htmlspecialchars(extractDbMessage($e));
                }
            }
        }
    }
}

/* ── Fetch all job titles ─────────────────────────────────────────────── */
$titles = $pdo->query("
    SELECT jt.id, jt.title_name, jt.is_active, jt.sort_order,
           (SELECT COUNT(*) FROM users u WHERE u.job_title_id = jt.id) AS user_count
    FROM job_titles jt
    ORDER BY jt.sort_order, jt.title_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Inline-edit target (if ?edit=id) ────────────────────────────────── */
$editId = (int)($_GET['edit'] ?? 0);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<h3 class="section-title">Job Titles</h3>

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

    <!-- ── Add New Title ──────────────────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white">➕ Add Job Title</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Title Name <span class="text-danger">*</span></label>
                        <input type="text" name="title_name" class="form-control" required
                               placeholder="e.g. Senior Chemist">
                    </div>
                    <button type="submit" class="btn btn-success w-100">✓ Add Title</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── List / Edit ────────────────────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-secondary text-white">
                📋 All Job Titles (<?= count($titles) ?>)
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Title</th>
                            <th class="text-center">Users</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($titles as $t): ?>
                        <?php if ($editId === (int)$t['id']): ?>
                        <!-- Inline Edit Row -->
                        <tr class="table-warning">
                            <td colspan="5">
                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <input type="text" name="title_name" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($t['title_name']) ?>" required style="max-width:320px">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="ea<?= $t['id'] ?>"
                                               <?= $t['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ea<?= $t['id'] ?>">Active</label>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-success">Save</button>
                                    <a href="/admin/job_titles.php" class="btn btn-sm btn-secondary">Cancel</a>
                                </form>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td class="text-muted small"><?= (int)$t['sort_order'] ?></td>
                            <td>
                                <?= htmlspecialchars($t['title_name']) ?>
                                <?php if (!$t['is_active']): ?>
                                    <span class="badge bg-secondary ms-1">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($t['user_count'] > 0): ?>
                                    <span class="badge bg-info"><?= (int)$t['user_count'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $t['is_active'] ? 'Yes' : 'No' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                <?php if ((int)$t['user_count'] === 0): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Delete job title \'<?= addslashes($t['title_name']) ?>\'?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">🗑️</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($titles)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No job titles defined.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
