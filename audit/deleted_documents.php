<?php
/**
 * /audit/deleted_documents.php
 * ============================
 * Authorized audit view listing all request documents that have been
 * soft-deleted, along with the original upload metadata and the deletion
 * history (who deleted it, when, and why).
 */
$REQUIRE_PERMISSION = 'view_audit_logs';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';

extract(getPaginationParams(20));

$where = ['rd.is_deleted = 1'];
$params = [];

if (!empty($_GET['request_number'])) {
    $where[] = 'pr.request_number LIKE :request_number';
    $params[':request_number'] = '%' . trim($_GET['request_number']) . '%';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT rd.document_id, rd.request_id, rd.document_type, rd.document_name,
           rd.document_path, rd.uploaded_by, rd.uploaded_at, rd.notes,
           rd.deleted_by, rd.deleted_at, rd.deletion_reason,
           u.full_name AS uploader_name,
           pr.request_number, pr.status AS request_status
    FROM request_documents rd
    LEFT JOIN users u ON rd.uploaded_by = u.user_id
    LEFT JOIN procurement_requests pr ON rd.request_id = pr.request_id
    $whereSQL
    ORDER BY rd.deleted_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$deletedDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "
    SELECT COUNT(*)
    FROM request_documents rd
    LEFT JOIN procurement_requests pr ON rd.request_id = pr.request_id
    $whereSQL
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-trash-fill me-2"></i>Deleted Request Documents</h4>
    <span class="badge bg-secondary"><?= $totalRows ?> deleted document<?= $totalRows !== 1 ? 's' : '' ?></span>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="request_number" class="form-control form-control-sm"
               placeholder="Search by request number..."
               value="<?= htmlspecialchars($_GET['request_number'] ?? '') ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        <a href="/audit/deleted_documents.php" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <?php if (count($deletedDocs) === 0): ?>
            <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> No deleted documents found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Request #</th>
                        <th>Type</th>
                        <th>File</th>
                        <th>Uploaded By</th>
                        <th>Uploaded At</th>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletedDocs as $doc): ?>
                    <tr>
                        <td class="small">
                            <a href="/procurement/view.php?id=<?= (int)$doc['request_id'] ?>">
                                <?= htmlspecialchars($doc['request_number'] ?? ('#' . $doc['request_id'])) ?>
                            </a>
                        </td>
                        <td class="small"><?= htmlspecialchars($doc['document_type']) ?></td>
                        <td class="small"><?= htmlspecialchars($doc['document_name']) ?></td>
                        <td class="small"><?= htmlspecialchars($doc['uploader_name'] ?? 'N/A') ?></td>
                        <td class="small"><?= $doc['uploaded_at'] ? date('d M Y H:i', strtotime($doc['uploaded_at'])) : '—' ?></td>
                        <td class="small"><?= htmlspecialchars($doc['deleted_by'] ?? 'N/A') ?></td>
                        <td class="small"><?= $doc['deleted_at'] ? date('d M Y H:i', strtotime($doc['deleted_at'])) : '—' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($doc['deletion_reason'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
