<?php
// ============================================================
// RabiesShield Daet — Patient: My Entry Pass (QR Code)
// Generates a QR-coded digital entry pass for an appointment
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db     = getDB();
$userId = (int)$_SESSION['patient_user_id'];
$apptId = (int)($_GET['id'] ?? 0);

// Fetch appointment — must belong to this user
$stmt = $db->prepare("
    SELECT a.*, p.patient_code, p.category, p.animal_type, p.body_location,
           u.full_name, u.contact_number
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE a.id = :aid AND u.id = :uid
    LIMIT 1
");
$stmt->execute([':aid' => $apptId, ':uid' => $userId]);
$appt = $stmt->fetch();

if (!$appt) {
    header('Location: ' . APP_BASE . '/patient/schedule.php');
    exit;
}

// Build QR payload — compact but verifiable
$qrPayload = json_encode([
    'sys'   => 'abc_connect',
    'appt'  => $appt['id'],
    'token' => $appt['qr_token'],
    'code'  => $appt['patient_code'],
    'date'  => $appt['appointment_date'],
]);

// Generate QR using phpqrcode
$qrLibPath = __DIR__ . '/../assets/libs/phpqrcode/qrlib.php';
$qrImagePath = null;
if (file_exists($qrLibPath)) {
    require_once $qrLibPath;
    $tmpDir = sys_get_temp_dir();
    $qrFile = $tmpDir . '/pass_' . $appt['id'] . '_' . substr($appt['qr_token'], 0, 8) . '.png';
    if (!file_exists($qrFile)) {
        QRcode::png($qrPayload, $qrFile, QR_ECLEVEL_H, 8, 2);
    }
    // Convert to base64 for inline embedding
    if (file_exists($qrFile)) {
        $qrImagePath = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
    }
}

$typeLabel = $appt['appointment_type'] === 'first_evaluation' ? 'First Evaluation' : 'Follow-up Dose — Day ' . $appt['dose_day'];
$dateStr   = date('l, F j, Y', strtotime($appt['appointment_date']));
$isToday   = $appt['appointment_date'] === date('Y-m-d');
$isPast    = strtotime($appt['appointment_date']) < strtotime('today');

$page_title = 'My Entry Pass';
$active_nav = 'schedule';
include __DIR__ . '/../includes/header_patient.php';
?>

<style>
.pass-card {
  background: linear-gradient(145deg, #006b5f 0%, #004d43 100%);
  border-radius: 20px;
  padding: var(--space-xl);
  color: white;
  position: relative;
  overflow: hidden;
  margin-bottom: var(--space-md);
  box-shadow: 0 12px 40px rgba(0,107,95,0.35);
}
.pass-card::before {
  content: '';
  position: absolute;
  top: -60px; right: -60px;
  width: 180px; height: 180px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
}
.pass-card::after {
  content: '';
  position: absolute;
  bottom: -40px; left: -30px;
  width: 120px; height: 120px;
  border-radius: 50%;
  background: rgba(255,255,255,0.04);
}
.pass-status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: var(--space-md);
}
.pass-qr-box {
  background: white;
  border-radius: 16px;
  padding: var(--space-lg);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-md);
  margin: var(--space-md) auto;
  max-width: 280px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}
.pass-detail-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-sm) 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  font-size: 13px;
}
.pass-detail-row:last-child { border-bottom: none; }
.pass-detail-label { opacity: 0.7; }
.pass-detail-value { font-weight: 700; text-align: right; max-width: 60%; }
</style>

<section class="animate-fade-in stagger-1">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">qr_code</span>
    <h2>Entry Pass</h2>
  </div>

  <?php if ($isPast): ?>
  <div class="alert alert-error" style="margin-bottom:var(--space-md)">
    <span class="material-symbols-outlined" style="color:var(--error)">history</span>
    <span>This appointment was on <?= $dateStr ?>. This pass is no longer valid.</span>
  </div>
  <?php endif; ?>

  <!-- Entry Pass Card -->
  <div class="pass-card animate-fade-in stagger-2">
    <!-- Header -->
    <div style="display:flex;align-items:center;gap:var(--space-sm);margin-bottom:var(--space-md);position:relative;z-index:1">
      <img src="<?= APP_BASE ?>/assets/logo.png" alt="ABC Connect Logo" style="width:36px;height:36px;object-fit:contain;border-radius:10px;background:white;padding:2px;"/>
      <div>
        <div style="font-weight:800;font-size:15px">ABC Connect</div>
        <div style="font-size:11px;opacity:0.75">Animal Bite Center</div>
      </div>
    </div>

    <!-- Status Badge -->
    <div style="position:relative;z-index:1">
      <?php if ($appt['status'] === 'scheduled' && !$isPast): ?>
        <div class="pass-status-badge" style="background:rgba(255,255,255,0.2)">
          <span class="material-symbols-outlined icon-filled" style="font-size:14px">verified</span>
          Valid Entry Pass
        </div>
      <?php elseif ($appt['status'] === 'completed'): ?>
        <div class="pass-status-badge" style="background:rgba(255,255,255,0.15)">
          <span class="material-symbols-outlined icon-filled" style="font-size:14px">check_circle</span>
          Completed
        </div>
      <?php else: ?>
        <div class="pass-status-badge" style="background:rgba(239,68,68,0.3)">
          <span class="material-symbols-outlined" style="font-size:14px">cancel</span>
          <?= ucfirst($appt['status']) ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Patient Info -->
    <div style="position:relative;z-index:1">
      <div style="font-size:22px;font-weight:800;margin-bottom:4px"><?= htmlspecialchars($appt['full_name']) ?></div>
      <div style="font-size:13px;opacity:0.8">Patient Code: <strong>#<?= htmlspecialchars($appt['patient_code']) ?></strong></div>
    </div>

    <!-- Details -->
    <div style="margin-top:var(--space-md);position:relative;z-index:1">
      <div class="pass-detail-row">
        <span class="pass-detail-label">Date</span>
        <span class="pass-detail-value"><?= $dateStr ?></span>
      </div>
      <div class="pass-detail-row">
        <span class="pass-detail-label">Type</span>
        <span class="pass-detail-value"><?= htmlspecialchars($typeLabel) ?></span>
      </div>
      <?php if ($appt['time_slot']): ?>
      <div class="pass-detail-row">
        <span class="pass-detail-label">Time</span>
        <span class="pass-detail-value"><?= htmlspecialchars($appt['time_slot']) ?></span>
      </div>
      <?php endif; ?>
      <div class="pass-detail-row">
        <span class="pass-detail-label">Category</span>
        <span class="pass-detail-value">Category <?= htmlspecialchars($appt['category']) ?></span>
      </div>
    </div>
  </div>

  <!-- QR Code -->
  <div class="pass-qr-box animate-fade-in stagger-3">
    <?php if ($qrImagePath): ?>
      <img src="<?= $qrImagePath ?>" alt="Entry Pass QR Code" style="width:200px;height:200px;image-rendering:pixelated"/>
      <div style="text-align:center">
        <div style="font-weight:800;font-size:14px;color:var(--on-surface)"><?= htmlspecialchars($appt['patient_code']) ?></div>
        <div style="font-size:11px;color:var(--on-surface-variant);margin-top:2px">Show this to clinic staff at arrival</div>
      </div>
    <?php else: ?>
      <div style="width:200px;height:200px;background:var(--surface-container);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--space-sm)">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--outline)">qr_code_2</span>
        <div style="font-size:12px;color:var(--on-surface-variant);text-align:center;padding:0 var(--space-md)">
          QR generation unavailable.<br>Show your Patient Code instead.
        </div>
      </div>
      <div style="font-size:20px;font-weight:800;color:var(--primary);letter-spacing:2px">
        #<?= htmlspecialchars($appt['patient_code']) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Info -->
  <div class="info-card animate-fade-in stagger-4">
    <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);flex-shrink:0">info</span>
    <div>
      <h4 style="font-size:var(--text-label-md);font-weight:700;color:var(--on-secondary-container);margin-bottom:4px">How to use this pass</h4>
      <p style="font-size:var(--text-label-sm);color:var(--on-secondary-container)">
        Show the QR code to the clinic staff when you arrive. They will scan it to verify your appointment and let you in.
        <strong>No walk-ins accepted if daily slots are full.</strong>
      </p>
    </div>
  </div>

  <a href="<?= APP_BASE ?>/patient/schedule.php" class="btn btn-surface btn-full" style="margin-top:var(--space-sm)">
    <span class="material-symbols-outlined">arrow_back</span>
    Back to Schedule
  </a>
</section>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
