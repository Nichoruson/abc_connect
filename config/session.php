<?php
// ============================================================
// ABC Connect — Session & Auth Helpers
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Detects if the request is originated from the mobile app (Capacitor wrapper)
 */
function is_mobile_app(): bool {
    if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'RabiesShieldApp') !== false) {
        $_SESSION['is_app'] = true;
        return true;
    }
    if (isset($_GET['platform']) && $_GET['platform'] === 'app') {
        $_SESSION['is_app'] = true;
        return true;
    }
    if (isset($_SESSION['is_app']) && $_SESSION['is_app'] === true) {
        return true;
    }
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'com.rabiesshield.daet') {
        $_SESSION['is_app'] = true;
        return true;
    }
    return false;
}

// Load APP_BASE if not already defined (e.g. when session.php is first include)
if (!defined('APP_BASE')) {
    require_once __DIR__ . '/db.php';
}

// ---- Patient Auth ----
function is_patient_logged_in(): bool {
    return isset($_SESSION['patient_user_id']) && !empty($_SESSION['patient_user_id']);
}

function require_patient_login(): void {
    if (!is_patient_logged_in()) {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
}

function patient_login(int $userId, string $fullName, string $patientCode = ''): void {
    $_SESSION['patient_user_id']  = $userId;
    $_SESSION['patient_name']     = $fullName;
    $_SESSION['patient_code']     = $patientCode;
}

function patient_logout(): void {
    unset($_SESSION['patient_user_id'], $_SESSION['patient_name'], $_SESSION['patient_code']);
}

// ---- Admin Auth ----
function is_admin_logged_in(): bool {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function require_admin_login(): void {
    if (!is_admin_logged_in()) {
        header('Location: ' . APP_BASE . '/admin/login.php');
        exit;
    }
}

function admin_login(int $adminId, string $fullName, string $role, string $initials): void {
    $_SESSION['admin_id']       = $adminId;
    $_SESSION['admin_name']     = $fullName;
    $_SESSION['admin_role']     = $role;
    $_SESSION['admin_initials'] = $initials;
}

function admin_logout(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_initials']);
}

// ---- Utilities ----
function flash(string $key, string $message = ''): string {
    if ($message) {
        $_SESSION['flash'][$key] = $message;
        return '';
    }
    $msg = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
