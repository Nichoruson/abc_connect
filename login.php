<?php
// ============================================================
// ABC Connect — Unified Portal Login
// Detects platform: Browser = Staff Login, Mobile App = Patient Login + Staff QR Scanner
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

$isApp = is_mobile_app();
$error = '';

if ($isApp) {
    // ---- Mobile App Flow: Patient Portal ----
    if (is_patient_logged_in()) {
        redirect(APP_BASE . '/patient/dashboard.php');
    }

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
} else {
    // ---- Web Browser Flow: Staff Portal ----
    if (is_admin_logged_in()) {
        redirect(APP_BASE . '/admin/dashboard.php');
    }

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
                // Generate a fresh app access token for QR login
                $token = bin2hex(random_bytes(32));
                $db->prepare("UPDATE admins SET app_login_token = :token WHERE id = :id")
                   ->execute([':token' => $token, ':id' => $admin['id']]);

                admin_login(
                    (int)$admin['id'], 
                    $admin['full_name'], 
                    $admin['role'], 
                    $admin['avatar_initials'] ?? strtoupper(substr($admin['full_name'], 0, 2))
                );
                redirect(APP_BASE . '/admin/dashboard.php');
            } else {
                $error = 'Invalid credentials. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $isApp ? 'Patient Login' : 'Admin Login' ?> | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <?php if ($isApp): ?>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/patient.css"/>
  <?php else: ?>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/admin.css"/>
  <?php endif; ?>
</head>
<body>

<?php if ($isApp): ?>
  <!-- ==================== MOBILE APP: PATIENT PORTAL LOGIN ==================== -->
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

      <!-- App Registration Link -->
      <div style="text-align:center;margin-top:var(--space-lg);font-size:14px;color:var(--on-surface-variant)">
        New patient?
        <a href="<?= APP_BASE ?>/register.php" style="color:var(--primary);font-weight:600;text-decoration:none">Register here</a>
      </div>

      <!-- Staff QR Login Trigger -->
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
        <video id="scanner-video" autoplay playsinline style="width: 100%; border-radius: 12px; background: black; transform: scaleX(-1); display:block;"></video>
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
        // Not a JSON payload matching our login format, ignore and scan on
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

<?php else: ?>
  <!-- ==================== WEB BROWSER: STAFF ONLY SIGN IN ==================== -->
  <div class="admin-login-wrap">
    <!-- Left Panel: Brand info -->
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
          A secure workspace designed for Daet Animal Bite Treatment Center personnel to track and manage clinical patient files.
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

    <!-- Right Panel: Staff Form -->
    <div class="admin-login-right">
      <div style="width:100%;max-width:360px">
        <h2 style="font-size:24px;font-weight:800;margin-bottom:6px">Staff Sign In</h2>
        <p style="color:var(--on-surface-variant);margin-bottom:var(--space-xl);font-size:15px">Access the ABC Connect staff portal</p>

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
                   placeholder="Enter username" required autocomplete="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input class="form-input" type="password" id="password" name="password"
                   placeholder="Enter password" required autocomplete="current-password"/>
          </div>
          <button class="btn btn-primary btn-full btn-lg" type="submit" style="margin-top:var(--space-sm)">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            Sign In to Staff Portal
          </button>
        </form>

        <p style="text-align:center;margin-top:var(--space-xl);font-size:13px;color:var(--on-surface-variant)">
          Default credentials: <strong>admin</strong> / <strong>password</strong>
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
