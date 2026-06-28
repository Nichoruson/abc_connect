<?php
// ============================================================
// ABC Connect — Patient Registration
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_patient_logged_in()) redirect(APP_BASE . '/patient/dashboard.php');

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
    <div class="auth-logo">
      <div class="auth-logo__icon">
        <span class="material-symbols-outlined icon-filled">pets</span>
      </div>
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
