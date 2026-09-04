<?php
$REQUIRE_PERMISSION = 'manage_inventory_locations';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$users = $pdo->query("SELECT user_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$editMode = false;
$loc = [];
if (!empty($_GET['id'])) {
    $editMode = true;
    $stmt = $pdo->prepare("
        SELECT location_id, location_code, site_campus, building, floor, room_storage_area,
               bin_shelf_rack, security_level, temp_humidity_req, custodian_user_id,
               capacity, is_active, location_type
        FROM inv_locations
        WHERE location_id = ?
    ");
    $stmt->execute([(int) $_GET['id']]);
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loc) { pop("Location not found.", "/inventory/locations/list.php", 1800, 'warning'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $code = trim($_POST['location_code'] ?? ($loc['location_code'] ?? ''));
        if (empty($code)) throw new Exception("Location code is required.");

        $data = [
            trim($_POST['site_campus'] ?? '') ?: null,
            trim($_POST['building'] ?? '') ?: null,
            trim($_POST['floor'] ?? '') ?: null,
            trim($_POST['room_storage_area'] ?? '') ?: null,
            trim($_POST['bin_shelf_rack'] ?? '') ?: null,
            $_POST['security_level'] ?? 'STANDARD',
            trim($_POST['temp_humidity_req'] ?? '') ?: null,
            ($_POST['custodian_user_id'] ?? null) ?: null,
            trim($_POST['capacity'] ?? '') ?: null,
            isset($_POST['is_active']) ? 1 : 0,
            $_POST['location_type'] ?? 'USABLE',
        ];

        if ($editMode) {
            $pdo->prepare("
                UPDATE inv_locations SET site_campus=?, building=?, floor=?, room_storage_area=?,
                bin_shelf_rack=?, security_level=?, temp_humidity_req=?, custodian_user_id=?,
                capacity=?, is_active=?, location_type=? WHERE location_id=?
            ")->execute(array_merge($data, [(int) $_GET['id']]));
            logInventoryAudit($pdo, 'inv_locations', (int) $_GET['id'], 'UPDATE', "Location updated: $code");
            pop("Location updated.", "/inventory/locations/list.php", 1800, 'success');
        } else {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM inv_locations WHERE location_code = ?");
            $dup->execute([$code]);
            if ($dup->fetchColumn() > 0) throw new Exception("Location code '$code' already exists.");

            $pdo->prepare("
                INSERT INTO inv_locations (location_code, site_campus, building, floor, room_storage_area,
                bin_shelf_rack, security_level, temp_humidity_req, custodian_user_id, capacity, is_active, location_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute(array_merge([$code], $data));
            $newId = (int) $pdo->lastInsertId();
            logInventoryAudit($pdo, 'inv_locations', $newId, 'CREATE', "Location created: $code");
            pop("Location created.", "/inventory/locations/list.php", 1800, 'success');
        }
        exit;
    } catch (Exception $e) {
        $error = extractDbMessage($e);
    }
}

$f = $loc ?: [];
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-geo-alt"></i> <?= $editMode ? 'Edit' : 'Add' ?> Location</h2>
    <a href="/inventory/locations/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Location Code <span class="text-danger">*</span></label>
                    <input type="text" name="location_code" class="form-control" required
                           value="<?= htmlspecialchars($f['location_code'] ?? '') ?>" <?= $editMode ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Site / Campus</label>
                    <input type="text" name="site_campus" class="form-control" value="<?= htmlspecialchars($f['site_campus'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Building</label>
                    <input type="text" name="building" class="form-control" value="<?= htmlspecialchars($f['building'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Floor</label>
                    <input type="text" name="floor" class="form-control" value="<?= htmlspecialchars($f['floor'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Room / Storage Area</label>
                    <input type="text" name="room_storage_area" class="form-control" value="<?= htmlspecialchars($f['room_storage_area'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bin / Shelf / Rack</label>
                    <input type="text" name="bin_shelf_rack" class="form-control" value="<?= htmlspecialchars($f['bin_shelf_rack'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location Type</label>
                    <select name="location_type" class="form-select">
                        <?php foreach (['USABLE','QUARANTINE','EXPIRED','DAMAGED','DISPOSAL','RECEIVING','STAGING'] as $lt): ?>
                        <option value="<?= $lt ?>" <?= ($f['location_type'] ?? '') === $lt ? 'selected' : '' ?>><?= $lt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Security Level</label>
                    <select name="security_level" class="form-select">
                        <?php foreach (['STANDARD','RESTRICTED','HIGH_SECURITY'] as $sl): ?>
                        <option value="<?= $sl ?>" <?= ($f['security_level'] ?? '') === $sl ? 'selected' : '' ?>><?= $sl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Temperature / Humidity Requirements</label>
                    <input type="text" name="temp_humidity_req" class="form-control" value="<?= htmlspecialchars($f['temp_humidity_req'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Responsible Custodian</label>
                    <select name="custodian_user_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= ($f['custodian_user_id'] ?? '') == $u['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Capacity</label>
                    <input type="text" name="capacity" class="form-control" value="<?= htmlspecialchars($f['capacity'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Active</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active"
                               <?= (!$editMode || ($f['is_active'] ?? 1)) ? 'checked' : '' ?>>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="text-end mb-4">
        <a href="/inventory/locations/list.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle"></i> <?= $editMode ? 'Update' : 'Create' ?> Location</button>
    </div>
</form>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>