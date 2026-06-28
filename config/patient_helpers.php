<?php
// ============================================================
// ABC Connect — Patient Helper Functions
// ============================================================

/**
 * Count actionable schedule items for the bottom-nav badge:
 * pending/missed doses + upcoming appointments for the active patient record.
 */
function get_schedule_badge_count(PDO $db, int $userId): int {
    $patStmt = $db->prepare("
        SELECT id FROM patients
        WHERE user_id = :uid AND status = 'active'
        ORDER BY created_at DESC LIMIT 1
    ");
    $patStmt->execute([':uid' => $userId]);
    $patient = $patStmt->fetch();
    if (!$patient) {
        return 0;
    }

    $pid = (int)$patient['id'];

    $dStmt = $db->prepare("
        SELECT COUNT(*) FROM vaccines
        WHERE patient_id = :pid AND status IN ('scheduled', 'missed')
    ");
    $dStmt->execute([':pid' => $pid]);
    $doseCount = (int)$dStmt->fetchColumn();

    $aStmt = $db->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE patient_id = :pid AND status = 'scheduled' AND appointment_date >= CURDATE()
    ");
    $aStmt->execute([':pid' => $pid]);
    $apptCount = (int)$aStmt->fetchColumn();

    return $doseCount + $apptCount;
}
