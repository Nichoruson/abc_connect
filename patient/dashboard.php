<?php
// ============================================================
// ABC Connect — Patient Dashboard (main Stitch screen)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_patient_login();

$db     = getDB();
$userId = (int)$_SESSION['patient_user_id'];

// ---- Get active patient record ----
$patStmt = $db->prepare("
    SELECT p.*, u.full_name, u.contact_number, u.birthdate, u.sex
    FROM patients p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id = :uid AND p.status = 'active'
    ORDER BY p.created_at DESC LIMIT 1
");
$patStmt->execute([':uid' => $userId]);
$patient = $patStmt->fetch();

// ---- Vaccine timeline ----
$doses = [];
if ($patient) {
    $dStmt = $db->prepare("SELECT * FROM vaccines WHERE patient_id = :pid ORDER BY dose_number ASC");
    $dStmt->execute([':pid' => $patient['id']]);
    $doses = $dStmt->fetchAll();
}

// ---- Slot availability for Day 0 ----
$slotPreview = [];
if ($patient && empty($doses)) {
    for ($i = 0; $i <= 6; $i++) {
        $d = date('Y-m-d', strtotime("+$i days"));
        
        // Fetch cap
        $capStmt = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date = :d");
        $capStmt->execute([':d' => $d]);
        $cap = $capStmt->fetch();
        $maxSlots = $cap ? (int)$cap['max_slots'] : 100;
        
        // Fetch scheduled appointments
        $cntAppt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :d AND status = 'scheduled'");
        $cntAppt->execute([':d' => $d]);
        $bookedAppt = (int)$cntAppt->fetchColumn();
        
        // Fetch today's queued patients if $d is today
        $bookedQueue = 0;
        if ($d === date('Y-m-d')) {
            $cntQ = $db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) = CURDATE() AND status != 'no_show'")->fetchColumn();
            $bookedQueue = (int)$cntQ;
        }
        
        $booked = $bookedAppt + $bookedQueue;
        $available = max(0, $maxSlots - $booked);
        $slotPreview[$d] = [
            'max' => $maxSlots,
            'booked' => $booked,
            'available' => $available,
            'full' => ($available <= 0)
        ];
    }
}

// ---- Triage form submission ----
$triageSuccess = '';
$triageError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_triage'])) {
    $animalType  = $_POST['animal_type'] ?? 'Dog';
    $ownership   = $_POST['animal_ownership'] ?? 'Stray';
    $biteDate    = $_POST['bite_date'] ?? date('Y-m-d');
    $bodyLoc     = trim($_POST['body_location'] ?? '');
    $category    = $_POST['category'] ?? 'II';
    $remarks     = trim($_POST['remarks'] ?? '');

    if (!$bodyLoc) {
        $triageError = 'Please enter the location of the bite on the body.';
    } else {
        // Generate patient code
        $countStmt = $db->query("SELECT COUNT(*) FROM patients");
        $count     = (int)$countStmt->fetchColumn() + 1;
        $pCode     = 'P-' . str_pad($count + 9000, 4, '0', STR_PAD_LEFT);

        $insP = $db->prepare("
            INSERT INTO patients (user_id, patient_code, animal_type, animal_ownership, bite_date, body_location, category, remarks, status)
            VALUES (:uid, :code, :atype, :own, :bdate, :bloc, :cat, :rem, 'active')
        ");
        $insP->execute([
            ':uid'   => $userId,
            ':code'  => $pCode,
            ':atype' => $animalType,
            ':own'   => $ownership,
            ':bdate' => $biteDate,
            ':bloc'  => $bodyLoc,
            ':cat'   => $category,
            ':rem'   => $remarks,
        ]);
        $_SESSION['patient_code'] = $pCode;
        
        redirect(APP_BASE . '/patient/dashboard.php?registered_triage=1');
    }
}

// ---- Schedule first dose submission ----
$scheduleError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_schedule'])) {
    $firstDoseDate = $_POST['first_dose_date'] ?? date('Y-m-d');
    
    // Check validation of selected date slot
    if ($firstDoseDate < date('Y-m-d')) {
        $scheduleError = 'First dose schedule cannot be in the past.';
    } else {
        if (!isset($slotPreview[$firstDoseDate])) {
            $capStmt = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date = :d");
            $capStmt->execute([':d' => $firstDoseDate]);
            $cap = $capStmt->fetch();
            $maxSlots = $cap ? (int)$cap['max_slots'] : 100;
            
            $cntAppt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :d AND status = 'scheduled'");
            $cntAppt->execute([':d' => $firstDoseDate]);
            $bookedAppt = (int)$cntAppt->fetchColumn();
            
            $bookedQueue = 0;
            if ($firstDoseDate === date('Y-m-d')) {
                $cntQ = $db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) = CURDATE() AND status != 'no_show'")->fetchColumn();
                $bookedQueue = (int)$cntQ;
            }
            $booked = $bookedAppt + $bookedQueue;
            $isFull = ($booked >= $maxSlots);
        } else {
            $isFull = $slotPreview[$firstDoseDate]['full'];
        }
        
        if ($isFull) {
            $scheduleError = 'Sorry, the selected date is fully booked. Please choose a different date.';
        } elseif ($patient) {
            $patientId = $patient['id'];
            $pCode     = $patient['patient_code'];
            
            // Create vaccine schedule (Day 0, 3, 7, 14, 28)
            $doseDays = [0 => 1, 3 => 2, 7 => 3, 14 => 4, 28 => 5];
            foreach ($doseDays as $day => $doseNum) {
                $schedDate = date('Y-m-d', strtotime($firstDoseDate . " +$day days"));
                $db->prepare("
                    INSERT INTO vaccines (patient_id, dose_number, dose_day, scheduled_date, status)
                    VALUES (:pid, :dn, :dd, :sd, 'scheduled')
                ")->execute([
                    ':pid' => $patientId,
                    ':dn'  => $doseNum,
                    ':dd'  => $day,
                    ':sd'  => $schedDate,
                ]);
            }

            // Add to queue or schedule appointment
            $today = date('Y-m-d');
            if ($firstDoseDate === $today) {
                $qCount = $db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) = CURDATE()")->fetchColumn();
                $qNum   = 'Q-' . str_pad((int)$qCount + 1, 3, '0', STR_PAD_LEFT);
                $db->prepare("
                    INSERT INTO queue (patient_id, queue_number, status, purpose)
                    VALUES (:pid, :qnum, 'waiting', 'first_evaluation')
                ")->execute([':pid' => $patientId, ':qnum' => $qNum]);

                redirect(APP_BASE . '/patient/dashboard.php?registered=1');
            } else {
                $qrToken = bin2hex(random_bytes(32));
                $db->prepare("
                    INSERT INTO appointments (patient_id, appointment_type, dose_day, appointment_date, status, qr_token)
                    VALUES (:pid, 'first_evaluation', 0, :date, 'scheduled', :token)
                ")->execute([
                    ':pid'   => $patientId,
                    ':date'  => $firstDoseDate,
                    ':token' => $qrToken,
                ]);

                redirect(APP_BASE . '/patient/dashboard.php?scheduled_appt=' . urlencode($firstDoseDate));
            }
        }
    }
}

// Dose progress calculation
$totalDoses    = count($doses);
$completedDoses= count(array_filter($doses, fn($d) => $d['status'] === 'administered'));
$progressPct   = $totalDoses > 0 ? ($completedDoses / $totalDoses) * 80 : 0; // 80% = span of bar

// Flash messages
$regFlash = '';
if (isset($_GET['registered_triage'])) {
    $regFlash = 'Bite incident report submitted successfully! Please select your first dose (Day 0) schedule below.';
} elseif (isset($_GET['registered'])) {
    $regFlash = 'First dose schedule confirmed! You have been added to today\'s queue. Please proceed to the clinic.';
} elseif (isset($_GET['scheduled_appt'])) {
    $dateStr = date('F j, Y', strtotime($_GET['scheduled_appt']));
    $regFlash = "First dose schedule confirmed! Your Day 0 appointment is scheduled for " . $dateStr . ". Go to 'My Pass' on that day.";
}

$page_title = 'Home';
$active_nav = 'home';
include __DIR__ . '/../includes/header_patient.php';
?>

<!-- Flash Success -->
<?php if ($regFlash): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($regFlash) ?></span>
</div>
<?php endif; ?>

<!-- ===== Vaccine Timeline ===== -->
<?php if ($patient && !empty($doses)): ?>
<section class="vaccine-timeline-card animate-fade-in stagger-1">
  <div class="vaccine-timeline-header">
    <span class="vaccine-timeline-label">Vaccination Schedule</span>
    <span class="badge badge-consult">Active</span>
  </div>
  <div class="timeline-track">
    <div class="timeline-line"></div>
    <div class="timeline-progress" style="width:<?= $progressPct ?>%"></div>

    <?php foreach ($doses as $dose):
      $status = $dose['status'];
      $dotClass = match($status) {
        'administered' => 'timeline-node__dot--done',
        'missed'       => 'timeline-node__dot--done',
        default => ($dose['dose_day'] === (
            // current next day
            min(array_column(array_filter($doses, fn($d) => $d['status'] === 'scheduled'), 'dose_day') ?: [0])
          ) ? 'timeline-node__dot--active' : 'timeline-node__dot--pending')
      };
      $labelClass = str_contains($dotClass, 'active') ? 'timeline-node__label--active' : '';
    ?>
    <div class="timeline-node">
      <div class="timeline-node__dot <?= $dotClass ?>">
        <?php if ($status === 'administered'): ?>
          <span class="material-symbols-outlined" style="font-size:14px">check</span>
        <?php elseif (str_contains($dotClass,'active')): ?>
          <span style="width:8px;height:8px;border-radius:50%;background:var(--primary);display:block"></span>
        <?php else: ?>
          <?= $dose['dose_day'] ?>
        <?php endif; ?>
      </div>
      <span class="timeline-node__label <?= $labelClass ?>">
        <?= $dose['dose_day'] === 0 ? 'Day 0' : 'Day ' . $dose['dose_day'] ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:var(--space-md);font-size:var(--text-label-sm);color:var(--on-surface-variant);text-align:center">
    <?= $completedDoses ?> of <?= $totalDoses ?> doses completed
    <?php if ($patient['patient_code']): ?>
    · <strong style="color:var(--primary)">#<?= htmlspecialchars($patient['patient_code']) ?></strong>
    <?php endif; ?>
  </div>
</section>
<?php elseif ($patient && empty($doses)): ?>
<section class="animate-fade-in stagger-1">
  <div class="card" style="padding:var(--space-lg);margin-bottom:var(--space-md);background:var(--surface-container);border:1px solid var(--border);border-radius:16px">
    <div style="display:flex;align-items:center;gap:var(--space-sm);color:var(--primary);margin-bottom:var(--space-md)">
      <span class="material-symbols-outlined icon-filled" style="font-size:32px">calendar_month</span>
      <h2 style="font-size:20px;font-weight:800">Schedule Your First Dose (Day 0)</h2>
    </div>
    <p style="color:var(--on-surface-variant);font-size:14px;line-height:1.5;margin-bottom:var(--space-md)">
      Your bite incident report has been submitted successfully (Patient ID: <strong style="color:var(--primary)">#<?= htmlspecialchars($patient['patient_code']) ?></strong>). 
      <br><br>
      To complete your registration, select the date you plan to visit the clinic to get evaluated and receive your first vaccine shot. All subsequent doses (Day 3, 7, 14, 28) will be calculated and scheduled from this date.
    </p>

    <?php if ($scheduleError): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-md)">
      <span class="material-symbols-outlined" style="color:var(--error)">error</span>
      <span><?= htmlspecialchars($scheduleError) ?></span>
    </div>
    <?php endif; ?>
    
    <form method="POST" action="" style="display:flex;flex-direction:column;gap:var(--space-md)">
      <input type="hidden" name="submit_schedule" value="1" />
      <div class="form-group">
        <label class="form-label" for="first_dose_date">First Dose Date (Day 0) <span style="color:var(--error)">*</span></label>
        <select class="form-select" id="first_dose_date" name="first_dose_date" required>
          <?php foreach ($slotPreview as $dateVal => $info):
            $formattedDate = date('l, M j', strtotime($dateVal));
            $slotsText = $info['full'] ? 'Fully Booked' : $info['available'] . ' slots available';
            $disabled = $info['full'] ? 'disabled style="color:var(--on-surface-variant); opacity:0.6;"' : '';
          ?>
            <option value="<?= $dateVal ?>" <?= $disabled ?>>
              <?= $formattedDate ?> — <?= $slotsText ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <button type="submit" class="booking-btn booking-btn--primary" style="margin-top:var(--space-sm)">
        <div class="booking-btn__left">
          <span class="material-symbols-outlined booking-btn__icon icon-filled">event_available</span>
          <span class="booking-btn__text">Confirm Schedule</span>
        </div>
        <span class="material-symbols-outlined">chevron_right</span>
      </button>
    </form>
  </div>
</section>
<?php else: ?>
<!-- No active record — show welcome -->
<div class="card animate-fade-in stagger-1" style="text-align:center;padding:var(--space-xl)">
  <span class="material-symbols-outlined" style="font-size:48px;color:var(--primary-container)">health_and_safety</span>
  <h2 style="font-size:20px;margin:var(--space-md) 0 var(--space-sm)">Welcome, <?= htmlspecialchars(explode(' ', $_SESSION['patient_name'])[0]) ?>!</h2>
  <p style="color:var(--on-surface-variant);font-size:15px">If you've been bitten by an animal, please complete the triage form below to start your care journey.</p>
</div>
<?php endif; ?>

<!-- ===== Triage Form ===== -->
<section class="animate-fade-in stagger-2">
  <div class="section-heading">
    <span class="material-symbols-outlined icon-filled">clinical_notes</span>
    <h2>Bite Incident Report</h2>
  </div>

  <?php if ($triageError): ?>
  <div class="alert alert-error" style="margin-bottom:var(--space-md)">
    <span class="material-symbols-outlined" style="color:var(--error)">error</span>
    <span><?= htmlspecialchars($triageError) ?></span>
  </div>
  <?php endif; ?>

  <form id="triage-form" method="POST" action="">
    <input type="hidden" name="submit_triage" value="1"/>
    <div style="display:flex;flex-direction:column;gap:var(--space-md)">
      <div class="form-group">
        <label class="form-label" for="animal_type">Type of Animal</label>
        <select class="form-select" id="animal_type" name="animal_type">
          <?php foreach (['Dog','Cat','Bat','Monkey','Other'] as $a): ?>
          <option value="<?= $a ?>"><?= $a ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="triage-form">
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="bite_date">Date of Incident</label>
            <input class="form-input" type="date" id="bite_date" name="bite_date"
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label" for="animal_ownership">Animal Ownership</label>
            <select class="form-select" id="animal_ownership" name="animal_ownership">
              <option value="Pet">Pet</option>
              <option value="Stray" selected>Stray</option>
              <option value="Unknown">Unknown</option>
            </select>
          </div>
        </div>

      </div>
      <div class="form-group">
        <label class="form-label" for="body_location">Location on Body <span style="color:var(--error)">*</span></label>
        <input class="form-input" type="text" id="body_location" name="body_location"
               placeholder="e.g. Left Hand, Right Leg" required/>
      </div>
      <div class="form-group">
        <label class="form-label" for="category">Exposure Category</label>
        <select class="form-select" id="category" name="category">
          <option value="I">Category I — No wound, no licking on skin</option>
          <option value="II" selected>Category II — Minor scratches, abrasions</option>
          <option value="III">Category III — Transdermal bite, bleeding wound</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="remarks">Additional Notes (optional)</label>
        <textarea class="form-textarea" id="remarks" name="remarks" rows="2"
                  placeholder="Describe the incident, animal behavior, etc."></textarea>
      </div>
    </div>
    <!-- Booking Buttons -->
    <div style="display:flex;flex-direction:column;gap:var(--space-md);margin-top:var(--space-lg)">
      <p style="font-size:var(--text-label-sm);text-transform:uppercase;letter-spacing:0.08em;color:var(--on-surface-variant);text-align:center;font-weight:700">
        Schedule Visit
      </p>
      <button type="submit" class="booking-btn booking-btn--primary">
        <div class="booking-btn__left">
          <span class="material-symbols-outlined booking-btn__icon icon-filled">emergency</span>
          <span class="booking-btn__text">First Evaluation</span>
        </div>
        <span class="material-symbols-outlined">chevron_right</span>
      </button>
      <a href="<?= APP_BASE ?>/patient/book.php" class="booking-btn booking-btn--outline">
        <div class="booking-btn__left">
          <span class="material-symbols-outlined booking-btn__icon">vaccines</span>
          <span class="booking-btn__text">Follow-up Dose</span>
        </div>
        <span class="material-symbols-outlined">chevron_right</span>
      </a>
    </div>
  </form>
</section>

<!-- Info Card -->
<div class="info-card animate-fade-in stagger-3" style="margin-bottom:var(--space-md)">
  <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);flex-shrink:0">info</span>
  <div>
    <h4 style="font-size:var(--text-label-md);font-weight:700;color:var(--on-secondary-container);margin-bottom:4px">First Aid Tip</h4>
    <p style="font-size:var(--text-label-sm);color:var(--on-secondary-container)">
      Immediately wash the wound with soap and water for at least 15 minutes before coming to the clinic.
    </p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_patient.php'; ?>
