<?php
// ============================================================
// API: Queue Update — POST /abc_connect/api/queue_update.php
// Moves a patient to a new queue status.
// When status → 'vaccinated': marks the current dose as
// administered and AUTO-BOOKS the next follow-up slot.
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if (!is_admin_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$queueId   = (int)($_POST['queue_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? '');

$allowed = ['waiting', 'in_consultation', 'vaccinated', 'no_show'];
if (!$queueId || !in_array($newStatus, $allowed)) {
    json_response(['success' => false, 'message' => 'Invalid parameters.'], 400);
}

$db = getDB();

// Verify queue entry exists
$stmt = $db->prepare("SELECT id, patient_id, status, purpose FROM queue WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $queueId]);
$entry = $stmt->fetch();

if (!$entry) {
    json_response(['success' => false, 'message' => 'Queue entry not found.'], 404);
}

// Update queue status
$served     = ($newStatus === 'vaccinated' || $newStatus === 'no_show') ? date('Y-m-d H:i:s') : null;
$assignedTo = ($newStatus === 'in_consultation') ? ($_SESSION['admin_id'] ?? null) : null;

$update = $db->prepare("
    UPDATE queue SET status = :status, served_at = :served, assigned_to = COALESCE(:assigned, assigned_to)
    WHERE id = :id
");
$update->execute([
    ':status'   => $newStatus,
    ':served'   => $served,
    ':assigned' => $assignedTo,
    ':id'       => $queueId,
]);

$autoBookedAppt = null;

// ---- When Vaccinated: mark dose + auto-book next ----
if ($newStatus === 'vaccinated') {
    $patientId = $entry['patient_id'];

    // Find and mark the next scheduled dose as administered
    $doseStmt = $db->prepare("
        SELECT id, dose_day, dose_number FROM vaccines
        WHERE patient_id = :pid AND status = 'scheduled'
        ORDER BY dose_number ASC LIMIT 1
    ");
    $doseStmt->execute([':pid' => $patientId]);
    $justAdministeredDose = $doseStmt->fetch();

    if ($justAdministeredDose) {
        $db->prepare("
            UPDATE vaccines SET status = 'administered', administered_date = :today, administered_by = :admin
            WHERE id = :id
        ")->execute([
            ':today' => date('Y-m-d'),
            ':admin' => $_SESSION['admin_id'],
            ':id'    => $justAdministeredDose['id'],
        ]);

        // ---- AUTO-REBOOKING: find the next scheduled dose ----
        $nextDoseStmt = $db->prepare("
            SELECT id, dose_day, dose_number, scheduled_date FROM vaccines
            WHERE patient_id = :pid AND status = 'scheduled'
            ORDER BY dose_number ASC LIMIT 1
        ");
        $nextDoseStmt->execute([':pid' => $patientId]);
        $nextDose = $nextDoseStmt->fetch();

        if ($nextDose && $nextDose['scheduled_date']) {
            $nextDate = $nextDose['scheduled_date'];

            // Check if an appointment for that dose already exists
            $existsStmt = $db->prepare("
                SELECT id FROM appointments
                WHERE patient_id = :pid AND dose_day = :dd AND status = 'scheduled'
                LIMIT 1
            ");
            $existsStmt->execute([':pid' => $patientId, ':dd' => $nextDose['dose_day']]);

            if (!$existsStmt->fetch()) {
                // Check cap for that date
                $capRow   = $db->prepare("SELECT max_slots FROM daily_cap WHERE cap_date = :d");
                $capRow->execute([':d' => $nextDate]);
                $cap      = $capRow->fetch();
                $maxSlots = $cap ? (int)$cap['max_slots'] : 100;

                $bookedCount = (int)$db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = :d AND status = 'scheduled'")
                                        ->execute([':d' => $nextDate]) ? $db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = '$nextDate' AND status = 'scheduled'")->fetchColumn() : 0;

                if ($bookedCount < $maxSlots) {
                    // Auto-book the next dose slot
                    $qrToken = bin2hex(random_bytes(32));
                    $db->prepare("
                        INSERT INTO appointments (patient_id, appointment_type, dose_day, appointment_date, status, qr_token, auto_booked)
                        VALUES (:pid, 'follow_up_dose', :dd, :date, 'scheduled', :token, 1)
                    ")->execute([
                        ':pid'   => $patientId,
                        ':dd'    => $nextDose['dose_day'],
                        ':date'  => $nextDate,
                        ':token' => $qrToken,
                    ]);
                    $autoBookedApptId = (int)$db->lastInsertId();

                    $autoBookedAppt = [
                        'dose_day'   => $nextDose['dose_day'],
                        'date'       => $nextDate,
                        'appt_id'    => $autoBookedApptId,
                        'qr_token'   => $qrToken,
                    ];
                }
            }
        }
    }

    // Mark today's scheduled appointment as completed
    $db->prepare("
        UPDATE appointments SET status = 'completed'
        WHERE patient_id = :pid AND appointment_date = :today AND status = 'scheduled'
        LIMIT 1
    ")->execute([':pid' => $patientId, ':today' => date('Y-m-d')]);
}

$response = ['success' => true, 'new_status' => $newStatus];
if ($autoBookedAppt) {
    $response['auto_booked']  = $autoBookedAppt;
    $response['auto_message'] = "Day {$autoBookedAppt['dose_day']} slot auto-reserved for " . date('F j, Y', strtotime($autoBookedAppt['date'])) . ".";
}

json_response($response);
