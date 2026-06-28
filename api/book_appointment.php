<?php
// ============================================================
// API: Book Appointment — POST /abc_connect/api/book_appointment.php
// Now includes: daily cap enforcement + QR token generation
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if (!is_patient_logged_in()) {
    json_response(['success' => false, 'message' => 'Please log in first.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = getDB();
$userId = (int)$_SESSION['patient_user_id'];

// Get active patient record for this user
$patStmt = $db->prepare("SELECT id FROM patients WHERE user_id = :uid AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$patStmt->execute([':uid' => $userId]);
$patient = $patStmt->fetch();

if (!$patient) {
    json_response(['success' => false, 'message' => 'No active patient record found. Please complete triage first.'], 400);
}

$patientId       = (int)$patient['id'];
$apptType        = in_array($_POST['type'] ?? '', ['first_evaluation', 'follow_up_dose']) ? $_POST['type'] : 'first_evaluation';
$apptDate        = $_POST['date'] ?? '';
$timeSlot        = $_POST['time_slot'] ?? '';
$doseDay         = (int)($_POST['dose_day'] ?? 0);

// Validate date
if (!$apptDate || !strtotime($apptDate)) {
    json_response(['success' => false, 'message' => 'Invalid appointment date.'], 400);
}
if (strtotime($apptDate) < strtotime('today')) {
    json_response(['success' => false, 'message' => 'Appointment date cannot be in the past.'], 400);
}

// ---- Daily Cap Check ----
// Get cap for the requested date (default 100 if not configured)
$capStmt = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date = :d");
$capStmt->execute([':d' => $apptDate]);
$capRow   = $capStmt->fetch();
$maxSlots = $capRow ? (int)$capRow['max_slots'] : 100;

// Count scheduled appointments for that date
$countStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :d AND status = 'scheduled'");
$countStmt->execute([':d' => $apptDate]);
$bookedCount = (int)$countStmt->fetchColumn();

if ($bookedCount >= $maxSlots) {
    json_response([
        'success'   => false,
        'full'      => true,
        'message'   => "Fully booked for " . date('F j, Y', strtotime($apptDate)) . ". Please try another date.",
        'booked'    => $bookedCount,
        'max_slots' => $maxSlots,
    ], 409);
}

// Check for duplicate appointment for this patient on same date
$dupStmt = $db->prepare("
    SELECT id FROM appointments
    WHERE patient_id = :pid AND appointment_date = :date AND status = 'scheduled'
");
$dupStmt->execute([':pid' => $patientId, ':date' => $apptDate]);
if ($dupStmt->fetch()) {
    json_response(['success' => false, 'message' => 'You already have an appointment on that date.'], 409);
}

// Generate a secure QR token
$qrToken = bin2hex(random_bytes(32));

// Insert appointment
$ins = $db->prepare("
    INSERT INTO appointments (patient_id, appointment_type, dose_day, appointment_date, time_slot, status, qr_token)
    VALUES (:pid, :type, :dday, :date, :slot, 'scheduled', :token)
");
$ins->execute([
    ':pid'   => $patientId,
    ':type'  => $apptType,
    ':dday'  => $doseDay,
    ':date'  => $apptDate,
    ':slot'  => $timeSlot,
    ':token' => $qrToken,
]);

$apptId = $db->lastInsertId();

// Update the vaccine record's scheduled_date if follow-up dose
if ($apptType === 'follow_up_dose') {
    $db->prepare("
        UPDATE vaccines SET scheduled_date = :date
        WHERE patient_id = :pid AND dose_day = :dday AND status = 'scheduled'
        LIMIT 1
    ")->execute([':date' => $apptDate, ':pid' => $patientId, ':dday' => $doseDay]);
}

json_response([
    'success'        => true,
    'appointment_id' => $apptId,
    'qr_token'       => $qrToken,
    'slots_remaining'=> ($maxSlots - $bookedCount - 1),
    'message'        => 'Appointment booked successfully!',
]);
