<?php
// ============================================================
// ABC Connect — Admin: Patient Detail
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db        = getDB();
$patientId = (int)($_GET['id'] ?? 0);

if (!$patientId) redirect(APP_BASE . '/admin/patients.php');

$patStmt = $db->prepare("
    SELECT p.*, u.full_name, u.contact_number, u.birthdate, u.sex, u.address,
           a.full_name AS registered_by_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN admins a ON p.registered_by = a.id
    WHERE p.id = :id LIMIT 1
");
$patStmt->execute([':id' => $patientId]);
$patient = $patStmt->fetch();
if (!$patient) redirect(APP_BASE . '/admin/patients.php');

// Doses
$doses = $db->prepare("SELECT v.*, a.full_name AS admin_name FROM vaccines v LEFT JOIN admins a ON v.administered_by=a.id WHERE v.patient_id=:pid ORDER BY v.dose_number ASC");
$doses->execute([':pid' => $patientId]);
$doseRows = $doses->fetchAll();

// Appointments
$appts = $db->prepare("SELECT * FROM appointments WHERE patient_id=:pid ORDER BY appointment_date DESC LIMIT 10");
$appts->execute([':pid' => $patientId]);
$apptRows = $appts->fetchAll();

// Queue history
$queue = $db->prepare("SELECT q.*,a.full_name AS admin_name FROM queue q LEFT JOIN admins a ON q.assigned_to=a.id WHERE q.patient_id=:pid ORDER BY q.queued_at DESC LIMIT 10");
$queue->execute([':pid' => $patientId]);
$queueRows = $queue->fetchAll();

// ---- Handle mark dose administered ----
$actionMsg = $actionErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'administer_dose') {
        $doseId = (int)$_POST['dose_id'];
        $db->prepare("UPDATE vaccines SET status='administered', administered_date=CURDATE(), administered_by=:adm WHERE id=:id AND patient_id=:pid")
           ->execute([':adm'=>$_SESSION['admin_id'],':id'=>$doseId,':pid'=>$patientId]);

        // Check if all doses done → mark patient completed
        $remain = $db->prepare("SELECT COUNT(*) FROM vaccines WHERE patient_id=:pid AND status='scheduled'");
        $remain->execute([':pid'=>$patientId]);
        if ((int)$remain->fetchColumn() === 0) {
            $db->prepare("UPDATE patients SET status='completed' WHERE id=:id")->execute([':id'=>$patientId]);
        }
        flash('success', 'Dose marked as administered.');
        redirect(APP_BASE . "/admin/patient_detail.php?id=$patientId");
    }
    if ($_POST['action'] === 'update_status') {
        $newStatus = $_POST['new_status'] ?? 'active';
        $db->prepare("UPDATE patients SET status=:s WHERE id=:id")->execute([':s'=>$newStatus,':id'=>$patientId]);
        redirect(APP_BASE . "/admin/patient_detail.php?id=$patientId");
    }
}

// Flash
$fl = flash('success');

$completedDoses = count(array_filter($doseRows, fn($d) => $d['status'] === 'administered'));
$totalDoses     = count($doseRows);
$progressPct    = $totalDoses > 0 ? ($completedDoses / $totalDoses) * 100 : 0;

$page_title  = 'Patient: ' . $patient['full_name'];
$active_page = 'patients';
include __DIR__ . '/../includes/header_admin.php';
?>

<?php if ($fl): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($fl) ?></span>
</div>
<?php endif; ?>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:var(--space-sm);margin-bottom:var(--space-md);font-size:14px">
  <a href="<?= APP_BASE ?>/admin/patients.php" style="color:var(--primary);text-decoration:none">Patients</a>
  <span class="material-symbols-outlined" style="font-size:16px;color:var(--on-surface-variant)">chevron_right</span>
  <span style="color:var(--on-surface-variant)"><?= htmlspecialchars($patient['full_name']) ?></span>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:var(--space-lg);align-items:start">

  <!-- Left: Patient Info Card -->
  <div style="display:flex;flex-direction:column;gap:var(--space-md)">
    <div class="card animate-fade-in stagger-1">
      <div style="text-align:center;padding-bottom:var(--space-md);border-bottom:1px solid var(--outline-variant);margin-bottom:var(--space-md)">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--secondary-container);color:var(--on-secondary-container);font-size:26px;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-md)">
          <?= strtoupper(substr($patient['full_name'],0,2)) ?>
        </div>
        <h2 style="font-size:18px;font-weight:700;margin-bottom:4px"><?= htmlspecialchars($patient['full_name']) ?></h2>
        <p style="font-size:13px;color:var(--on-surface-variant)"><?= htmlspecialchars($patient['contact_number']) ?></p>
        <div style="margin-top:var(--space-sm);display:flex;justify-content:center;gap:var(--space-sm)">
          <span class="badge badge-cat-<?= $patient['category'] ?>">Cat <?= $patient['category'] ?></span>
          <span class="badge <?= $patient['status']==='active'?'badge-consult':($patient['status']==='completed'?'badge-vaccinated':'badge-error') ?>">
            <?= ucfirst($patient['status']) ?>
          </span>
        </div>
        <p style="font-size:13px;color:var(--primary);font-weight:700;margin-top:var(--space-sm)">#<?= htmlspecialchars($patient['patient_code']) ?></p>
      </div>

      <!-- Details -->
      <?php $details = [
        ['Bite Date', date('F j, Y', strtotime($patient['bite_date']))],
        ['Animal', $patient['animal_ownership'].' '.$patient['animal_type']],
        ['Body Location', $patient['body_location']],
        ['Sex', $patient['sex'] ?? 'N/A'],
        ['Birthdate', $patient['birthdate'] ? date('M j, Y', strtotime($patient['birthdate'])) : 'N/A'],
        ['Address', $patient['address'] ?: 'N/A'],
        ['Registered By', $patient['registered_by_name'] ?? 'Self'],
        ['Registered On', date('M j, Y', strtotime($patient['created_at']))],
      ]; ?>
      <?php foreach ($details as [$label, $value]): ?>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:var(--space-sm)">
        <span style="color:var(--on-surface-variant)"><?= $label ?></span>
        <span style="font-weight:600;text-align:right;max-width:160px"><?= htmlspecialchars($value) ?></span>
      </div>
      <?php endforeach; ?>

      <!-- Update Status -->
      <form method="POST" style="margin-top:var(--space-md)">
        <input type="hidden" name="action" value="update_status"/>
        <div class="form-group">
          <label class="form-label">Update Status</label>
          <div style="display:flex;gap:var(--space-sm)">
            <select class="form-select" name="new_status">
              <option value="active"    <?= $patient['status']==='active'?'selected':'' ?>>Active</option>
              <option value="completed" <?= $patient['status']==='completed'?'selected':'' ?>>Completed</option>
              <option value="defaulted" <?= $patient['status']==='defaulted'?'selected':'' ?>>Defaulted</option>
            </select>
            <button class="btn btn-surface btn-sm" type="submit">Save</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Dose Progress -->
    <div class="card animate-fade-in stagger-2">
      <h4 style="font-weight:700;margin-bottom:var(--space-sm)">Dose Progress</h4>
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
        <span style="color:var(--on-surface-variant)"><?= $completedDoses ?> of <?= $totalDoses ?> doses</span>
        <span style="color:var(--primary);font-weight:700"><?= round($progressPct) ?>%</span>
      </div>
      <div class="progress-bar" style="height:10px">
        <div class="progress-bar__fill progress-bar__fill--<?= $progressPct<30?'critical':($progressPct<70?'warning':'good') ?>" style="width:<?= $progressPct ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Right: Vaccine Schedule + Appointments -->
  <div style="display:flex;flex-direction:column;gap:var(--space-lg)">

    <!-- Vaccine Dose Schedule -->
    <div class="card animate-fade-in stagger-1">
      <h3 style="font-weight:700;margin-bottom:var(--space-md)">Vaccination Schedule</h3>
      <div class="data-table-wrap" style="border:none;box-shadow:none">
        <table class="data-table">
          <thead>
            <tr><th>Dose</th><th>Day</th><th>Scheduled</th><th>Administered</th><th>Status</th><th>By</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($doseRows as $d): ?>
            <tr>
              <td style="font-weight:700">Dose <?= $d['dose_number'] ?></td>
              <td>Day <?= $d['dose_day'] ?></td>
              <td style="font-size:13px"><?= $d['scheduled_date'] ? date('M j, Y', strtotime($d['scheduled_date'])) : '—' ?></td>
              <td style="font-size:13px"><?= $d['administered_date'] ? date('M j, Y', strtotime($d['administered_date'])) : '—' ?></td>
              <td>
                <?php if ($d['status']==='administered'): ?>
                <span class="badge badge-consult">Done</span>
                <?php elseif ($d['status']==='missed'): ?>
                <span class="badge badge-error">Missed</span>
                <?php else: ?>
                <span class="badge badge-info">Pending</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px"><?= $d['admin_name'] ? htmlspecialchars($d['admin_name']) : '—' ?></td>
              <td>
                <?php if ($d['status'] === 'scheduled'): ?>
                <form method="POST">
                  <input type="hidden" name="action" value="administer_dose"/>
                  <input type="hidden" name="dose_id" value="<?= $d['id'] ?>"/>
                  <button class="btn btn-primary btn-sm" type="submit">Mark Done</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Appointments -->
    <div class="card animate-fade-in stagger-2">
      <h3 style="font-weight:700;margin-bottom:var(--space-md)">Appointment History</h3>
      <?php if (empty($apptRows)): ?>
      <p style="color:var(--on-surface-variant);font-size:14px">No appointments recorded.</p>
      <?php else: ?>
      <div class="data-table-wrap" style="border:none;box-shadow:none">
        <table class="data-table">
          <thead><tr><th>Type</th><th>Dose Day</th><th>Date</th><th>Time Slot</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($apptRows as $a): ?>
            <tr>
              <td><?= $a['appointment_type']==='first_evaluation'?'First Evaluation':'Follow-up' ?></td>
              <td>Day <?= $a['dose_day'] ?></td>
              <td><?= date('M j, Y', strtotime($a['appointment_date'])) ?></td>
              <td style="font-size:13px"><?= $a['time_slot'] ?: '—' ?></td>
              <td><span class="badge <?= $a['status']==='completed'?'badge-consult':($a['status']==='cancelled'?'badge-error':'badge-info') ?>"><?= ucfirst($a['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Queue History -->
    <?php if (!empty($queueRows)): ?>
    <div class="card animate-fade-in stagger-3">
      <h3 style="font-weight:700;margin-bottom:var(--space-md)">Queue History</h3>
      <div class="data-table-wrap" style="border:none;box-shadow:none">
        <table class="data-table">
          <thead><tr><th>Queue #</th><th>Purpose</th><th>Status</th><th>Assigned To</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach ($queueRows as $q): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($q['queue_number']) ?></td>
              <td><?= $q['purpose']==='first_evaluation'?'First Eval.':'Follow-up' ?></td>
              <td><span class="badge <?= match($q['status']){'waiting'=>'badge-waiting','in_consultation'=>'badge-consult','vaccinated'=>'badge-vaccinated',default=>'badge-error'} ?>"><?= ucfirst(str_replace('_',' ',$q['status'])) ?></span></td>
              <td style="font-size:13px"><?= $q['admin_name'] ? htmlspecialchars($q['admin_name']) : '—' ?></td>
              <td style="font-size:13px"><?= date('M j, Y g:i A', strtotime($q['queued_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
