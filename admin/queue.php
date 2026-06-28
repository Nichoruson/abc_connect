<?php
// ============================================================
// ABC Connect — Admin Queue (full-page live queue manager)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db    = getDB();
$today = date('Y-m-d');

$queueData = [
    'waiting'         => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id WHERE q.status='waiting' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
    'in_consultation' => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name,a.full_name AS assigned_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id LEFT JOIN admins a ON q.assigned_to=a.id WHERE q.status='in_consultation' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
    'vaccinated'      => $db->query("SELECT q.*,p.patient_code,p.body_location,p.category,p.animal_type,p.animal_ownership,u.full_name FROM queue q JOIN patients p ON q.patient_id=p.id JOIN users u ON p.user_id=u.id WHERE q.status='vaccinated' AND DATE(q.queued_at)='$today' ORDER BY q.queued_at ASC")->fetchAll(),
];

function waitLabel(string $queuedAt): string {
    $mins = max(0, (int)((time() - strtotime($queuedAt)) / 60));
    return $mins >= 60 ? round($mins / 60, 1) . 'h wait' : $mins . 'm wait';
}

$page_title  = 'Queue Manager';
$active_page = 'queue';
include __DIR__ . '/../includes/header_admin.php';
?>

<div class="queue-board animate-fade-in">
  <div class="queue-board__header">
    <div class="queue-board__title">
      <span class="queue-board__live-dot"></span>
      <span class="material-symbols-outlined" style="color:var(--primary)">swap_vert</span>
      Live Queue Manager
    </div>
    <div style="display:flex;align-items:center;gap:var(--space-md)">
      <span id="queue-last-update" style="font-size:12px;color:var(--on-surface-variant)">Loading...</span>
      <button class="btn btn-primary btn-sm btn-pill" onclick="openModal('modal-new-patient')">
        <span class="material-symbols-outlined" style="font-size:18px">add</span>
        New Patient
      </button>
    </div>
  </div>

  <div class="queue-columns">
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
            <span class="q-card__wait" style="<?= (time() - strtotime($q['queued_at'])) > 1800 ? 'color:var(--error)' : '' ?>">
              <?= waitLabel($q['queued_at']) ?>
            </span>
          </div>
          <div class="q-card__name"><?= htmlspecialchars($q['full_name']) ?></div>
          <div class="q-card__desc"><?= htmlspecialchars(ucfirst($q['animal_ownership']) . ' ' . $q['animal_type']) ?> · <?= htmlspecialchars($q['body_location']) ?></div>
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
          <div class="q-card__desc"><?= htmlspecialchars(ucfirst($q['animal_ownership']) . ' ' . $q['animal_type']) ?> · <?= htmlspecialchars($q['body_location']) ?></div>
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
              <?php foreach (['Dog', 'Cat', 'Bat', 'Monkey', 'Other'] as $a): ?>
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
