<?php
// ============================================================
// ABC Connect — Admin Dashboard (main Stitch screen)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db    = getDB();
$today = date('Y-m-d');

// ---- Stats ----
$totalToday = (int)$db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)=:d AND status!='no_show'")->execute([':d'=>$today]) ? $db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)='$today' AND status!='no_show'")->fetchColumn() : 0;

$stmtT = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)=:d AND status != 'no_show'");
$stmtT->execute([':d' => $today]);
$totalToday = (int)$stmtT->fetchColumn();

$stmtF = $db->prepare("SELECT COUNT(*) FROM appointments WHERE status='scheduled' AND appointment_date<=:d AND appointment_type='follow_up_dose'");
$stmtF->execute([':d' => $today]);
$pendingFollowups = (int)$stmtF->fetchColumn();

$stmtV = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)=:d AND status='vaccinated'");
$stmtV->execute([':d' => $today]);
$vaccinatedToday = (int)$stmtV->fetchColumn();

$stmtW = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)=:d AND status='waiting'");
$stmtW->execute([':d' => $today]);
$waitingNow = (int)$stmtW->fetchColumn();

// ---- Daily Cap ----
$capRow   = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date=:d");
$capRow->execute([':d' => $today]);
$cap      = $capRow->fetch();
$dailyMax = $cap ? (int)$cap['max_slots'] : 100;
$dailyBooked = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date='$today' AND status='scheduled'")->fetchColumn();
$dailyRemaining = max(0, $dailyMax - $dailyBooked);
$slotPct = $dailyMax > 0 ? min(100, round(($dailyBooked / $dailyMax) * 100)) : 0;

// ---- Notifications ----
$notifications = $db->query("SELECT * FROM notifications WHERE is_read=0 ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ---- Initial queue fetch for page load (JS will take over) ----
$queueData = [
    'waiting'        => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id WHERE q.status='waiting' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
    'in_consultation' => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name,a.full_name AS assigned_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id LEFT JOIN admins a ON q.assigned_to=a.id WHERE q.status='in_consultation' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
    'vaccinated'      => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id WHERE q.status='vaccinated' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
];

function waitLabel(string $queuedAt): string {
    $mins = max(0, (int)((time() - strtotime($queuedAt)) / 60));
    return $mins >= 60 ? round($mins/60, 1).'h wait' : $mins.'m wait';
}

$page_title  = 'Dashboard';
$active_page = 'dashboard';
include __DIR__ . '/../includes/header_admin.php';
?>

<!-- Flash -->
<?php $flash = flash('success'); if ($flash): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($flash) ?></span>
</div>
<?php endif; ?>

<!-- ===== STAT CARDS ===== -->
<div class="stats-grid">
  <div class="stat-card animate-fade-in stagger-1">
    <div class="stat-card__icon stat-card__icon--primary">
      <span class="material-symbols-outlined icon-filled">group</span>
    </div>
    <div>
      <div class="stat-card__label">Patients Today</div>
      <div class="stat-card__value" id="stat-today"><?= $totalToday ?></div>
    </div>
  </div>
  <div class="stat-card animate-fade-in stagger-2">
    <div class="stat-card__icon stat-card__icon--secondary">
      <span class="material-symbols-outlined icon-filled">schedule</span>
    </div>
    <div>
      <div class="stat-card__label">Pending Follow-ups</div>
      <div class="stat-card__value" id="stat-followups"><?= $pendingFollowups ?></div>
    </div>
  </div>
  <div class="stat-card animate-fade-in stagger-3">
    <div class="stat-card__icon stat-card__icon--primary">
      <span class="material-symbols-outlined icon-filled">vaccines</span>
    </div>
    <div>
      <div class="stat-card__label">Vaccinated Today</div>
      <div class="stat-card__value"><?= $vaccinatedToday ?></div>
    </div>
  </div>
  <div class="stat-card animate-fade-in stagger-4">
    <div class="stat-card__icon stat-card__icon--tertiary">
      <span class="material-symbols-outlined icon-filled">pending</span>
    </div>
    <div>
      <div class="stat-card__label">Currently Waiting</div>
      <div class="stat-card__value"><?= $waitingNow ?></div>
    </div>
  </div>
  <div class="stat-card animate-fade-in stagger-5" style="position:relative">
    <div class="stat-card__icon" style="background:<?= $slotPct >= 100 ? 'rgba(239,68,68,0.12)' : 'rgba(245,158,11,0.12)' ?>">
      <span class="material-symbols-outlined icon-filled" style="color:<?= $slotPct >= 100 ? 'var(--error)' : '#d97706' ?>">event_available</span>
    </div>
    <div style="flex:1">
      <div class="stat-card__label">Slots Today</div>
      <div class="stat-card__value" style="color:<?= $slotPct >= 100 ? 'var(--error)' : ($slotPct >= 80 ? '#d97706' : 'inherit') ?>">
        <?= $dailyBooked ?><span style="font-size:14px;font-weight:500;color:var(--on-surface-variant)"> / <?= $dailyMax ?></span>
      </div>
      <div style="height:4px;background:var(--outline-variant);border-radius:2px;margin-top:4px">
        <div style="height:100%;width:<?= $slotPct ?>%;background:<?= $slotPct >= 100 ? 'var(--error)' : ($slotPct >= 80 ? '#f59e0b' : 'var(--primary)') ?>;border-radius:2px;transition:width 0.4s"></div>
      </div>
    </div>
    <a href="<?= APP_BASE ?>/admin/daily_cap.php" title="Adjust cap" style="position:absolute;top:var(--space-sm);right:var(--space-sm);background:none;border:none;cursor:pointer;color:var(--on-surface-variant);text-decoration:none">
      <span class="material-symbols-outlined" style="font-size:16px">tune</span>
    </a>
  </div>
</div>

<!-- ===== LIVE QUEUE BOARD ===== -->
<div class="queue-board animate-fade-in stagger-2">
  <div class="queue-board__header">
    <div class="queue-board__title">
      <span class="queue-board__live-dot"></span>
      <span class="material-symbols-outlined" style="color:var(--primary)">swap_vert</span>
      Live Queue Manager
    </div>
    <div style="display:flex;align-items:center;gap:var(--space-md)">
      <span id="queue-last-update" style="font-size:12px;color:var(--on-surface-variant)">Loading...</span>
      <a href="<?= APP_BASE ?>/admin/queue.php" class="btn btn-surface btn-sm btn-pill">View All</a>
      <button class="btn btn-primary btn-sm btn-pill" onclick="openModal('modal-new-patient')">
        <span class="material-symbols-outlined" style="font-size:18px">add</span>
        New Patient
      </button>
    </div>
  </div>

  <div class="queue-columns">
    <!-- Waiting -->
    <div class="queue-col">
      <div class="queue-col__header">
        <div class="queue-col__name">
          <span class="queue-col__dot dot-waiting"></span>
          Waiting
        </div>
        <span class="queue-col__count count-waiting" id="col-waiting-count">
          <?= str_pad(count($queueData['waiting']), 2, '0', STR_PAD_LEFT) ?>
        </span>
      </div>
      <div class="queue-col__body" id="col-waiting">
        <?php if (empty($queueData['waiting'])): ?>
        <div class="empty-state"><span class="material-symbols-outlined">inbox</span><p>No patients waiting</p></div>
        <?php else: ?>
        <?php foreach ($queueData['waiting'] as $q): ?>
        <div class="q-card animate-fade-in" data-queue-id="<?= $q['id'] ?>" onclick="window.location=APP_BASE+'/admin/patient_detail.php?id=<?= $q['patient_id'] ?>'">
          <div class="q-card__top">
            <span class="q-card__code">#<?= htmlspecialchars($q['patient_code']) ?></span>
            <span class="q-card__wait" style="<?= (time()-strtotime($q['queued_at']))>1800 ? 'color:var(--error)' : '' ?>">
              <?= waitLabel($q['queued_at']) ?>
            </span>
          </div>
          <div class="q-card__name"><?= htmlspecialchars($q['full_name']) ?></div>
          <div class="q-card__desc"><?= htmlspecialchars(ucfirst($q['animal_ownership']).' '.$q['animal_type']) ?> · <?= htmlspecialchars($q['body_location']) ?></div>
          <div class="q-card__footer">
            <span class="badge badge-cat-<?= $q['category'] ?>">Cat <?= $q['category'] ?></span>
          </div>
          <div class="q-card__actions">
            <button class="q-btn q-btn-advance" onclick="event.stopPropagation();QueueManager.advance(<?= $q['id'] ?>,'in_consultation')">Call In</button>
            <button class="q-btn q-btn-noshow" onclick="event.stopPropagation();QueueManager.advance(<?= $q['id'] ?>,'no_show')">No Show</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- In Consultation -->
    <div class="queue-col">
      <div class="queue-col__header">
        <div class="queue-col__name">
          <span class="queue-col__dot dot-consult"></span>
          In Consultation
        </div>
        <span class="queue-col__count count-consult" id="col-consulting-count">
          <?= str_pad(count($queueData['in_consultation']), 2, '0', STR_PAD_LEFT) ?>
        </span>
      </div>
      <div class="queue-col__body" id="col-consulting">
        <?php if (empty($queueData['in_consultation'])): ?>
        <div class="empty-state"><span class="material-symbols-outlined">inbox</span><p>No active consultations</p></div>
        <?php else: ?>
        <?php foreach ($queueData['in_consultation'] as $q): ?>
        <div class="q-card in-consultation" data-queue-id="<?= $q['id'] ?>" onclick="window.location=APP_BASE+'/admin/patient_detail.php?id=<?= $q['patient_id'] ?>'">
          <div class="q-card__top">
            <span class="q-card__code">#<?= htmlspecialchars($q['patient_code']) ?></span>
            <span class="q-card__wait" style="color:var(--primary);font-weight:700">Examining</span>
          </div>
          <div class="q-card__name"><?= htmlspecialchars($q['full_name']) ?></div>
          <div class="q-card__desc"><?= htmlspecialchars(ucfirst($q['animal_ownership']).' '.$q['animal_type']) ?> · <?= htmlspecialchars($q['body_location']) ?></div>
          <div class="q-card__footer">
            <span class="badge badge-cat-<?= $q['category'] ?>">Cat <?= $q['category'] ?></span>
            <?php if (!empty($q['assigned_name'])): ?>
            <span class="label-sm text-muted"><?= htmlspecialchars($q['assigned_name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="q-card__actions">
            <button class="q-btn q-btn-advance" onclick="event.stopPropagation();QueueManager.advance(<?= $q['id'] ?>,'vaccinated')">Mark Vaccinated</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Vaccinated -->
    <div class="queue-col">
      <div class="queue-col__header">
        <div class="queue-col__name">
          <span class="queue-col__dot dot-done"></span>
          Vaccinated
        </div>
        <span class="queue-col__count count-done" id="col-vaccinated-count">
          <?= count($queueData['vaccinated']) ?> Today
        </span>
      </div>
      <div class="queue-col__body" id="col-vaccinated">
        <?php if (empty($queueData['vaccinated'])): ?>
        <div class="empty-state"><span class="material-symbols-outlined">inbox</span><p>None yet today</p></div>
        <?php else: ?>
        <?php foreach ($queueData['vaccinated'] as $q): ?>
        <div class="q-card vaccinated">
          <div class="q-card__top">
            <span class="q-card__code">#<?= htmlspecialchars($q['patient_code']) ?></span>
            <span style="font-size:12px;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:3px">
              <span class="material-symbols-outlined icon-filled" style="font-size:14px">check_circle</span> Done
            </span>
          </div>
          <div class="q-card__name" style="text-decoration:line-through;color:var(--on-surface-variant)"><?= htmlspecialchars($q['full_name']) ?></div>
          <div class="q-card__desc">Dose administered</div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== BOTTOM ROW: Chart + Notifications ===== -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(100%, 360px), 1fr));gap:var(--space-md)">

  <!-- Patient Flow Chart -->
  <div class="card animate-fade-in stagger-3">
    <h4 style="font-weight:700;margin-bottom:var(--space-md)">Patient Flow Today</h4>
    <div class="bar-chart">
      <?php
      $hours = [8,9,10,11,12,13,14,15,16];
      $heights = [20,35,60,85,70,55,40,25,15]; // mock distribution
      $peakH = array_search(max($heights), $heights);
      foreach ($hours as $i => $h): ?>
      <div class="bar-chart__bar <?= $i===$peakH?'peak':'' ?>" style="height:<?= $heights[$i] ?>%">
        <span class="bar-chart__bar-label"><?= $h < 12 ? $h.' AM' : ($h===12?'12 PM':($h-12).' PM') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="bar-chart-labels">
      <span>8 AM</span><span>Noon</span><span>4 PM</span>
    </div>
  </div>

  <!-- System Notifications -->
  <div class="notif-panel animate-fade-in stagger-4">
    <h4 style="font-weight:700;margin-bottom:var(--space-md)">System Notifications</h4>
    <?php if (empty($notifications)): ?>
    <p style="font-size:13px;opacity:0.6">No new notifications.</p>
    <?php else: ?>
    <?php foreach ($notifications as $n):
      $icon = match($n['type']) {
        'critical' => 'report',
        'warning'  => 'warning',
        'success'  => 'sync',
        default    => 'info',
      };
      $iconColor = match($n['type']) {
        'critical' => 'var(--error)',
        'warning'  => '#f59e0b',
        'success'  => 'var(--primary-container)',
        default    => 'var(--secondary-container)',
      };
    ?>
    <div class="notif-item">
      <div class="notif-item__icon" style="background:rgba(255,255,255,0.08)">
        <span class="material-symbols-outlined" style="font-size:18px;color:<?= $iconColor ?>"><?= $icon ?></span>
      </div>
      <div>
        <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
        <div class="notif-item__desc"><?= htmlspecialchars($n['message']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ===== MODAL: New Walk-in Patient ===== -->
<div class="modal-overlay" id="modal-new-patient">
  <div class="modal-box">
    <div class="modal-header">
      <h3 style="font-size:18px;font-weight:700">Add Walk-in Patient</h3>
      <button onclick="closeModal('modal-new-patient')" style="background:none;border:none;cursor:pointer;color:var(--on-surface-variant)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <form method="POST" action="<?= APP_BASE ?>/admin/patients.php">
      <input type="hidden" name="action" value="add_walkin"/>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Patient Full Name</label>
            <input class="form-input" type="text" name="full_name" required placeholder="Juan Dela Cruz"/>
          </div>
          <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input class="form-input" type="tel" name="contact" required placeholder="09XXXXXXXXX"/>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Animal Type</label>
            <select class="form-select" name="animal_type">
              <?php foreach(['Dog','Cat','Bat','Monkey','Other'] as $a): ?>
              <option><?= $a ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Ownership</label>
            <select class="form-select" name="animal_ownership">
              <option>Stray</option><option>Pet</option><option>Unknown</option>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Bite Date</label>
            <input class="form-input" type="date" name="bite_date" value="<?= date('Y-m-d') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select class="form-select" name="category">
              <option value="I">Cat I</option>
              <option value="II" selected>Cat II</option>
              <option value="III">Cat III</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Body Location</label>
          <input class="form-input" type="text" name="body_location" required placeholder="e.g. Right Hand"/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modal-new-patient')" class="btn btn-surface">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <span class="material-symbols-outlined">add</span>
          Add to Queue
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
