-- ============================================================
-- ABC Connect System — Database Schema
-- Animal Bite Center Management System
-- ============================================================

-- CREATE DATABASE IF NOT EXISTS abc_connect_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE abc_connect_db;

-- ============================================================
-- ADMINS
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'doctor', 'nurse') DEFAULT 'nurse',
    avatar_initials VARCHAR(4),
    app_login_token VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- USERS (Patients)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    birthdate DATE,
    sex ENUM('Male', 'Female', 'Other') DEFAULT 'Male',
    contact_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(150) NULL UNIQUE,
    address TEXT,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PATIENTS (Bite Incident Records — one user can have many)
-- ============================================================
CREATE TABLE IF NOT EXISTS patients (
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
);

-- ============================================================
-- QUEUE (Live Queue Management)
-- ============================================================
CREATE TABLE IF NOT EXISTS queue (
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
);

-- ============================================================
-- APPOINTMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_type ENUM('first_evaluation', 'follow_up_dose') DEFAULT 'first_evaluation',
    dose_day INT DEFAULT 0 COMMENT '0=Day0, 3=Day3, 7=Day7, 14=Day14, 28=Day28',
    appointment_date DATE NOT NULL,
    time_slot VARCHAR(20),
    status ENUM('scheduled', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
    qr_token VARCHAR(64) UNIQUE COMMENT 'Secure token encoded in QR Entry Pass',
    auto_booked TINYINT(1) DEFAULT 0 COMMENT '1 if this was auto-reserved after previous dose',
    sms_reminder_sent TINYINT(1) DEFAULT 0 COMMENT '1 if tomorrow SMS reminder has been sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

-- ============================================================
-- DAILY CAP (Slot Capacity per Date)
-- ============================================================
CREATE TABLE IF NOT EXISTS daily_cap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cap_date DATE NOT NULL UNIQUE,
    max_slots INT NOT NULL DEFAULT 100 COMMENT 'Max appointments allowed for this date',
    updated_by INT COMMENT 'Admin who last changed the cap',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ============================================================
-- VACCINES (Dose Schedule & Tracking)
-- ============================================================
CREATE TABLE IF NOT EXISTS vaccines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    dose_number INT NOT NULL COMMENT '1=Day0, 2=Day3, 3=Day7, 4=Day14, 5=Day28',
    dose_day INT NOT NULL COMMENT '0, 3, 7, 14, 28',
    scheduled_date DATE,
    administered_date DATE,
    vaccine_brand VARCHAR(100) DEFAULT 'Rabies Vaccine',
    administered_by INT,
    lot_number VARCHAR(50),
    notes TEXT,
    status ENUM('scheduled', 'administered', 'missed', 'skipped') DEFAULT 'scheduled',
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (administered_by) REFERENCES admins(id) ON DELETE SET NULL
);

-- ============================================================
-- INVENTORY
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vaccine_name VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit VARCHAR(20) DEFAULT 'vials',
    threshold_low INT DEFAULT 20 COMMENT 'Alert when below this',
    threshold_critical INT DEFAULT 10 COMMENT 'Critical when below this',
    last_restocked TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- NOTIFICATIONS (System Alerts)
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('info', 'warning', 'critical', 'success') DEFAULT 'info',
    title VARCHAR(150) NOT NULL,
    message TEXT,
    is_read TINYINT(1) DEFAULT 0,
    related_table VARCHAR(50),
    related_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default Admin Account (password: Admin@1234)
INSERT INTO admins (username, password_hash, full_name, role, avatar_initials) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Jane Doe', 'admin', 'JD'),
('dr.aris', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Aris Santos', 'doctor', 'AS'),
('nurse01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nurse Maria Cruz', 'nurse', 'MC');

-- Note: default password hash above is 'password' for testing
-- In production, generate proper hashes

-- Vaccine Inventory
INSERT INTO inventory (vaccine_name, quantity, unit, threshold_low, threshold_critical) VALUES
('Rabies Vaccine (PCECV)', 80, 'vials', 30, 15),
('Tetanus Toxoid', 15, 'vials', 25, 10),
('Rabies Immunoglobulin (ERIG)', 40, 'vials', 20, 8),
('Anti-Tetanus Serum', 35, 'vials', 15, 5);

-- System Notifications
INSERT INTO notifications (type, title, message) VALUES
('success', 'Database Synced', 'Daily patient logs uploaded to central health repository.'),
('critical', 'Temperature Alert', 'Cold storage Unit B reached +8°C. Please check seal.'),
('warning', 'Low Stock', 'Tetanus Toxoid is running low. Current stock: 15 vials.');

-- Sample Users
INSERT INTO users (full_name, birthdate, sex, contact_number, email, address, password_hash) VALUES
('Mark Robertson', '1990-05-15', 'Male', '09171234567', 'mark@example.com', '123 Main St, Daet, Camarines Norte', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Sarah Chen', '1995-08-20', 'Female', '09281234567', 'sarah@example.com', '456 Oak Ave, Daet, Camarines Norte', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('James Wilson', '1985-03-10', 'Male', '09391234567', 'james@example.com', '789 Pine Rd, Daet, Camarines Norte', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Alice Thompson', '2000-11-25', 'Female', '09401234567', 'alice@example.com', '321 Cedar Blvd, Daet, Camarines Norte', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Sample Patients
INSERT INTO patients (user_id, patient_code, animal_type, animal_ownership, bite_date, body_location, category, registered_by) VALUES
(1, 'P-9012', 'Dog', 'Stray', CURDATE(), 'Right Hand', 'III', 1),
(2, 'P-9013', 'Cat', 'Pet', CURDATE(), 'Ankle', 'II', 1),
(3, 'P-8998', 'Dog', 'Stray', CURDATE() - INTERVAL 1 DAY, 'Shoulder', 'III', 2),
(4, 'P-8985', 'Dog', 'Pet', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Right Leg', 'II', 1);

-- Queue entries
INSERT INTO queue (patient_id, queue_number, status, purpose, assigned_to) VALUES
(1, 'Q-001', 'waiting', 'first_evaluation', NULL),
(2, 'Q-002', 'waiting', 'first_evaluation', NULL),
(3, 'Q-003', 'in_consultation', 'first_evaluation', 2),
(4, 'Q-004', 'vaccinated', 'follow_up_dose', 1);

-- Vaccine dose schedule for patient 4 (Alice - Day 3 done, tracking)
INSERT INTO vaccines (patient_id, dose_number, dose_day, scheduled_date, administered_date, vaccine_brand, administered_by, status) VALUES
(4, 1, 0, CURDATE() - INTERVAL 3 DAY, CURDATE() - INTERVAL 3 DAY, 'Rabies Vaccine (PCECV)', 1, 'administered'),
(4, 2, 3, CURDATE(), CURDATE(), 'Rabies Vaccine (PCECV)', 1, 'administered'),
(4, 3, 7, CURDATE() + INTERVAL 4 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(4, 4, 14, CURDATE() + INTERVAL 11 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(4, 5, 28, CURDATE() + INTERVAL 25 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled');

-- Vaccine schedule for patient 1 (Mark - Day 0 just registered)
INSERT INTO vaccines (patient_id, dose_number, dose_day, scheduled_date, administered_date, vaccine_brand, administered_by, status) VALUES
(1, 1, 0, CURDATE(), NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(1, 2, 3, CURDATE() + INTERVAL 3 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(1, 3, 7, CURDATE() + INTERVAL 7 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(1, 4, 14, CURDATE() + INTERVAL 14 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled'),
(1, 5, 28, CURDATE() + INTERVAL 28 DAY, NULL, 'Rabies Vaccine (PCECV)', NULL, 'scheduled');
