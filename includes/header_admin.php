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
      <div style="width:1px;height:28px;background:var(--outline-variant)"></div>
      <span class="topbar-page-title"><?= htmlspecialchars($page_title) ?></span>
    </div>
  </header>

  <!-- Content Canvas -->
  <div class="admin-content">
