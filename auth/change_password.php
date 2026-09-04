<?php
require_once $_SERVER['DOCUMENT_ROOT']."/config/auth.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: /auth/login.php");
    exit;
}

// Check if password change is required (forced by admin)
$isForcedChange = isset($_SESSION['force_pw_change']);

$error = "";
$successMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate password match
    if (empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
        $error = "Both password fields are required.";
    } elseif ($_POST['new_password'] !== $_POST['confirm_password']) {
        $error = "Passwords do not match.";
    } elseif (($passwordPolicyError = validatePasswordPolicy($_POST['new_password'])) !== null) {
        $error = $passwordPolicyError;
    } else {
        try {
            $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = ?, must_change_password = 0, password_changed_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$hash, $_SESSION['user_id']]);
            
            $pdo->prepare("
                INSERT INTO audit_log (table_name, record_id, action, changed_by, notes)
                VALUES ('users', ?, 'PASSWORD_CHANGE', ?, 'Password updated')
            ")->execute([$_SESSION['user_id'], $_SESSION['full_name'] ?? null]);

            // Clear forced password change if applicable
            if ($isForcedChange) {
                unset($_SESSION['force_pw_change']);
                $successMessage = "Password updated successfully! Redirecting...";
            } else {
                $successMessage = "Password updated successfully!";
            }
        } catch (PDOException $e) {
            $error = "An error occurred while updating your password. Please try again.";
            error_log("Password change error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Change Password | DGC Procurement</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
  :root {
    --dgc-green: #0b5e2b;
    --dgc-green-light: #0d7a38;
    --dgc-gold: #c9a227;
  }

  * { box-sizing: border-box; }

  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  }

  /* Modal backdrop */
  .modal-backdrop {
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(3px);
  }

  /* Modal styling */
  .modal.show .modal-dialog {
    animation: modalSlideUp 0.4s ease-out;
  }

  @keyframes modalSlideUp {
    from {
      opacity: 0;
      transform: translateY(30px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .modal-content {
    border: none;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    overflow: hidden;
  }

  .modal-header {
    background: linear-gradient(90deg, var(--dgc-green) 0%, var(--dgc-gold) 100%);
    border: none;
    padding: 1.5rem;
    position: relative;
  }

  .modal-header::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, var(--dgc-green) 0%, var(--dgc-gold) 100%);
  }

  .modal-title {
    font-weight: 700;
    font-size: 1.4rem;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 0.6rem;
  }

  .modal-title i {
    font-size: 1.5rem;
  }

  .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.7;
    transition: opacity 0.2s;
  }

  .btn-close:hover {
    opacity: 1;
  }

  .modal-body {
    padding: 2rem;
    background: #fff;
  }

  .modal-subtitle {
    color: #666;
    font-size: 0.95rem;
    margin-bottom: 1.5rem;
    line-height: 1.4;
  }

  /* Form inputs */
  .form-floating {
    position: relative;
    margin-bottom: 1.5rem;
  }

  .form-floating label {
    position: absolute;
    top: 0;
    left: 0;
    font-size: 0.8rem;
    font-weight: 600;
    color: #333;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .form-floating .form-control {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 0.9rem 2.5rem 0.9rem 2.5rem;
    height: 2.8rem;
    font-size: 0.95rem;
    background: #fafafa;
    transition: all 0.25s ease;
    margin-top: 1.8rem;
    font-weight: 500;
  }

  .form-floating .form-control:focus {
    border-color: var(--dgc-green);
    box-shadow: 0 0 0 3px rgba(11, 94, 43, 0.08);
    background: #fff;
    outline: none;
  }

  .input-icon {
    position: absolute;
    left: 0.9rem;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 1rem;
    pointer-events: none;
    transition: color 0.25s;
  }

  .form-floating:focus-within .input-icon {
    color: var(--dgc-green);
  }

  .pw-toggle {
    position: absolute;
    right: 0.9rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #999;
    font-size: 1rem;
    cursor: pointer;
    padding: 0.4rem 0;
    transition: color 0.25s;
    z-index: 10;
  }

  .pw-toggle:hover {
    color: var(--dgc-green);
  }

  /* Password rules */
  .pw-rules {
    font-size: 0.8rem;
    color: #666;
    margin: 0.8rem 0 1.5rem;
    padding: 0.9rem;
    background: #f9f9f9;
    border-radius: 8px;
    border: 1px solid #eee;
  }

  .pw-rules .rule {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0;
    transition: all 0.2s;
  }

  .pw-rules .rule i {
    font-size: 0.85rem;
    min-width: 1rem;
  }

  .pw-rules .rule.pass {
    color: var(--dgc-green);
    font-weight: 600;
  }

  .pw-rules .rule.pass i {
    animation: scaleUp 0.3s ease-out;
  }

  .pw-rules .rule.fail {
    color: #ccc;
  }

  /* Match indicator */
  .match-indicator {
    font-size: 0.8rem;
    min-height: 1.2rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
  }

  .match-indicator span {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 600;
  }

  .match-indicator span.text-success {
    color: var(--dgc-green);
  }

  /* Alerts */
  .alert-modal {
    border-radius: 10px;
    border-left: 4px solid;
    font-size: 0.9rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
  }

  .alert-danger.alert-modal {
    background: #fef2f2;
    border-color: #fecaca;
    color: #7f1d1d;
  }

  .alert-success.alert-modal {
    background: #f0fdf4;
    border-color: #86efac;
    color: #166534;
  }

  .alert-modal i {
    margin-right: 0.5rem;
  }

  /* Buttons */
  .modal-footer {
    background: #f9f9f9;
    border-top: 1px solid #e9ecef;
    padding: 1rem 2rem;
    gap: 0.75rem;
  }

  .btn-modal {
    border: none;
    border-radius: 8px;
    font-weight: 600;
    padding: 0.7rem 1.5rem;
    transition: all 0.25s ease;
  }

  .btn-modal.btn-primary {
    background: linear-gradient(135deg, var(--dgc-green) 0%, var(--dgc-green-light) 100%);
    color: #fff;
    box-shadow: 0 3px 10px rgba(11, 94, 43, 0.2);
  }

  .btn-modal.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(11, 94, 43, 0.3);
    color: #fff;
  }

  .btn-modal.btn-primary:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .btn-modal.btn-secondary {
    background: #e9ecef;
    color: #495057;
  }

  .btn-modal.btn-secondary:hover {
    background: #dee2e6;
    color: #212529;
  }

  @keyframes scaleUp {
    from { transform: scale(0.8); }
    to { transform: scale(1); }
  }
</style>
</head>

<body>

<!-- Modal -->
<div class="modal fade" id="changePwModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-lock"></i> Change Password
        </h5>
        <button type="button" class="btn-close" id="closeBtn" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Body -->
      <div class="modal-body">
        <p class="modal-subtitle">Update your password to keep your account secure</p>

        <?php if ($isForcedChange): ?>
        <div class="alert alert-warning alert-modal d-flex align-items-start gap-2 mb-3" role="alert">
          <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
          <div><strong>Required:</strong> Your administrator requires you to change your password.</div>
        </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-modal d-flex align-items-start gap-2 mb-3" role="alert">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if (!empty($successMessage)): ?>
          <div class="alert alert-success alert-modal d-flex align-items-start gap-2 mb-3" role="alert">
            <i class="bi bi-check-circle-fill flex-shrink-0"></i>
            <span><?= htmlspecialchars($successMessage) ?></span>
          </div>
          <script>
            setTimeout(function() {
              window.location.href = '/dashboard/index.php';
            }, 1500);
          </script>
        <?php endif; ?>

        <!-- Form -->
        <form method="post" id="changePwForm" autocomplete="off">

          <!-- New Password -->
          <div class="form-floating position-relative">
            <i class="bi bi-lock-fill input-icon"></i>
            <input
              type="password"
              name="new_password"
              id="newPassword"
              class="form-control"
              placeholder="   New password"
              required
              autofocus
              oninput="checkStrength(); checkMatch();"
            >
            <label for="newPassword">New Password</label>
            <button type="button" class="pw-toggle" onclick="togglePw('newPassword', 'pwIcon1')" tabindex="-1">
              <i class="bi bi-eye" id="pwIcon1"></i>
            </button>
          </div>

          <!-- Password Rules -->
          <div class="pw-rules" id="pwRules">
            <div class="rule fail" id="ruleLen"><i class="bi bi-circle-fill"></i> <span>At least 8 characters</span></div>
            <div class="rule fail" id="ruleUpper"><i class="bi bi-circle-fill"></i> <span>One uppercase letter (A-Z)</span></div>
            <div class="rule fail" id="ruleNum"><i class="bi bi-circle-fill"></i> <span>One number (0-9)</span></div>
          </div>

          <!-- Confirm Password -->
          <div class="form-floating position-relative">
            <i class="bi bi-lock input-icon"></i>
            <input
              type="password"
              name="confirm_password"
              id="confirmPassword"
              class="form-control"
              placeholder="   Confirm password"
              required
              oninput="checkMatch();"
            >
            <label for="confirmPassword">Confirm Password</label>
            <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword', 'pwIcon2')" tabindex="-1">
              <i class="bi bi-eye" id="pwIcon2"></i>
            </button>
          </div>

          <!-- Match Indicator -->
          <div class="match-indicator" id="matchIndicator"></div>

        </form>
      </div>

      <!-- Footer -->
      <div class="modal-footer">
        <button type="button" class="btn btn-modal btn-secondary" id="cancelBtn" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i> Cancel
        </button>
        <button type="submit" form="changePwForm" class="btn btn-modal btn-primary" id="submitBtn" disabled>
          <i class="bi bi-check-circle me-1"></i> Update Password
        </button>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const isForcedChange = <?php echo $isForcedChange ? 'true' : 'false'; ?>;
let modal;

// Initialize modal on page load
document.addEventListener('DOMContentLoaded', function() {
  modal = new bootstrap.Modal(document.getElementById('changePwModal'), {
    backdrop: isForcedChange ? 'static' : true,
    keyboard: !isForcedChange
  });
  modal.show();

  // Disable close button if forced
  if (isForcedChange) {
    document.getElementById('closeBtn').style.display = 'none';
    document.getElementById('cancelBtn').style.display = 'none';
  }

  checkStrength();
  updateSubmitButton(false);
  document.getElementById('newPassword').focus();
});

// Toggle password visibility
function togglePw(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('bi-eye', 'bi-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('bi-eye-slash', 'bi-eye');
  }
}

// Check password strength
function checkStrength() {
  const pw = document.getElementById('newPassword').value;
  const hasLen = pw.length >= 8;
  const hasUpper = /[A-Z]/.test(pw);
  const hasNum = /[0-9]/.test(pw);
  
  updateRule('ruleLen', hasLen);
  updateRule('ruleUpper', hasUpper);
  updateRule('ruleNum', hasNum);
  
  checkMatch();
}

// Update rule display
function updateRule(ruleId, passed) {
  const el = document.getElementById(ruleId);
  const icon = el.querySelector('i');
  
  if (passed) {
    el.classList.add('pass');
    el.classList.remove('fail');
    icon.className = 'bi bi-check-circle-fill';
  } else {
    el.classList.remove('pass');
    el.classList.add('fail');
    icon.className = 'bi bi-circle-fill';
  }
}

// Check password match
function checkMatch() {
  const pw = document.getElementById('newPassword').value;
  const confirmPw = document.getElementById('confirmPassword').value;
  const indicator = document.getElementById('matchIndicator');
  
  if (!confirmPw) {
    indicator.innerHTML = '';
    updateSubmitButton(false);
    return;
  }
  
  const hasLen = pw.length >= 8;
  const hasUpper = /[A-Z]/.test(pw);
  const hasNum = /[0-9]/.test(pw);
  const strengthOk = hasLen && hasUpper && hasNum;
  
  if (pw === confirmPw && strengthOk) {
    indicator.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
    updateSubmitButton(true);
  } else if (pw !== confirmPw && confirmPw) {
    indicator.innerHTML = '<span style="color:#dc3545;"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
    updateSubmitButton(false);
  } else {
    indicator.innerHTML = '';
    updateSubmitButton(false);
  }
}

// Update submit button
function updateSubmitButton(enabled) {
  document.getElementById('submitBtn').disabled = !enabled;
}

// Handle form submission
document.getElementById('changePwForm').addEventListener('submit', function(e) {
  const btn = document.getElementById('submitBtn');
  if (!btn.disabled) {
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Updating...';
    btn.disabled = true;
  }
});

// Prevent modal close if forced and form has data
document.getElementById('changePwModal').addEventListener('hide.bs.modal', function(e) {
  if (isForcedChange) {
    const pw = document.getElementById('newPassword').value;
    const confirmPw = document.getElementById('confirmPassword').value;
    
    if (pw || confirmPw) {
      e.preventDefault();
      alert('You must change your password before leaving.');
    }
  } else {
    // Non-forced mode: redirect if cancelled after a short delay
    if (!e.reason) {
      // Modal dismissed without submission, redirect to dashboard
      setTimeout(() => {
        window.location.href = '/dashboard/index.php';
      }, 100);
    }
  }
});
</script>

</body>
</html>
