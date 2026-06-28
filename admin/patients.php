<?php
// ============================================================
// ABC Connect — Admin: Patient List
// Handles both listing and adding walk-in patients
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();

// ---- Handle walk-in add ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_walkin') {
    $fullName  = trim($_POST['full_name'] ?? '');
    $contact   = trim($_POST['contact'] ?? '');
    $animalT   = $_POST['animal_type'] ?? 'Dog';
    $ownership = $_POST['animal_ownership'] ?? 'Stray';
    $biteDate  = $_POST['bite_date'] ?? date('Y-m-d');
    $category  = $_POST['category'] ?? 'II';
    $bodyLoc   = trim($_POST['body_location'] ?? 'Unknown');

    if ($fullName && $contact) {
        // Create or find user
        $uStmt = $db->prepare("SELECT id FROM users WHERE contact_number=:c LIMIT 1");
        $uStmt->execute([':c' => $contact]);
        $existUser = $uStmt->fetch();

        if ($existUser) {
            $userId = (int)$existUser['id'];
        } else {
            $db->prepare("INSERT INTO users (full_name, contact_number, password_hash) VALUES (:n,:c,:h)")
               ->execute([':n'=>$fullName,':c'=>$contact,':h'=>password_hash(substr($contact,-4), PASSWORD_BCRYPT)]);
            $userId = (int)$db->lastInsertId();
        }

        $count = (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn() + 1;
        $pCode = 'P-' . str_pad($count + 9000, 4, '0', STR_PAD_LEFT);

        $db->prepare("INSERT INTO patients (user_id, patient_code, animal_type, animal_ownership, bite_date, body_location, category, registered_by) VALUES (:uid,:code,:at,:own,:bd,:bl,:cat,:adm)")
           ->execute([':uid'=>$userId,':code'=>$pCode,':at'=>$animalT,':own'=>$ownership,':bd'=>$biteDate,':bl'=>$bodyLoc,':cat'=>$category,':adm'=>$_SESSION['admin_id']]);
        $newPatId = (int)$db->lastInsertId();

        // Vaccine schedule
        foreach ([0=>1,3=>2,7=>3,14=>4,28=>5] as $day=>$dn) {
            $sd = date('Y-m-d', strtotime($biteDate."+$day days"));
            $db->prepare("INSERT INTO vaccines (patient_id,dose_number,dose_day,scheduled_date,status) VALUES (:pid,:dn,:dd,:sd,'scheduled')")
               ->execute([':pid'=>$newPatId,':dn'=>$dn,':dd'=>$day,':sd'=>$sd]);
        }

        // Queue
        $qNum = 'Q-' . str_pad((int)$db->query("SELECT COUNT(*) FROM queue WHERE DATE(queued_at)=CURDATE()")->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO queue (patient_id,queue_number,status,purpose) VALUES (:pid,:qn,'waiting','first_evaluation')")
           ->execute([':pid'=>$newPatId,':qn'=>$qNum]);

        flash('success', "Patient $fullName added. Queue #$qNum assigned.");
        redirect(APP_BASE . '/admin/patients.php');
    }
}

// ---- Filters ----
$search   = trim($_GET['q'] ?? '');
$catFilter= $_GET['category'] ?? '';
$statFilter = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = "(u.full_name LIKE :q OR p.patient_code LIKE :q OR u.contact_number LIKE :q)";
    $params[':q'] = "%$search%";
}
if ($catFilter) { $where[] = "p.category=:cat"; $params[':cat'] = $catFilter; }
if ($statFilter){ $where[] = "p.status=:stat"; $params[':stat'] = $statFilter; }
$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM patients p JOIN users u ON p.user_id=u.id WHERE $whereStr");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages   = max(1, ceil($totalRecords / $perPage));

$listStmt = $db->prepare("
    SELECT p.*, u.full_name, u.contact_number,
           (SELECT COUNT(*) FROM vaccines v WHERE v.patient_id=p.id AND v.status='administered') AS doses_done,
           (SELECT COUNT(*) FROM vaccines v WHERE v.patient_id=p.id) AS doses_total
    FROM patients p
    JOIN users u ON p.user_id=u.id
    WHERE $whereStr
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$listStmt->execute($params);
$patients = $listStmt->fetchAll();

$page_title  = 'Patients';
$active_page = 'patients';
include __DIR__ . '/../includes/header_admin.php';
?>

<!-- Flash -->
<?php $fl = flash('success'); if ($fl): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($fl) ?></span>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">Patient Records</h1>
    <p class="page-header__sub"><?= number_format($totalRecords) ?> total patients</p>
  </div>
  <button class="btn btn-primary btn-pill" onclick="openModal('modal-add-patient')">
    <span class="material-symbols-outlined">add</span>
    Add Walk-in
  </button>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:var(--space-md);flex-wrap:wrap;align-items:center;margin-bottom:var(--space-md)" class="animate-fade-in stagger-1">
  <input class="form-input" type="search" name="q" placeholder="Search by name, ID, contact..." value="<?= htmlspecialchars($search) ?>" style="max-width:320px"/>
  <select class="form-select" name="category" style="max-width:160px" onchange="this.form.submit()">
    <option value="">All Categories</option>
    <option value="I"  <?= $catFilter==='I'?'selected':'' ?>>Category I</option>
    <option value="II" <?= $catFilter==='II'?'selected':'' ?>>Category II</option>
    <option value="III"<?= $catFilter==='III'?'selected':'' ?>>Category III</option>
  </select>
  <select class="form-select" name="status" style="max-width:160px" onchange="this.form.submit()">
    <option value="">All Status</option>
    <option value="active"    <?= $statFilter==='active'?'selected':'' ?>>Active</option>
    <option value="completed" <?= $statFilter==='completed'?'selected':'' ?>>Completed</option>
    <option value="defaulted" <?= $statFilter==='defaulted'?'selected':'' ?>>Defaulted</option>
  </select>
  <button class="btn btn-primary btn-sm" type="submit">Search</button>
  <?php if ($search || $catFilter || $statFilter): ?>
  <a href="<?= APP_BASE ?>/admin/patients.php" class="btn btn-surface btn-sm">Clear</a>
  <?php endif; ?>
</form>

<!-- Table -->
<div class="data-table-wrap animate-fade-in stagger-2">
  <table class="data-table">
    <thead>
      <tr>
        <th>Patient Code</th>
        <th>Full Name</th>
        <th>Contact</th>
        <th>Animal / Bite</th>
        <th>Category</th>
        <th>Doses</th>
        <th>Status</th>
        <th>Registered</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($patients)): ?>
      <tr><td colspan="9" style="text-align:center;padding:var(--space-xl);color:var(--on-surface-variant)">No patients found.</td></tr>
      <?php else: ?>
      <?php foreach ($patients as $pat): ?>
      <tr>
        <td><strong style="font-size:13px;color:var(--primary)">#<?= htmlspecialchars($pat['patient_code']) ?></strong></td>
        <td><strong><?= htmlspecialchars($pat['full_name']) ?></strong></td>
        <td style="font-size:13px"><?= htmlspecialchars($pat['contact_number']) ?></td>
        <td style="font-size:13px">
          <?= htmlspecialchars($pat['animal_ownership']) ?> <?= htmlspecialchars($pat['animal_type']) ?><br>
          <span style="color:var(--on-surface-variant)"><?= htmlspecialchars($pat['body_location']) ?></span>
        </td>
        <td><span class="badge badge-cat-<?= $pat['category'] ?>">Cat <?= $pat['category'] ?></span></td>
        <td>
          <div style="display:flex;align-items:center;gap:var(--space-sm)">
            <div class="progress-bar" style="width:60px">
              <div class="progress-bar__fill progress-bar__fill--good" style="width:<?= $pat['doses_total']>0?round(($pat['doses_done']/$pat['doses_total'])*100):0 ?>%"></div>
            </div>
            <span style="font-size:12px;color:var(--on-surface-variant)"><?= $pat['doses_done'] ?>/<?= $pat['doses_total'] ?></span>
          </div>
        </td>
        <td><span class="badge <?= $pat['status']==='active'?'badge-consult':($pat['status']==='completed'?'badge-vaccinated':'badge-error') ?>"><?= ucfirst($pat['status']) ?></span></td>
        <td style="font-size:12px;color:var(--on-surface-variant)"><?= date('M j, Y', strtotime($pat['created_at'])) ?></td>
        <td>
          <a href="<?= APP_BASE ?>/admin/patient_detail.php?id=<?= $pat['id'] ?>" class="btn btn-surface btn-sm">View</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:var(--space-sm);justify-content:center;margin-top:var(--space-md)">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&category=<?= $catFilter ?>&status=<?= $statFilter ?>"
     class="btn btn-sm <?= $p===$page?'btn-primary':'btn-surface' ?>">
    <?= $p ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Add Patient Modal -->
<div class="modal-overlay" id="modal-add-patient">
  <div class="modal-box">
    <div class="modal-header">
      <h3 style="font-size:18px;font-weight:700">Add Walk-in Patient</h3>
      <button onclick="closeModal('modal-add-patient')" style="background:none;border:none;cursor:pointer;color:var(--on-surface-variant)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_walkin"/>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Full Name <span style="color:var(--error)">*</span></label>
            <input class="form-input" type="text" name="full_name" required placeholder="Juan Dela Cruz"/>
          </div>
          <div class="form-group">
            <label class="form-label">Contact <span style="color:var(--error)">*</span></label>
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
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-md)">
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
          <div class="form-group">
            <label class="form-label">Body Location</label>
            <input class="form-input" type="text" name="body_location" required placeholder="e.g. Left hand"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modal-add-patient')" class="btn btn-surface">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <span class="material-symbols-outlined">add</span>
          Add to Queue
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
