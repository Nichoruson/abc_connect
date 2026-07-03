<?php
// ============================================================
// API: Staff QR Login — POST /abc_connect/api/staff_qr_login.php
// Validates a scanned app access token and logs staff in on mobile
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Read JSON input or POST fields
$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? $_POST['token'] ?? '');

if (empty($token)) {
    json_response(['success' => false, 'message' => 'No login token provided.'], 400);
}

try {
    $db = getDB();
    
    // Look up the admin matching this token
    $stmt = $db->prepare("SELECT * FROM admins WHERE app_login_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $admin = $stmt->fetch();

    if ($admin) {
        // Successful login
        $avatarInitials = $admin['avatar_initials'] ?: strtoupper(substr($admin['full_name'], 0, 2));
        admin_login(
            (int)$admin['id'],
            $admin['full_name'],
            $admin['role'],
            $avatarInitials
        );

        // Mark session as logged via QR code (from mobile app)
        $_SESSION['staff_logged_via_qr'] = true;

        // Optional: Regenerate session ID for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Return redirection URL
        json_response([
            'success'  => true,
            'message'  => 'Staff authenticated successfully!',
            'redirect' => APP_BASE . '/admin/scan.php'
        ]);
    } else {
        json_response([
            'success' => false,
            'message' => 'Invalid or expired QR Access token.'
        ], 401);
    }
} catch (Exception $e) {
    json_response([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ], 500);
}
