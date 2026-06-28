<?php
// ============================================================
// ABC Connect — Admin/Staff Login Redirection
// Redirects to root login.php (which handles browser staff login)
// ============================================================
require_once __DIR__ . '/../config/session.php';
redirect(APP_BASE . '/login.php');
