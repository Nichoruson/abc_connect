<?php
// ============================================================
// ABC Connect — Landing Page (index.php)
// Redirects to appropriate portal based on session
// ============================================================
require_once __DIR__ . '/config/session.php';

$isApp = is_mobile_app();

if (is_admin_logged_in()) {
    redirect(APP_BASE . '/admin/dashboard.php');
} elseif (is_patient_logged_in()) {
    if (!$isApp) {
        patient_logout();
        redirect(APP_BASE . '/login.php');
    }
    redirect(APP_BASE . '/patient/dashboard.php');
}

// Redirect directly to login.php (which handles platform-specific login forms)
redirect(APP_BASE . '/login.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RabiesShield Daet — Animal Bite Center Appointment & Records System</title>
  <meta name="description" content="RabiesShield Daet — Book appointments, track your rabies vaccination schedule, and manage your animal bite care journey at Daet ABTC."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/global.css"/>
  <style>
    body { margin: 0; min-height: 100vh; background: linear-gradient(145deg, #e0fdf8 0%, #f8f9ff 50%, #dce9ff 100%); }
    .landing { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:100vh; padding:var(--space-lg); text-align:center; }
    .landing-logo { width:80px; height:80px; background:var(--primary); border-radius:20px; display:flex; align-items:center; justify-content:center; margin:0 auto var(--space-lg); box-shadow:0 12px 32px rgba(0,107,95,0.3); }
    .landing-logo .material-symbols-outlined { font-size:44px; color:white; }
    .landing h1 { font-size:clamp(28px,6vw,48px); font-weight:800; color:var(--primary); margin-bottom:var(--space-sm); letter-spacing:-0.03em; }
    .landing p  { font-size:18px; color:var(--on-surface-variant); max-width:480px; margin:0 auto var(--space-xl); line-height:1.6; }
    .landing-cards { display:grid; grid-template-columns:1fr 1fr; gap:var(--space-md); max-width:540px; width:100%; }
    .landing-card  { background:white; border:1px solid var(--outline-variant); border-radius:var(--radius-xl); padding:var(--space-xl) var(--space-lg); text-decoration:none; transition:all var(--transition-base); box-shadow:var(--shadow-card); display:flex; flex-direction:column; align-items:center; gap:var(--space-sm); }
    .landing-card:hover { box-shadow:var(--shadow-elevated); transform:translateY(-3px); border-color:var(--primary); }
    .landing-card .card-icon { width:56px; height:56px; border-radius:var(--radius-full); display:flex; align-items:center; justify-content:center; margin-bottom:4px; }
    .landing-card .card-title { font-size:16px; font-weight:700; color:var(--on-surface); }
    .landing-card .card-sub   { font-size:13px; color:var(--on-surface-variant); text-align:center; }
    .dots { display:flex; gap:6px; margin-top:var(--space-xl); }
    .dot  { width:6px; height:6px; border-radius:50%; background:var(--primary-container); }
    .dot.active { background:var(--primary); width:20px; border-radius:3px; }
    @media(max-width:480px){ .landing-cards{grid-template-columns:1fr;} }
  </style>
</head>
<body>
<div class="landing animate-fade-in">
  <div class="landing-logo">
    <span class="material-symbols-outlined icon-filled">pets</span>
  </div>
  <h1>RabiesShield Daet</h1>
  <p>Daet Animal Bite Treatment Center — Book appointments, track your vaccinations, and manage patient care with ease.</p>

  <div class="landing-cards">
    <a class="landing-card stagger-1 animate-slide-up" href="<?= APP_BASE ?>/login.php">
      <div class="card-icon" style="background:rgba(0,107,95,0.1)">
        <span class="material-symbols-outlined icon-filled" style="color:var(--primary);font-size:28px">person</span>
      </div>
      <span class="card-title">Patient Portal</span>
      <span class="card-sub">Track your vaccine schedule and book appointments</span>
    </a>
    <a class="landing-card stagger-2 animate-slide-up" href="<?= APP_BASE ?>/admin/login.php">
      <div class="card-icon" style="background:rgba(64,89,170,0.1)">
        <span class="material-symbols-outlined icon-filled" style="color:var(--secondary);font-size:28px">admin_panel_settings</span>
      </div>
      <span class="card-title">Admin Portal</span>
      <span class="card-sub">Manage queue, inventory, and patient records</span>
    </a>
  </div>

  <div class="dots">
    <div class="dot active"></div>
    <div class="dot"></div>
    <div class="dot"></div>
  </div>

  <p style="margin-top:var(--space-xl);font-size:12px;color:var(--on-surface-variant)">
    © <?= date('Y') ?> RabiesShield Daet · Daet Animal Bite Treatment Center · All Rights Reserved
  </p>
</div>
</body>
</html>
