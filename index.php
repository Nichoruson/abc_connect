<?php
// ============================================================
// ABC Connect — Main Entry Redirection (index.php)
// Redirects web browser visitors to the Staff Login Portal
// ============================================================
require_once __DIR__ . '/config/session.php';

if (is_admin_logged_in()) {
    if (isset($_SESSION['staff_logged_via_qr']) && $_SESSION['staff_logged_via_qr'] === true) {
        redirect(APP_BASE . '/admin/scan.php');
    } else {
        redirect(APP_BASE . '/admin/dashboard.php');
    }
} elseif (is_patient_logged_in()) {
    redirect(APP_BASE . '/patient/dashboard.php');
}

// Route based on environment
if (is_mobile_app()) {
    redirect(APP_BASE . '/login.php');
} else {
    redirect(APP_BASE . '/admin/login.php');
}
?>
