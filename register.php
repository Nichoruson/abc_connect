<?php
// ============================================================
// ABC Connect — Patient Registration
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_patient_logged_in()) redirect(APP_BASE . '/patient/dashboard.php');

if (!is_mobile_app()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8"/>
      <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
      <title>Access Restricted | ABC Connect</title>
      <link rel="preconnect" href="https://fonts.googleapis.com"/>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
      <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
      <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
      <style>
        body { margin: 0; min-height: 100vh; background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%); display: flex; align-items: center; justify-content: center; font-family: 'Inter', sans-serif; }
        .restrict-card { background: white; border: 1.5px solid var(--outline-variant); border-radius: var(--radius-xl); padding: var(--space-xl); max-width: 440px; width: 100%; text-align: center; box-shadow: var(--shadow-card); margin: var(--space-md); }
        .restrict-icon { width: 64px; height: 64px; background: rgba(239, 68, 68, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto var(--space-lg); }
        .restrict-icon .material-symbols-outlined { color: var(--error); font-size: 32px; }
      </style>
    </head>
    <body>
      <div class="restrict-card">
        <div class="restrict-icon">
          <span class="material-symbols-outlined">block</span>
        </div>
        <h2 style="font-weight: 800; color: var(--on-surface); margin-bottom: var(--space-sm);">Mobile Registration Only</h2>
        <p style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.6; margin-bottom: var(--space-lg);">
          For security purposes, patient registration is strictly restricted to the official <strong>ABC Connect</strong> mobile application.
        </p>
        <div style="background: var(--surface-container); border-radius: var(--radius-lg); padding: var(--space-md); font-size: 13px; color: var(--on-surface-variant); line-height: 1.5; margin-bottom: var(--space-lg);">
          Please open or download our mobile app on your Android or iOS device to complete your registration.
        </div>
        <a href="<?= APP_BASE ?>/login.php" class="btn btn-surface btn-full" style="justify-content: center; text-decoration: none;">Go to Sign In</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $sex       = $_POST['sex'] ?? 'Male';
    $contact   = trim($_POST['contact'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if (!$full_name)  $errors[] = 'Full name is required.';
    if (!$contact)    $errors[] = 'Contact number is required.';
    if (!$password)   $errors[] = 'Password is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $db = getDB();
        // Check duplicate
        $dup = $db->prepare("SELECT id FROM users WHERE contact_number = :c LIMIT 1");
        $dup->execute([':c' => $contact]);
        if ($dup->fetch()) {
            $errors[] = 'That contact number is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $db->prepare("
                INSERT INTO users (full_name, birthdate, sex, contact_number, address, password_hash)
                VALUES (:name, :bd, :sex, :contact, :addr, :hash)
            ");
            $ins->execute([
                ':name'    => $full_name,
                ':bd'      => $birthdate ?: null,
                ':sex'     => $sex,
                ':contact' => $contact,
                ':addr'    => $address,
                ':hash'    => $hash,
            ]);
            $newUserId = (int)$db->lastInsertId();
            patient_login($newUserId, $full_name, '');
            redirect(APP_BASE . '/patient/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/patient.css"/>
  <style>.reg-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md);}@media(max-width:480px){.reg-grid{grid-template-columns:1fr;}}</style>
</head>
<body>
<div class="auth-container" style="padding:var(--space-xl) var(--space-md)">
  <div class="auth-card animate-slide-up" style="max-width:520px">
    <div class="auth-logo" style="display: flex; align-items: center; gap: var(--space-md);">
      <img src="<?= APP_BASE ?>/assets/logo.png" alt="ABC Connect Logo" style="width: 44px; height: 44px; object-fit: contain;"/>
      <div>
        <h1 style="font-size:20px;color:var(--primary);margin:0">Create Account</h1>
        <p style="font-size:13px;color:var(--on-surface-variant);margin:0">ABC Connect Patient Portal</p>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-error">
      <span class="material-symbols-outlined" style="color:var(--error)">error</span>
      <ul style="list-style:none;padding:0;margin:0">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <div style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label" for="full_name">Full Name <span style="color:var(--error)">*</span></label>
          <input class="form-input" type="text" id="full_name" name="full_name"
                 placeholder="Juan dela Cruz" required
                 value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
        </div>
        <div class="reg-grid">
          <div class="form-group">
            <label class="form-label" for="birthdate">Date of Birth</label>
            <input class="form-input" type="date" id="birthdate" name="birthdate"
                   value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label" for="sex">Sex</label>
            <select class="form-select" id="sex" name="sex">
              <option value="Male"   <?= ($_POST['sex'] ?? '') === 'Male'   ? 'selected':'' ?>>Male</option>
              <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected':'' ?>>Female</option>
              <option value="Other"  <?= ($_POST['sex'] ?? '') === 'Other'  ? 'selected':'' ?>>Other</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="contact">Contact Number <span style="color:var(--error)">*</span></label>
          <input class="form-input" type="tel" id="contact" name="contact"
                 placeholder="09XXXXXXXXX" required
                 value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label" for="address">Address</label>
          <textarea class="form-textarea" id="address" name="address" placeholder="Street, Barangay, City" rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>
        <div class="reg-grid">
          <div class="form-group">
            <label class="form-label" for="password">Password <span style="color:var(--error)">*</span></label>
            <input class="form-input" type="password" id="password" name="password" placeholder="Min. 6 characters" required/>
          </div>
          <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm Password</label>
            <input class="form-input" type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password"/>
          </div>
        </div>
        <button class="btn btn-primary btn-full btn-lg" type="submit" style="margin-top:4px">
          <span class="material-symbols-outlined">how_to_reg</span>
          Create Account
        </button>
      </div>
    </form>

    <div style="text-align:center;margin-top:var(--space-lg);font-size:14px;color:var(--on-surface-variant)">
      Already registered?
      <a href="<?= APP_BASE ?>/login.php" style="color:var(--primary);font-weight:600;text-decoration:none">Sign in</a>
    </div>
  </div>
</div>
</body>
</html>
