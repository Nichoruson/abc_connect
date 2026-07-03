<?php
// ============================================================
// ABC Connect — Main Entry Redirection (index.php)
// Redirects web browser visitors to the Staff Login Portal
// ============================================================
require_once __DIR__ . '/config/session.php';

if (is_admin_logged_in()) {
    redirect(APP_BASE . '/admin/dashboard.php');
} elseif (is_patient_logged_in()) {
    redirect(APP_BASE . '/patient/dashboard.php');
}

// Web visitors default to Staff Portal
redirect(APP_BASE . '/admin/login.php');
?>
