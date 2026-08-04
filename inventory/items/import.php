<?php
$REQUIRE_PERMISSION = 'import_inventory_assets';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/AssetImportService.php';

$importTablesReady = true;
try {
    $pdo->query("SELECT 1 FROM inv_import_batches LIMIT 1");
    $pdo->query("SELECT 1 FROM inv_asset_details LIMIT 1");
} catch (PDOException $e) {
    $importTablesReady = false;
}

$summary = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $importTablesReady) {
    try {
        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $codes = [
                UPLOAD_ERR_INI_SIZE => 'The file exceeds the maximum upload size.',
                UPLOAD_ERR_FORM_SIZE => 'The file exceeds the maximum upload size.',
                UPLOAD_ERR_NO_FILE => 'Please choose a file to import.',
            ];
            $code = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new RuntimeException($codes[$code] ?? 'File upload failed. Please try again.');
        }

        $fileName = $_FILES['import_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv'], true)) {
            throw new RuntimeException('Unsupported file type. Please upload an .xlsx or .csv file.');
        }

        $service = new AssetImportService($pdo, (int)($_SESSION['user_id'] ?? 0), [
            'update_existing'     => !empty($_POST['update_existing']),
            'auto_create_lookups' => !empty($_POST['auto_create_lookups']),
            'allow_negative'      => !empty($_POST['allow_negative']),
            'date_format'         => ($_POST['date_format'] ?? 'dmy') === 'mdy' ? 'mdy' : 'dmy',
        ]);

        $summary = $service->import($_FILES['import_file']['tmp_name'], $fileName);

        logAudit($pdo, 'inv_import_batches', $summary['batch_id'], 'IMPORT',
            sprintf('Asset import "%s": %d rows, %d created, %d updated, %d skipped, %d errors',
                $fileName, $summary['total_rows'], $summary['created'],
                $summary['updated'], $summary['skipped'], $summary['errors']));
    } catch (Exception $e) {
        $error = extractDbMessage($e);
    }
}

/* Import history */
$history = [];
if ($importTablesReady) {
    $history = $pdo->query("
        SELECT b.*, u.full_name AS imported_by_name
        FROM inv_import_batches b
        LEFT JOIN users u ON u.user_id = b.imported_by
        ORDER BY b.batch_id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-earmark-arrow-up"></i> Import Assets</h2>
    <div>
        <a href="/inventory/items/import_template.php" class="btn btn-outline-secondary">
            <i class="bi bi-download"></i> Download Template
        </a>
        <a href="/inventory/items/list.php" class="btn btn-outline-primary">
            <i class="bi bi-boxes"></i> Back to Items
        </a>
    </div>
</div>

<?php if (!$importTablesReady): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    The asset import tables have not been created yet. Please run the migration
    <code>migrations/2026_07_15_inventory_asset_import.sql</code> and reload this page.
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; exit; endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($summary): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <i class="bi bi-clipboard-check"></i> Import Summary
    </div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col">
                <div class="fs-4 fw-bold"><?= number_format($summary['total_rows']) ?></div>
                <small class="text-muted">Total Rows</small>
            </div>
            <div class="col">
                <div class="fs-4 fw-bold text-success"><?= number_format($summary['created']) ?></div>
                <small class="text-muted">Successfully Imported</small>
            </div>
            <div class="col">
                <div class="fs-4 fw-bold text-primary"><?= number_format($summary['updated']) ?></div>
                <small class="text-muted">Updated Existing</small>
            </div>
            <div class="col">
                <div class="fs-4 fw-bold text-warning"><?= number_format($summary['skipped']) ?></div>
                <small class="text-muted">Skipped Duplicates</small>
            </div>
            <div class="col">
                <div class="fs-4 fw-bold text-danger"><?= number_format($summary['errors']) ?></div>
                <small class="text-muted">Validation Errors</small>
            </div>
        </div>

        <?php if (!empty($summary['error_rows'])): ?>
        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Row Issues (<?= count($summary['error_rows']) ?>)</h6>
            <a href="/inventory/items/import_errors_csv.php?batch_id=<?= (int)$summary['batch_id'] ?>"
               class="btn btn-sm btn-outline-danger">
                <i class="bi bi-download"></i> Download Error Log
            </a>
        </div>
        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
            <table class="table table-sm table-striped align-middle">
                <thead class="table-dark">
                    <tr><th>Row</th><th>Asset Code</th><th>Field</th><th>Error Message</th></tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($summary['error_rows'], 0, 200) as $e): ?>
                    <tr>
                        <td><?= (int)$e['row'] ?></td>
                        <td><?= htmlspecialchars($e['asset_code'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($e['field'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($e['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($summary['error_rows']) > 200): ?>
        <small class="text-muted">Showing first 200 issues — download the full error log above.</small>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-dark">
                <i class="bi bi-upload"></i> Upload Asset Register
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Excel / CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="import_file" class="form-control" accept=".xlsx,.xlsm,.csv" required>
                        <small class="text-muted">Supported formats: .xlsx, .csv. First row must contain column headers.</small>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing" value="1"
                               <?= !empty($_POST['update_existing']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="update_existing">
                            Update existing records
                            <small class="d-block text-muted">When a matching asset is found, update it instead of skipping the row.</small>
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="auto_create_lookups" id="auto_create_lookups" value="1"
                               <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['auto_create_lookups']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="auto_create_lookups">
                            Auto-create missing categories &amp; departments
                            <small class="d-block text-muted">Otherwise unmatched lookup values generate row errors. Asset types must match an approved Asset Classification.</small>
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow_negative" id="allow_negative" value="1"
                               <?= !empty($_POST['allow_negative']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_negative">
                            Permit negative monetary values
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ambiguous Date Interpretation</label>
                        <select name="date_format" class="form-select">
                            <option value="dmy" <?= ($_POST['date_format'] ?? 'dmy') === 'dmy' ? 'selected' : '' ?>>dd/mm/yyyy (day first)</option>
                            <option value="mdy" <?= ($_POST['date_format'] ?? '') === 'mdy' ? 'selected' : '' ?>>mm/dd/yyyy (month first)</option>
                        </select>
                        <small class="text-muted">Used only when a date such as 05/04/2024 could be read either way.</small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-file-earmark-arrow-up"></i> Import Assets
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-dark text-white"><i class="bi bi-info-circle"></i> How duplicates are detected</div>
            <div class="card-body small">
                <ol class="mb-1">
                    <li><strong>Asset Code</strong> — primary unique identifier.</li>
                    <li><strong>Serial Number</strong> — when Asset Code is empty.</li>
                    <li><strong>Reference Number</strong> — when the above are empty.</li>
                    <li><strong>Item Name + Make + Acquired Date</strong> — final fallback.</li>
                </ol>
                Re-importing the same file never creates duplicate assets.
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-dark"><i class="bi bi-clock-history"></i> Import History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th><th>File</th><th>Imported By</th>
                                <th class="text-end">Rows</th><th class="text-end">Created</th>
                                <th class="text-end">Updated</th><th class="text-end">Skipped</th>
                                <th class="text-end">Errors</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No imports yet.</td></tr>
                        <?php else: foreach ($history as $h): ?>
                            <tr>
                                <td><small><?= htmlspecialchars($h['started_at']) ?></small></td>
                                <td><small><?= htmlspecialchars($h['source_file_name']) ?></small></td>
                                <td><small><?= htmlspecialchars($h['imported_by_name'] ?? '—') ?></small></td>
                                <td class="text-end"><?= number_format((int)$h['total_rows']) ?></td>
                                <td class="text-end text-success"><?= number_format((int)$h['created_count']) ?></td>
                                <td class="text-end text-primary"><?= number_format((int)$h['updated_count']) ?></td>
                                <td class="text-end text-warning"><?= number_format((int)$h['skipped_count']) ?></td>
                                <td class="text-end text-danger"><?= number_format((int)$h['error_count']) ?></td>
                                <td>
                                    <?php if ((int)$h['error_count'] > 0 || (int)$h['skipped_count'] > 0): ?>
                                    <a href="/inventory/items/import_errors_csv.php?batch_id=<?= (int)$h['batch_id'] ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Download error log">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>