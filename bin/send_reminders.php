<?php
// ============================================================
// ABC Connect — CLI: Send Tomorrow's SMS Reminders
// Can be run via task scheduler or cron: php bin/send_reminders.php
// ============================================================
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms.php';

try {
    $db = getDB();
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    // Query all scheduled appointments for tomorrow that haven't been notified yet
    $stmt = $db->prepare("
        SELECT a.*, p.patient_code, u.full_name, u.contact_number
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE a.appointment_date = :tomorrow
          AND a.status = 'scheduled'
          AND a.sms_reminder_sent = 0
    ");
    $stmt->execute([':tomorrow' => $tomorrow]);
    $appointments = $stmt->fetchAll();

    echo "Found " . count($appointments) . " appointments scheduled for tomorrow (" . date('F j, Y', strtotime($tomorrow)) . ") requiring SMS reminders.\n";

    $successCount = 0;
    $failCount = 0;

    foreach ($appointments as $appt) {
        $typeLabel = $appt['appointment_type'] === 'first_evaluation' 
            ? 'First Evaluation' 
            : 'Follow-up Dose (Day ' . $appt['dose_day'] . ')';
            
        $timeSlotStr = $appt['time_slot'] ? ' at ' . $appt['time_slot'] : '';
        
        $message = "Hello " . $appt['full_name'] . ", this is a reminder from RabiesShield Daet. You have a scheduled " . $typeLabel . " appointment tomorrow, " . date('M j, Y', strtotime($tomorrow)) . $timeSlotStr . ". Please bring your QR Pass. Thank you!";
        
        echo "Sending SMS to " . $appt['full_name'] . " (" . $appt['contact_number'] . ")... ";
        
        $result = send_sms($appt['contact_number'], $message);
        
        if ($result['success']) {
            $update = $db->prepare("UPDATE appointments SET sms_reminder_sent = 1 WHERE id = :id");
            $update->execute([':id' => $appt['id']]);
            echo "SUCCESS: " . ($result['message'] ?? 'Sent') . "\n";
            $successCount++;
        } else {
            echo "FAILED: " . ($result['message'] ?? 'Unknown error') . "\n";
            $failCount++;
        }
    }

    echo "Completed. Total Sent: {$successCount}, Failed: {$failCount}.\n";
} catch (Exception $e) {
    echo "Fatal error executing reminders: " . $e->getMessage() . "\n";
}
