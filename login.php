<?php
// ============================================================
// ABC Connect — Patient Portal Login (login.php)
// Renders patient login + Staff QR Code Scanner button
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (is_patient_logged_in()) {
    redirect(APP_BASE . '/patient/dashboard.php');
}

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
  <title>Patient Login | RabiesShield Daet</title>
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
        <h1 style="font-size:22px;color:var(--primary);margin:0">RabiesShield Daet</h1>
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

    <!-- Staff QR Code Login Button -->
    <div style="margin-top:var(--space-md); border-top:1.5px dashed var(--outline-variant); padding-top:var(--space-md); text-align:center;">
      <button type="button" class="btn btn-surface btn-full" onclick="startQRScanner()" style="justify-content:center; gap:8px;">
        <span class="material-symbols-outlined">qr_code_scanner</span>
        Login as Staff (Scan QR)
      </button>
    </div>
  </div>
</div>

<!-- QR Scanner Modal Overlay -->
<div class="modal-overlay" id="modal-login-scanner" style="z-index: 10000;">
  <div class="modal-box" style="max-width: 400px; text-align: center;">
    <div class="modal-header">
      <h3 style="font-size:18px;font-weight:700">Scan Staff QR Code</h3>
      <button type="button" onclick="stopQRScanner()" style="background:none;border:none;cursor:pointer;color:var(--on-surface-variant)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="modal-body" style="position:relative; padding: var(--space-md); overflow:hidden;">
      <video id="scanner-video" autoplay playsinline style="width: 100%; border-radius: 12px; background: black; display:block;"></video>
      <canvas id="scanner-canvas" style="display:none;"></canvas>
      <div id="scan-overlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:180px;height:180px;border:3px solid var(--primary);border-radius:12px;pointer-events:none;box-shadow:0 0 0 9999px rgba(0,0,0,0.5)">
        <div class="scanner-laser" style="position:absolute; width:100%; height:3px; background:var(--primary); top:0; left:0; box-shadow: 0 0 8px var(--primary); animation: scanLaser 2s linear infinite;"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="stopQRScanner()" class="btn btn-surface" style="width: 100%; justify-content: center;">Cancel</button>
    </div>
  </div>
</div>

<style>
@keyframes scanLaser {
  0% { top: 0; }
  50% { top: 100%; }
  100% { top: 0; }
}
.modal-overlay.open {
  display: flex !important;
  align-items: center;
  justify-content: center;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.6);
  backdrop-filter: blur(4px);
}
.modal-overlay:not(.open) {
  display: none !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
let videoStream = null;
let scanInterval = null;

function startQRScanner() {
  const modal = document.getElementById('modal-login-scanner');
  if (modal) modal.classList.add('open');
  document.body.style.overflow = 'hidden';

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(stream => {
      videoStream = stream;
      const video = document.getElementById('scanner-video');
      video.srcObject = stream;
      scanInterval = setInterval(tick, 250);
    })
    .catch(err => {
      alert('Camera access denied or unavailable: ' + err.message);
      stopQRScanner();
    });
}

function stopQRScanner() {
  const modal = document.getElementById('modal-login-scanner');
  if (modal) modal.classList.remove('open');
  document.body.style.overflow = '';

  if (videoStream) {
    videoStream.getTracks().forEach(track => track.stop());
    videoStream = null;
  }
  if (scanInterval) {
    clearInterval(scanInterval);
    scanInterval = null;
  }
}

function tick() {
  const video = document.getElementById('scanner-video');
  const canvas = document.getElementById('scanner-canvas');
  if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return;

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const decoded = jsQR(imageData.data, imageData.width, imageData.height);

  if (decoded && decoded.data) {
    const data = decoded.data;
    try {
      const payload = JSON.parse(data);
      if (payload.type === 'staff_login' && payload.token) {
        stopQRScanner();
        authenticateStaff(payload.token);
      }
    } catch (e) {
      // Ignore invalid QR formats and keep scanning
    }
  }
}

function authenticateStaff(token) {
  fetch('<?= APP_BASE ?>/api/staff_qr_login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: token })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      window.location.href = data.redirect;
    } else {
      alert(data.message || 'QR authentication failed.');
      startQRScanner();
    }
  })
  .catch(err => {
    alert('Network error connecting to authentication server.');
    startQRScanner();
  });
}
</script>

</body>
</html>
