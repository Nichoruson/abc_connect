<?php
// ============================================================
// ABC Connect — Admin Sidebar + Header Partial
// Requires: $page_title, $active_page
// ============================================================
if (!isset($page_title))  $page_title  = 'Admin Portal';
if (!isset($active_page)) $active_page = 'dashboard';
$admin_name     = $_SESSION['admin_name']     ?? 'Admin';
$admin_role     = $_SESSION['admin_role']     ?? 'nurse';
$admin_initials = $_SESSION['admin_initials'] ?? 'AD';

$adminId = $_SESSION['admin_id'] ?? 0;
$appLoginToken = '';
$qrImagePath = '';

if ($adminId) {
    if (!function_exists('getDB')) {
        require_once __DIR__ . '/../config/db.php';
    }
    $db = getDB();
    
    // Fetch or generate mobile app login token for QR code authentication
    $stmt = $db->prepare("SELECT app_login_token FROM admins WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $adminId]);
    $appLoginToken = $stmt->fetchColumn();
    
    if (empty($appLoginToken)) {
        $appLoginToken = bin2hex(random_bytes(32));
        $db->prepare("UPDATE admins SET app_login_token = :token WHERE id = :id")
           ->execute([':token' => $appLoginToken, ':id' => $adminId]);
    }
    
    // Generate QR code using the built-in phpqrcode library
    $qrLibPath = __DIR__ . '/../assets/libs/phpqrcode/qrlib.php';
    if (file_exists($qrLibPath)) {
        require_once $qrLibPath;
        $tmpDir = sys_get_temp_dir();
        $qrFile = $tmpDir . '/staff_login_' . $adminId . '.png';
        
        $qrPayload = json_encode([
            'type'  => 'staff_login',
            'token' => $appLoginToken
        ]);
        
        QRcode::png($qrPayload, $qrFile, QR_ECLEVEL_H, 6, 2);
        if (file_exists($qrFile)) {
            $qrImagePath = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
        }
    }
}


if (!isset($notif_count)) {
    if (!function_exists('getDB')) {
        require_once __DIR__ . '/../config/db.php';
    }
    $notif_count = (int)getDB()->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
} else {
    $notif_count = (int)$notif_count;
}

if (!isset($notif_items)) {
    if (!function_exists('getDB')) {
        require_once __DIR__ . '/../config/db.php';
    }
    $notif_items = getDB()->query("SELECT * FROM notifications WHERE is_read=0 ORDER BY created_at DESC LIMIT 8")->fetchAll();
}

$nav_items = [
  ['key' => 'dashboard', 'icon' => 'dashboard',       'label' => 'Dashboard',   'href' => APP_BASE . '/admin/dashboard.php'],
  ['key' => 'queue',     'icon' => 'swap_vert',        'label' => 'Queue',       'href' => APP_BASE . '/admin/queue.php'],
  ['key' => 'daily_cap', 'icon' => 'event_available',  'label' => 'Daily Cap',   'href' => APP_BASE . '/admin/daily_cap.php'],
  ['key' => 'scanner',   'icon' => 'qr_code_scanner',  'label' => 'QR Scanner',  'href' => APP_BASE . '/admin/scan.php'],
  ['key' => 'patients',  'icon' => 'personal_injury',  'label' => 'Patients',    'href' => APP_BASE . '/admin/patients.php'],
  ['key' => 'inventory', 'icon' => 'inventory_2',      'label' => 'Inventory',   'href' => APP_BASE . '/admin/inventory.php'],
  ['key' => 'reports',   'icon' => 'assessment',       'label' => 'Reports',     'href' => APP_BASE . '/admin/reports.php'],
  ['key' => 'reminders', 'icon' => 'sms',             'label' => 'SMS Reminders', 'href' => APP_BASE . '/admin/sms_reminders.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($page_title) ?> — RabiesShield Daet Admin</title>
  <meta name="description" content="RabiesShield Daet Admin Portal — Manage queue, patients, and daily slot capacity."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/admin.css"/>
  <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body class="admin-body">

<!-- ========== SIDEBAR ========== -->
<aside class="admin-sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-brand__title">
      <span class="material-symbols-outlined icon-filled" style="color:var(--primary);font-size:26px">pets</span>
      <span>RabiesShield Daet</span>
    </div>
    <div class="sidebar-brand__subtitle">Daet Animal Bite Treatment Center</div>
  </div>

  <nav class="sidebar-nav">
    <span class="sidebar-nav__section-label">Main</span>
    <?php foreach ($nav_items as $item): ?>
    <a class="sidebar-nav__item <?= $active_page === $item['key'] ? 'active' : '' ?>"
       href="<?= $item['href'] ?>">
      <span class="material-symbols-outlined <?= $active_page === $item['key'] ? 'icon-filled' : '' ?>">
        <?= $item['icon'] ?>
      </span>
      <span><?= $item['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <a class="sidebar-user" href="<?= APP_BASE ?>/admin/logout.php">
      <div class="sidebar-user__avatar"><?= htmlspecialchars($admin_initials) ?></div>
      <div>
        <div class="sidebar-user__name"><?= htmlspecialchars($admin_name) ?></div>
        <div class="sidebar-user__role"><?= ucfirst(htmlspecialchars($admin_role)) ?></div>
      </div>
    </a>
  </div>
</aside>

<!-- ========== MAIN ========== -->
<div class="admin-main">

  <!-- Top Bar -->
  <header class="admin-topbar">
    <div class="topbar-search">
      <span class="material-symbols-outlined" style="color:var(--on-surface-variant);font-size:20px">search</span>
      <input type="text" id="global-search" placeholder="Search patients, vaccines..." autocomplete="off"/>
    </div>
    <div class="topbar-actions">
      <div class="topbar-notif-wrap">
        <button class="topbar-notif" id="notif-btn" title="Notifications" aria-expanded="false" aria-controls="notif-dropdown">
          <span class="material-symbols-outlined">notifications</span>
          <?php if ($notif_count > 0): ?>
          <span class="topbar-notif__badge" id="notif-badge"><?= $notif_count > 9 ? '9+' : $notif_count ?></span>
          <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notif-dropdown" hidden>
          <div class="notif-dropdown__header">
            <span>Notifications</span>
            <?php if ($notif_count > 0): ?>
            <span class="notif-dropdown__count"><?= $notif_count ?> unread</span>
            <?php endif; ?>
          </div>
          <?php if (empty($notif_items)): ?>
          <p class="notif-dropdown__empty">No new notifications.</p>
          <?php else: ?>
          <div class="notif-dropdown__list">
            <?php foreach ($notif_items as $n):
              $icon = match($n['type']) {
                'critical' => 'report',
                'warning'  => 'warning',
                'success'  => 'sync',
                default    => 'info',
              };
            ?>
            <div class="notif-dropdown__item">
              <span class="material-symbols-outlined notif-dropdown__icon notif-dropdown__icon--<?= htmlspecialchars($n['type']) ?>"><?= $icon ?></span>
              <div>
                <div class="notif-dropdown__title"><?= htmlspecialchars($n['title']) ?></div>
                <div class="notif-dropdown__desc"><?= htmlspecialchars($n['message']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <a href="<?= APP_BASE ?>/admin/dashboard.php" class="notif-dropdown__footer">View all on Dashboard</a>
        </div>
      </div>
      <!-- App Access Trigger -->
      <button class="btn btn-surface btn-sm btn-pill" onclick="openModal('modal-app-login')" style="display:flex;align-items:center;gap:6px;padding:6px 12px;margin-right:8px;font-size:12px;font-weight:600;" title="Link Mobile App">
        <span class="material-symbols-outlined" style="font-size:16px;">phone_iphone</span>
        <span>App Access</span>
      </button>
      <div style="width:1px;height:28px;background:var(--outline-variant)"></div>
      <span class="topbar-page-title"><?= htmlspecialchars($page_title) ?></span>
    </div>
  </header>

  <!-- Content Canvas -->
  <div class="admin-content">

<!-- Modal for Mobile App QR Access -->
<div class="modal-overlay" id="modal-app-login" style="z-index: 10000;">
  <div class="modal-box" style="max-width: 400px; text-align: center; border-radius: var(--radius-xl); padding: var(--space-xl);">
    <div class="modal-header" style="border-bottom: none; padding-bottom: 0;">
      <h3 style="font-size:18px;font-weight:700">Mobile App Staff Access</h3>
      <button onclick="closeModal('modal-app-login')" style="background:none;border:none;cursor:pointer;color:var(--on-surface-variant)">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="modal-body" style="padding: var(--space-lg) 0; display: flex; flex-direction: column; align-items: center; gap: var(--space-md);">
      <p style="font-size:14px; color: var(--on-surface-variant); line-height: 1.5; margin: 0;">
        Scan this QR code using the <strong>RabiesShield Daet</strong> mobile app to log in as staff instantly.
      </p>
      <?php if ($qrImagePath): ?>
        <img src="<?= $qrImagePath ?>" alt="App Login QR" style="border: 1px solid var(--outline-variant); border-radius: 16px; padding: 12px; background: white; width: 180px; height: 180px; box-shadow: var(--shadow-card);" />
      <?php else: ?>
        <p style="color: var(--error)">Failed to generate QR Code. Check library files.</p>
      <?php endif; ?>
      <div style="font-size: 12px; color: var(--on-surface-variant); background: var(--surface-container); padding: 8px 12px; border-radius: var(--radius-md); width: 100%; word-break: break-all;">
        Access Token: <span style="font-family: monospace; font-weight: 600;"><?= htmlspecialchars($appLoginToken) ?></span>
      </div>
    </div>
    <div class="modal-footer" style="border-top: none; padding-top: 0;">
      <button type="button" onclick="closeModal('modal-app-login')" class="btn btn-surface" style="width: 100%; justify-content: center;">Close</button>
    </div>
  </div>
</div>
