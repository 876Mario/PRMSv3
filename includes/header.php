<?php
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/auth.php";

$__prmsExportEnabled = !preg_match('#^/(auth|uploads|lib|vendor)/#', $_SERVER['SCRIPT_NAME'] ?? '');
if ($__prmsExportEnabled && empty($_SESSION['prms_export_csrf_token'])) {
  $_SESSION['prms_export_csrf_token'] = bin2hex(random_bytes(32));
}
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
  <link rel="stylesheet" href="/assets/css/pipeline.css?v=<?= time() ?>">
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
    <!-- Notification Bell -->
    <div id="prms-notif-bell" style="position:relative; margin-right:0.25rem;">
      <button id="prms-notif-btn"
              onclick="prmsToggleNotifDropdown()"
              style="background:none; border:none; cursor:pointer; color:#ccc; font-size:1.25rem; padding:0.25rem 0.5rem; position:relative;"
              title="Notifications"
              aria-label="Notifications">
        <i class="bi bi-bell-fill"></i>
        <span id="prms-notif-badge"
              style="display:none; position:absolute; top:-4px; right:-4px; background:#e74c3c; color:#fff; font-size:0.6rem; font-weight:700; border-radius:50%; min-width:16px; height:16px; line-height:16px; text-align:center; padding:0 3px;">0</span>
      </button>
      <div id="prms-notif-dropdown"
           style="display:none; position:absolute; right:0; top:calc(100% + 6px); width:360px; background:#fff; border:1px solid #e0e0e0; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:9999; overflow:hidden;">
        <div style="padding:0.75rem 1rem; border-bottom:1px solid #eee; display:flex; align-items:center; justify-content:space-between;">
          <span style="font-weight:700; font-size:0.9rem; color:#333;">Notifications</span>
          <button onclick="prmsMarkAllRead()" style="background:none; border:none; cursor:pointer; font-size:0.75rem; color:#667eea; font-weight:600;">Mark all read</button>
        </div>
        <div id="prms-notif-list" style="max-height:380px; overflow-y:auto;"></div>
        <div style="padding:0.6rem 1rem; border-top:1px solid #eee; text-align:center;">
          <small style="color:#999; font-size:0.75rem;">Showing latest 20 notifications</small>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var notifOpen = false;
      window.prmsToggleNotifDropdown = function () {
        var dd = document.getElementById('prms-notif-dropdown');
        notifOpen = !notifOpen;
        dd.style.display = notifOpen ? 'block' : 'none';
        if (notifOpen) { prmsLoadNotifications(); }
      };
      document.addEventListener('click', function (e) {
        var bell = document.getElementById('prms-notif-bell');
        if (bell && !bell.contains(e.target)) {
          document.getElementById('prms-notif-dropdown').style.display = 'none';
          notifOpen = false;
        }
      });

      function prmsLoadNotifications() {
        fetch('/api/notifications.php?action=list&limit=20')
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var list = document.getElementById('prms-notif-list');
            var items = data.notifications || [];
            if (!items.length) {
              list.innerHTML = '<div style="padding:2rem; text-align:center; color:#aaa; font-size:0.85rem;"><i class="bi bi-check2-circle" style="font-size:1.5rem; color:#43e97b; display:block; margin-bottom:0.5rem;"></i>All caught up!</div>';
              return;
            }
            list.innerHTML = items.map(function (n) {
              var icon = prmsNotifIcon(n.type);
              var unreadStyle = n.is_read == '0' ? 'background:#f0f4ff;' : '';
              var age = prmsAge(n.created_at);
              return '<a href="' + (n.action_url || '#') + '" onclick="prmsMarkRead(' + n.id + ', this)" ' +
                'style="display:block; padding:0.75rem 1rem; border-bottom:1px solid #f0f0f0; text-decoration:none; color:inherit; ' + unreadStyle + '">' +
                '<div style="display:flex; align-items:flex-start; gap:0.6rem;">' +
                '<span style="font-size:1.1rem; flex-shrink:0;">' + icon + '</span>' +
                '<div style="flex:1; min-width:0;">' +
                '<div style="font-size:0.82rem; font-weight:' + (n.is_read == '0' ? '700' : '400') + '; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + prmsEsc(n.title) + '</div>' +
                (n.body ? '<div style="font-size:0.75rem; color:#666; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + prmsEsc(n.body) + '</div>' : '') +
                '<div style="font-size:0.7rem; color:#aaa; margin-top:3px;">' + age + '</div>' +
                '</div>' +
                (n.is_read == '0' ? '<span style="width:8px; height:8px; background:#667eea; border-radius:50%; flex-shrink:0; margin-top:4px;"></span>' : '') +
                '</div></a>';
            }).join('');
          })
          .catch(function () {});
      }

      function prmsNotifIcon(type) {
        var icons = {
          approval_needed:   '🔔',
          return_correction: '↩️',
          clarification:     '❓',
          rejection:         '❌',
          cancellation:      '🚫',
          draft_ready:       '📝',
          submission:        '✅'
        };
        return icons[type] || '🔔';
      }

      function prmsAge(dt) {
        var d = new Date(dt.replace(' ', 'T'));
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)   return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
      }

      function prmsEsc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
      }

      window.prmsMarkRead = function (id, el) {
        fetch('/api/notifications.php?action=mark_read&id=' + id, { method: 'POST', keepalive: true })
          .then(function () { prmsRefreshBadge(); });
      };

      window.prmsMarkAllRead = function () {
        fetch('/api/notifications.php?action=mark_all_read', { method: 'POST' })
          .then(function () { prmsRefreshBadge(); prmsLoadNotifications(); });
      };

      function prmsRefreshBadge() {
        fetch('/api/notifications.php?action=count')
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var badge = document.getElementById('prms-notif-badge');
            if (!badge) return;
            var count = d.unread || 0;
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
          })
          .catch(function () {});
      }

      // Initial badge load + poll every 60 s
      prmsRefreshBadge();
      setInterval(prmsRefreshBadge, 60000);
    })();
    </script>
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
window.PRMS_EXPORT_CSRF_TOKEN = <?= json_encode($_SESSION['prms_export_csrf_token'] ?? '') ?>;
window.prmsExportPdf = function () {
  const main = document.querySelector('main') || document.body;
  const title = (document.querySelector('main h1, main h2, main h3, main h4')?.innerText || document.title || 'Export').trim();
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '/reports/export_page_pdf.php';
  form.target = '_blank';
  form.style.display = 'none';
  const titleInput = document.createElement('input');
  titleInput.type = 'hidden';
  titleInput.name = 'title';
  titleInput.value = title;
  const htmlInput = document.createElement('input');
  htmlInput.type = 'hidden';
  htmlInput.name = 'html';
  htmlInput.value = main.innerHTML;
  const tokenInput = document.createElement('input');
  tokenInput.type = 'hidden';
  tokenInput.name = 'csrf_token';
  tokenInput.value = window.PRMS_EXPORT_CSRF_TOKEN || '';
  form.appendChild(titleInput);
  form.appendChild(htmlInput);
  form.appendChild(tokenInput);
  document.body.appendChild(form);
  form.submit();
  form.remove();
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
