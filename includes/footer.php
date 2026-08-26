

        </main>
    </div>
</div> <!-- end container-fluid -->

<!-- Footer -->
<footer class="prms-footer">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="footer-text">
      <strong>&copy; <?= date('Y') ?> Department of Government Chemist</strong>
      <span class="footer-version-badge">PIAMS v3.0</span>
    </div>
    <div class="footer-credit">
      <i class="bi bi-cpu me-1"></i>Developed by ICT Unit &nbsp;&bull;&nbsp; All Rights Reserved
    </div>
  </div>
</footer>


<script>
let rowIndex = 1;

const addRowBtn = document.getElementById('addRow');
if (addRowBtn) {
  addRowBtn.addEventListener('click', function () {
  const tbody = document.querySelector('#itemsTable tbody');
  if (!tbody) return;

  const row = document.createElement('tr');

  row.innerHTML = `
    <td><input name="items[${rowIndex}][name]" class="form-control" required></td>
    <td><input name="items[${rowIndex}][spec]" class="form-control"></td>
    <td><input name="items[${rowIndex}][qty]" type="number" min="1" class="form-control" required></td>
    <td><input name="items[${rowIndex}][remarks]" class="form-control"></td>
    <td class="text-center">
      <button type="button" class="btn btn-danger btn-sm removeRow">×</button>
    </td>
  `;

  tbody.appendChild(row);
  rowIndex++;
  });
}

document.addEventListener('click', function (e) {
  if (e.target.classList.contains('removeRow')) {
    const tr = e.target.closest('tr');
    if (tr) tr.remove();
  }
});
</script>

<!-- Access Denied Modal -->
<div class="modal fade" id="accessDeniedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Access Denied</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        You do not have permission to access this section.
        <br><br>
        Please contact the system administrator if you believe this is an error.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- Decline Modal -->
<div class="modal fade" id="declineModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" action="/procurement/decline.php" class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Decline Procurement Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="id" value="<?= $request['request_id'] ?>">
        <div class="mb-3">
          <label class="form-label">Reason for decline</label>
          <textarea name="reason" class="form-control" rows="4" required></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Confirm Decline</button>
      </div>

    </form>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const isAdmin = <?= json_encode($_SESSION['role'] === 'Admin') ?>;
  let autoCloseTimer = null;

  document.querySelectorAll(".admin-only").forEach(link => {
    link.addEventListener("click", function (e) {
      if (!isAdmin) {
        e.preventDefault(); // stop navigation

        const modalEl = document.getElementById("accessDeniedModal");
        if (!modalEl) return;

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Auto-close after 5 seconds
        autoCloseTimer = setTimeout(() => {
          modal.hide();
        }, 5000);

        // Clear timer if user closes manually
        modalEl.addEventListener("hidden.bs.modal", () => {
          if (autoCloseTimer) {
            clearTimeout(autoCloseTimer);
            autoCloseTimer = null;
          }
        }, { once: true });
      }
    });
  });

});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const showAdminToast = <?= json_encode(
    isset($_SESSION['show_admin_welcome']) && $_SESSION['show_admin_welcome'] === true
  ) ?>;

  if (showAdminToast) {
    const toastEl = document.getElementById("adminWelcomeToast");
    if (!toastEl) return;

    const toast = new bootstrap.Toast(toastEl, {
      delay: 5000, // auto-hide after 5 seconds
      autohide: true
    });
    toast.show();
  }

});
</script>

<?php
if (isset($_SESSION['show_admin_welcome'])) {
    unset($_SESSION['show_admin_welcome']);
}
?>

<!-- Admin Welcome Toast -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 100;">
  <div id="adminWelcomeToast"
       class="toast align-items-center text-bg-success border-0"
       role="alert"
       aria-live="assertive"
       aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body">
        👋 Welcome back, Administrator.<br>
        You have full system access.
      </div>
      <button type="button"
              class="btn-close btn-close-white me-2 m-auto"
              data-bs-dismiss="toast"
              aria-label="Close"></button>
    </div>
  </div>
</div>

<script>
document.addEventListener('click', function (e) {
    const row = e.target.closest('.audit-row');
    if (!row) return;

    const table = row.dataset.table;
    const id = row.dataset.id;

    if (!id || id === '0') return;

    const routes = {
        procurement_requests: `/procurement/view.php?id=${id}`,
        commitments: `/commitments/view.php?request_id=${id}`,
        purchase_orders: `/po/view.php?po_id=${id}`,
        invoices: `/invoice/view.php?id=${id}`
    };

    if (routes[table]) {
        window.location.href = routes[table];
    }
});
</script>

<div class="modal fade" id="auditModal" tabindex="-1">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">🧾 Audit Trail</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="auditFrame" style="width:100%;height:100%;border:none;"></iframe>
      </div>
    </div>
  </div>
</div>
<script>
function openAudit(table, id) {
    document.getElementById('auditFrame').src =
        `/audit/view.php?table=${table}&id=${id}`;

    new bootstrap.Modal(
        document.getElementById('auditModal')
    ).show();
}
</script>

<?php if (!empty($_SESSION['modal'])): ?>
<div class="modal fade" id="systemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-<?= $_SESSION['modal']['type'] ?>">
        <h5 class="modal-title text-white">
          <?= $_SESSION['modal']['title'] ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <?= nl2br(htmlspecialchars($_SESSION['modal']['message'])) ?>
      </div>

      <div class="modal-footer">
        <a href="<?= $_SESSION['modal']['redirect'] ?>"
           class="btn btn-<?= $_SESSION['modal']['type'] ?>">
           OK
        </a>
      </div>

    </div>
  </div>
</div>

<script>
  const modal = new bootstrap.Modal(document.getElementById('systemModal'));
  modal.show();
</script>

<?php unset($_SESSION['modal']); ?>
<?php endif; ?>
<script>
function refreshTable() {
    location.reload();
}
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app-nav.js?v=<?= time() ?>"></script>
<script src="/assets/js/pipeline.js?v=<?= time() ?>"></script>
</body>
</html>