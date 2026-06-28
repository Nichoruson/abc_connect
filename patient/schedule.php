<?php
// ============================================================
// ABC Connect — Patient Dose Schedule & Appointment History
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db     = getDB();
$userId = (int)$_SESSION['patient_user_id'];

// Active patient
$patStmt = $db->prepare("SELECT * FROM patients WHERE user_id = :uid AND status='active' ORDER BY created_at DESC LIMIT 1");
$patStmt->execute([':uid' => $userId]);
$patient = $patStmt->fetch();

// Doses
$doses = [];
if ($patient) {
    $dStmt = $db->prepare("SELECT v.*, a.full_name AS admin_name FROM vaccines v LEFT JOIN admins a ON v.administered_by = a.id WHERE v.patient_id = :pid ORDER BY v.dose_number ASC");
    $dStmt->execute([':pid' => $patient['id']]);
    $doses = $dStmt->fetchAll();
}

// Upcoming appointments
$upcoming = [];
if ($patient) {
    $uStmt = $db->prepare("SELECT * FROM appointments WHERE patient_id = :pid AND status='scheduled' AND appointment_date >= CURDATE() ORDER BY appointment_date ASC LIMIT 5");
    $uStmt->execute([':pid' => $patient['id']]);
    $upcoming = $uStmt->fetchAll();
}

$page_title = 'My Schedule';
$active_nav = 'schedule';
include __DIR__ . '/../includes/header_patient.php';

function doseIcon(string $status): string {
    return match($status) {
        'administered' => '<div class="dose-icon dose-icon--done"><span class="material-symbols-outlined icon-filled" style="font-size:18px">check</span></div>',
        'missed'       => '<div class="dose-icon dose-icon--overdue"><span class="material-symbols-outlined icon-filled" style="font-size:18px">warning</span></div>',
        default        => '<div class="dose-icon dose-icon--pending"><span class="material-symbols-outlined" style="font-size:18px">vaccines</span></div>',
    };
}
?>

<section class="animate-fade-in stagger-1">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">calendar_month</span>
    <h2>Vaccination Schedule</h2>
  </div>

  <?php if (empty($doses)): ?>
  <div class="empty-state">
    <span class="material-symbols-outlined">vaccines</span>
    <p>No vaccination schedule yet.<br/>Complete a triage form to begin.</p>
    <a href="<?= APP_BASE ?>/patient/dashboard.php" class="btn btn-primary" style="margin-top:var(--space-md)">
      Start Triage
    </a>
  </div>
  <?php else: ?>
  <?php
    $activeDoseId = null;
    foreach ($doses as $d) {
      if ($d['status'] === 'scheduled' || $d['status'] === 'missed') {
        $activeDoseId = $d['id'];
        break;
      }
    }
  ?>
  <div style="display:flex;flex-direction:column;gap:var(--space-md)">
    <?php foreach ($doses as $i => $dose):
      $isAdministered = $dose['status'] === 'administered';
      $isMissed       = $dose['status'] === 'missed';
      $isActive       = !$isAdministered && (int)$dose['id'] === (int)$activeDoseId;
      $extraClass     = $isAdministered ? 'done' : ($isMissed ? 'overdue' : ($isActive ? 'active' : ''));
    ?>
    <div class="dose-schedule-item <?= $extraClass ?> animate-fade-in stagger-<?= min($i+1,5) ?>">
      <?= doseIcon($dose['status']) ?>
      <div style="flex:1">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-weight:700;font-size:15px">
            Day <?= $dose['dose_day'] ?> — Dose <?= $dose['dose_number'] ?>
          </span>
          <?php if ($isAdministered): ?>
          <span class="badge badge-consult">Done</span>
          <?php elseif ($isMissed): ?>
          <span class="badge badge-error">Missed</span>
          <?php else: ?>
          <span class="badge badge-info">Pending</span>
          <?php endif; ?>
        </div>
        <div style="font-size:var(--text-label-sm);color:var(--on-surface-variant);margin-top:3px">
          <?php if ($isAdministered): ?>
            Administered <?= $dose['administered_date'] ? date('M j, Y', strtotime($dose['administered_date'])) : 'N/A' ?>
            <?php if ($dose['admin_name']): ?> · <?= htmlspecialchars($dose['admin_name']) ?><?php endif; ?>
          <?php else: ?>
            Scheduled: <?= $dose['scheduled_date'] ? date('M j, Y', strtotime($dose['scheduled_date'])) : 'TBD' ?>
            <?php if ($dose['scheduled_date'] && strtotime($dose['scheduled_date']) < strtotime('today')): ?>
            <span class="badge badge-error" style="margin-left:6px">Overdue</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($dose['vaccine_brand']): ?>
        <div style="font-size:11px;color:var(--on-surface-variant);margin-top:2px"><?= htmlspecialchars($dose['vaccine_brand']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- Upcoming Appointments -->
<section class="animate-fade-in stagger-3">
  <div class="section-heading" style="margin-top:var(--space-sm)">
    <span class="material-symbols-outlined icon-filled">event</span>
    <h2>Upcoming Appointments</h2>
  </div>

  <?php if (empty($upcoming)): ?>
  <div class="card" style="text-align:center;padding:var(--space-lg)">
    <p style="color:var(--on-surface-variant);font-size:15px">No upcoming appointments.</p>
    <a href="<?= APP_BASE ?>/patient/book.php" class="btn btn-secondary btn-sm" style="margin-top:var(--space-md)">Book Appointment</a>
  </div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:var(--space-md)">
    <?php foreach ($upcoming as $appt): 
      $dateTs  = strtotime($appt['appointment_date']);
      $typeLabel = $appt['appointment_type'] === 'first_evaluation' ? 'First Evaluation' : 'Follow-up Dose (Day ' . $appt['dose_day'] . ')';
    ?>
    <div class="appointment-card">
      <div class="appointment-card__date-box">
        <span class="day"><?= date('d', $dateTs) ?></span>
        <span class="month"><?= date('M', $dateTs) ?></span>
      </div>
      <div class="appointment-card__info">
        <div class="appointment-card__title">
          <?= htmlspecialchars($typeLabel) ?>
          <?php if (!empty($appt['auto_booked'])): ?>
          <span class="badge badge-info" style="font-size:10px;margin-left:4px">Auto-Reserved</span>
          <?php endif; ?>
        </div>
        <div class="appointment-card__sub">
          <?= date('l, Y', $dateTs) ?>
          <?php if ($appt['time_slot']): ?> · <?= htmlspecialchars($appt['time_slot']) ?><?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end">
        <span class="badge badge-info"><?= ucfirst($appt['status']) ?></span>
        <?php if (!empty($appt['qr_token'])): ?>
        <a href="<?= APP_BASE ?>/patient/my_pass.php?id=<?= $appt['id'] ?>"
           class="btn btn-surface btn-sm" style="font-size:11px;padding:4px 8px">
          <span class="material-symbols-outlined" style="font-size:14px">qr_code</span>
          Pass
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
