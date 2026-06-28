<?php
// ============================================================
// API: Queue Fetch — GET /abc_connect/api/queue_fetch.php
// Returns current queue state as JSON
// ============================================================
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

if (!is_admin_logged_in()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = getDB();

// Helper: compute wait label
function waitLabel(string $queuedAt): array {
    $mins = max(0, (int)((time() - strtotime($queuedAt)) / 60));
    $urgent = $mins >= 30;
    $label  = $mins >= 60
        ? round($mins/60, 1) . 'h wait'
        : $mins . 'm wait';
    return ['label' => $label, 'urgent' => $urgent];
}

$statuses = ['waiting', 'in_consultation', 'vaccinated'];
$result   = [];
$today    = date('Y-m-d');

foreach ($statuses as $status) {
    $stmt = $db->prepare("
        SELECT q.id AS queue_id, q.status, q.queued_at, q.purpose, q.notes,
               p.id AS patient_id, p.patient_code, p.body_location, p.category,
               p.animal_type, p.animal_ownership,
               u.full_name,
               a.full_name AS assigned_name
        FROM queue q
        JOIN patients p ON q.patient_id = p.id
        JOIN users u    ON p.user_id = u.id
        LEFT JOIN admins a ON q.assigned_to = a.id
        WHERE q.status = :status
          AND DATE(q.queued_at) = :today
        ORDER BY q.queued_at ASC
    ");
    $stmt->execute([':status' => $status, ':today' => $today]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $wl = waitLabel($row['queued_at']);
        $row['wait_label']  = $wl['label'];
        $row['wait_urgent'] = $wl['urgent'];
        $row['bite_label']  = ucfirst($row['animal_ownership']) . ' ' . $row['animal_type'];
    }
    $key = ($status === 'in_consultation') ? 'in_consultation' : $status;
    $result[$key] = $rows;
}

// Stats
$stmtToday = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) = :today AND status != 'no_show'");
$stmtToday->execute([':today' => $today]);
$todayTotal = (int)$stmtToday->fetchColumn();

$stmtFollowup = $db->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE status = 'scheduled' AND appointment_date <= :today AND appointment_type = 'follow_up_dose'
");
$stmtFollowup->execute([':today' => $today]);
$pendingFollowups = (int)$stmtFollowup->fetchColumn();

$result['stats'] = [
    'today_total'      => $todayTotal,
    'pending_followups'=> $pendingFollowups,
];

echo json_encode($result);
