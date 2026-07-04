<?php
// ============================================================
// RabiesShield Daet — Admin: QR Scanner / Entry Verification
// Staff scans patient QR code to verify appointment
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();

// Handle QR token lookup (POST from JS scanner or manual input)
$verifyResult = null;
$rawToken     = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    $rawToken = trim($_POST['qr_token']);

    // Try to decode JSON payload first (from QR)
    $decoded = @json_decode($rawToken, true);
    $token   = is_array($decoded) && isset($decoded['token']) ? $decoded['token'] : $rawToken;

    $stmt = $db->prepare("
        SELECT a.*, p.patient_code, p.category, p.animal_type, p.animal_ownership,
               p.body_location, p.bite_date, u.full_name, u.contact_number
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE a.qr_token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $appt = $stmt->fetch();

    if (!$appt) {
        $verifyResult = ['status' => 'invalid', 'message' => 'QR code not found. This pass is not valid.'];
    } elseif ($appt['status'] === 'completed') {
        $verifyResult = ['status' => 'used', 'appt' => $appt, 'message' => 'This pass has already been used (appointment completed).'];
    } elseif ($appt['status'] === 'cancelled') {
        $verifyResult = ['status' => 'cancelled', 'appt' => $appt, 'message' => 'This appointment has been cancelled.'];
    } elseif ($appt['appointment_date'] !== date('Y-m-d')) {
        $verifyResult = ['status' => 'wrong_date', 'appt' => $appt,
            'message' => 'This pass is for ' . date('F j, Y', strtotime($appt['appointment_date'])) . ', not today.'];
    } else {
        $verifyResult = ['status' => 'valid', 'appt' => $appt, 'message' => 'Valid! Patient is admitted.'];
    }

    // If valid, mark appointment as in-progress (optional)
    if (($verifyResult['status'] ?? '') === 'valid' && isset($_POST['admit'])) {
        $db->prepare("UPDATE appointments SET status='completed' WHERE id=:id")
           ->execute([':id' => $appt['id']]);
        $verifyResult['status']  = 'admitted';
        $verifyResult['message'] = 'Patient admitted and appointment marked complete.';
    }
}

$page_title  = 'QR Scanner';
$active_page = 'scanner';
include __DIR__ . '/../includes/header_admin.php';
?>

<style>
#scanner-video { width:100%; max-width:360px; border-radius:16px; background:#000; display:block; margin:0 auto; }
#scanner-canvas { display:none; }
.verify-card { border-radius:16px; padding:var(--space-xl); margin-top:var(--space-lg); }
.verify-card--valid   { background:#dcfce7; border:2px solid #22c55e; }
.verify-card--invalid { background:#fee2e2; border:2px solid #ef4444; }
.verify-card--wrong   { background:#fef3c7; border:2px solid #f59e0b; }
.verify-card--used    { background:#f3f4f6; border:2px solid #9ca3af; }
</style>

<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">QR Entry Scanner</h1>
    <p class="page-header__sub">Scan patient entry pass QR code to verify appointment</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 280px), 1fr));gap:var(--space-lg);align-items:start" class="animate-fade-in stagger-2">

  <!-- Camera Scanner -->
  <div class="card">
    <h3 style="font-weight:700;margin-bottom:var(--space-md);display:flex;align-items:center;gap:var(--space-sm)">
      <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">camera_alt</span>
      Camera Scan
    </h3>
    <div style="position:relative; overflow:hidden; border-radius:16px; max-width:360px; margin:0 auto;">
      <video id="scanner-video" autoplay playsinline></video>
      <canvas id="scanner-canvas"></canvas>
      <div id="scan-overlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:200px;border:3px solid var(--primary);border-radius:12px;pointer-events:none;box-shadow:0 0 0 9999px rgba(0,0,0,0.4)"></div>
    </div>
    <button id="btn-start-scan" class="btn btn-primary btn-full" style="margin-top:var(--space-md)" onclick="startScanner()">
      <span class="material-symbols-outlined">qr_code_scanner</span>
      Start Camera
    </button>
    <button id="btn-stop-scan" class="btn btn-surface btn-full" style="margin-top:var(--space-sm);display:none" onclick="stopScanner()">
      <span class="material-symbols-outlined">stop</span>
      Stop Camera
    </button>
    <p style="font-size:12px;color:var(--on-surface-variant);text-align:center;margin-top:var(--space-sm)">
      Point camera at the patient's QR entry pass
    </p>
  </div>

  <!-- Manual Lookup -->
  <div class="card">
    <h3 style="font-weight:700;margin-bottom:var(--space-md);display:flex;align-items:center;gap:var(--space-sm)">
      <span class="material-symbols-outlined" style="color:var(--secondary)">keyboard</span>
      Manual Lookup
    </h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:var(--space-md)">
      <div class="form-group">
        <label class="form-label" for="qr_token">QR Token / Patient Code</label>
        <textarea class="form-textarea" id="qr_token" name="qr_token" rows="4"
                  placeholder="Paste QR code data here, or type patient code..."><?= htmlspecialchars($rawToken) ?></textarea>
      </div>
      <button class="btn btn-primary" type="submit">
        <span class="material-symbols-outlined">search</span>
        Verify
      </button>
    </form>

    <!-- Verify Result -->
    <?php if ($verifyResult): ?>
    <?php
      $cardClass = match($verifyResult['status']) {
        'valid', 'admitted' => 'verify-card--valid',
        'invalid'           => 'verify-card--invalid',
        'wrong_date'        => 'verify-card--wrong',
        default             => 'verify-card--used',
      };
      $icon = match($verifyResult['status']) {
        'valid', 'admitted' => 'check_circle',
        'invalid'           => 'cancel',
        'wrong_date'        => 'event_busy',
        default             => 'info',
      };
    ?>
    <div class="verify-card <?= $cardClass ?>">
      <div style="display:flex;align-items:center;gap:var(--space-sm);margin-bottom:var(--space-md)">
        <span class="material-symbols-outlined icon-filled" style="font-size:28px"><?= $icon ?></span>
        <div>
          <div style="font-weight:800;font-size:16px"><?= ucfirst($verifyResult['status']) ?></div>
          <div style="font-size:13px"><?= htmlspecialchars($verifyResult['message']) ?></div>
        </div>
      </div>
      <?php if (!empty($verifyResult['appt'])): $a = $verifyResult['appt']; ?>
      <div style="display:flex;flex-direction:column;gap:4px;font-size:13px;border-top:1px solid rgba(0,0,0,0.1);padding-top:var(--space-sm)">
        <div><strong><?= htmlspecialchars($a['full_name']) ?></strong> · #<?= htmlspecialchars($a['patient_code']) ?></div>
        <div><?= date('F j, Y', strtotime($a['appointment_date'])) ?>
             <?php if ($a['time_slot']): ?> · <?= htmlspecialchars($a['time_slot']) ?><?php endif; ?></div>
        <div>Category <?= htmlspecialchars($a['category']) ?> · <?= htmlspecialchars($a['animal_type']) ?> bite</div>
        <div style="color:var(--on-surface-variant)"><?= htmlspecialchars($a['body_location']) ?></div>
      </div>
      <?php if ($verifyResult['status'] === 'valid'): ?>
      <form method="POST" style="margin-top:var(--space-md)">
        <input type="hidden" name="qr_token" value="<?= htmlspecialchars($rawToken) ?>"/>
        <input type="hidden" name="admit" value="1"/>
        <button class="btn btn-primary btn-full" type="submit">
          <span class="material-symbols-outlined">how_to_reg</span>
          Admit Patient & Mark Complete
        </button>
      </form>
      <?php endif; ?>
      <a href="<?= APP_BASE ?>/admin/patient_detail.php?id=<?= $a['patient_id'] ?>"
         class="btn btn-surface btn-full" style="margin-top:var(--space-sm)">
        <span class="material-symbols-outlined">person</span>
        View Full Record
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- jsQR library for camera QR decoding -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
let videoStream = null;
let scanInterval = null;

async function startScanner() {
  try {
    videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    const video = document.getElementById('scanner-video');
    video.srcObject = videoStream;
    document.getElementById('btn-start-scan').style.display = 'none';
    document.getElementById('btn-stop-scan').style.display  = '';
    scanInterval = setInterval(tick, 250);
  } catch (e) {
    alert('Camera access denied or not available: ' + e.message);
  }
}

function stopScanner() {
  if (videoStream) { videoStream.getTracks().forEach(t => t.stop()); videoStream = null; }
  clearInterval(scanInterval);
  document.getElementById('btn-start-scan').style.display = '';
  document.getElementById('btn-stop-scan').style.display  = 'none';
}

function tick() {
  const video  = document.getElementById('scanner-video');
  const canvas = document.getElementById('scanner-canvas');
  if (video.readyState !== video.HAVE_ENOUGH_DATA) return;
  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const code = jsQR(imageData.data, imageData.width, imageData.height);
  if (code && code.data) {
    stopScanner();
    document.getElementById('qr_token').value = code.data;
    document.querySelector('form[method="POST"] button[type="submit"]').click();
  }
}
</script>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
