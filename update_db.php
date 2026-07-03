<?php
// ============================================================
// ABC Connect — Database Schema Updater & Migration Tool
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

// Initialize variables
$db = null;
$error = '';
$logs = [];
$migrationExecuted = false;

try {
    $db = getDB();
} catch (Exception $e) {
    $error = 'Failed to connect to the database: ' . $e->getMessage();
}

// Define the expected tables and their schemas
$expected_tables = [
    'admins' => "CREATE TABLE admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        role ENUM('admin', 'doctor', 'nurse') DEFAULT 'nurse',
        avatar_initials VARCHAR(4),
        app_login_token VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'users' => "CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        birthdate DATE,
        sex ENUM('Male', 'Female', 'Other') DEFAULT 'Male',
        contact_number VARCHAR(20) NOT NULL UNIQUE,
        email VARCHAR(150) NULL UNIQUE,
        address TEXT,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'patients' => "CREATE TABLE patients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        patient_code VARCHAR(20) NOT NULL UNIQUE,
        animal_type ENUM('Dog', 'Cat', 'Bat', 'Monkey', 'Other') DEFAULT 'Dog',
        animal_ownership ENUM('Pet', 'Stray', 'Unknown') DEFAULT 'Stray',
        bite_date DATE NOT NULL,
        body_location VARCHAR(100) NOT NULL,
        category ENUM('I', 'II', 'III') DEFAULT 'II',
        status ENUM('active', 'completed', 'defaulted') DEFAULT 'active',
        remarks TEXT,
        registered_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (registered_by) REFERENCES admins(id) ON DELETE SET NULL
    )",
    'queue' => "CREATE TABLE queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        queue_number VARCHAR(10) NOT NULL,
        status ENUM('waiting', 'in_consultation', 'vaccinated', 'no_show') DEFAULT 'waiting',
        purpose ENUM('first_evaluation', 'follow_up_dose') DEFAULT 'first_evaluation',
        assigned_to INT,
        notes TEXT,
        queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        served_at TIMESTAMP NULL,
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_to) REFERENCES admins(id) ON DELETE SET NULL
    )",
    'appointments' => "CREATE TABLE appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        appointment_type ENUM('first_evaluation', 'follow_up_dose') DEFAULT 'first_evaluation',
        dose_day INT DEFAULT 0,
        appointment_date DATE NOT NULL,
        time_slot VARCHAR(20),
        status ENUM('scheduled', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
        qr_token VARCHAR(64) UNIQUE,
        auto_booked TINYINT(1) DEFAULT 0,
        sms_reminder_sent TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    )",
    'daily_cap' => "CREATE TABLE daily_cap (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cap_date DATE NOT NULL UNIQUE,
        max_slots INT NOT NULL DEFAULT 100,
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
    )",
    'vaccines' => "CREATE TABLE vaccines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        dose_number INT NOT NULL,
        dose_day INT NOT NULL,
        scheduled_date DATE,
        administered_date DATE,
        vaccine_brand VARCHAR(100) DEFAULT 'Rabies Vaccine',
        administered_by INT,
        lot_number VARCHAR(50),
        notes TEXT,
        status ENUM('scheduled', 'administered', 'missed', 'skipped') DEFAULT 'scheduled',
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
        FOREIGN KEY (administered_by) REFERENCES admins(id) ON DELETE SET NULL
    )",
    'inventory' => "CREATE TABLE inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vaccine_name VARCHAR(100) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        unit VARCHAR(20) DEFAULT 'vials',
        threshold_low INT DEFAULT 20,
        threshold_critical INT DEFAULT 10,
        last_restocked TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    'notifications' => "CREATE TABLE notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('info', 'warning', 'critical', 'success') DEFAULT 'info',
        title VARCHAR(150) NOT NULL,
        message TEXT,
        is_read TINYINT(1) DEFAULT 0,
        related_table VARCHAR(50),
        related_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

// Helper to check if a column exists
function getTableColumnsList($db, string $table): array {
    try {
        $stmt = $db->query("DESCRIBE `$table`");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower($row['Field'])] = $row;
        }
        return $cols;
    } catch (Exception $e) {
        return [];
    }
}

// Perform Migration and Admin Actions
$action_log = '';
if ($db && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['auto']))) {
    $action = $_POST['action'] ?? 'migrate';
    
    if ($action === 'migrate' || isset($_GET['auto'])) {
        $migrationExecuted = true;
        
        // Disable FK checks to safely alter/create tables
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        foreach ($expected_tables as $table => $createSql) {
        // 1. Check if table exists
        $tableExists = false;
        try {
            $db->query("SELECT 1 FROM `$table` LIMIT 1");
            $tableExists = true;
        } catch (Exception $e) {
            $tableExists = false;
        }
        
        if (!$tableExists) {
            // Table doesn't exist, create it
            try {
                $db->exec($createSql);
                $logs[] = [
                    'status' => 'success',
                    'message' => "Created missing table <strong>$table</strong> successfully."
                ];
                
                // If it's a table with seed data, insert default seeds if we just created it
                if ($table === 'admins') {
                    $db->exec("INSERT INTO admins (username, password_hash, full_name, role, avatar_initials) VALUES
                    ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Jane Doe', 'admin', 'JD'),
                    ('dr.aris', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Aris Santos', 'doctor', 'AS'),
                    ('nurse01', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse Maria Cruz', 'nurse', 'MC')");
                    $logs[] = ['status' => 'info', 'message' => "Seeded default administrator accounts."];
                } elseif ($table === 'inventory') {
                    $db->exec("INSERT INTO inventory (vaccine_name, quantity, unit, threshold_low, threshold_critical) VALUES
                    ('Rabies Vaccine (PCECV)', 80, 'vials', 30, 15),
                    ('Tetanus Toxoid', 15, 'vials', 25, 10),
                    ('Rabies Immunoglobulin (ERIG)', 40, 'vials', 20, 8),
                    ('Anti-Tetanus Serum', 35, 'vials', 15, 5)");
                    $logs[] = ['status' => 'info', 'message' => "Seeded initial inventory items."];
                }
            } catch (Exception $e) {
                $logs[] = [
                    'status' => 'error',
                    'message' => "Failed to create table <strong>$table</strong>: " . $e->getMessage()
                ];
            }
        } else {
            // Table exists, check columns
            $cols = getTableColumnsList($db, $table);
            
            // Fixes for specific tables
            if ($table === 'users') {
                if (!isset($cols['contact_number'])) {
                    // Check if 'contact' exists and rename it
                    if (isset($cols['contact'])) {
                        try {
                            $db->exec("ALTER TABLE users CHANGE contact contact_number VARCHAR(20) NOT NULL");
                            $db->exec("ALTER TABLE users ADD UNIQUE KEY unique_contact_number (contact_number)");
                            $logs[] = [
                                'status' => 'success',
                                'message' => "Renamed column <strong>contact</strong> to <strong>contact_number</strong> in <strong>users</strong> table."
                            ];
                        } catch (Exception $e) {
                            $logs[] = [
                                'status' => 'error',
                                'message' => "Failed to rename <strong>contact</strong> to <strong>contact_number</strong> in <strong>users</strong>: " . $e->getMessage()
                            ];
                        }
                    } else {
                        // contact doesn't exist either, add contact_number as nullable first to prevent errors if rows exist, or as not null default
                        try {
                            $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
                            if ($userCount > 0) {
                                $db->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) NULL");
                                $db->exec("ALTER TABLE users ADD UNIQUE KEY unique_contact_number (contact_number)");
                                $logs[] = [
                                    'status' => 'warning',
                                    'message' => "Added nullable <strong>contact_number</strong> column to <strong>users</strong> (table has $userCount existing rows)."
                                ];
                            } else {
                                $db->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) NOT NULL UNIQUE");
                                $logs[] = [
                                    'status' => 'success',
                                    'message' => "Added <strong>contact_number</strong> column to <strong>users</strong> table."
                                ];
                            }
                        } catch (Exception $e) {
                            $logs[] = [
                                'status' => 'error',
                                'message' => "Failed to add <strong>contact_number</strong> to <strong>users</strong>: " . $e->getMessage()
                            ];
                        }
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>users</strong>: column <strong>contact_number</strong> already exists."];
                }

                if (!isset($cols['email'])) {
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL");
                        $db->exec("ALTER TABLE users ADD UNIQUE KEY unique_email (email)");
                        $logs[] = [
                            'status' => 'success',
                            'message' => "Added column <strong>email</strong> to <strong>users</strong> table."
                        ];
                    } catch (Exception $e) {
                        $logs[] = [
                            'status' => 'error',
                            'message' => "Failed to add column <strong>email</strong> to <strong>users</strong>: " . $e->getMessage()
                        ];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>users</strong>: column <strong>email</strong> already exists."];
                }
            }
            
            if ($table === 'admins') {
                if (!isset($cols['app_login_token'])) {
                    try {
                        $db->exec("ALTER TABLE admins ADD COLUMN app_login_token VARCHAR(64) NULL");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>app_login_token</strong> to <strong>admins</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>app_login_token</strong> to <strong>admins</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>admins</strong>: column <strong>app_login_token</strong> already exists."];
                }
            }
            
            if ($table === 'queue') {
                if (!isset($cols['purpose'])) {
                    try {
                        $db->exec("ALTER TABLE queue ADD COLUMN purpose ENUM('first_evaluation', 'follow_up_dose') DEFAULT 'first_evaluation'");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>purpose</strong> to <strong>queue</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>purpose</strong> to <strong>queue</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>queue</strong>: column <strong>purpose</strong> already exists."];
                }
                
                if (!isset($cols['served_at'])) {
                    try {
                        $db->exec("ALTER TABLE queue ADD COLUMN served_at TIMESTAMP NULL");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>served_at</strong> to <strong>queue</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>served_at</strong> to <strong>queue</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>queue</strong>: column <strong>served_at</strong> already exists."];
                }
            }
            
            if ($table === 'appointments') {
                if (!isset($cols['appointment_type'])) {
                    try {
                        $db->exec("ALTER TABLE appointments ADD COLUMN appointment_type ENUM('first_evaluation', 'follow_up_dose') DEFAULT 'first_evaluation'");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>appointment_type</strong> to <strong>appointments</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>appointment_type</strong> to <strong>appointments</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>appointments</strong>: column <strong>appointment_type</strong> already exists."];
                }
                
                if (!isset($cols['sms_reminder_sent'])) {
                    try {
                        $db->exec("ALTER TABLE appointments ADD COLUMN sms_reminder_sent TINYINT(1) DEFAULT 0");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>sms_reminder_sent</strong> to <strong>appointments</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>sms_reminder_sent</strong> to <strong>appointments</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>appointments</strong>: column <strong>sms_reminder_sent</strong> already exists."];
                }

                if (!isset($cols['auto_booked'])) {
                    try {
                        $db->exec("ALTER TABLE appointments ADD COLUMN auto_booked TINYINT(1) DEFAULT 0");
                        $logs[] = ['status' => 'success', 'message' => "Added column <strong>auto_booked</strong> to <strong>appointments</strong> table."];
                    } catch (Exception $e) {
                        $logs[] = ['status' => 'error', 'message' => "Failed to add <strong>auto_booked</strong> to <strong>appointments</strong>: " . $e->getMessage()];
                    }
                } else {
                    $logs[] = ['status' => 'ok', 'message' => "Table <strong>appointments</strong>: column <strong>auto_booked</strong> already exists."];
                }
            }
        }
        
        // Re-enable FK checks
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $hash = password_hash('password', PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
                $stmt->execute([':h' => $hash, ':id' => $userId]);
                $action_log = "Successfully reset password for user ID $userId to 'password'!";
            } catch (Exception $e) {
                $action_log = "Error resetting password: " . $e->getMessage();
            }
        }
    } elseif ($action === 'create_user') {
        $name = trim($_POST['full_name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? 'password';
        if ($name && $contact) {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO users (full_name, contact_number, email, password_hash) VALUES (:n, :c, :e, :h)");
                $stmt->execute([':n' => $name, ':c' => $contact, ':e' => $email ?: null, ':h' => $hash]);
                $new_id = $db->lastInsertId();
                
                // Create patient profile
                $code = 'P-' . rand(1000, 9999);
                $stmt2 = $db->prepare("INSERT INTO patients (user_id, patient_code, animal_type, animal_ownership, bite_date, body_location, category) VALUES (:uid, :code, 'Dog', 'Pet', :date, 'Arm', 'II')");
                $stmt2->execute([':uid' => $new_id, ':code' => $code, ':date' => date('Y-m-d')]);
                
                $action_log = "Created user '$name' ($contact) with password '$password' and patient code '$code'!";
            } catch (Exception $e) {
                $action_log = "Error creating user: " . $e->getMessage();
            }
        } else {
            $action_log = "Name and contact number are required!";
        }
    }
}

// Fetch users
$users = [];
if ($db) {
    try {
        $users = $db->query("SELECT id, full_name, contact_number, email FROM users ORDER BY id DESC LIMIT 15")->fetchAll();
    } catch (Exception $e) {
        // Table users might not exist or be mismatching
    }
}

// Run basic dry-run status checks to present in UI
$schema_status = [];
$all_healthy = true;

if ($db) {
    foreach (array_keys($expected_tables) as $table) {
        $tableExists = false;
        try {
            $db->query("SELECT 1 FROM `$table` LIMIT 1");
            $tableExists = true;
        } catch (Exception $e) {
            $tableExists = false;
        }
        
        if (!$tableExists) {
            $schema_status[$table] = [
                'status' => 'missing',
                'message' => 'Table is missing entirely.'
            ];
            $all_healthy = false;
        } else {
            $cols = getTableColumnsList($db, $table);
            $missing = [];
            
            if ($table === 'users') {
                if (!isset($cols['contact_number'])) $missing[] = 'contact_number';
                if (!isset($cols['email'])) $missing[] = 'email';
            }
            if ($table === 'admins' && !isset($cols['app_login_token'])) {
                $missing[] = 'app_login_token';
            }
            if ($table === 'queue') {
                if (!isset($cols['purpose'])) $missing[] = 'purpose';
                if (!isset($cols['served_at'])) $missing[] = 'served_at';
            }
            if ($table === 'appointments') {
                if (!isset($cols['appointment_type'])) $missing[] = 'appointment_type';
                if (!isset($cols['sms_reminder_sent'])) $missing[] = 'sms_reminder_sent';
                if (!isset($cols['auto_booked'])) $missing[] = 'auto_booked';
            }
            
            if (empty($missing)) {
                $schema_status[$table] = [
                    'status' => 'healthy',
                    'message' => 'All expected columns present (' . count($cols) . ' columns).'
                ];
            } else {
                $schema_status[$table] = [
                    'status' => 'mismatch',
                    'message' => 'Missing columns: ' . implode(', ', $missing)
                ];
                $all_healthy = false;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Database System Sync | ABC Connect</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <style>
    :root {
      --bg: #0b1329;
      --surface: #111a36;
      --surface-hover: #17244a;
      --border: rgba(255, 255, 255, 0.08);
      --primary: #00f5d4;
      --primary-hover: #00d2b4;
      --primary-glow: rgba(0, 245, 212, 0.15);
      --secondary: #3b82f6;
      --text: #f8fafc;
      --text-muted: #94a3b8;
      
      --success: #10b981;
      --warning: #f59e0b;
      --error: #ef4444;
      --info: #06b6d4;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 24px;
    }

    .container {
      max-width: 720px;
      width: 100%;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 32px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 80px rgba(0, 245, 212, 0.03);
      position: relative;
      overflow: hidden;
    }

    .container::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--secondary), var(--primary));
    }

    header {
      text-align: center;
      margin-bottom: 32px;
    }

    h1 {
      font-family: 'Outfit', sans-serif;
      font-weight: 800;
      font-size: 28px;
      background: linear-gradient(135deg, #fff 0%, #a5b4fc 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      margin-bottom: 8px;
      letter-spacing: -0.5px;
    }

    header p {
      color: var(--text-muted);
      font-size: 15px;
    }

    .config-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
    }

    .config-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .config-label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
    }

    .config-value {
      font-size: 14px;
      font-family: monospace;
      font-weight: 600;
      word-break: break-all;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 99px;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
    }
    
    .status-healthy { background: rgba(16, 185, 129, 0.1); color: var(--success); }
    .status-missing { background: rgba(239, 68, 68, 0.1); color: var(--error); }
    .status-mismatch { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

    .checklist {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 32px;
    }

    .check-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      background: rgba(255, 255, 255, 0.02);
      border: 1px solid var(--border);
      border-radius: 12px;
      transition: background var(--transition-base);
    }

    .check-item:hover {
      background: rgba(255, 255, 255, 0.04);
    }

    .check-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .check-name {
      font-weight: 600;
      font-size: 15px;
      color: #fff;
    }

    .check-desc {
      font-size: 13px;
      color: var(--text-muted);
    }

    .btn {
      width: 100%;
      padding: 16px;
      border-radius: 12px;
      border: none;
      background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
      color: #0b1329;
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 20px var(--primary-glow);
    }

    .btn:active {
      transform: translateY(1px);
    }

    .migration-logs {
      margin-top: 32px;
      background: #070c1a;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      max-height: 240px;
      overflow-y: auto;
    }

    .log-title {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 12px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      padding-bottom: 8px;
    }

    .log-item {
      font-family: monospace;
      font-size: 13px;
      padding: 4px 0;
      border-bottom: 1px dotted rgba(255, 255, 255, 0.02);
      display: flex;
      align-items: flex-start;
      gap: 8px;
    }

    .log-item span {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: bold;
      text-transform: uppercase;
    }

    .log-success span { background: rgba(16, 185, 129, 0.15); color: var(--success); }
    .log-warning span { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
    .log-error span { background: rgba(239, 68, 68, 0.15); color: var(--error); }
    .log-info span { background: rgba(6, 182, 212, 0.15); color: var(--info); }
    .log-ok span { background: rgba(156, 163, 175, 0.15); color: #fff; }

    .action-links {
      display: flex;
      gap: 12px;
      margin-top: 24px;
      justify-content: center;
    }

    .action-link {
      color: var(--primary);
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: color 0.2s;
    }

    .action-link:hover {
      color: #fff;
    }
  </style>
</head>
<body>

<div class="container">
  <header>
    <h1>Database Health Control Panel</h1>
    <p>Check, repair, and upgrade schemas for the ABC Connect system</p>
  </header>

  <?php if ($error): ?>
    <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--error); border-radius: 12px; padding: 16px; margin-bottom: 24px; color: var(--error); display: flex; align-items: center; gap: 8px;">
      <span class="material-symbols-outlined">report</span>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php else: ?>
    <div class="config-card">
      <div class="config-item">
        <span class="config-label">Host</span>
        <span class="config-value"><?= htmlspecialchars(DB_HOST) ?></span>
      </div>
      <div class="config-item">
        <span class="config-label">Database</span>
        <span class="config-value"><?= htmlspecialchars(DB_NAME) ?></span>
      </div>
      <div class="config-item">
        <span class="config-label">Status</span>
        <div>
          <span class="status-badge status-healthy" style="padding: 2px 8px; font-size: 10px;">
            Connected
          </span>
        </div>
      </div>
    </div>

    <div class="checklist">
      <?php foreach ($schema_status as $table => $status): ?>
        <div class="check-item">
          <div class="check-info">
            <div class="check-name"><?= htmlspecialchars($table) ?></div>
            <div class="check-desc"><?= htmlspecialchars($status['message']) ?></div>
          </div>
          <div>
            <span class="status-badge status-<?= $status['status'] ?>">
              <?= $status['status'] ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$all_healthy || isset($_GET['show_btn'])): ?>
      <form method="POST" action="">
        <button type="submit" class="btn">
          <span class="material-symbols-outlined">upgrade</span>
          Execute Schema Migrations
        </button>
      </form>
    <?php else: ?>
      <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px;">
        <span class="material-symbols-outlined" style="font-size: 48px; color: var(--success); margin-bottom: 8px;">check_circle</span>
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 18px; margin-bottom: 4px;">Database is Healthy!</h3>
        <p style="color: var(--text-muted); font-size: 14px;">All tables and columns match the expected system schemas.</p>
      </div>
    <?php endif; ?>

    <?php if ($migrationExecuted): ?>
      <div class="migration-logs">
        <div class="log-title">Migration Logs</div>
        <?php if (empty($logs)): ?>
          <div class="log-item log-info"><span>Info</span> No actions were required. Everything is already up-to-date!</div>
        <?php else: ?>
          <?php foreach ($logs as $log): ?>
            <div class="log-item log-<?= $log['status'] ?>">
              <span><?= $log['status'] ?></span>
              <div><?= $log['message'] ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <?php if ($db && !empty($users)): ?>
    <div style="margin-top: 32px; border-top: 1px solid var(--border); padding-top: 24px;">
      <h2 style="font-family: 'Outfit', sans-serif; font-size: 20px; margin-bottom: 16px; color: #fff; display: flex; align-items: center; gap: 8px;">
        <span class="material-symbols-outlined" style="color: var(--primary);">group</span>
        Registered Patients / Users
      </h2>
      
      <?php if ($action_log): ?>
        <div style="background: rgba(0, 245, 212, 0.08); border: 1px solid var(--primary); border-radius: 12px; padding: 12px; margin-bottom: 16px; font-size: 14px; color: var(--primary);">
          <?= htmlspecialchars($action_log) ?>
        </div>
      <?php endif; ?>

      <div style="max-height: 250px; overflow-y: auto; background: rgba(0,0,0,0.15); border-radius: 12px; border: 1px solid var(--border); margin-bottom: 24px;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
          <thead>
            <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.02);">
              <th style="padding: 12px 16px; color: var(--text-muted);">Name</th>
              <th style="padding: 12px 16px; color: var(--text-muted);">Contact Number</th>
              <th style="padding: 12px 16px; color: var(--text-muted);">Email</th>
              <th style="padding: 12px 16px; text-align: right; color: var(--text-muted);">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                <td style="padding: 12px 16px; font-weight: 600; color: #fff;"><?= htmlspecialchars($u['full_name']) ?></td>
                <td style="padding: 12px 16px; font-family: monospace;"><?= htmlspecialchars($u['contact_number'] ?: 'N/A') ?></td>
                <td style="padding: 12px 16px; font-family: monospace;"><?= htmlspecialchars($u['email'] ?: 'N/A') ?></td>
                <td style="padding: 12px 16px; text-align: right;">
                  <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn" style="padding: 6px 12px; font-size: 12px; display: inline-flex; width: auto; font-family: inherit; margin: 0; box-shadow: none;">
                      Reset Password
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3 style="font-family: 'Outfit', sans-serif; font-size: 16px; margin-bottom: 12px; color: #fff;">Create Test Patient Account</h3>
      <form method="POST" style="display: flex; flex-direction: column; gap: 12px; background: rgba(255,255,255,0.01); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 24px;">
        <input type="hidden" name="action" value="create_user">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; text-transform: uppercase; color: var(--text-muted);">Full Name</label>
            <input type="text" name="full_name" placeholder="John Doe" required style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 6px; padding: 8px; color: #fff; font-family: inherit;">
          </div>
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; text-transform: uppercase; color: var(--text-muted);">Contact Number</label>
            <input type="text" name="contact" placeholder="09123456789" required style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 6px; padding: 8px; color: #fff; font-family: inherit;">
          </div>
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; text-transform: uppercase; color: var(--text-muted);">Email Address</label>
            <input type="email" name="email" placeholder="john@example.com" style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 6px; padding: 8px; color: #fff; font-family: inherit;">
          </div>
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; text-transform: uppercase; color: var(--text-muted);">Password</label>
            <input type="text" name="password" value="password" required style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 6px; padding: 8px; color: #fff; font-family: inherit;">
          </div>
        </div>
        <button type="submit" class="btn" style="width: auto; align-self: flex-start; padding: 10px 20px; font-size: 14px;">
          Create User Account
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div class="action-links">
    <a href="admin/dashboard.php" class="action-link">
      <span class="material-symbols-outlined" style="font-size: 16px;">dashboard</span>
      Go to Admin Dashboard
    </a>
    <span style="color: var(--border);">|</span>
    <a href="login.php" class="action-link">
      <span class="material-symbols-outlined" style="font-size: 16px;">login</span>
      Go to Patient Login
    </a>
  </div>
</div>

</body>
</html>
