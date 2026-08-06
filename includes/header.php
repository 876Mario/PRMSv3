<?php
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/auth.php";

$__prmsExportEnabled = !preg_match('#^/(auth|uploads|lib|vendor)/#', $_SERVER['SCRIPT_NAME'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DGC IPAMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="/logo/cropped-Logo.png">
  <link rel="shortcut icon" type="image/png" href="/logo/cropped-Logo.png">
  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= time() ?>">
  <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= time() ?>">
  <link rel="stylesheet" href="/assets/css/tables.css?v=<?= time() ?>">
  <link rel="stylesheet" href="/assets/css/modern-ui.css?v=<?= time() ?>">
  <style>
    @media print {
      #sidebarMenu,
      .mobile-topbar,
      .global-topbar,
      .prms-footer,
      .prms-export-toolbar,
      .btn,
      button,
      form { display: none !important; }
      main { width: 100% !important; margin: 0 !important; padding: 0 !important; }
      body { background: #fff !important; }
      .card { box-shadow: none !important; border: 1px solid #ddd !important; }
      a { color: inherit !important; text-decoration: none !important; }
    }
  </style>
</head>

<body class="prms-body">

<!-- Top progress bar (page navigation indicator) -->
<div id="pageLoaderBar"></div>

<!-- Full-page loading overlay shown while navigating between pages -->
<div id="pageLoader" aria-hidden="true">
  <div class="page-loader-spinner">
    <div class="page-loader-ring"></div>
    <div class="page-loader-label">Loading…</div>
  </div>
</div>

<!-- Mobile sidebar toggle -->
<div class="d-md-none mobile-topbar">
  <a href="/dashboard/index.php" class="mobile-topbar-brand">
    <img src="/logo/cropped-Logo.png" alt="Logo" style="height:26px; filter: brightness(0) invert(1);">
    <span>DGC PRMS</span>
  </a>
  <button class="btn btn-sm mobile-menu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
    <i class="bi bi-list"></i>
  </button>
</div>

<div class="container-fluid">
  <div class="row">
    <!-- Sidebar -->
    <nav id="sidebarMenu"
         class="col-md-2 col-lg-2 d-md-block bg-dark sidebar collapse">
      <?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
    </nav>
    <script>
      // Restore the sidebar's scroll position immediately (before first paint)
      // so it stays where the user left it after selecting a link/tab.
      // window.PRMS_SIDEBAR_SCROLL_KEY establishes the shared sessionStorage
      // key used by both this script and assets/js/app-nav.js; whichever
      // script runs first sets it (falling back to the same default string),
      // and the other reuses it so both always agree on the same key.
      (function () {
        var KEY = window.PRMS_SIDEBAR_SCROLL_KEY || 'prms.sidebarScrollTop';
        window.PRMS_SIDEBAR_SCROLL_KEY = KEY;
        var sidebar = document.getElementById('sidebarMenu');
        var saved = sessionStorage.getItem(KEY);
        if (sidebar && saved !== null) {
          sidebar.scrollTop = parseInt(saved, 10) || 0;
        }
      })();
    </script>

    <!-- Main content -->
    <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 pt-3">

<!-- Global Top Bar -->
<div class="global-topbar mb-3">
  <div class="global-topbar-left">
    <a href="/dashboard/index.php" class="topbar-home-link">
      <i class="bi bi-house-fill"></i>
    </a>
    <span class="topbar-divider">
      <i class="bi bi-chevron-right"></i>
    </span>
    <span class="topbar-app-name">DGC PRMS</span>
  </div>
  <div class="global-topbar-right">
    <span class="topbar-date d-none d-sm-flex">
      <i class="bi bi-calendar3 me-1"></i>
      <time datetime="<?= date('Y-m-d') ?>"><?= date('D, j M Y') ?></time>
    </span>
    <div class="topbar-user-chip">
      <div class="topbar-avatar-circle">
        <i class="bi bi-person-fill"></i>
      </div>
      <div class="topbar-user-details">
        <span class="topbar-user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></span>
        <span class="topbar-user-role"><?= htmlspecialchars($_SESSION['role_name'] ?? '') ?></span>
      </div>
    </div>
    <a href="/auth/logout.php" class="topbar-logout-btn" title="Sign out">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</div>

<?php if ($__prmsExportEnabled): ?>
<div class="prms-export-toolbar d-flex justify-content-end gap-2 mb-3">
  <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.prmsExportPdf && window.prmsExportPdf()">
    <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
  </button>
  <button type="button" class="btn btn-sm btn-outline-success" onclick="window.prmsExportExcel && window.prmsExportExcel()">
    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
  </button>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
    <i class="bi bi-printer me-1"></i>Print
  </button>
</div>
<script>
window.prmsExportPdf = function () {
  window.print();
};
window.prmsExportExcel = function () {
  const tables = Array.from(document.querySelectorAll('main table'));
  if (!tables.length) {
    window.print();
    return;
  }
  const title = (document.querySelector('main h1, main h2, main h3, main h4')?.innerText || document.title || 'Export').trim();
  const html = [
    '<html><head><meta charset="UTF-8"></head><body>',
    '<h3>' + title.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</h3>',
    ...tables.map(table => table.outerHTML),
    '</body></html>'
  ].join('');
  const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = title.replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '').toLowerCase() + '.xls';
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(link.href);
};
</script>
<?php endif; ?>

<?php
// Flash and login notification modals
$__flash_msg   = $_SESSION['popup_error']  ?? $_SESSION['popup_success'] ?? null;
$__flash_isErr = isset($_SESSION['popup_error']);
if ($__flash_msg !== null) {
  unset($_SESSION['popup_error'], $_SESSION['popup_success']);
}
$__login_notification = $_SESSION['login_notification'] ?? null;
if ($__login_notification !== null) {
  unset($_SESSION['login_notification']);
}
?>

<?php if ($__flash_msg !== null): ?>
  <!-- Global Flash Modal -->
  <div class="modal fade" id="flashModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0">
        <div class="modal-header <?= $__flash_isErr ? 'bg-danger' : 'bg-success' ?> text-white">
          <h5 class="modal-title">
            <?= $__flash_isErr ? 'Action Blocked' : 'Success' ?>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= htmlspecialchars($__flash_msg) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn <?= $__flash_isErr ? 'btn-danger' : 'btn-success' ?>" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('flashModal');
    if (el && window.bootstrap && bootstrap.Modal) {
      new bootstrap.Modal(el).show();
    }
  });
  </script>

<?php endif; ?>

<?php if (!empty($__login_notification)): ?>
  <!-- Login Notification Modal -->
  <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Welcome</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?= htmlspecialchars($__login_notification) ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('loginModal');
    if (el && window.bootstrap && bootstrap.Modal) {
      new bootstrap.Modal(el).show();
    }
  });
  </script>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div class="container mt-3">
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php unset($_SESSION['error']); endif; ?>
