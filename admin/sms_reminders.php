<?php
// ============================================================
// ABC Connect — Admin: SMS Reminders Panel
// Manage SMS notifications, check pending queue, and send test SMS
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/sms.php';
require_admin_login();

$db = getDB();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$success_msg = $error_msg = '';

// ---- Handle Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_all') {
        // Fetch unsent reminders for tomorrow
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
        $pending = $stmt->fetchAll();

        if (empty($pending)) {
            $success_msg = 'No pending reminders to send for tomorrow.';
        } else {
            $sent = 0;
            $failed = 0;
            $failed_details = [];

            foreach ($pending as $appt) {
                $typeLabel = $appt['appointment_type'] === 'first_evaluation' 
                    ? 'First Evaluation' 
                    : 'Follow-up Dose (Day ' . $appt['dose_day'] . ')';
                
                $timeSlotStr = $appt['time_slot'] ? ' at ' . $appt['time_slot'] : '';
                $message = "Hello " . $appt['full_name'] . ", this is a reminder from RabiesShield Daet. You have a scheduled " . $typeLabel . " appointment tomorrow, " . date('M j, Y', strtotime($tomorrow)) . $timeSlotStr . ". Please bring your QR Pass. Thank you!";
                
                $result = send_sms($appt['contact_number'], $message);
                
                if ($result['success']) {
                    $up = $db->prepare("UPDATE appointments SET sms_reminder_sent = 1 WHERE id = :id");
                    $up->execute([':id' => $appt['id']]);
                    $sent++;
                } else {
                    $failed++;
                    $failed_details[] = $appt['full_name'] . ' (' . ($result['message'] ?? 'Unknown Error') . ')';
                }
            }

            if ($failed > 0) {
                $success_msg = "Successfully sent {$sent} reminders.";
                $error_msg = "Failed to send {$failed} reminders: " . implode(', ', $failed_details);
            } else {
                $success_msg = "Successfully sent all {$sent} reminders for tomorrow!";
            }
        }
    } elseif ($_POST['action'] === 'send_test') {
        $test_num = $_POST['test_number'] ?? '';
        $test_body = $_POST['test_message'] ?? 'This is a test SMS from RabiesShield Daet System.';

        if (empty($test_num)) {
            $error_msg = 'Please enter a valid phone number.';
        } else {
            $result = send_sms($test_num, $test_body);
            if ($result['success']) {
                $success_msg = 'Test SMS sent successfully! ' . ($result['message'] ?? '');
            } else {
                $error_msg = 'Test SMS failed: ' . ($result['message'] ?? 'Unknown Error');
            }
        }
    }
}

// ---- Fetch Data for tomorrow ----
// Tomorrow's appointments
$apptStmt = $db->prepare("
    SELECT a.*, p.patient_code, u.full_name, u.contact_number
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE a.appointment_date = :tomorrow
      AND a.status = 'scheduled'
    ORDER BY u.full_name ASC
");
$apptStmt->execute([':tomorrow' => $tomorrow]);
$tomorrow_appointments = $apptStmt->fetchAll();

// Count stats
$totalTomorrow = count($tomorrow_appointments);
$sentTomorrow = 0;
$pendingTomorrow = 0;

foreach ($tomorrow_appointments as $appt) {
    if ($appt['sms_reminder_sent'] == 1) {
        $sentTomorrow++;
    } else {
        $pendingTomorrow++;
    }
}

$page_title  = 'SMS Reminders';
$active_page = 'reminders';
include __DIR__ . '/../includes/header_admin.php';
?>

<div class="page-header animate-fade-in stagger-1">
  <div>
    <h1 class="page-header__title">SMS Reminder Manager</h1>
    <p class="page-header__sub">Send and monitor automated SMS appointment reminders for tomorrow</p>
  </div>
</div>

<!-- Alerts -->
<?php if ($success_msg): ?>
<div class="alert alert-success animate-fade-in">
  <span class="material-symbols-outlined icon-filled" style="color:var(--primary)">check_circle</span>
  <span><?= htmlspecialchars($success_msg) ?></span>
</div>
<?php endif; ?>

<?php if ($error_msg): ?>
<div class="alert alert-error animate-fade-in" style="margin-top: 10px;">
  <span class="material-symbols-outlined" style="color:var(--error)">error</span>
  <span><?= htmlspecialchars($error_msg) ?></span>
</div>
<?php endif; ?>

<!-- Status Banner (Log Driver info) -->
<?php if (SMS_PROVIDER === 'log'): ?>
<div class="alert alert-info animate-fade-in" style="background: rgba(var(--primary-rgb, 63, 81, 181), 0.08); border: 1.5px solid var(--primary); margin-bottom: var(--space-lg);">
  <span class="material-symbols-outlined" style="color:var(--primary)">info</span>
  <div style="flex:1">
    <strong>Developer Mode Active:</strong> The SMS provider is currently set to <code>'log'</code>. Outbound text messages will be simulated and written to <a href="file:///c:/Users/adria/OneDrive/Desktop/Programming/abc_connect/logs/sms.log" target="_blank" style="color:var(--primary);font-weight:600">logs/sms.log</a>. Update this to <code>'semaphore'</code> in <code>config/sms.php</code> when deploying.
  </div>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid animate-fade-in stagger-2" style="margin-bottom: var(--space-lg);">
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--primary">
      <span class="material-symbols-outlined icon-filled">calendar_today</span>
    </div>
    <div>
      <div class="stat-card__label">Tomorrow's Appointments</div>
      <div class="stat-card__value"><?= $totalTomorrow ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--primary" style="background: rgba(34,197,94,0.12)">
      <span class="material-symbols-outlined icon-filled" style="color: #22c55e">check_circle</span>
    </div>
    <div>
      <div class="stat-card__label">Reminders Sent</div>
      <div class="stat-card__value" style="color: #22c55e"><?= $sentTomorrow ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card__icon stat-card__icon--secondary" style="background: rgba(245,158,11,0.12)">
      <span class="material-symbols-outlined icon-filled" style="color: #f59e0b">pending</span>
    </div>
    <div>
      <div class="stat-card__label">Pending Dispatches</div>
      <div class="stat-card__value" style="color: #f59e0b"><?= $pendingTomorrow ?></div>
    </div>
  </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:var(--space-md); align-items: start;">

  <!-- Tomorrow's Appointments Table -->
  <div class="card animate-fade-in stagger-3">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--space-md)">
      <h3 style="font-size:16px; font-weight:700">Tomorrow's Schedule Details (<?= date('F j, Y', strtotime($tomorrow)) ?>)</h3>
      <?php if ($pendingTomorrow > 0): ?>
        <form method="POST">
          <input type="hidden" name="action" value="send_all" />
          <button type="submit" class="btn btn-primary btn-pill">
            <span class="material-symbols-outlined">send</span>
            Send All Reminders (<?= $pendingTomorrow ?>)
          </button>
        </form>
      <?php else: ?>
        <button class="btn btn-surface btn-pill" disabled style="opacity: 0.6; cursor: not-allowed;">
          <span class="material-symbols-outlined">done_all</span>
          All Sent
        </button>
      <?php endif; ?>
    </div>

    <?php if (empty($tomorrow_appointments)): ?>
      <div class="empty-state" style="padding: var(--space-xl) 0;">
        <span class="material-symbols-outlined" style="font-size:48px; opacity:0.3">event_busy</span>
        <p style="margin-top:var(--space-sm)">No appointments scheduled for tomorrow.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table" style="width:100%; border-collapse: collapse;">
          <thead>
            <tr style="border-bottom: 2px solid var(--outline-variant); text-align: left;">
              <th style="padding: 12px 8px; font-weight: 600;">Patient</th>
              <th style="padding: 12px 8px; font-weight: 600;">Code</th>
              <th style="padding: 12px 8px; font-weight: 600;">Contact Number</th>
              <th style="padding: 12px 8px; font-weight: 600;">Type</th>
              <th style="padding: 12px 8px; font-weight: 600;">Time Slot</th>
              <th style="padding: 12px 8px; font-weight: 600; text-align: center;">SMS Reminder</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tomorrow_appointments as $appt): 
              $isSent = $appt['sms_reminder_sent'] == 1;
              $typeLabel = $appt['appointment_type'] === 'first_evaluation' ? 'First Eval' : 'Dose (Day ' . $appt['dose_day'] . ')';
            ?>
              <tr style="border-bottom: 1px solid var(--outline-variant); transition: background-color 0.2s;">
                <td style="padding: 12px 8px; font-weight:500;"><?= htmlspecialchars($appt['full_name']) ?></td>
                <td style="padding: 12px 8px; color: var(--on-surface-variant);">#<?= htmlspecialchars($appt['patient_code']) ?></td>
                <td style="padding: 12px 8px; font-family: monospace;"><?= htmlspecialchars($appt['contact_number']) ?></td>
                <td style="padding: 12px 8px;"><?= htmlspecialchars($typeLabel) ?></td>
                <td style="padding: 12px 8px; color: var(--on-surface-variant);"><?= htmlspecialchars($appt['time_slot'] ?: 'TBD') ?></td>
                <td style="padding: 12px 8px; text-align: center;">
                  <?php if ($isSent): ?>
                    <span class="badge badge-consult" style="background: rgba(34,197,94,0.12); color: #166534;">Sent</span>
                  <?php else: ?>
                    <span class="badge badge-info" style="background: rgba(245,158,11,0.12); color: #9a3412;">Pending</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Send Test SMS Utility -->
  <div class="card animate-fade-in stagger-4">
    <h3 style="font-size:16px; font-weight:700; margin-bottom:var(--space-md); display:flex; align-items:center; gap:var(--space-sm)">
      <span class="material-symbols-outlined icon-filled" style="color:var(--secondary); font-size:20px">sms</span>
      Send Test SMS
    </h3>
    <form method="POST">
      <input type="hidden" name="action" value="send_test" />
      <div class="form-group" style="margin-bottom: var(--space-md);">
        <label class="form-label" for="test_number">Recipient Mobile Number</label>
        <input class="form-input" type="tel" id="test_number" name="test_number" placeholder="09171234567" required />
      </div>
      <div class="form-group" style="margin-bottom: var(--space-lg);">
        <label class="form-label" for="test_message">Test Message Body</label>
        <textarea class="form-input" id="test_message" name="test_message" rows="3" required style="resize: none;">Hello! This is a test reminder from RabiesShield Daet SMS system.</textarea>
      </div>
      <button class="btn btn-secondary" type="submit" style="width: 100%; justify-content: center;">
        <span class="material-symbols-outlined">send</span>
        Dispatch Test SMS
      </button>
    </form>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer_admin.php'; ?>
