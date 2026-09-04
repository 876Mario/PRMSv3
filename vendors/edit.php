<?php
$REQUIRE_PERMISSION = 'manage_vendors';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

/* ===============================
   Fetch Vendor
================================ */
$id = $_GET['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
    pop('Invalid vendor ID', '/vendors/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT vendor_id, vendor_name, contact_person, email, phone, address, status, total_awards, performance_rating, created_at
    FROM vendors
    WHERE vendor_id = ?
");
$stmt->execute([$id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    pop('Vendor not found', '/vendors/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ===============================
   Handle Form Submission
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $vendor_name    = trim($_POST['vendor_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $status         = trim($_POST['status'] ?? 'ACTIVE');

    if ($vendor_name === '') {
        $error = "Vendor name is required.";
    } else {

        /* Check for duplicate name (excluding current vendor) */
        $stmt = $pdo->prepare("
            SELECT vendor_id FROM vendors
            WHERE vendor_name = ? AND vendor_id != ?
        ");
        $stmt->execute([$vendor_name, $id]);

        if ($stmt->fetch()) {
            $error = "Vendor name already exists.";
        } else {

            /* Track changes for audit */
            $changes = [];
            if ($vendor['vendor_name'] !== $vendor_name) $changes[] = "Name: {$vendor['vendor_name']} → $vendor_name";
            if ($vendor['contact_person'] !== $contact_person) $changes[] = "Contact: {$vendor['contact_person']} → $contact_person";
            if ($vendor['email'] !== $email) $changes[] = "Email: {$vendor['email']} → $email";
            if ($vendor['phone'] !== $phone) $changes[] = "Phone: {$vendor['phone']} → $phone";
            if ($vendor['address'] !== $address) $changes[] = "Address updated";
            if ($vendor['status'] !== $status) $changes[] = "Status: {$vendor['status']} → $status";

            /* Update vendor */
            try {
            $stmt = $pdo->prepare("
                UPDATE vendors
                SET vendor_name = ?,
                    contact_person = ?,
                    email = ?,
                    phone = ?,
                    address = ?,
                    status = ?
                WHERE vendor_id = ?
            ");

            $stmt->execute([
                $vendor_name,
                $contact_person,
                $email,
                $phone,
                $address,
                $status,
                $id
            ]);

            /* Audit Log */
            if (!empty($changes)) {
                $notes = "Updated: " . implode("; ", $changes);
            } else {
                $notes = "No changes made";
            }

            $pdo->prepare("
                INSERT INTO audit_log
                (table_name, record_id, action, changed_by, change_date, notes)
                VALUES ('vendors', ?, 'UPDATE', ?, NOW(), ?)
            ")->execute([
                $id,
                $_SESSION['user_id'],
                $notes
            ]);

            header("Location: view.php?id=$id");
            exit;
            } catch (Throwable $e) {
                require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
                $error = extractDbMessage($e);
            }
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="section-title">✏️ Edit Vendor</h3>
    <a href="/vendors/view.php?id=<?= (int)$vendor['vendor_id'] ?>" class="btn btn-secondary btn-sm">
        ← Back
    </a>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>⚠️ Error:</strong> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📋 Vendor Information</h5>
            </div>
            <div class="card-body">

                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Vendor Name *</label>
                        <input type="text" name="vendor_name"
                               value="<?= htmlspecialchars($vendor['vendor_name']) ?>"
                               class="form-control" required>
                        <small class="text-muted">Legal business name</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Contact Person</label>
                        <input type="text" name="contact_person"
                               value="<?= htmlspecialchars($vendor['contact_person'] ?? '') ?>"
                               class="form-control">
                        <small class="text-muted">Primary contact name</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" name="email"
                                   value="<?= htmlspecialchars($vendor['email'] ?? '') ?>"
                                   class="form-control">
                            <small class="text-muted">Email address</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Phone</label>
                            <input type="text" name="phone"
                                   value="<?= htmlspecialchars($vendor['phone'] ?? '') ?>"
                                   class="form-control">
                            <small class="text-muted">Contact phone number</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Address</label>
                        <textarea name="address" rows="3" class="form-control"><?= htmlspecialchars($vendor['address'] ?? '') ?></textarea>
                        <small class="text-muted">Physical business address</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="ACTIVE" <?= $vendor['status'] === 'ACTIVE' ? 'selected' : '' ?>>
                                ✅ Active
                            </option>
                            <option value="INACTIVE" <?= $vendor['status'] === 'INACTIVE' ? 'selected' : '' ?>>
                                ❌ Inactive
                            </option>
                        </select>
                        <small class="text-muted">Vendor availability for procurement</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            💾 Save Changes
                        </button>
                        <a href="/vendors/view.php?id=<?= (int)$vendor['vendor_id'] ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📊 Vendor Statistics</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted fw-bold">Total Awards</small>
                    <p class="h5 mb-0"><?= (int)$vendor['total_awards'] ?></p>
                </div>

                <div class="mb-3">
                    <small class="text-muted fw-bold">Performance Rating</small>
                    <p class="h5 mb-0">
                        <?= number_format($vendor['performance_rating'] ?? 0, 2) ?>/5
                        <span class="text-warning">⭐</span>
                    </p>
                </div>

                <div class="mb-3">
                    <small class="text-muted fw-bold">Created</small>
                    <p class="text-muted small mb-0">
                        <?= date('d M Y', strtotime($vendor['created_at'] ?? 'now')) ?>
                    </p>
                </div>

                <hr>

                <div class="alert alert-info small mb-0">
                    <strong>💡 Tip:</strong> All changes are logged in the audit trail for compliance and tracking.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
