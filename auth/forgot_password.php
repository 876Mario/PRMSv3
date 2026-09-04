<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/app.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));

    // Always respond the same (anti-enumeration)
    $genericMsg = "If the email exists, a reset link has been sent.";

    if ($email === '') {
        modalPop(
            "Request Failed",
            $genericMsg,
            "/auth/forgot_password.php",
            "info"
        );
    }

    // Lookup user
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600);

        // Store hash + expiry
        $stmt = $pdo->prepare("
            UPDATE users
            SET reset_token_hash = ?, reset_token_expires = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$tokenHash, $expires, $user['user_id']]);

        // Build reset link
        $link = rtrim(APP_URL, '/') . "/auth/reset_password.php?token={$token}";

        // Email body
        $body = "
            <p>You requested a password reset.</p>
            <p>
              Click the link below to reset your password:<br>
              <a href='{$link}'>{$link}</a>
            </p>
            <p>This link will expire in 1 hour.</p>
        ";

        // ✅ sendMail ONLY here
        sendMail(
            $email,
            "Password Reset Request",
            $body
        );
    }

    modalPop(
        "Request Submitted",
        $genericMsg,
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
<title>Forgot Password | DGC Procurement</title>

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

  /* ── Split layout ── */
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

  /* Alert */
  .alert-login {
    border-radius: 10px;
    border-left: 4px solid #0dcaf0;
    font-size: 0.9rem;
  }

  /* Footer */
  .login-footer {
    text-align: center;
    margin-top: 1.25rem;
    font-size: 0.8rem;
    color: #adb5bd;
  }

  /* Info box */
  .info-box {
    background: #f0faf4;
    border: 1px solid #d4edda;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    font-size: 0.85rem;
    color: #155724;
    margin-bottom: 1.5rem;
  }

  .info-box i { color: var(--dgc-green); }

  /* ── Responsive ── */
  @media (max-width: 991.98px) {
    .hero-panel { display: none; }
    .form-panel {
      flex: 1;
      background: linear-gradient(160deg, #f8f9fa 60%, #e9ecef 100%);
    }
  }

  /* Fade-in animation */
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

          <!-- Mobile logo (shown only on small screens) -->
          <div class="text-center d-lg-none mb-3">
            <img src="/logo/cropped-Logo.png"
                 alt="Government Chemist Logo"
                 style="height:64px;" class="mb-2">
          </div>

          <h4><i class="bi bi-key me-2" style="color: var(--dgc-gold);"></i>Reset Password</h4>
          <p class="subtitle">Enter your email and we'll send you a reset link</p>

          <div class="info-box">
            <i class="bi bi-info-circle me-1"></i>
            A password reset link will be sent to your registered email address. The link expires in 1 hour.
          </div>

          <!-- Optional success/error message -->
          <?php if (!empty($message)): ?>
            <div class="alert alert-info alert-login d-flex align-items-center gap-2" role="alert">
              <i class="bi bi-info-circle-fill"></i>
              <?= htmlspecialchars($message) ?>
            </div>
          <?php endif; ?>

          <!-- Form -->
          <form method="POST" novalidate autocomplete="off">

            <div class="form-floating position-relative mb-4">
              <i class="bi bi-envelope input-icon"></i>
              <input
                type="email"
                name="email"
                id="resetEmail"
                class="form-control"
                placeholder="your.name@moh.gov.jm"
                required
                autofocus
              >
              <label for="resetEmail">Email address</label>
            </div>

            <button type="submit" class="btn btn-login w-100">
              <i class="bi bi-send me-1"></i> Send Reset Link
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

</body>
</html>
