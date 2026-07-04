<?php
// ============================================================
// RabiesShield Daet — Patient: Digital Vaccination Card
// Permanent QR ID card with full medical record
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db       = getDB();
$userId   = (int)$_SESSION['patient_user_id'];
$patientId= (int)($_GET['id'] ?? 0);

// Fetch patient — must belong to this user
$stmt = $db->prepare("
    SELECT p.*, u.full_name, u.contact_number, u.birthdate, u.sex, u.address
    FROM patients p JOIN users u ON p.user_id = u.id
    WHERE p.id = :pid AND p.user_id = :uid
    LIMIT 1
");
$stmt->execute([':pid' => $patientId, ':uid' => $userId]);
$patient = $stmt->fetch();

if (!$patient) {
    // Default to most recent active patient
    $stmt2 = $db->prepare("
        SELECT p.*, u.full_name, u.contact_number, u.birthdate, u.sex, u.address
        FROM patients p JOIN users u ON p.user_id = u.id
        WHERE p.user_id = :uid ORDER BY p.created_at DESC LIMIT 1
    ");
    $stmt2->execute([':uid' => $userId]);
    $patient = $stmt2->fetch();
}

if (!$patient) {
    header('Location: ' . APP_BASE . '/patient/dashboard.php');
    exit;
}

// Doses
$doses = $db->prepare("
    SELECT v.*, a.full_name AS administered_by_name
    FROM vaccines v
    LEFT JOIN admins a ON v.administered_by = a.id
    WHERE v.patient_id = :pid
    ORDER BY v.dose_number ASC
");
$doses->execute([':pid' => $patient['id']]);
$doses = $doses->fetchAll();

// QR payload for the card identity
$cardPayload = json_encode([
    'sys'   => 'abc_connect',
    'type'  => 'vaccination_card',
    'code'  => $patient['patient_code'],
    'uid'   => $userId,
]);

// Generate QR
$qrLibPath  = __DIR__ . '/../assets/libs/phpqrcode/qrlib.php';
$qrImageB64 = null;
if (file_exists($qrLibPath)) {
    require_once $qrLibPath;
    $tmpDir = __DIR__ . '/../logs';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }
    $qrFile = $tmpDir . '/card_' . $patient['id'] . '.png';
    if (!file_exists($qrFile)) {
        QRcode::png($cardPayload, $qrFile, QR_ECLEVEL_H, 6, 2);
    }
    if (file_exists($qrFile)) {
        $qrImageB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
    }
}

$doneCount  = count(array_filter($doses, fn($d) => $d['status'] === 'administered'));
$totalCount = count($doses);
$pct        = $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0;

$page_title = 'Vaccination Card';
$active_nav = 'card';
include __DIR__ . '/../includes/header_patient.php';
?>

<style>
.vcard {
  background: linear-gradient(150deg, #006b5f 0%, #003d36 60%, #001f1b 100%);
  border-radius: 20px;
  padding: var(--space-xl);
  color: white;
  position: relative;
  overflow: hidden;
  box-shadow: 0 16px 48px rgba(0,107,95,0.4);
}
.vcard__watermark {
  position: absolute;
  bottom: -20px; right: -20px;
  width: 160px; height: 160px;
  border-radius: 50%;
  background: rgba(255,255,255,0.04);
  pointer-events: none;
}
.vcard__watermark2 {
  position: absolute;
  top: -40px; left: -30px;
  width: 120px; height: 120px;
  border-radius: 50%;
  background: rgba(255,255,255,0.03);
  pointer-events: none;
}
.dose-row {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-sm) 0;
  border-bottom: 1px solid var(--outline-variant);
}
.dose-row:last-child { border-bottom: none; }
.dose-check {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.dose-check--done    { background: #dcfce7; }
.dose-check--pending { background: var(--surface-container); }
.dose-check--missed  { background: #fee2e2; }
</style>

<section class="animate-fade-in stagger-1">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">badge</span>
    <h2>Digital Vaccination Card</h2>
  </div>

  <!-- The Card -->
  <div class="vcard animate-fade-in stagger-2">
    <div class="vcard__watermark"></div>
    <div class="vcard__watermark2"></div>

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-lg);position:relative;z-index:1">
      <div style="display:flex;align-items:center;gap:var(--space-sm)">
        <img src="<?= APP_BASE ?>/assets/logo.png" alt="ABC Connect Logo" style="width:38px;height:38px;object-fit:contain;border-radius:10px;background:white;padding:2px;"/>
        <div>
          <div style="font-weight:800;font-size:14px">ABC Connect</div>
          <div style="font-size:10px;opacity:0.7;text-transform:uppercase;letter-spacing:0.08em">Animal Bite Center</div>
        </div>
      </div>
      <!-- QR Code -->
      <div style="background:white;border-radius:10px;padding:6px;display:flex;align-items:center;justify-content:center">
        <?php if ($qrImageB64): ?>
          <img src="<?= $qrImageB64 ?>" alt="Patient QR" style="width:64px;height:64px;image-rendering:pixelated"/>
        <?php else: ?>
          <span class="material-symbols-outlined" style="font-size:48px;color:var(--outline)">qr_code</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Patient -->
    <div style="position:relative;z-index:1;margin-bottom:var(--space-md)">
      <div style="font-size:24px;font-weight:900;letter-spacing:-0.02em"><?= htmlspecialchars($patient['full_name']) ?></div>
      <div style="font-size:13px;opacity:0.8;margin-top:4px">
        #<?= htmlspecialchars($patient['patient_code']) ?>
        <?php if ($patient['birthdate']): ?>
          · Born <?= date('M j, Y', strtotime($patient['birthdate'])) ?>
        <?php endif; ?>
        <?php if ($patient['sex']): ?>
          · <?= htmlspecialchars($patient['sex']) ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Bite Info -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-sm);position:relative;z-index:1;margin-bottom:var(--space-md)">
      <div style="background:rgba(255,255,255,0.08);border-radius:10px;padding:var(--space-sm) var(--space-md)">
        <div style="font-size:10px;opacity:0.6;text-transform:uppercase;letter-spacing:0.06em">Category</div>
        <div style="font-weight:800;font-size:18px">Category <?= htmlspecialchars($patient['category']) ?></div>
      </div>
      <div style="background:rgba(255,255,255,0.08);border-radius:10px;padding:var(--space-sm) var(--space-md)">
        <div style="font-size:10px;opacity:0.6;text-transform:uppercase;letter-spacing:0.06em">Animal</div>
        <div style="font-weight:700;font-size:15px"><?= htmlspecialchars($patient['animal_ownership']) ?> <?= htmlspecialchars($patient['animal_type']) ?></div>
      </div>
      <div style="background:rgba(255,255,255,0.08);border-radius:10px;padding:var(--space-sm) var(--space-md)">
        <div style="font-size:10px;opacity:0.6;text-transform:uppercase;letter-spacing:0.06em">Bite Site</div>
        <div style="font-weight:600;font-size:14px"><?= htmlspecialchars($patient['body_location']) ?></div>
      </div>
      <div style="background:rgba(255,255,255,0.08);border-radius:10px;padding:var(--space-sm) var(--space-md)">
        <div style="font-size:10px;opacity:0.6;text-transform:uppercase;letter-spacing:0.06em">Bite Date</div>
        <div style="font-weight:600;font-size:14px"><?= date('M j, Y', strtotime($patient['bite_date'])) ?></div>
      </div>
    </div>

    <!-- Progress -->
    <div style="position:relative;z-index:1">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
        <span style="opacity:0.7">Dose Progress</span>
        <span style="font-weight:700"><?= $doneCount ?>/<?= $totalCount ?> doses</span>
      </div>
      <div style="height:6px;background:rgba(255,255,255,0.15);border-radius:3px">
        <div style="height:100%;width:<?= $pct ?>%;background:white;border-radius:3px;transition:width 0.6s"></div>
      </div>
    </div>
  </div>

  <!-- Dose Details -->
  <div class="card animate-fade-in stagger-3" style="margin-top:var(--space-md)">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:var(--space-md)">Vaccination Record</h3>
    <?php if (empty($doses)): ?>
    <div style="text-align:center;color:var(--on-surface-variant);padding:var(--space-lg)">
      <span class="material-symbols-outlined" style="font-size:36px;color:var(--outline)">vaccines</span>
      <p>No doses recorded yet.</p>
    </div>
    <?php else: ?>
    <?php foreach ($doses as $dose):
      $isAdmn = $dose['status'] === 'administered';
      $isMiss = $dose['status'] === 'missed';
      $checkBg = $isAdmn ? 'dose-check--done' : ($isMiss ? 'dose-check--missed' : 'dose-check--pending');
      $checkColor = $isAdmn ? '#16a34a' : ($isMiss ? '#dc2626' : 'var(--on-surface-variant)');
      $icon = $isAdmn ? 'check' : ($isMiss ? 'close' : 'schedule');
    ?>
    <div class="dose-row">
      <div class="dose-check <?= $checkBg ?>">
        <span class="material-symbols-outlined icon-filled" style="font-size:16px;color:<?= $checkColor ?>"><?= $icon ?></span>
      </div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:14px">Day <?= $dose['dose_day'] ?> — Dose <?= $dose['dose_number'] ?></div>
        <div style="font-size:12px;color:var(--on-surface-variant)">
          <?php if ($isAdmn): ?>
            Administered <?= $dose['administered_date'] ? date('M j, Y', strtotime($dose['administered_date'])) : 'N/A' ?>
            <?php if ($dose['administered_by_name']): ?> by <?= htmlspecialchars($dose['administered_by_name']) ?><?php endif; ?>
          <?php else: ?>
            Scheduled: <?= $dose['scheduled_date'] ? date('M j, Y', strtotime($dose['scheduled_date'])) : 'TBD' ?>
          <?php endif; ?>
        </div>
        <?php if ($dose['vaccine_brand'] && $dose['vaccine_brand'] !== 'Rabies Vaccine'): ?>
        <div style="font-size:11px;color:var(--on-surface-variant)"><?= htmlspecialchars($dose['vaccine_brand']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($isAdmn): ?>
      <span class="badge badge-consult">Done</span>
      <?php elseif ($isMiss): ?>
      <span class="badge badge-error">Missed</span>
      <?php else: ?>
      <span class="badge badge-info">Pending</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Remarks -->
  <?php if (!empty($patient['remarks'])): ?>
  <div class="info-card animate-fade-in stagger-4" style="margin-top:var(--space-sm)">
    <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);flex-shrink:0">clinical_notes</span>
    <div>
      <h4 style="font-size:var(--text-label-md);font-weight:700;color:var(--on-secondary-container);margin-bottom:4px">Clinical Remarks</h4>
      <p style="font-size:var(--text-label-sm);color:var(--on-secondary-container)"><?= htmlspecialchars($patient['remarks']) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <a href="<?= APP_BASE ?>/patient/profile.php" class="btn btn-surface btn-full" style="margin-top:var(--space-md)">
    <span class="material-symbols-outlined">arrow_back</span>
    Back to Profile
  </a>
</section>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
