<?php
// ============================================================
// ABC Connect — Patient Header Partial
// Requires: $page_title (string), $active_nav ('home'|'schedule'|'profile')
// ============================================================
if (!isset($page_title))  $page_title  = 'ABC Connect';
if (!isset($active_nav))  $active_nav  = 'home';
$user_name = $_SESSION['patient_name'] ?? 'Patient';
$initials  = strtoupper(substr($user_name, 0, 1));

if (!isset($schedule_badge_count) && !empty($_SESSION['patient_user_id'])) {
    if (!function_exists('get_schedule_badge_count')) {
        require_once __DIR__ . '/../config/patient_helpers.php';
    }
    if (!function_exists('getDB')) {
        require_once __DIR__ . '/../config/db.php';
    }
    $schedule_badge_count = get_schedule_badge_count(getDB(), (int)$_SESSION['patient_user_id']);
} elseif (!isset($schedule_badge_count)) {
    $schedule_badge_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($page_title) ?> | RabiesShield Daet</title>
  <meta name="description" content="RabiesShield Daet — Daet ABTC patient portal for vaccine scheduling and appointment tracking."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/patient.css"/>
  <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body class="patient-body">

<!-- Top App Bar -->
<header class="patient-header">
  <a class="patient-header__brand" href="<?= APP_BASE ?>/patient/dashboard.php">
    <div class="patient-header__logo">
      <span class="material-symbols-outlined icon-filled" style="font-size:20px">pets</span>
    </div>
    <span class="patient-header__title">RabiesShield Daet</span>
  </a>
  <a class="patient-header__avatar" href="<?= APP_BASE ?>/patient/profile.php" title="Profile">
    <?= $initials ?>
  </a>
</header>

<!-- Main Content -->
<main class="patient-main">
