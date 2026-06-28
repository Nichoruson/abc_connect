<?php
// ============================================================
// RabiesShield Daet — Admin: Daily Slot Cap Management
// Allows staff to set/override appointment capacity per day
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();
$today = date('Y-m-d');

$success = $error = '';

// ---- Handle cap update POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_cap') {
    $capDate  = $_POST['cap_date'] ?? '';
    $maxSlots = (int)($_POST['max_slots'] ?? 100);

    if (!$capDate || !strtotime($capDate)) {
        $error = 'Invalid date provided.';
    } elseif ($maxSlots < 1 || $maxSlots > 500) {
        $error = 'Slot limit must be between 1 and 500.';
    } else {
        // Upsert — insert or update existing cap for that date
        $stmt = $db->prepare("
            INSERT INTO daily_cap (cap_date, max_slots, updated_by)
            VALUES (:d, :m, :uid)
            ON DUPLICATE KEY UPDATE max_slots = :m2, updated_by = :uid2, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':d'    => $capDate,
            ':m'    => $maxSlots,
            ':uid'  => $_SESSION['admin_id'],
            ':m2'   => $maxSlots,
            ':uid2' => $_SESSION['admin_id'],
        ]);
        $success = "Daily cap for " . date('F j, Y', strtotime($capDate)) . " set to $maxSlots slots.";
    }
}

// ---- Fetch next 14 days of cap data ----
$days = [];
for ($i = 0; $i < 14; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    $days[] = $d;
}

// Get all caps for those 14 days
$placeholders = implode(',', array_fill(0, count($days), '?'));
$capStmt = $db->prepare("SELECT cap_date, max_slots, updated_by FROM daily_cap WHERE cap_date IN ($placeholders)");
$capStmt->execute($days);
$capRows = $capStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get appointment counts per day for next 14 days
$bookingStmt = $db->prepare("
    SELECT appointment_date, COUNT(*) AS cnt
    FROM appointments
    WHERE appointment_date IN ($placeholders) AND status = 'scheduled'
    GROUP BY appointment_date
");
$bookingStmt->execute($days);
$bookingRows = $bookingStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Build a unified grid
$grid = [];
foreach ($days as $d) {
    $maxSlots    = (int)($capRows[$d] ?? 100); // default 100
    $booked      = (int)($bookingRows[$d] ?? 0);
    $available   = max(0, $maxSlots - $booked);
    $pct         = $maxSlots > 0 ? min(100, round(($booked / $maxSlots) * 100)) : 0;
    $status      = $pct >= 100 ? 'full' : ($pct >= 80 ? 'almost' : 'open');
    $grid[$d]    = compact('maxSlots', 'booked', 'available', 'pct', 'status');
}

$page_title  = 'Daily Cap';
$active_page = 'daily_cap';
include __DIR__ . '/../includes/header_admin.php';
?>

<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">Daily Slot Cap</h1>
    <p class="page-header__sub">Manage daily appointment capacity — default is 100 slots per day</p>
  </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($success) ?></span>
</div>
<?php elseif ($error): ?>
<div class="alert alert-error animate-fade-in">
  <span class="material-symbols-outlined" style="color:var(--error)">error</span>
  <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Quick Set Form -->
<div class="card animate-fade-in stagger-2" style="margin-bottom:var(--space-lg)">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:var(--space-md);display:flex;align-items:center;gap:var(--space-sm)">
    <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);font-size:20px">tune</span>
    Override Slot Capacity
  </h3>
  <form method="POST" style="display:flex;gap:var(--space-md);align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="action" value="set_cap"/>
    <div class="form-group" style="flex:1;min-width:160px">
      <label class="form-label" for="cap_date">Date</label>
      <input class="form-input" type="date" id="cap_date" name="cap_date"
             min="<?= $today ?>" value="<?= $today ?>"/>
    </div>
    <div class="form-group" style="flex:1;min-width:140px">
      <label class="form-label" for="max_slots">Max Slots</label>
      <input class="form-input" type="number" id="max_slots" name="max_slots"
             min="1" max="500" value="100" placeholder="100"/>
    </div>
    <button class="btn btn-primary" type="submit" style="white-space:nowrap">
      <span class="material-symbols-outlined">save</span>
      Set Cap
    </button>
  </form>
  <p style="font-size:12px;color:var(--on-surface-variant);margin-top:var(--space-sm)">
    <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle">info</span>
    Leave at 100 for normal days. Reduce when a doctor is on leave or clinic capacity is reduced.
  </p>
</div>

<!-- 14-Day Grid -->
<div class="card animate-fade-in stagger-3">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:var(--space-lg)">Next 14 Days — Slot Overview</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--space-md)">
    <?php foreach ($grid as $date => $g):
      $isToday   = $date === $today;
      $dayLabel  = $isToday ? 'Today' : date('D, M j', strtotime($date));
      $barColor  = $g['status'] === 'full' ? 'var(--error)' : ($g['status'] === 'almost' ? '#f59e0b' : 'var(--primary)');
    ?>
    <div style="background:var(--surface-container);border-radius:var(--radius-lg);padding:var(--space-md);border:1.5px solid <?= $isToday ? 'var(--primary)' : 'var(--outline-variant)' ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-sm)">
        <span style="font-weight:700;font-size:14px;color:<?= $isToday ? 'var(--primary)' : 'var(--on-surface)' ?>">
          <?= htmlspecialchars($dayLabel) ?>
        </span>
        <?php if ($g['status'] === 'full'): ?>
          <span class="badge badge-error">FULL</span>
        <?php elseif ($g['status'] === 'almost'): ?>
          <span class="badge badge-info" style="background:#fef3c7;color:#92400e">Almost Full</span>
        <?php else: ?>
          <span class="badge badge-consult">Open</span>
        <?php endif; ?>
      </div>
      <div style="font-size:24px;font-weight:800;color:<?= $barColor ?>;margin-bottom:4px">
        <?= $g['booked'] ?><span style="font-size:14px;font-weight:500;color:var(--on-surface-variant)"> / <?= $g['maxSlots'] ?></span>
      </div>
      <div style="height:6px;background:var(--outline-variant);border-radius:3px;margin-bottom:6px">
        <div style="height:100%;width:<?= $g['pct'] ?>%;background:<?= $barColor ?>;border-radius:3px;transition:width 0.4s"></div>
      </div>
      <div style="font-size:12px;color:var(--on-surface-variant)">
        <?= $g['available'] ?> slots available
        <?php if ($g['maxSlots'] !== 100): ?>
          · <span style="color:#f59e0b;font-weight:600">Cap: <?= $g['maxSlots'] ?></span>
        <?php endif; ?>
      </div>
      <!-- Inline quick edit button -->
      <button onclick="quickEdit('<?= $date ?>', <?= $g['maxSlots'] ?>)"
              style="margin-top:var(--space-sm);font-size:12px;color:var(--secondary);background:none;border:none;cursor:pointer;padding:0;font-family:inherit;display:flex;align-items:center;gap:4px">
        <span class="material-symbols-outlined" style="font-size:14px">edit</span>
        Adjust cap
      </button>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function quickEdit(date, currentCap) {
  document.getElementById('cap_date').value = date;
  document.getElementById('max_slots').value = currentCap;
  document.getElementById('cap_date').scrollIntoView({ behavior: 'smooth', block: 'center' });
  document.getElementById('max_slots').focus();
}
</script>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
