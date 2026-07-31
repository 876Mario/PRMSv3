<?php
$REQUIRE_PERMISSION = 'view_inventory_reports';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';

/* ── Schema readiness guards ─────────────────────────────────────────────── */
function reportColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function reportTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$domainReady          = reportColumnExists($pdo, 'inv_items', 'item_domain');
$assetTypeReady       = reportColumnExists($pdo, 'inv_items', 'asset_type_id');
$invTypeReady         = reportColumnExists($pdo, 'inv_items', 'inventory_type_id');
$assetTypesTableReady = reportTableExists($pdo, 'asset_types');
$invTypesTableReady   = reportTableExists($pdo, 'inventory_types');
$serialTableReady     = reportTableExists($pdo, 'inv_serial_numbers');
$assetDetailsReady    = reportTableExists($pdo, 'inv_asset_details')
                     && reportColumnExists($pdo, 'inv_asset_details', 'accountable_officer');
$branchesReady        = reportTableExists($pdo, 'branches');
$locationsReady       = reportTableExists($pdo, 'inv_locations');

/** Build a partial-match LIKE pattern, escaping LIKE wildcards in user input. */
function reportLikePattern(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

$ppeLabel        = getPrimaryAssetTypeLabel('ASSET');
$consumableLabel = getPrimaryAssetTypeLabel('INVENTORY');

/* ── Filter inputs ───────────────────────────────────────────────────────── */
$q              = trim($_GET['q'] ?? '');
$recordType     = $_GET['record_type'] ?? '';           // '', assets, inventory
$primaryType    = $_GET['primary_type'] ?? '';          // '', PPE, CONSUMABLE
$classification = trim($_GET['classification'] ?? '');  // 'a:<id>' or 'i:<id>'
$departmentId   = (int) ($_GET['department_id'] ?? 0);
$custodian      = trim($_GET['custodian'] ?? '');
$custodianRole  = trim($_GET['custodian_role'] ?? '');
$locationId     = (int) ($_GET['location_id'] ?? 0);
$condition      = trim($_GET['condition'] ?? '');
$acquiredFrom   = trim($_GET['acquired_from'] ?? '');
$acquiredTo     = trim($_GET['acquired_to'] ?? '');

$where  = ["i.item_status = 'ACTIVE'"];
$params = [];

/* Record type: All Records / Assets Only / Inventory-Consumables Only */
if ($domainReady) {
    if ($recordType === 'assets') {
        $where[] = "i.item_domain IN ('ASSET','BOTH')";
    } elseif ($recordType === 'inventory') {
        $where[] = "i.item_domain IN ('INVENTORY','BOTH')";
    }
    if ($primaryType === 'PPE') {
        $where[] = "i.item_domain IN ('ASSET','BOTH')";
    } elseif ($primaryType === 'CONSUMABLE') {
        $where[] = "i.item_domain IN ('INVENTORY','BOTH')";
    }
} elseif ($recordType === 'assets' || $primaryType === 'PPE') {
    $where[] = "EXISTS (SELECT 1 FROM inv_categories c2 WHERE c2.category_id = i.category_id AND c2.category_code = 'ASSETS')";
} elseif ($recordType === 'inventory' || $primaryType === 'CONSUMABLE') {
    $where[] = "NOT EXISTS (SELECT 1 FROM inv_categories c2 WHERE c2.category_id = i.category_id AND c2.category_code = 'ASSETS')";
}

/* Classification (belongs to a Primary Asset Type) */
if ($classification !== '' && preg_match('/^(a|i):(\d+)$/', $classification, $m)) {
    if ($m[1] === 'a' && $assetTypeReady) {
        $where[]  = "i.asset_type_id = ?";
        $params[] = (int) $m[2];
    } elseif ($m[1] === 'i' && $invTypeReady) {
        $where[]  = "i.inventory_type_id = ?";
        $params[] = (int) $m[2];
    }
}

/* Department */
if ($departmentId > 0 && $branchesReady) {
    $deptClauses = [];
    if ($assetDetailsReady) {
        $deptClauses[] = "ad.department_branch_id = ?";
        $params[] = $departmentId;
    }
    if ($serialTableReady) {
        $deptClauses[] = "EXISTS (SELECT 1 FROM inv_serial_numbers snd WHERE snd.item_id = i.item_id AND snd.issued_to_department = ?)";
        $params[] = $departmentId;
    }
    if ($deptClauses) $where[] = '(' . implode(' OR ', $deptClauses) . ')';
}

/* Custodian (partial match) */
if ($custodian !== '') {
    $custLike = reportLikePattern($custodian);
    $custClauses = [];
    if ($assetDetailsReady) {
        $custClauses[] = "ad.custodian_name LIKE ?";
        $params[] = $custLike;
        $custClauses[] = "adcu.full_name LIKE ?";
        $params[] = $custLike;
        $custClauses[] = "ad.accountable_officer LIKE ?";
        $params[] = $custLike;
        $custClauses[] = "ad.secondary_custodian LIKE ?";
        $params[] = $custLike;
    }
    if ($serialTableReady) {
        $custClauses[] = "EXISTS (
            SELECT 1 FROM inv_serial_numbers snc
            JOIN users snu ON snc.issued_to_user_id = snu.user_id
            WHERE snc.item_id = i.item_id AND snu.full_name LIKE ?
        )";
        $params[] = $custLike;
    }
    if ($custClauses) $where[] = '(' . implode(' OR ', $custClauses) . ')';
}

/* Custodian Role / Job Title (exact match) */
if ($custodianRole !== '' && $assetDetailsReady) {
    $where[]  = "ad.custodian_role = ?";
    $params[] = $custodianRole;
}

/* Location */
if ($locationId > 0 && $locationsReady) {
    $locClauses = ["EXISTS (SELECT 1 FROM inv_stock sl WHERE sl.item_id = i.item_id AND sl.location_id = ?)"];
    $params[] = $locationId;
    if ($serialTableReady) {
        $locClauses[] = "EXISTS (SELECT 1 FROM inv_serial_numbers snl WHERE snl.item_id = i.item_id AND snl.location_id = ?)";
        $params[] = $locationId;
    }
    if ($assetDetailsReady) {
        /* Also match assets whose location_id FK is set directly on the asset detail record */
        $locClauses[] = "EXISTS (SELECT 1 FROM inv_asset_details adl WHERE adl.item_id = i.item_id AND adl.location_id = ?)";
        $params[] = $locationId;
    }
    $where[] = '(' . implode(' OR ', $locClauses) . ')';
}

/* Asset status / condition */
if ($condition !== '') {
    $condLike = reportLikePattern($condition);
    $condClauses = [];
    if ($assetDetailsReady) {
        $condClauses[] = "ad.asset_condition LIKE ?";
        $params[] = $condLike;
        $condClauses[] = "ad.asset_status LIKE ?";
        $params[] = $condLike;
    }
    if ($serialTableReady) {
        $condClauses[] = "EXISTS (SELECT 1 FROM inv_serial_numbers snq WHERE snq.item_id = i.item_id AND (snq.current_condition LIKE ? OR snq.lifecycle_status LIKE ?))";
        $params[] = $condLike;
        $params[] = $condLike;
    }
    if ($condClauses) $where[] = '(' . implode(' OR ', $condClauses) . ')';
}

/* Date acquired range */
if ($assetDetailsReady) {
    if ($acquiredFrom !== '') {
        $where[]  = "ad.acquired_date >= ?";
        $params[] = $acquiredFrom;
    }
    if ($acquiredTo !== '') {
        $where[]  = "ad.acquired_date <= ?";
        $params[] = $acquiredTo;
    }
}

/* Global search — partial match across assets AND inventory/consumables */
if ($q !== '') {
    $like = reportLikePattern($q);
    $searchClauses = [
        "i.item_name LIKE ?", "i.item_code LIKE ?", "i.barcode LIKE ?",
        "i.manufacturer LIKE ?", "i.brand LIKE ?", "i.model LIKE ?", "i.part_number LIKE ?",
    ];
    $searchParams = array_fill(0, 7, $like);
    if ($assetTypeReady && $assetTypesTableReady) { $searchClauses[] = "at.type_name LIKE ?"; $searchParams[] = $like; }
    if ($invTypeReady && $invTypesTableReady)     { $searchClauses[] = "it.type_name LIKE ?"; $searchParams[] = $like; }
    if ($domainReady) {
        // Primary Asset Type label matching (labels passed as bound parameters)
        $searchClauses[] = "(i.item_domain IN ('ASSET','BOTH') AND ? LIKE ?)";
        $searchParams[] = $ppeLabel;
        $searchParams[] = $like;
        $searchClauses[] = "(i.item_domain IN ('INVENTORY','BOTH') AND ? LIKE ?)";
        $searchParams[] = $consumableLabel;
        $searchParams[] = $like;
    }
    if ($assetDetailsReady) {
        foreach (['ad.asset_code', 'ad.serial_number', 'ad.custodian_name', 'ad.accountable_officer',
                  'ad.secondary_custodian', 'ad.site', 'ad.building', 'ad.floor_room', 'ad.address', 'ad.asset_status'] as $col) {
            $searchClauses[] = "$col LIKE ?";
            $searchParams[] = $like;
        }
        if ($branchesReady) { $searchClauses[] = "adb.branch_name LIKE ?"; $searchParams[] = $like; }
        $searchClauses[] = "adcu.full_name LIKE ?";
        $searchParams[] = $like;
    }
    if ($serialTableReady) {
        $snSearch = "EXISTS (
            SELECT 1 FROM inv_serial_numbers sns
            " . ($locationsReady ? "LEFT JOIN inv_locations lsn ON sns.location_id = lsn.location_id" : "") . "
            " . ($branchesReady ? "LEFT JOIN branches bsn ON sns.issued_to_department = bsn.branch_id" : "") . "
            LEFT JOIN users usn ON sns.issued_to_user_id = usn.user_id
            WHERE sns.item_id = i.item_id AND (
                sns.serial_number LIKE ? OR sns.dgc_asset_number LIKE ? OR usn.full_name LIKE ?"
                . ($locationsReady ? " OR lsn.site_name LIKE ? OR lsn.building LIKE ? OR lsn.room_storage_area LIKE ?" : "")
                . ($branchesReady ? " OR bsn.branch_name LIKE ?" : "") . "
            )
        )";
        $searchClauses[] = $snSearch;
        $snParamCount = 3 + ($locationsReady ? 3 : 0) + ($branchesReady ? 1 : 0);
        for ($k = 0; $k < $snParamCount; $k++) $searchParams[] = $like;
    }
    if ($locationsReady) {
        $searchClauses[] = "EXISTS (
            SELECT 1 FROM inv_stock ss
            JOIN inv_locations ls ON ss.location_id = ls.location_id
            WHERE ss.item_id = i.item_id
              AND (ls.site_name LIKE ? OR ls.building LIKE ? OR ls.room_storage_area LIKE ? OR ls.location_code LIKE ?)
        )";
        for ($k = 0; $k < 4; $k++) $searchParams[] = $like;
    }
    $where[] = '(' . implode(' OR ', $searchClauses) . ')';
    $params = array_merge($params, $searchParams);
}

/* Exclude BOS status items from active inventory reports (unless specifically filtered) */
if ($condition !== 'BOS' && $assetDetailsReady) {
    $where[] = "(ad.asset_status IS NULL OR ad.asset_status != 'BOS')";
}

/* ── Joins / selects ─────────────────────────────────────────────────────── */
$assetTypeJoin   = ($assetTypeReady && $assetTypesTableReady) ? "LEFT JOIN asset_types at ON i.asset_type_id = at.asset_type_id" : "";
$assetTypeSelect = ($assetTypeReady && $assetTypesTableReady) ? "at.type_name AS asset_type_name," : "NULL AS asset_type_name,";
$invTypeJoin     = ($invTypeReady && $invTypesTableReady) ? "LEFT JOIN inventory_types it ON i.inventory_type_id = it.inventory_type_id" : "";
$invTypeSelect   = ($invTypeReady && $invTypesTableReady) ? "it.type_name AS inventory_type_name," : "NULL AS inventory_type_name,";
$domainSelect    = $domainReady ? "i.item_domain," : "'INVENTORY' AS item_domain,";

$adJoin = $assetDetailsReady
    ? "LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id
       LEFT JOIN users adcu ON ad.custodian_user_id = adcu.user_id"
      . ($branchesReady ? " LEFT JOIN branches adb ON ad.department_branch_id = adb.branch_id" : "")
    : "";
$adSelect = $assetDetailsReady
    ? "ad.asset_code, ad.acquired_date, ad.asset_condition, ad.asset_status,
       COALESCE(ad.custodian_name, adcu.full_name) AS custodian_display,
       ad.secondary_custodian,"
      . ($branchesReady ? " adb.branch_name AS department_name," : " NULL AS department_name,")
    : "NULL AS asset_code, NULL AS acquired_date, NULL AS asset_condition, NULL AS asset_status,
       NULL AS custodian_display, NULL AS secondary_custodian, NULL AS department_name,";

$serialJoin = $serialTableReady
    ? "LEFT JOIN (
            SELECT item_id, COUNT(*) AS serial_count
            FROM inv_serial_numbers
            GROUP BY item_id
        ) sn ON sn.item_id = i.item_id"
    : "";
$serialSelect = $serialTableReady ? "COALESCE(sn.serial_count, 0)" : "0";

$stmt = $pdo->prepare("
    SELECT
        i.item_id, i.item_code, i.item_name, i.item_status, i.serial_number_flag,
        i.manufacturer, i.model,
        c.category_name,
        $domainSelect
        $assetTypeSelect
        $invTypeSelect
        $adSelect
        COALESCE(st.total_qty, 0) AS total_qty,
        COALESCE(st.total_value, 0) AS total_value,
        $serialSelect AS serial_count
    FROM inv_items i
    LEFT JOIN inv_categories c ON i.category_id = c.category_id
    $assetTypeJoin
    $invTypeJoin
    $adJoin
    LEFT JOIN (
        SELECT
            item_id,
            SUM(quantity_on_hand) AS total_qty,
            SUM(quantity_on_hand * unit_cost) AS total_value
        FROM inv_stock
        GROUP BY item_id
    ) st ON st.item_id = i.item_id
    $serialJoin
    WHERE " . implode(' AND ', $where) . "
    ORDER BY total_value DESC, i.item_name ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Filter option lists ─────────────────────────────────────────────────── */
$ppeClassifications = ($assetTypeReady && $assetTypesTableReady)
    ? $pdo->query("SELECT asset_type_id, type_name FROM asset_types WHERE is_active = 1 ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$consumableClassifications = ($invTypeReady && $invTypesTableReady)
    ? $pdo->query("SELECT inventory_type_id, type_name FROM inventory_types WHERE is_active = 1 ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$departments = $branchesReady
    ? $pdo->query("SELECT branch_id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$locations = $locationsReady
    ? $pdo->query("SELECT location_id, location_code, site_name, building, room_storage_area FROM inv_locations WHERE is_active = 1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$conditionOptions = ['Serviceable', 'Under Repair', 'Obsolete', 'Disposed'];
if ($assetDetailsReady) {
    $existing = $pdo->query("SELECT DISTINCT asset_condition FROM inv_asset_details WHERE asset_condition IS NOT NULL AND asset_condition <> '' ORDER BY asset_condition")->fetchAll(PDO::FETCH_COLUMN);
    $conditionOptions = array_values(array_unique(array_merge($conditionOptions, $existing)));
}

$jobTitles = [];
try {
    $jobTitles = $pdo->query("SELECT id, title_name FROM job_titles WHERE is_active = 1 ORDER BY sort_order, title_name")
                     ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* graceful degradation */ }

$totalItems      = count($rows);
$totalQty        = array_sum(array_map(static fn($r) => (float) $r['total_qty'], $rows));
$totalValue      = array_sum(array_map(static fn($r) => (float) $r['total_value'], $rows));
$serializedItems = count(array_filter($rows, static fn($r) => (int) $r['serial_number_flag'] === 1));

// Paginate rows (summary stats reflect full result set)
extract(getPaginationParams(25));
$pageRows  = array_slice($rows, $offset, $perPage);

$pdfUrl = '/inventory/reports/export_pdf.php?report=asset_register&' . http_build_query($_GET);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-building"></i> Asset Register Report</h2>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf"></i> Export PDF</a>
        <a href="/inventory/reports/" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Reports</a>
    </div>
</div>

<form method="GET" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <!-- Global search across Assets and Inventory/Consumables -->
        <div class="row g-2 mb-3">
            <div class="col-md-10">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="q" class="form-control form-control-lg"
                       placeholder="Search item name, item code, asset tag, serial number, primary asset type, classification, location, custodian, department, manufacturer or model..."
                       value="<?= htmlspecialchars($q) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-dark btn-lg w-100"><i class="bi bi-search"></i> Search</button>
            </div>
        </div>
        <!-- Advanced filters -->
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label">Record Type</label>
                <select name="record_type" class="form-select">
                    <option value="">All Records</option>
                    <option value="assets"    <?= $recordType === 'assets'    ? 'selected' : '' ?>>Assets Only</option>
                    <option value="inventory" <?= $recordType === 'inventory' ? 'selected' : '' ?>>Inventory/Consumables Only</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Primary Asset Type</label>
                <select name="primary_type" class="form-select">
                    <option value="">All Primary Asset Types</option>
                    <option value="PPE"        <?= $primaryType === 'PPE'        ? 'selected' : '' ?>><?= htmlspecialchars($ppeLabel) ?></option>
                    <option value="CONSUMABLE" <?= $primaryType === 'CONSUMABLE' ? 'selected' : '' ?>><?= htmlspecialchars($consumableLabel) ?></option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Classification</label>
                <select name="classification" class="form-select">
                    <option value="">All Classifications</option>
                    <?php if ($ppeClassifications): ?>
                    <optgroup label="<?= htmlspecialchars($ppeLabel) ?>">
                        <?php foreach ($ppeClassifications as $pc): ?>
                        <option value="a:<?= (int) $pc['asset_type_id'] ?>" <?= $classification === 'a:' . $pc['asset_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pc['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if ($consumableClassifications): ?>
                    <optgroup label="<?= htmlspecialchars($consumableLabel) ?>">
                        <?php foreach ($consumableClassifications as $cc): ?>
                        <option value="i:<?= (int) $cc['inventory_type_id'] ?>" <?= $classification === 'i:' . $cc['inventory_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cc['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <?php if ($departments): ?>
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dep): ?>
                    <option value="<?= (int) $dep['branch_id'] ?>" <?= $departmentId === (int) $dep['branch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dep['branch_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Custodian</label>
                <input type="text" name="custodian" class="form-control" placeholder="Custodian name..."
                       value="<?= htmlspecialchars($custodian) ?>">
            </div>
            <?php if (!empty($jobTitles)): ?>
            <div class="col-md-3">
                <label class="form-label">Custodian Role / Job Title</label>
                <select name="custodian_role" class="form-select">
                    <option value="">All Job Titles</option>
                    <?php foreach ($jobTitles as $jt): ?>
                    <option value="<?= htmlspecialchars($jt['title_name']) ?>"
                        <?= $custodianRole === $jt['title_name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($jt['title_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($locations): ?>
            <div class="col-md-3">
                <label class="form-label">Location</label>
                <select name="location_id" class="form-select">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int) $loc['location_id'] ?>" <?= $locationId === (int) $loc['location_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(trim($loc['location_code'] . ' — ' . implode(' / ', array_filter([$loc['site_name'], $loc['building'], $loc['room_storage_area']])), ' —')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Asset Status / Condition</label>
                <select name="condition" class="form-select">
                    <option value="">All Conditions</option>
                    <?php foreach ($conditionOptions as $co): ?>
                    <option value="<?= htmlspecialchars($co) ?>" <?= strcasecmp($condition, $co) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($co) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date Acquired (From)</label>
                <input type="date" name="acquired_from" class="form-control" value="<?= htmlspecialchars($acquiredFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Date Acquired (To)</label>
                <input type="date" name="acquired_to" class="form-control" value="<?= htmlspecialchars($acquiredTo) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-dark w-100 me-2"><i class="bi bi-funnel"></i> Apply Filters</button>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <a href="/inventory/reports/asset_register.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-primary bg-opacity-10">
            <div class="card-body text-center">
                <h4 class="mb-0"><?= number_format($totalItems) ?></h4>
                <small class="text-muted">Matching Records</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
            <div class="card-body text-center">
                <h4 class="mb-0"><?= number_format($totalQty, 2) ?></h4>
                <small class="text-muted">Quantity on Hand</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-info bg-opacity-10">
            <div class="card-body text-center">
                <h4 class="mb-0">$<?= number_format($totalValue, 2) ?></h4>
                <small class="text-muted">Current Stock Value</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-warning bg-opacity-10">
            <div class="card-body text-center">
                <h4 class="mb-0"><?= number_format($serializedItems) ?></h4>
                <small class="text-muted">Serialized Item Types</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Item</th>
                        <th>Primary Asset Type</th>
                        <th>Classification</th>
                        <th>Department</th>
                        <th>Custodian</th>
                        <th>Condition</th>
                        <th>Acquired</th>
                        <th class="text-end">Serial Units</th>
                        <th class="text-end">Qty on Hand</th>
                        <th class="text-end">Value</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pageRows)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No records found for the selected search and filters.</td></tr>
                    <?php else: foreach ($pageRows as $r): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($r['item_code']) ?></code>
                            <?php if (!empty($r['asset_code']) && $r['asset_code'] !== $r['item_code']): ?>
                            <span class="badge bg-light text-dark border" title="Asset Tag"><?= htmlspecialchars($r['asset_code']) ?></span>
                            <?php endif; ?>
                            <br>
                            <?= htmlspecialchars($r['item_name']) ?>
                            <?php if (!empty($r['manufacturer']) || !empty($r['model'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars(trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $d = $r['item_domain'] ?? 'INVENTORY'; ?>
                            <?php if ($d === 'ASSET' || $d === 'BOTH'): ?>
                            <span class="badge bg-success" title="<?= htmlspecialchars($ppeLabel) ?>">PPE (Non-Financial Assets)</span>
                            <?php endif; ?>
                            <?php if ($d === 'INVENTORY' || $d === 'BOTH'): ?>
                            <span class="badge bg-primary" title="<?= htmlspecialchars($consumableLabel) ?>">Consumable &amp; Expendable</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['asset_type_name'] ?? $r['inventory_type_name'] ?? '-') ?>
                            <?php if (!empty($r['asset_type_name']) && !empty($r['inventory_type_name'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($r['inventory_type_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['department_name'] ?? '-') ?></td>
                        <td>
                            <?= htmlspecialchars($r['custodian_display'] ?? '-') ?>
                            <?php if (!empty($r['secondary_custodian'])): ?>
                            <br><small class="text-muted">2nd: <?= htmlspecialchars($r['secondary_custodian']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['asset_condition'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['acquired_date'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format((float) $r['serial_count']) ?></td>
                        <td class="text-end"><?= number_format((float) $r['total_qty'], 2) ?></td>
                        <td class="text-end fw-bold">$<?= number_format((float) $r['total_value'], 2) ?></td>
                        <td class="text-end">
                            <a href="/inventory/items/view.php?id=<?= (int) $r['item_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderShowingInfo($page, $perPage, $totalItems); ?>
<?php renderPagination($totalItems, $perPage, $page, $_GET); ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
