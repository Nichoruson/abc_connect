<?php
// ============================================================
// RabiesShield Daet — Patient: Book Appointment
// Now shows daily slot availability + QR pass link after booking
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db     = getDB();
$userId = (int)$_SESSION['patient_user_id'];
$today  = date('Y-m-d');

// Active patient
$patStmt = $db->prepare("SELECT * FROM patients WHERE user_id=:uid AND status='active' ORDER BY created_at DESC LIMIT 1");
$patStmt->execute([':uid' => $userId]);
$patient = $patStmt->fetch();

// Pending doses
$pendingDoses = [];
if ($patient) {
    $dStmt = $db->prepare("SELECT * FROM vaccines WHERE patient_id=:pid AND status='scheduled' ORDER BY dose_number ASC");
    $dStmt->execute([':pid' => $patient['id']]);
    $pendingDoses = $dStmt->fetchAll();
}

// Helper: get cap & booked count for a date
function getSlotInfo(PDO $db, string $date): array {
    $capStmt = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date = :d");
    $capStmt->execute([':d' => $date]);
    $cap      = $capStmt->fetch();
    $maxSlots = $cap ? (int)$cap['max_slots'] : 100;

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :d AND status = 'scheduled'");
    $cntStmt->execute([':d' => $date]);
    $booked = (int)$cntStmt->fetchColumn();
    return ['max' => $maxSlots, 'booked' => $booked, 'available' => max(0, $maxSlots - $booked), 'full' => $booked >= $maxSlots];
}

// Build 7-day availability preview
$slotPreview = [];
for ($i = 0; $i <= 6; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    $slotPreview[$d] = getSlotInfo($db, $d);
}

$success    = '';
$error      = '';
$bookedApptId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apptType = $_POST['appt_type'] ?? 'follow_up_dose';
    $apptDate = $_POST['appt_date'] ?? '';
    $timeSlot = $_POST['time_slot'] ?? '';
    $doseDay  = (int)($_POST['dose_day'] ?? 0);

    if (!$apptDate) {
        $error = 'Please select an appointment date.';
    } elseif (!$patient) {
        $error = 'No active patient record. Please complete a triage form first.';
    } else {
        $patientId = (int)$patient['id'];
        $slotInfo  = getSlotInfo($db, $apptDate);

        if ($slotInfo['full']) {
            $error = "That date is fully booked ({$slotInfo['booked']}/{$slotInfo['max']} slots). Please try another date.";
        } else {
            $dupStmt = $db->prepare("SELECT id FROM appointments WHERE patient_id=:pid AND appointment_date=:date AND status='scheduled'");
            $dupStmt->execute([':pid' => $patientId, ':date' => $apptDate]);
            if ($dupStmt->fetch()) {
                $error = 'You already have an appointment on that date.';
            } else {
                $qrToken = bin2hex(random_bytes(32));
                $ins = $db->prepare("INSERT INTO appointments (patient_id, appointment_type, dose_day, appointment_date, time_slot, qr_token) VALUES (:pid,:type,:dd,:date,:slot,:token)");
                $ins->execute([':pid' => $patientId, ':type' => $apptType, ':dd' => $doseDay, ':date' => $apptDate, ':slot' => $timeSlot, ':token' => $qrToken]);
                $bookedApptId = (int)$db->lastInsertId();

                if ($apptType === 'follow_up_dose') {
                    $db->prepare("UPDATE vaccines SET scheduled_date=:date WHERE patient_id=:pid AND dose_day=:dd AND status='scheduled' LIMIT 1")
                       ->execute([':date' => $apptDate, ':pid' => $patientId, ':dd' => $doseDay]);
                }
                $success = 'Appointment booked for ' . date('F j, Y', strtotime($apptDate)) . '!';
            }
        }
    }
}

$page_title = 'Book Appointment';
$active_nav = 'book';
include __DIR__ . '/../includes/header_patient.php';
?>

<section class="animate-fade-in stagger-1">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">event_available</span>
    <h2>Book Appointment</h2>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success" style="flex-direction:column;align-items:flex-start;gap:var(--space-sm)">
    <div style="display:flex;align-items:center;gap:var(--space-sm)">
      <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php if ($bookedApptId): ?>
    <a href="<?= APP_BASE ?>/patient/my_pass.php?id=<?= $bookedApptId ?>"
       class="btn btn-primary btn-full" style="margin-top:var(--space-sm)">
      <span class="material-symbols-outlined">qr_code</span>
      View My Entry Pass (QR Code)
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-error">
    <span class="material-symbols-outlined" style="color:var(--error)">error</span>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <!-- 7-Day Slot Availability -->
  <div style="margin-bottom:var(--space-lg)">
    <h3 style="font-size:14px;font-weight:700;color:var(--on-surface-variant);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:var(--space-sm)">
      Slot Availability
    </h3>
    <div style="display:flex;gap:var(--space-sm);overflow-x:auto;padding-bottom:4px">
      <?php foreach ($slotPreview as $d => $s):
        $label = $d === date('Y-m-d') ? 'Today' : date('D', strtotime($d));
        $dayNum = date('j', strtotime($d));
        $bg = $s['full'] ? '#fee2e2' : ($s['available'] <= 20 ? '#fef3c7' : '#dcfce7');
        $fc = $s['full'] ? '#991b1b' : ($s['available'] <= 20 ? '#92400e' : '#166534');
        $txt = $s['full'] ? 'Full' : $s['available'].' left';
      ?>
      <div style="flex-shrink:0;text-align:center;padding:var(--space-sm) var(--space-md);background:<?= $bg ?>;border-radius:var(--radius-lg);min-width:70px">
        <div style="font-size:11px;font-weight:600;color:<?= $fc ?>;text-transform:uppercase"><?= $label ?></div>
        <div style="font-size:22px;font-weight:800;color:<?= $fc ?>"><?= $dayNum ?></div>
        <div style="font-size:11px;color:<?= $fc ?>;font-weight:600"><?= $txt ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$patient): ?>
  <div class="card" style="text-align:center;padding:var(--space-xl)">
    <span class="material-symbols-outlined" style="font-size:48px;color:var(--outline)">medical_information</span>
    <p style="margin:var(--space-md) 0;color:var(--on-surface-variant)">You need an active patient record first.</p>
    <a href="<?= APP_BASE ?>/patient/dashboard.php" class="btn btn-primary">Complete Triage</a>
  </div>
  <?php else: ?>

  <form method="POST" action="" style="display:flex;flex-direction:column;gap:var(--space-md)">
    <!-- Appointment Type -->
    <div class="form-group">
      <label class="form-label">Appointment Type</label>
      <div style="display:flex;flex-direction:column;gap:var(--space-sm)">
        <label style="display:flex;align-items:center;gap:var(--space-md);padding:var(--space-md);border:1.5px solid var(--outline-variant);border-radius:var(--radius-lg);cursor:pointer;transition:border-color 0.15s">
          <input type="radio" name="appt_type" value="first_evaluation" style="accent-color:var(--primary)"/>
          <div>
            <div style="font-weight:700;font-size:15px">First Evaluation</div>
            <div style="font-size:12px;color:var(--on-surface-variant)">Initial assessment after animal bite</div>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:var(--space-md);padding:var(--space-md);border:1.5px solid var(--outline-variant);border-radius:var(--radius-lg);cursor:pointer;transition:border-color 0.15s">
          <input type="radio" name="appt_type" value="follow_up_dose" checked style="accent-color:var(--primary)"/>
          <div>
            <div style="font-weight:700;font-size:15px">Follow-up Dose</div>
            <div style="font-size:12px;color:var(--on-surface-variant)">Scheduled vaccine dose (Day 3, 7, 14, or 28)</div>
          </div>
        </label>
      </div>
    </div>

    <!-- Dose Selection (for follow-up) -->
    <?php if (!empty($pendingDoses)): ?>
    <div class="form-group" id="dose-select-wrap">
      <label class="form-label" for="dose_day">Select Dose</label>
      <select class="form-select" id="dose_day" name="dose_day">
        <?php foreach ($pendingDoses as $pd): ?>
        <option value="<?= $pd['dose_day'] ?>">
          Day <?= $pd['dose_day'] ?> — Dose <?= $pd['dose_number'] ?>
          <?php if ($pd['scheduled_date']): ?>(target: <?= date('M j', strtotime($pd['scheduled_date'])) ?>)<?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <!-- Date Picker -->
    <div class="form-group">
      <label class="form-label" for="appt_date">Preferred Date</label>
      <input class="form-input" type="date" id="appt_date" name="appt_date"
             min="<?= date('Y-m-d') ?>" required
             value="<?= date('Y-m-d', strtotime('+1 day')) ?>"/>
      <div id="slot-availability-hint" style="margin-top:6px;font-size:13px;color:var(--on-surface-variant)"></div>
    </div>

    <!-- Time Slot -->
    <div class="form-group">
      <label class="form-label" for="time_slot">Preferred Time Slot</label>
      <select class="form-select" id="time_slot" name="time_slot">
        <option value="">No preference</option>
        <option value="8:00 AM - 8:30 AM">8:00 AM – 8:30 AM</option>
        <option value="8:30 AM - 9:00 AM">8:30 AM – 9:00 AM</option>
        <option value="9:00 AM - 9:30 AM">9:00 AM – 9:30 AM</option>
        <option value="9:30 AM - 10:00 AM">9:30 AM – 10:00 AM</option>
        <option value="10:00 AM - 10:30 AM">10:00 AM – 10:30 AM</option>
        <option value="10:30 AM - 11:00 AM">10:30 AM – 11:00 AM</option>
        <option value="1:00 PM - 1:30 PM">1:00 PM – 1:30 PM</option>
        <option value="1:30 PM - 2:00 PM">1:30 PM – 2:00 PM</option>
        <option value="2:00 PM - 2:30 PM">2:00 PM – 2:30 PM</option>
        <option value="2:30 PM - 3:00 PM">2:30 PM – 3:00 PM</option>
        <option value="3:00 PM - 3:30 PM">3:00 PM – 3:30 PM</option>
        <option value="3:30 PM - 4:00 PM">3:30 PM – 4:00 PM</option>
      </select>
    </div>

    <button class="btn btn-primary btn-full btn-lg" type="submit" id="book-btn">
      <span class="material-symbols-outlined">event_available</span>
      Confirm Booking
    </button>
  </form>
  <?php endif; ?>
</section>

<!-- Info -->
<div class="info-card animate-fade-in stagger-3">
  <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);flex-shrink:0">schedule</span>
  <div>
    <h4 style="font-size:var(--text-label-md);font-weight:700;color:var(--on-secondary-container);margin-bottom:4px">Clinic Hours</h4>
    <p style="font-size:var(--text-label-sm);color:var(--on-secondary-container)">
      Monday–Friday: 8:00 AM – 5:00 PM<br>
      Saturday: 8:00 AM – 12:00 PM<br>
      <strong>Closed on Sundays &amp; Holidays</strong>
    </p>
  </div>
</div>

<script>
// Live slot hint as date changes
const slotData = <?= json_encode($slotPreview) ?>;
const dateInput = document.getElementById('appt_date');
const hint      = document.getElementById('slot-availability-hint');
const bookBtn   = document.getElementById('book-btn');

function updateHint(date) {
  if (!hint || !bookBtn) return;
  if (slotData[date]) {
    const s = slotData[date];
    if (s.full) {
      hint.innerHTML = '<span style="color:var(--error);font-weight:600">⛔ Fully booked — try another date.</span>';
      bookBtn.disabled = true;
      bookBtn.textContent = 'Full – Try Another Date';
      bookBtn.style.opacity = '0.5';
    } else {
      hint.innerHTML = '<span style="color:#166534;font-weight:600">✅ ' + s.available + ' slots remaining</span>';
      bookBtn.disabled = false;
      bookBtn.innerHTML = '<span class="material-symbols-outlined">event_available</span> Confirm Booking';
      bookBtn.style.opacity = '1';
    }
  } else {
    hint.innerHTML = '<span style="color:var(--on-surface-variant)">Checking availability...</span>';
  }
}

if (dateInput) {
  dateInput.addEventListener('change', () => updateHint(dateInput.value));
  updateHint(dateInput.value);
}
</script>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
