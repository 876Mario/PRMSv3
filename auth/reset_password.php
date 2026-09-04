<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/app.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$token = $_GET['token'] ?? '';

if ($token === '') {
    modalPop(
        "Invalid Link",
        "Reset token is missing.",
        "/auth/login.php",
        "error"
    );
}

$tokenHash = hash('sha256', $token);

/* 🔍 Validate token BEFORE showing form */
$stmt = $pdo->prepare("
    SELECT user_id
    FROM users
    WHERE reset_token_hash = ?
      AND reset_token_expires > NOW()
");
$stmt->execute([$tokenHash]);
$user = $stmt->fetch();

if (!$user) {
    modalPop(
        "Expired or Invalid Link",
        "This password reset link is invalid or has expired.",
        "/auth/forgot_password.php",
        "error"
    );
}

/* Handle form submit */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($password === '' || $password !== $confirm) {
        modalPop(
            "Invalid Password",
            "Passwords do not match.",
            "",
            "warning"
        );
    }

    $passwordPolicyError = validatePasswordPolicy($password);
    if ($passwordPolicyError !== null) {
        modalPop(
            "Weak Password",
            $passwordPolicyError,
            "",
            "warning"
        );
    }

    // Update password (✅ correct column)
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        UPDATE users
        SET password_hash = ?,
            reset_token_hash = NULL,
            reset_token_expires = NULL,
            must_change_password = 0
        WHERE user_id = ?
    ");
    $stmt->execute([$hash, $user['user_id']]);

    modalPop(
        "Password Reset",
        "Your password has been updated successfully.",
        "/auth/login.php",
        "success"
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password | DGC Procurement</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
  :root {
    --dgc-green: #0b5e2b;
    --dgc-green-light: #0d7a38;
    --dgc-gold: #c9a227;
    --dgc-gold-light: #e4c44a;
  }

  * { box-sizing: border-box; }

  html, body {
    height: 100%;
    margin: 0;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  }

  .login-wrapper {
    display: flex;
    min-height: 100vh;
  }

  /* Left hero panel */
  .hero-panel {
    flex: 1 1 50%;
    background: linear-gradient(160deg, var(--dgc-green) 0%, #064a20 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 2rem;
    position: relative;
    overflow: hidden;
    color: #fff;
  }

  .hero-panel::before {
    content: '';
    position: absolute;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(201, 162, 39, 0.08);
    top: -120px;
    right: -120px;
  }

  .hero-panel::after {
    content: '';
    position: absolute;
    width: 350px;
    height: 350px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    bottom: -80px;
    left: -80px;
  }

  .hero-panel .hero-content {
    position: relative;
    z-index: 1;
    text-align: center;
    max-width: 380px;
  }

  .hero-panel .hero-logo {
    width: 110px;
    height: 110px;
    object-fit: contain;
    margin-bottom: 1.5rem;
    filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
  }

  .hero-panel h1 {
    font-size: 1.6rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
  }

  .hero-panel .hero-sub {
    font-size: 0.95rem;
    opacity: 0.8;
    line-height: 1.5;
  }

  .hero-panel .hero-badge {
    display: inline-block;
    background: var(--dgc-gold);
    color: var(--dgc-green);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    padding: 0.3rem 1rem;
    border-radius: 20px;
    margin-top: 1.5rem;
  }

  /* Right form panel */
  .form-panel {
    flex: 1 1 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    padding: 2rem;
  }

  .login-card {
    width: 100%;
    max-width: 420px;
  }

  .login-card .card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.08);
    overflow: hidden;
  }

  .login-card .card-body {
    padding: 2.5rem 2rem 2rem;
  }

  .login-card .card-header-bar {
    height: 5px;
    background: linear-gradient(90deg, var(--dgc-green), var(--dgc-gold));
  }

  .login-card h4 {
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 0.25rem;
  }

  .login-card .subtitle {
    color: #6c757d;
    font-size: 0.9rem;
    margin-bottom: 1.75rem;
  }

  /* Form inputs */
  .form-floating .form-control {
    border-radius: 10px;
    border: 1.5px solid #dee2e6;
    padding-left: 2.8rem;
    height: 3.2rem;
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
  }

  .form-floating .form-control:focus {
    border-color: var(--dgc-green);
    box-shadow: 0 0 0 3px rgba(11, 94, 43, 0.12);
  }

  .form-floating label {
    padding-left: 2.8rem;
    color: #999;
  }

  .input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    font-size: 1.1rem;
    z-index: 5;
    pointer-events: none;
    transition: color 0.2s;
  }

  .form-floating:focus-within .input-icon {
    color: var(--dgc-green);
  }

  .pw-toggle {
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #adb5bd;
    font-size: 1.1rem;
    z-index: 5;
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
  }

  .pw-toggle:hover { color: var(--dgc-green); }

  /* Submit button */
  .btn-login {
    background: linear-gradient(135deg, var(--dgc-green), var(--dgc-green-light));
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1rem;
    padding: 0.75rem;
    color: #fff;
    transition: transform 0.15s, box-shadow 0.15s;
  }

  .btn-login:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(11, 94, 43, 0.3);
    color: #fff;
  }

  .btn-login:active {
    transform: translateY(0);
  }

  /* Links */
  .auth-link {
    color: var(--dgc-green);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.875rem;
    transition: color 0.2s;
  }

  .auth-link:hover { color: var(--dgc-gold); }

  /* Strength meter */
  .pw-rules {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 0.5rem;
  }

  .pw-rules .rule {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin-bottom: 0.2rem;
  }

  .pw-rules .rule.pass { color: var(--dgc-green); }
  .pw-rules .rule.fail { color: #adb5bd; }

  /* Footer */
  .login-footer {
    text-align: center;
    margin-top: 1.25rem;
    font-size: 0.8rem;
    color: #adb5bd;
  }

  /* Match indicator */
  .match-indicator {
    font-size: 0.8rem;
    margin-top: 0.35rem;
  }

  /* ── Responsive ── */
  @media (max-width: 991.98px) {
    .hero-panel { display: none; }
    .form-panel {
      flex: 1;
      background: linear-gradient(160deg, #f8f9fa 60%, #e9ecef 100%);
    }
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .login-card { animation: fadeUp 0.5s ease-out; }
</style>
</head>

<body>

<div class="login-wrapper">

  <!-- Hero Panel (left) -->
  <div class="hero-panel d-none d-lg-flex">
    <div class="hero-content">
      <img src="/logo/cropped-Logo.png"
           alt="Government Chemist Logo"
           class="hero-logo">
      <h1>DGC Procurement Portal</h1>
      <p class="hero-sub">
        Streamlined procurement management for the Department of the Government Chemist.
      </p>
      <span class="hero-badge">Secure &bull; Audited &bull; Compliant</span>
    </div>
  </div>

  <!-- Form Panel (right) -->
  <div class="form-panel">
    <div class="login-card">

      <div class="card">
        <div class="card-header-bar"></div>
        <div class="card-body">

          <!-- Mobile logo -->
          <div class="text-center d-lg-none mb-3">
            <img src="/logo/cropped-Logo.png"
                 alt="Government Chemist Logo"
                 style="height:64px;" class="mb-2">
          </div>

          <h4><i class="bi bi-shield-lock me-2" style="color: var(--dgc-gold);"></i>Set New Password</h4>
          <p class="subtitle">Choose a strong password for your account</p>

          <!-- Form -->
          <form method="POST" novalidate autocomplete="off">

            <div class="form-floating position-relative mb-3">
              <i class="bi bi-lock input-icon"></i>
              <input
                type="password"
                name="password"
                id="newPassword"
                class="form-control"
                placeholder="New password"
                required
                autofocus
                oninput="checkStrength(); checkMatch();"
              >
              <label for="newPassword">New password</label>
              <button type="button"
                      class="pw-toggle"
                      onclick="togglePw('newPassword', 'pwIcon1')"
                      aria-label="Toggle password visibility">
                <i class="bi bi-eye" id="pwIcon1"></i>
              </button>
            </div>

            <div class="pw-rules" id="pwRules">
              <div class="rule" id="ruleLen"><i class="bi bi-circle"></i> At least 8 characters</div>
              <div class="rule" id="ruleUpper"><i class="bi bi-circle"></i> One uppercase letter</div>
              <div class="rule" id="ruleNum"><i class="bi bi-circle"></i> One number</div>
            </div>

            <div class="form-floating position-relative mb-3 mt-3">
              <i class="bi bi-lock-fill input-icon"></i>
              <input
                type="password"
                name="confirm"
                id="confirmPassword"
                class="form-control"
                placeholder="Confirm password"
                required
                oninput="checkMatch();"
              >
              <label for="confirmPassword">Confirm password</label>
              <button type="button"
                      class="pw-toggle"
                      onclick="togglePw('confirmPassword', 'pwIcon2')"
                      aria-label="Toggle password visibility">
                <i class="bi bi-eye" id="pwIcon2"></i>
              </button>
            </div>

            <div class="match-indicator" id="matchIndicator"></div>

            <button type="submit" class="btn btn-login w-100 mt-2" id="submitBtn">
              <i class="bi bi-check-circle me-1"></i> Reset Password
            </button>

          </form>

          <div class="text-center mt-3">
            <a href="/auth/login.php" class="auth-link">
              <i class="bi bi-arrow-left me-1"></i> Back to login
            </a>
          </div>

        </div>
      </div>

      <div class="login-footer">
        &copy; <?= date('Y') ?> Department of the Government Chemist &middot; All rights reserved
      </div>

    </div>
  </div>

</div>

<script>
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

function checkStrength() {
  const pw = document.getElementById('newPassword').value;
  setRule('ruleLen',   pw.length >= 8);
  setRule('ruleUpper', /[A-Z]/.test(pw));
  setRule('ruleNum',   /[0-9]/.test(pw));
}

function setRule(id, pass) {
  const el = document.getElementById(id);
  const icon = el.querySelector('i');
  if (pass) {
    el.classList.add('pass');
    el.classList.remove('fail');
    icon.className = 'bi bi-check-circle-fill';
  } else {
    el.classList.remove('pass');
    el.classList.add('fail');
    icon.className = 'bi bi-circle';
  }
}

function checkMatch() {
  const pw = document.getElementById('newPassword').value;
  const cf = document.getElementById('confirmPassword').value;
  const el = document.getElementById('matchIndicator');
  if (cf === '') {
    el.innerHTML = '';
    return;
  }
  if (pw === cf) {
    el.innerHTML = '<span style="color:var(--dgc-green)"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>';
  } else {
    el.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Passwords do not match</span>';
  }
}
</script>

</body>
</html>
