<?php
// ============================================================
// ABC Connect — Admin Login
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if (is_admin_logged_in()) redirect(APP_BASE . '/admin/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Please enter both username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM admins WHERE username=:u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            admin_login((int)$admin['id'], $admin['full_name'], $admin['role'], $admin['avatar_initials'] ?? strtoupper(substr($admin['full_name'], 0, 2)));
            redirect(APP_BASE . '/admin/dashboard.php');
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Login | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/admin.css"/>
</head>
<body>
<div class="admin-login-wrap">
  <!-- Left Hero Panel -->
  <div class="admin-login-left">
    <div style="max-width:420px">
      <div style="display:flex;align-items:center;gap:var(--space-md);margin-bottom:var(--space-xl)">
        <div style="width:52px;height:52px;background:rgba(255,255,255,0.2);border-radius:14px;display:flex;align-items:center;justify-content:center">
          <span class="material-symbols-outlined icon-filled" style="color:white;font-size:28px">pets</span>
        </div>
        <div>
          <h1 style="color:white;font-size:22px;margin:0">ABC Connect</h1>
          <p style="color:rgba(255,255,255,0.7);font-size:13px;margin:0">Animal Bite Center</p>
        </div>
      </div>
      <h2 style="color:white;font-size:clamp(28px,4vw,40px);font-weight:800;line-height:1.2;margin-bottom:var(--space-md)">
        Manage. Track.<br>Protect.
      </h2>
      <p style="color:rgba(255,255,255,0.75);font-size:16px;line-height:1.7">
        A complete system for managing rabies post-exposure prophylaxis — from patient intake to vaccine inventory.
      </p>
      <div style="margin-top:var(--space-xl);display:flex;flex-direction:column;gap:var(--space-md)">
        <?php foreach (['Live queue management','Dose tracking & schedules','Inventory alerts','Patient records & reports'] as $f): ?>
        <div style="display:flex;align-items:center;gap:var(--space-sm);color:rgba(255,255,255,0.85);font-size:14px;font-weight:500">
          <span class="material-symbols-outlined icon-filled" style="font-size:18px;color:var(--primary-container)">check_circle</span>
          <?= $f ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Right Login Panel -->
  <div class="admin-login-right">
    <div style="width:100%;max-width:360px">
      <h2 style="font-size:24px;font-weight:800;margin-bottom:6px">Admin Sign In</h2>
      <p style="color:var(--on-surface-variant);margin-bottom:var(--space-xl);font-size:15px">Access the ABC Connect admin portal</p>

      <?php if ($error): ?>
      <div class="alert alert-error" style="margin-bottom:var(--space-md)">
        <span class="material-symbols-outlined" style="color:var(--error)">error</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input class="form-input" type="text" id="username" name="username"
                 placeholder="admin" required autocomplete="username"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input class="form-input" type="password" id="password" name="password"
                 placeholder="••••••••" required autocomplete="current-password"/>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit" style="margin-top:var(--space-sm)">
          <span class="material-symbols-outlined">admin_panel_settings</span>
          Sign In to Admin Portal
        </button>
      </form>

      <p style="text-align:center;margin-top:var(--space-xl);font-size:13px;color:var(--on-surface-variant)">
        Default credentials: <strong>admin</strong> / <strong>password</strong>
      </p>
      <div style="text-align:center;margin-top:var(--space-md)">
        <a href="<?= APP_BASE ?>/index.php" style="color:var(--on-surface-variant);font-size:13px;text-decoration:none">← Back to home</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
