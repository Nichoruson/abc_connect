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
        $newPatientId = (int)$db->lastInsertId();

        // Create vaccine schedule (Day 0, 3, 7, 14, 28)
        $doseDays = [0 => 1, 3 => 2, 7 => 3, 14 => 4, 28 => 5];
        foreach ($doseDays as $day => $doseNum) {
            $schedDate = date('Y-m-d', strtotime($biteDate . " +$day days"));
            $db->prepare("
                INSERT INTO vaccines (patient_id, dose_number, dose_day, scheduled_date, status)
                VALUES (:pid, :dn, :dd, :sd, 'scheduled')
            ")->execute([
                ':pid' => $newPatientId,
                ':dn'  => $doseNum,
                ':dd'  => $day,
                ':sd'  => $schedDate,
            ]);
        }

        // Add to queue
        $qCount = $db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) = CURDATE()")->fetchColumn();
        $qNum   = 'Q-' . str_pad((int)$qCount + 1, 3, '0', STR_PAD_LEFT);
        $db->prepare("
            INSERT INTO queue (patient_id, queue_number, status, purpose)
            VALUES (:pid, :qnum, 'waiting', 'first_evaluation')
        ")->execute([':pid' => $newPatientId, ':qnum' => $qNum]);

        // Update session code
        $_SESSION['patient_code'] = $pCode;
        $triageSuccess = "Registered successfully! Queue #$qNum assigned. Please proceed to the clinic.";

        // Reload page to show updated data
        redirect(APP_BASE . '/patient/dashboard.php?registered=1');
    }
}

// Dose progress calculation
$totalDoses    = count($doses);
$completedDoses= count(array_filter($doses, fn($d) => $d['status'] === 'administered'));
$progressPct   = $totalDoses > 0 ? ($completedDoses / $totalDoses) * 80 : 0; // 80% = span of bar

// Flash messages
$regFlash = isset($_GET['registered']) ? 'Triage submitted! You\'ve been added to today\'s queue.' : '';

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
