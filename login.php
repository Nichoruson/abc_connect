<?php
// ============================================================
// ABC Connect — Patient Login
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_patient_logged_in()) redirect(APP_BASE . '/patient/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact  = trim($_POST['contact'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$contact || !$password) {
        $error = 'Please fill in all fields.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, full_name, password_hash FROM users WHERE contact_number = :c LIMIT 1");
        $stmt->execute([':c' => $contact]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Get latest active patient code
            $ps = $db->prepare("SELECT patient_code FROM patients WHERE user_id = :uid AND status='active' ORDER BY created_at DESC LIMIT 1");
            $ps->execute([':uid' => $user['id']]);
            $pat = $ps->fetch();
            patient_login((int)$user['id'], $user['full_name'], $pat['patient_code'] ?? '');
            redirect(APP_BASE . '/patient/dashboard.php');
        } else {
            $error = 'Invalid contact number or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Patient Login | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/patient.css"/>
</head>
<body>
<div class="auth-container">
  <div class="auth-card animate-slide-up">
    <div class="auth-logo">
      <div class="auth-logo__icon">
        <span class="material-symbols-outlined icon-filled">pets</span>
      </div>
      <div>
        <h1 style="font-size:22px;color:var(--primary);margin:0">ABC Connect</h1>
        <p style="font-size:13px;color:var(--on-surface-variant);margin:0">Patient Portal</p>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-md)">
      <span class="material-symbols-outlined" style="color:var(--error)">error</span>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <div style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="contact">Contact Number</label>
          <input class="form-input" type="tel" id="contact" name="contact"
                 placeholder="09XXXXXXXXX" required
                 value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input class="form-input" type="password" id="password" name="password"
                 placeholder="Enter your password" required/>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit" style="margin-top:var(--space-sm)">
          <span class="material-symbols-outlined">login</span>
          Sign In
        </button>
      </div>
    </form>

    <div style="text-align:center;margin-top:var(--space-lg);font-size:14px;color:var(--on-surface-variant)">
      New patient?
      <a href="<?= APP_BASE ?>/register.php" style="color:var(--primary);font-weight:600;text-decoration:none">Register here</a>
    </div>
    <div style="text-align:center;margin-top:var(--space-sm);font-size:13px">
      <a href="<?= APP_BASE ?>/index.php" style="color:var(--on-surface-variant);text-decoration:none">← Back to home</a>
    </div>
  </div>
</div>
</body>
</html>
