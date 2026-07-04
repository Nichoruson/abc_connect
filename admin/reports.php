<?php
// ============================================================
// ABC Connect — Admin: Reports
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();

// Date range
$from = $_GET['from'] ?? date('Y-m-01'); // first of month
$to   = $_GET['to']   ?? date('Y-m-d');

// ---- Stats ----
$stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN :f AND :t");
$stmt->execute([':f'=>$from,':t'=>$to]);
$newPatients = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM vaccines WHERE administered_date BETWEEN :f AND :t AND status='administered'");
$stmt->execute([':f'=>$from,':t'=>$to]);
$dosesGiven = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) BETWEEN :f AND :t AND status='no_show'");
$stmt->execute([':f'=>$from,':t'=>$to]);
$noShows = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE status='defaulted' AND DATE(created_at) BETWEEN :f AND :t");
$stmt->execute([':f'=>$from,':t'=>$to]);
$defaulted = (int)$stmt->fetchColumn();

// Category breakdown
$catBreakdown = $db->prepare("SELECT category, COUNT(*) AS cnt FROM patients WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY category ORDER BY category");
$catBreakdown->execute([':f'=>$from,':t'=>$to]);
$catRows = $catBreakdown->fetchAll();

// Animal type breakdown
$animalBreakdown = $db->prepare("SELECT animal_type, COUNT(*) AS cnt FROM patients WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY animal_type ORDER BY cnt DESC");
$animalBreakdown->execute([':f'=>$from,':t'=>$to]);
$animalRows = $animalBreakdown->fetchAll();

// Daily patient count (last 14 days or filtered)
$dailyStmt = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM patients
    WHERE DATE(created_at) BETWEEN :f AND :t
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$dailyStmt->execute([':f'=>$from,':t'=>$to]);
$dailyRows = $dailyStmt->fetchAll();

// Completion rate
$stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE status='completed' AND DATE(created_at) BETWEEN :f AND :t");
$stmt->execute([':f'=>$from,':t'=>$to]);
$completed = (int)$stmt->fetchColumn();
$completionRate = $newPatients > 0 ? round(($completed / $newPatients) * 100) : 0;

$page_title  = 'Reports';
$active_page = 'reports';
include __DIR__ . '/../includes/header_admin.php';
?>

<!-- Page Header -->
<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">Reports & Analytics</h1>
    <p class="page-header__sub"><?= date('F j, Y', strtotime($from)) ?> – <?= date('F j, Y', strtotime($to)) ?></p>
  </div>
  <div style="display:flex;align-items:center;gap:var(--space-sm)">
    <form method="GET" style="display:flex;align-items:center;gap:var(--space-sm);margin:0">
      <label class="form-label" style="white-space:nowrap;margin:0">From</label>
      <input class="form-input" type="date" name="from" value="<?= $from ?>"/>
      <label class="form-label" style="white-space:nowrap;margin:0">To</label>
      <input class="form-input" type="date" name="to" value="<?= $to ?>" max="<?= date('Y-m-d') ?>"/>
      <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    </form>
    
    <div style="position:relative;display:inline-block;" id="export-dropdown-wrap">
      <button class="btn btn-surface btn-sm" onclick="const el = document.getElementById('export-dropdown'); el.style.display = el.style.display === 'flex' ? 'none' : 'flex'; event.stopPropagation();" style="display:flex;align-items:center;gap:6px;height:36px;box-sizing:border-box;">
        <span class="material-symbols-outlined" style="font-size:18px">download</span>
        <span>Export</span>
        <span class="material-symbols-outlined" style="font-size:16px">arrow_drop_down</span>
      </button>
      <div id="export-dropdown" class="card" style="position:absolute;top:100%;right:0;margin-top:6px;width:160px;z-index:100;display:none;flex-direction:column;padding:4px;box-shadow:var(--shadow-card);background:var(--surface-container-high);border:1px solid var(--border);border-radius:var(--radius-md);">
        <a href="<?= APP_BASE ?>/admin/export.php?format=csv&from=<?= $from ?>&to=<?= $to ?>" style="padding:10px 12px;font-size:13px;border-radius:var(--radius-sm);color:var(--on-surface);text-decoration:none;display:flex;align-items:center;gap:8px;font-weight:500;" class="sidebar-nav__item">
          <span class="material-symbols-outlined" style="font-size:18px;color:var(--primary)">table_view</span>
          <span>Download CSV</span>
        </a>
        <a href="<?= APP_BASE ?>/admin/export.php?format=print&from=<?= $from ?>&to=<?= $to ?>" target="_blank" style="padding:10px 12px;font-size:13px;border-radius:var(--radius-sm);color:var(--on-surface);text-decoration:none;display:flex;align-items:center;gap:8px;font-weight:500;" class="sidebar-nav__item">
          <span class="material-symbols-outlined" style="font-size:18px;color:var(--secondary)">print</span>
          <span>Print / PDF</span>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid animate-fade-in stagger-1">
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--primary"><span class="material-symbols-outlined icon-filled">person_add</span></div>
    <div><div class="stat-card__label">New Patients</div><div class="stat-card__value"><?= $newPatients ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--secondary"><span class="material-symbols-outlined icon-filled">vaccines</span></div>
    <div><div class="stat-card__label">Doses Administered</div><div class="stat-card__value"><?= $dosesGiven ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--primary"><span class="material-symbols-outlined icon-filled">verified</span></div>
    <div><div class="stat-card__label">Treatment Completed</div><div class="stat-card__value"><?= $completed ?></div><div class="stat-card__trend"><?= $completionRate ?>% completion rate</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--error"><span class="material-symbols-outlined icon-filled">person_remove</span></div>
    <div><div class="stat-card__label">No-Shows</div><div class="stat-card__value"><?= $noShows ?></div></div>
  </div>
</div>

<!-- Charts Row -->
<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:var(--space-md)">

  <!-- Daily Trend -->
  <div class="card animate-fade-in stagger-2">
    <h4 style="font-weight:700;margin-bottom:var(--space-md)">Daily Patient Volume</h4>
    <?php if (empty($dailyRows)): ?>
    <p style="color:var(--on-surface-variant);text-align:center;padding:var(--space-xl)">No data for this period.</p>
    <?php else:
      $maxCnt = max(array_column($dailyRows,'cnt'));
    ?>
    <div class="bar-chart" style="height:180px">
      <?php foreach ($dailyRows as $dr):
        $h = $maxCnt > 0 ? round(($dr['cnt'] / $maxCnt) * 100) : 10;
      ?>
      <div class="bar-chart__bar" style="height:<?= $h ?>%;flex:none;width:<?= max(12, min(32, 480/count($dailyRows))) ?>px" title="<?= $dr['day'] ?>: <?= $dr['cnt'] ?> patients">
        <span class="bar-chart__bar-label"><?= $dr['cnt'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--on-surface-variant);margin-top:6px">
      <span><?= date('M j', strtotime($dailyRows[0]['day'])) ?></span>
      <span><?= date('M j', strtotime($dailyRows[count($dailyRows)-1]['day'])) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Category Breakdown -->
  <div class="card animate-fade-in stagger-2">
    <h4 style="font-weight:700;margin-bottom:var(--space-md)">By Category</h4>
    <?php if (empty($catRows)): ?>
    <p style="color:var(--on-surface-variant);font-size:14px">No data.</p>
    <?php else:
      $catTotal = array_sum(array_column($catRows,'cnt'));
    ?>
    <div style="display:flex;flex-direction:column;gap:var(--space-md)">
      <?php foreach ($catRows as $c):
        $pct = $catTotal > 0 ? round(($c['cnt'] / $catTotal) * 100) : 0;
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
          <span style="font-weight:700">Category <?= $c['category'] ?></span>
          <span style="color:var(--on-surface-variant)"><?= $c['cnt'] ?> (<?= $pct ?>%)</span>
        </div>
        <div class="progress-bar">
          <div class="progress-bar__fill progress-bar__fill--<?= $c['category']==='III'?'critical':($c['category']==='II'?'warning':'good') ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Animal Type -->
  <div class="card animate-fade-in stagger-3">
    <h4 style="font-weight:700;margin-bottom:var(--space-md)">By Animal</h4>
    <?php if (empty($animalRows)): ?>
    <p style="color:var(--on-surface-variant);font-size:14px">No data.</p>
    <?php else:
      $anTotal = array_sum(array_column($animalRows,'cnt'));
    ?>
    <div style="display:flex;flex-direction:column;gap:var(--space-md)">
      <?php foreach ($animalRows as $an):
        $pct = $anTotal > 0 ? round(($an['cnt'] / $anTotal) * 100) : 0;
      ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
          <span style="font-weight:700"><?= htmlspecialchars($an['animal_type']) ?></span>
          <span style="color:var(--on-surface-variant)"><?= $an['cnt'] ?> (<?= $pct ?>%)</span>
        </div>
        <div class="progress-bar">
          <div class="progress-bar__fill progress-bar__fill--good" style="width:<?= $pct ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Export Note -->
<div class="card animate-fade-in stagger-4" style="background:var(--surface-container);border:none">
  <div style="display:flex;align-items:center;gap:var(--space-md)">
    <span class="material-symbols-outlined" style="color:var(--secondary)">download</span>
    <div>
      <h4 style="font-weight:700;margin-bottom:2px">Export Report</h4>
      <p style="font-size:13px;color:var(--on-surface-variant)">Use your browser's print function (Ctrl+P) to save this page as PDF for official reporting.</p>
    </div>
    <button class="btn btn-secondary btn-sm" onclick="window.print()">
      <span class="material-symbols-outlined">print</span>
      Print/Save PDF
    </button>
  </div>
</div>

<script>
  window.addEventListener('click', function() {
      const el = document.getElementById('export-dropdown');
      if (el) el.style.display = 'none';
  });
</script>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
