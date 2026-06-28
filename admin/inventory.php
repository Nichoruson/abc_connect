<?php
// ============================================================
// ABC Connect — Admin: Inventory Management
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();

// Handle updates
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'update_stock') {
        $id  = (int)$_POST['inv_id'];
        $qty = (int)$_POST['quantity'];
        $db->prepare("UPDATE inventory SET quantity=:q, last_restocked=NOW() WHERE id=:id")
           ->execute([':q'=>$qty,':id'=>$id]);
        $success = 'Stock updated successfully.';

        // Auto-dismiss notification if resolved
        $inv = $db->prepare("SELECT * FROM inventory WHERE id=:id LIMIT 1");
        $inv->execute([':id'=>$id]);
        $invRow = $inv->fetch();
        if ($invRow && $invRow['quantity'] > $invRow['threshold_low']) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE related_table='inventory' AND related_id=:id")->execute([':id'=>$id]);
        }
    }
    if ($_POST['action'] === 'add_vaccine') {
        $name  = trim($_POST['vaccine_name'] ?? '');
        $qty   = (int)$_POST['quantity'];
        $unit  = trim($_POST['unit'] ?? 'vials');
        $low   = (int)$_POST['threshold_low'];
        $crit  = (int)$_POST['threshold_critical'];
        if ($name) {
            $db->prepare("INSERT INTO inventory (vaccine_name,quantity,unit,threshold_low,threshold_critical,last_restocked) VALUES (:n,:q,:u,:l,:c,NOW())")
               ->execute([':n'=>$name,':q'=>$qty,':u'=>$unit,':l'=>$low,':c'=>$crit]);
            $success = "\"$name\" added to inventory.";
        } else {
            $error = 'Vaccine name is required.';
        }
    }
}

$items = $db->query("SELECT * FROM inventory ORDER BY (quantity/threshold_low) ASC")->fetchAll();

function inventoryPct(array $item): float {
    $max = max($item['quantity'], $item['threshold_low'] * 2, 10);
    return min(100, round(($item['quantity'] / $max) * 100));
}
function inventoryColor(array $item): string {
    if ($item['quantity'] <= $item['threshold_critical']) return 'critical';
    if ($item['quantity'] <= $item['threshold_low'])     return 'warning';
    return 'good';
}

$page_title  = 'Inventory';
$active_page = 'inventory';
include __DIR__ . '/../includes/header_admin.php';
?>

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

<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">Vaccine Inventory</h1>
    <p class="page-header__sub">Manage stock levels and reorder thresholds</p>
  </div>
  <button class="btn btn-primary btn-pill" onclick="openModal('modal-add-vaccine')">
    <span class="material-symbols-outlined">add</span>
    Add Vaccine
  </button>
</div>

<!-- Inventory Cards Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--space-md)">
  <?php foreach ($items as $i => $inv):
    $pct   = inventoryPct($inv);
    $color = inventoryColor($inv);
  ?>
  <div class="card animate-fade-in stagger-<?= min($i+1,5) ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:var(--space-md)">
      <div>
        <h4 style="font-weight:700;font-size:16px;margin-bottom:3px"><?= htmlspecialchars($inv['vaccine_name']) ?></h4>
        <?php if ($inv['last_restocked']): ?>
        <p style="font-size:12px;color:var(--on-surface-variant)">Restocked: <?= date('M j, Y', strtotime($inv['last_restocked'])) ?></p>
        <?php endif; ?>
      </div>
      <span class="badge <?= $color==='good'?'badge-consult':($color==='warning'?'badge-waiting':'badge-error') ?>">
        <?= ucfirst($color) ?>
      </span>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:var(--space-sm)">
      <div>
        <span style="font-size:32px;font-weight:800;color:<?= $color==='critical'?'var(--error)':($color==='warning'?'#f59e0b':'var(--primary)') ?>">
          <?= $inv['quantity'] ?>
        </span>
        <span style="font-size:14px;color:var(--on-surface-variant);margin-left:4px"><?= htmlspecialchars($inv['unit']) ?></span>
      </div>
      <div style="font-size:12px;color:var(--on-surface-variant);text-align:right">
        Alert at: <?= $inv['threshold_low'] ?><br>
        Critical at: <?= $inv['threshold_critical'] ?>
      </div>
    </div>

    <div class="progress-bar" style="height:10px;margin-bottom:var(--space-md)">
      <div class="progress-bar__fill progress-bar__fill--<?= $color ?>" style="width:<?= $pct ?>%"></div>
    </div>

    <?php if ($color === 'critical' || $color === 'warning'): ?>
    <div class="inventory-alert" style="margin-bottom:var(--space-md)">
      <span class="material-symbols-outlined" style="font-size:14px">warning</span>
      <?= $color === 'critical' ? 'Critical: Reorder immediately!' : 'Low stock — consider reordering soon.' ?>
    </div>
    <?php endif; ?>

    <!-- Quick Update Form -->
    <form method="POST" style="display:flex;gap:var(--space-sm)">
      <input type="hidden" name="action" value="update_stock"/>
      <input type="hidden" name="inv_id" value="<?= $inv['id'] ?>"/>
      <input class="form-input" type="number" name="quantity" value="<?= $inv['quantity'] ?>" min="0" style="flex:1"/>
      <button class="btn btn-primary btn-sm" type="submit">Update</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<!-- Full Table -->
<div class="data-table-wrap animate-fade-in stagger-3" style="margin-top:var(--space-lg)">
  <table class="data-table">
    <thead>
      <tr>
        <th>Vaccine</th>
        <th>Quantity</th>
        <th>Unit</th>
        <th>Alert Threshold</th>
        <th>Critical Threshold</th>
        <th>Status</th>
        <th>Last Restocked</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $inv):
        $color = inventoryColor($inv);
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($inv['vaccine_name']) ?></strong></td>
        <td style="font-size:18px;font-weight:700;color:<?= $color==='critical'?'var(--error)':($color==='warning'?'#f59e0b':'var(--primary)') ?>">
          <?= $inv['quantity'] ?>
        </td>
        <td><?= htmlspecialchars($inv['unit']) ?></td>
        <td><?= $inv['threshold_low'] ?></td>
        <td><?= $inv['threshold_critical'] ?></td>
        <td><span class="badge <?= $color==='good'?'badge-consult':($color==='warning'?'badge-waiting':'badge-error') ?>"><?= ucfirst($color) ?></span></td>
        <td style="font-size:13px"><?= $inv['last_restocked'] ? date('M j, Y', strtotime($inv['last_restocked'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add Vaccine Modal -->
<div class="modal-overlay" id="modal-add-vaccine">
  <div class="modal-box">
    <div class="modal-header">
      <h3 style="font-size:18px;font-weight:700">Add Vaccine to Inventory</h3>
      <button onclick="closeModal('modal-add-vaccine')" style="background:none;border:none;cursor:pointer;color:var(--on-surface-variant)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_vaccine"/>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-md)">
        <div class="form-group">
          <label class="form-label">Vaccine Name <span style="color:var(--error)">*</span></label>
          <input class="form-input" type="text" name="vaccine_name" required placeholder="e.g. Rabies Vaccine (PCECV)"/>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Initial Quantity</label>
            <input class="form-input" type="number" name="quantity" value="0" min="0"/>
          </div>
          <div class="form-group">
            <label class="form-label">Unit</label>
            <select class="form-select" name="unit">
              <option value="vials">Vials</option>
              <option value="doses">Doses</option>
              <option value="ampoules">Ampoules</option>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md)">
          <div class="form-group">
            <label class="form-label">Alert Threshold</label>
            <input class="form-input" type="number" name="threshold_low" value="20" min="1"/>
          </div>
          <div class="form-group">
            <label class="form-label">Critical Threshold</label>
            <input class="form-input" type="number" name="threshold_critical" value="10" min="1"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="closeModal('modal-add-vaccine')" class="btn btn-surface">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Vaccine</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
