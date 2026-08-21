<?php

/**
 * UI Feedback Doctrine
 * ====================
 *
 * 🔔 Toasts for flow
 *   - pop()
 *   - Non-blocking
 *   - Success / info / warnings
 *   - Allows user to continue naturally
 *
 * 🪟 Modals for gravity
 *   - modalPop()
 *   - Blocking
 *   - Errors
 *   - Approvals / rejections
 *   - Permissions & irreversible actions
 *
 * RULE:
 *   pop() MUST NOT be used for errors.
 *   error → modalPop()
 */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ================================
   Constants
================================ */
if (!defined('POP_DEFAULT_DELAY_MS')) {
    define('POP_DEFAULT_DELAY_MS', 1800);
}


function logRequestTimeline(PDO $pdo, int $request_id, string $action, string $notes = null) {
    $stmt = $pdo->prepare("
        INSERT INTO audit_log
        (table_name, record_id, action, notes, changed_by)
        VALUES ('procurement_requests', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $request_id,
        $action,
        $notes,
        $_SESSION['full_name'] ?? null
    ]);
}

function timelineIcon($action) {
    return match (true) {
        str_contains($action, 'COMMITMENT') => '💰',
        str_contains($action, 'PO_VARIATION') => '🔄',
        str_contains($action, 'PO') => '📑',
        default => '📝',
    };
}


/* ================================
   Validation Helpers
================================ */

function require_valid_id(string $key, string $redirect): int
{
    if (
        !isset($_GET[$key]) ||
        !is_numeric($_GET[$key]) ||
        (int)$_GET[$key] <= 0
    ) {
        pop("Missing or invalid identifier.", $redirect, POP_DEFAULT_DELAY_MS, 'error');
    }

    return (int)$_GET[$key];
}

/* ================================
   Number Generators
================================ */

function generateRequestNumber(PDO $pdo): string
{
    $last = $pdo->query("
        SELECT request_number
        FROM procurement_requests
        WHERE request_number LIKE 'PR%'
        ORDER BY request_id DESC
        LIMIT 1
    ")->fetchColumn();

    return $last
        ? 'PR' . str_pad(((int)substr($last, 2)) + 1, 3, '0', STR_PAD_LEFT)
        : 'PR001';
}

function generateCommitmentNumber(PDO $pdo): string
{
    $last = $pdo->query("
        SELECT commitment_number
        FROM commitments
        WHERE commitment_number LIKE 'CM%'
        ORDER BY commitment_id DESC
        LIMIT 1
    ")->fetchColumn();

    return $last
        ? 'CM' . str_pad(((int)substr($last, 2)) + 1, 3, '0', STR_PAD_LEFT)
        : 'CM001';
}

function generatePONumber(PDO $pdo): string
{
    $last = $pdo->query("
        SELECT po_number
        FROM purchase_orders
        WHERE po_number LIKE 'PO%'
        ORDER BY po_id DESC
        LIMIT 1
    ")->fetchColumn();

    return $last
        ? 'PO' . str_pad(((int)substr($last, 2)) + 1, 3, '0', STR_PAD_LEFT)
        : 'PO001';
}

/* ================================
   extractDbMessage() — Clean DB exception message
   Strips the PDO/MySQL prefix (e.g. "SQLSTATE[45000]: <HY000>: 1644 ")
   from trigger SIGNAL errors, returning just the human-readable MESSAGE_TEXT.
================================ */
function extractDbMessage(Throwable $e): string {
    $msg = $e->getMessage();

    // MySQL error 1442: a trigger/stored function tried to modify a table
    // that is already being modified by the statement that invoked it
    // (recursive trigger execution). Log full diagnostics — including a
    // backtrace pinpointing the exact workflow action/file/line that issued
    // the offending statement — so the specific trigger/procedure at fault
    // can be identified quickly, then return a clear, actionable message.
    if (strpos($msg, '1442') !== false && stripos($msg, "can't update table") !== false) {
        $trace = $e->getTraceAsString();
        error_log(
            "[DB ERROR 1442 - RECURSIVE TRIGGER] {$msg}\n" .
            "Triggering call site: {$e->getFile()}:{$e->getLine()}\n" .
            "Backtrace:\n{$trace}"
        );
        return 'Database error: This action could not be completed because it triggered a '
             . 'conflicting update on the same record (Error 1442). This has been logged for '
             . 'investigation — please contact the system administrator if the problem persists.';
    }

    // PDO trigger error format: "SQLSTATE[XXXXX]: <YYYYY>: NNNN Actual message"
    if (preg_match('/SQLSTATE\[[^\]]*\]:\s*<[^>]*>:\s*\d*\s*(.+)$/s', $msg, $matches)) {
        return trim($matches[1]);
    }
    return $msg;
}

/* ================================
   pop() — SIMPLE ALERT
================================ */

function pop(
    string $message,
    string $redirect = '',
    int $delay = POP_DEFAULT_DELAY_MS,
    string $type = 'info'
): void {
    
    // 🚨 Doctrine enforcement: error → modalPop
if ($type === 'error') {

    if (defined('APP_ENV') && APP_ENV === 'dev') {
        error_log(
            "[UI DOCTRINE] pop() called with type=error. Auto-upgraded to modalPop(). Message: {$message}"
        );
    }

    modalPop(
        'Error',
        $message,
        $redirect,
        'error',
        $delay
    );

    exit; // 🔥 THIS IS THE MISSING LINE
}

if ($redirect !== '' && !str_starts_with($redirect, '/')) {

    // Convert full URL to relative path
    if (preg_match('/^https?:\/\//i', $redirect)) {
        $parsed = parse_url($redirect);
        $redirect = $parsed['path'] ?? '/';
    }

    // After normalization, still not relative → hard fail
    if (!str_starts_with($redirect, '/')) {
        throw new InvalidArgumentException("Redirect must be a relative path");
    }
}


    $map = [
        'success' => ['bg-success', '✅', 'text-success'],
        'error'   => ['bg-danger',  '❌', 'text-danger'],
        'warning' => ['bg-warning text-dark', '⚠️', 'text-warning'],
        'info'    => ['bg-primary', 'ℹ️', 'text-primary'],
    ];

    [$bg, $icon, $textColor] = $map[$type] ?? $map['info'];

    $safeMessage  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRedirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');

    // Body content: show a centered card when redirecting so the page is not blank.
    // When there is no redirect, auto-hide the toast after $delay ms.
    $delayJs    = (int) $delay;
    $bodyContent = '';
    if ($redirect !== '') {
        $redirectJs = json_encode($redirect, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $bodyContent = <<<HTML

<div class="d-flex justify-content-center align-items-center" style="min-height:100vh;">
  <div class="text-center p-4">
    <div class="{$textColor} mb-3" style="font-size:3rem;">{$icon}</div>
    <p class="fs-5 fw-semibold mb-1">{$safeMessage}</p>
    <p class="text-muted small">Redirecting, please wait…</p>
    <div class="spinner-border spinner-border-sm text-secondary mt-2" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>
  </div>
</div>

<script>
setTimeout(function () {
    window.location.replace({$redirectJs});
}, {$delayJs});
</script>
HTML;
    } else {
        $bodyContent = <<<HTML
<script>
setTimeout(function () {
    document.querySelector('.toast')?.classList.remove('show');
}, {$delayJs});
</script>
HTML;
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Notice</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
.toast-container {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 1056;
}
</style>
</head>

<body>

<div class="toast-container">
  <div class="toast show {$bg} text-white" role="alert">
    <div class="toast-body d-flex align-items-center gap-2">
      <span style="font-size:1.3rem;">{$icon}</span>
      <span>{$safeMessage}</span>
    </div>
  </div>
</div>

{$bodyContent}

</body></html>
HTML;

    exit;
}


/* ================================
   modalPop() — MODAL UI
================================ */
function modalPop(
    string $title,
    string $message,
    string $redirect = '',
    string $type = 'info',
    int $delay = POP_DEFAULT_DELAY_MS
): void {

    if ($redirect !== '' && !str_starts_with($redirect, '/')) {
        throw new InvalidArgumentException("Redirect must be a relative path");
    }

    $map = [
        'success' => ['✔️', 'text-success', 'success'],
        'error'   => ['❌', 'text-danger', 'danger'],
        'warning' => ['⚠️', 'text-warning', 'warning'],
        'info'    => ['ℹ️', 'text-primary', 'primary'],
    ];

    [$icon, $color, $btn] = $map[$type] ?? $map['info'];

    $safeTitle    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRedirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');

    // 🔹 Optional "Create PO Variation" button
    $variationButton = '';
    if (str_contains($redirect, '/po/view.php') && isset($_GET['po_id'])) {
        $poId = (int)$_GET['po_id'];
        $variationButton = "
            <a href=\"/po/variation_create.php?po_id={$poId}\"
               class=\"btn btn-outline-secondary w-100\">
               ➕ Create PO Variation
            </a>
        ";
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$safeTitle}</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.modal-content {
    border-radius:16px;
    border:none;
    box-shadow:0 15px 40px rgba(0,0,0,.2);
}
.pop-icon {
    font-size:3rem;
}
</style>
</head>
<body class="bg-light">

<div class="modal fade show" style="display:block;" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="modal-body">
        <div class="pop-icon {$color} mb-2">{$icon}</div>
        <h5 class="fw-bold mb-2">{$safeTitle}</h5>
        <p class="text-muted mb-4">{$safeMessage}</p>

        <div class="d-grid gap-2">
            <button onclick="window.location.href='{$safeRedirect}'"
                    class="btn btn-{$btn}">
                Continue
            </button>
            {$variationButton}
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop fade show"></div>

</body>
</html>
HTML;

    exit;
}


/* ================================
   Dashboard Helpers
================================ */

function money($amount, string $currency = 'JMD'): string
{
    $symbol = $currency === 'USD' ? 'US$' : '$';
    return $symbol . number_format((float)$amount, 2);
}

/**
 * Format a monetary amount with the currency code prefix.
 * Used across views, dashboards, and approval pages for consistent display.
 *
 * @param float|string $amount  The numeric amount
 * @param string       $currency Currency code from the request ('JMD', 'USD')
 * @param int          $decimals Number of decimal places (default 2)
 * @return string  e.g. "JMD 1,234.56" or "USD 500.00"
 */
function fmtCurrency($amount, string $currency = 'JMD', int $decimals = 2): string
{
    $code = in_array($currency, ['JMD', 'USD']) ? $currency : 'JMD';
    return $code . ' ' . number_format((float)$amount, $decimals);
}

function trend($current, $previous): array
{
    if ($previous <= 0) {
        return ['percent' => 0, 'icon' => '—', 'class' => 'text-muted'];
    }

    $change = (($current - $previous) / $previous) * 100;

    return [
        'percent' => round(abs($change), 1),
        'icon' => $change >= 0 ? '▲' : '▼',
        'class' => $change >= 0 ? 'text-danger' : 'text-success'
    ];
}

/* ================================
   Audit Helpers
================================ */

if (!function_exists('logAudit')) {
function logAudit(PDO $pdo, string $table, ?int $recordId, string $action, ?string $notes = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO audit_log
        (table_name, record_id, action, changed_by, notes, change_date)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $table,
        $recordId,
        strtoupper($action),
        $_SESSION['full_name'] ?? null,
        $notes
    ]);
}
}

function auditUrl(string $table, int $id): string
{
    return "/audit/view.php?table={$table}&id={$id}";
}

function auditIcon(string $action): string
{
    return match ($action) {
        'STATUS_CHANGE'   => '🔁',
        'CREATE'          => '➕',
        'UPDATE'          => '✏️',
        'DELETE'          => '🗑️',
        'PASSWORD_CHANGE' => '🔐',
        default           => '📌'
    };
}


function flash($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

if (!function_exists('hasPermission')) {
    function hasPermission($perm) {
        return has_permission($perm);
    }
}

/**
 * Format date/time to Jamaica timezone with 12-hour format
 * @param string $datetime - DateTime string from database
 * @param string $format - Format string (default: 'd M Y, g:i A' for "18 Feb 2026, 2:30 PM")
 * @return string - Formatted datetime
 */
function formatJamaicanDateTime($datetime, $format = 'd M Y, g:i A'): string {
    if (empty($datetime)) {
        return '—';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimeZone(new DateTimeZone('America/Jamaica'));
        return $dt->format($format);
    } catch (Exception $e) {
        return '—';
    }
}

/**
 * Alias for formatJamaicanDateTime - format date only (12-hour not needed)
 */
function formatJamaicanDate($datetime, $format = 'd M Y'): string {
    if (empty($datetime)) {
        return '—';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimeZone(new DateTimeZone('America/Jamaica'));
        return $dt->format($format);
    } catch (Exception $e) {
        return '—';
    }
}


/**
 * Return a color-coded HTML status badge for a procurement request status.
 *
 * @param string $status  Value of procurement_requests.status
 * @return string         Safe inline HTML <span> badge
 */
if (!function_exists('statusBadge')) {
function statusBadge(string $status): string {
    $map = [
        'DRAFT'                 => ['#f5f5f5', '#666',    '📝'],
        'SUBMITTED'             => ['#fff3cd', '#856404', '📤'],
        'HOD_APPROVED'          => ['#cfe2ff', '#084298', '✅'],
        'FUNDS_VERIFIED'        => ['#d1e7dd', '#0a3622', '💰'],
        'DIRECTOR_APPROVED'     => ['#cfe2ff', '#084298', '✅'],
        'GC_APPROVED'           => ['#d1e7dd', '#0a3622', '✅'],
        'RFQ_LETTER_AVAILABLE'  => ['#d0f0fd', '#0c566b', '📬'],
        'QUOTE_REVIEW_PENDING'  => ['#fff3cd', '#856404', '🔍'],
        'QUOTE_REQUESTOR_REVIEW_PENDING' => ['#ffe5b4', '#8a4b08', '🧾'],
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['#d1e7dd', '#0a3622', '✅'],
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['#cff4fc', '#055160', '🛡️'],
        'QUOTE_APPROVED'        => ['#cfe2ff', '#084298', '✔️'],
        'COMMITMENTS_PENDING'   => ['#fff3cd', '#856404', '⏳'],
        'COMMITMENT_APPROVED'   => ['#d1e7dd', '#0a3622', '📋'],
        'COMMITMENT_DECLINED'   => ['#f8d7da', '#842029', '❌'],
        'PO_PENDING'            => ['#d1e7dd', '#0a3622', '📄'],
        'INVOICE_RECEIVED'      => ['#cfe2ff', '#084298', '🧾'],
        'PROCUREMENT_STAGE'     => ['#e2e3e5', '#333',    '⚙️'],
        'EVALUATION_STAGE'      => ['#e2e3e5', '#333',    '📊'],
        'COMMITTEE_RECOMMENDED' => ['#cfe2ff', '#084298', '👥'],
        'AWARDED'               => ['#d1e7dd', '#0a3622', '🏆'],
        'COMPLETED'             => ['#212529', '#fff',    '✓'],
        'DECLINED'              => ['#f8d7da', '#842029', '❌'],
        'CANCELLED'             => ['#f8d7da', '#842029', '🚫'],
        'PAUSED'                => ['#fff3cd', '#856404', '⏸️'],
    ];

    $status = strtoupper($status);
    [$bg, $color, $icon] = $map[$status] ?? ['#e2e3e5', '#333', '📌'];

    $label = match($status) {
        'DRAFT'                 => 'Draft',
        'SUBMITTED'             => 'Submitted',
        'HOD_APPROVED'          => 'HOD Approved',
        'FUNDS_VERIFIED'        => 'Funds Verified',
        'DIRECTOR_APPROVED'     => 'Director Approved',
        'GC_APPROVED'           => 'GC Approved',
        'RFQ_LETTER_AVAILABLE'  => 'RFQ Letters',
        'QUOTE_REVIEW_PENDING'  => 'Quote Review',
        'QUOTE_REQUESTOR_REVIEW_PENDING' => 'Pending Requestor Review',
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => 'Requestor Review Approved',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => 'Pending Branch Head Approval',
        'QUOTE_APPROVED'        => 'Quote Approved',
        'COMMITMENTS_PENDING'   => 'Commitment Form',
        'COMMITMENT_APPROVED'   => 'Committed',
        'COMMITMENT_DECLINED'   => 'Commitment Declined',
        'PO_PENDING'            => 'PO Created',
        'INVOICE_RECEIVED'      => 'Invoice Received',
        'PROCUREMENT_STAGE'     => 'Procurement',
        'EVALUATION_STAGE'      => 'Evaluation',
        'COMMITTEE_RECOMMENDED' => 'Committee Rec.',
        'AWARDED'               => 'Awarded',
        'COMPLETED'             => 'Completed',
        'DECLINED'              => 'Declined',
        'CANCELLED'             => 'Cancelled',
        'PAUSED'                => 'Paused',
        default                 => str_replace('_', ' ', $status),
    };

    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return "<span style=\"display:inline-flex;align-items:center;gap:0.25rem;background:{$bg};"
         . "color:{$color};padding:0.3rem 0.65rem;border-radius:20px;font-size:0.78rem;font-weight:600;\">"
         . "{$icon} {$safeLabel}</span>";
}
}


/**
 * Common typos fixed:
 * - USB → USD
 * - Defaults to JMD if invalid
 * 
 * @param string $currency Currency code from database
 * @return string Normalized currency code (JMD or USD)
 */
function normalizeCurrency(string $currency = 'JMD'): string {
    $currency = strtoupper(trim($currency ?? 'JMD'));
    
    // Fix common typo: USB → USD
    if ($currency === 'USB') {
        $currency = 'USD';
    }
    
    // Validate and return - default to JMD if invalid
    return in_array($currency, ['JMD', 'USD']) ? $currency : 'JMD';
}

/**
 * Format file size in human-readable format
 * 
 * @param int $bytes File size in bytes
 * @return string Formatted file size (e.g., "1.5 MB")
 */
function formatFileSize(int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Save reimbursement invoice attachment
 * Handles file validation, upload, and database insertion
 * 
 * @param PDO $pdo Database connection
 * @param array $file $_FILES array entry
 * @param int $reimb_invoice_id Reimbursement invoice ID
 * @param int $uploaded_by User ID
 * @return int Attachment ID on success
 * @throws Exception on validation or upload failure
 */
function saveReimbursementAttachment(PDO $pdo, array $file, int $reimb_invoice_id, int $uploaded_by): int {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed. Please try again.');
    }

    // Validate file type via MIME
    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new Exception('Invalid file type. Allowed types: PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX.');
    }

    // Also validate by extension as a secondary guard
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new Exception('Invalid file extension. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX.');
    }

    // Validate file size (10 MB max) using actual file size, not client-reported size
    $maxBytes = 10 * 1024 * 1024;
    $actualFileSize = filesize($file['tmp_name']);
    if ($actualFileSize === false || $actualFileSize > $maxBytes) {
        throw new Exception('File size exceeds the 10 MB limit.');
    }

    // Build upload directory
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/reimbursement_invoice_attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Sanitize and generate unique filename
    $safeExt      = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $uniqueName   = 'REIMB_' . $reimb_invoice_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
    $uploadPath   = $uploadDir . $uniqueName;
    $relativePath = '/uploads/reimbursement_invoice_attachments/' . $uniqueName;

    try {
        // Move uploaded file and ensure cleanup on failure
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to save the file. Please try again.');
        }

        // Sanitize original filename for storage
        $originalName = preg_replace('/[^\w.\-]/', '_', basename($file['name']));

        // Persist to database
        $ins = $pdo->prepare("
            INSERT INTO reimbursement_invoice_attachments
                (reimb_invoice_id, file_name, original_file_name, file_path, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $reimb_invoice_id,
            $uniqueName,
            $originalName,
            $relativePath,
            $mimeType,
            (int)$actualFileSize,
            $uploaded_by,
        ]);

        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // Clean up file on any error (upload or DB)
        if (file_exists($uploadPath)) {
            unlink($uploadPath);
        }
        throw $e;
    }
}
