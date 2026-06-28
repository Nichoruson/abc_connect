<?php
// ============================================================
// ABC Connect — Patient Profile
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db     = getDB();
$userId = (int)$_SESSION['patient_user_id'];

$userStmt = $db->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");
$userStmt->execute([':id' => $userId]);
$user = $userStmt->fetch();

// All patient records
$pStmt = $db->prepare("SELECT * FROM patients WHERE user_id=:uid ORDER BY created_at DESC");
$pStmt->execute([':uid' => $userId]);
$records = $pStmt->fetchAll();

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $contact   = trim($_POST['contact'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $newPw     = $_POST['new_password'] ?? '';
    $curPw     = $_POST['current_password'] ?? '';

    if (!$full_name || !$contact) {
        $error = 'Name and contact number are required.';
    } else {
        $upd = $db->prepare("UPDATE users SET full_name=:n, contact_number=:c, address=:a WHERE id=:id");
        $upd->execute([':n'=>$full_name,':c'=>$contact,':a'=>$address,':id'=>$userId]);
        $_SESSION['patient_name'] = $full_name;

        if ($newPw) {
            if (!password_verify($curPw, $user['password_hash'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($newPw) < 6) {
                $error = 'New password must be at least 6 characters.';
            } else {
                $db->prepare("UPDATE users SET password_hash=:h WHERE id=:id")
                   ->execute([':h' => password_hash($newPw, PASSWORD_BCRYPT), ':id' => $userId]);
                $success = 'Profile and password updated.';
            }
        } else {
            $success = 'Profile updated successfully.';
        }
        // Reload user
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();
    }
}

$initials = strtoupper(substr($user['full_name'], 0, 2));
$page_title = 'My Profile';
$active_nav = 'profile';
include __DIR__ . '/../includes/header_patient.php';
?>

<!-- Profile Hero -->
<div class="profile-hero animate-fade-in stagger-1">
  <div class="profile-hero__avatar"><?= htmlspecialchars($initials) ?></div>
  <div>
    <h2 style="font-size:20px;font-weight:700;margin-bottom:4px;color:white"><?= htmlspecialchars($user['full_name']) ?></h2>
    <p style="font-size:13px;opacity:0.85;color:white"><?= htmlspecialchars($user['contact_number']) ?></p>
    <?php if (!empty($records)): ?>
    <span style="background:rgba(255,255,255,0.2);color:white;padding:3px 10px;border-radius:50px;font-size:12px;font-weight:700;margin-top:6px;display:inline-block">
      <?= count($records) ?> incident(s) on record
    </span>
    <?php endif; ?>
  </div>
</div>

<!-- Alerts -->
<?php if ($success): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($success) ?></span>
</div>
<?php elseif ($error): ?>
<div class="alert alert-error animate-fade-in">
  <span class="material-symbols-outlined" style="color:var(--error)">error</span>
  <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Edit Profile Form -->
<section class="card animate-fade-in stagger-2">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:var(--space-md)">Edit Profile</h3>
  <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-md)">
    <div class="form-group">
      <label class="form-label" for="full_name">Full Name</label>
      <input class="form-input" type="text" id="full_name" name="full_name" required
             value="<?= htmlspecialchars($user['full_name']) ?>"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="contact">Contact Number</label>
      <input class="form-input" type="tel" id="contact" name="contact" required
             value="<?= htmlspecialchars($user['contact_number']) ?>"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="address">Address</label>
      <textarea class="form-textarea" id="address" name="address" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
    </div>

    <div class="divider"></div>
    <p style="font-size:var(--text-label-sm);color:var(--on-surface-variant);font-weight:600">
      Change Password (leave blank to keep current)
    </p>
    <div class="form-group">
      <label class="form-label" for="current_password">Current Password</label>
      <input class="form-input" type="password" id="current_password" name="current_password"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="new_password">New Password</label>
      <input class="form-input" type="password" id="new_password" name="new_password"/>
    </div>

    <button class="btn btn-primary btn-full" type="submit">
      <span class="material-symbols-outlined">save</span>
      Save Changes
    </button>
  </form>
</section>

<!-- Patient Records -->
<?php if (!empty($records)): ?>
<section class="animate-fade-in stagger-3">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">history</span>
    <h2>Incident History</h2>
  </div>
  <div style="display:flex;flex-direction:column;gap:var(--space-md)">
    <?php foreach ($records as $r): ?>
    <div class="card card-sm">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <div>
          <span style="font-weight:700;font-size:15px"><?= htmlspecialchars($r['animal_ownership']) ?> <?= htmlspecialchars($r['animal_type']) ?> Bite</span>
          <p style="font-size:13px;color:var(--on-surface-variant);margin-top:3px">
            <?= htmlspecialchars($r['body_location']) ?> · <?= date('M j, Y', strtotime($r['bite_date'])) ?>
          </p>
        </div>
        <span class="badge badge-cat-<?= $r['category'] ?>">Cat <?= $r['category'] ?></span>
      </div>
      <div style="margin-top:var(--space-sm);display:flex;gap:var(--space-sm);flex-wrap:wrap;align-items:center">
        <span class="badge <?= $r['status'] === 'active' ? 'badge-consult' : 'badge-info' ?>"><?= ucfirst($r['status']) ?></span>
        <span style="font-size:12px;color:var(--on-surface-variant)">#<?= htmlspecialchars($r['patient_code']) ?></span>
        <a href="<?= APP_BASE ?>/patient/vaccine_card.php?id=<?= $r['id'] ?>"
           class="btn btn-surface btn-sm" style="font-size:11px;padding:4px 10px;margin-left:auto">
          <span class="material-symbols-outlined" style="font-size:14px">badge</span>
          View Card
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Logout -->
<a href="<?= APP_BASE ?>/logout.php" class="btn btn-surface btn-full" style="margin-bottom:var(--space-sm)">
  <span class="material-symbols-outlined">logout</span>
  Sign Out
</a>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
