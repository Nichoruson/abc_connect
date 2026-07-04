<?php
// ============================================================
// ABC Connect — Reports Export Tool (CSV & PDF Print)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_admin_login();

$db = getDB();

// Input dates
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$format = $_GET['format'] ?? 'csv';

// Retrieve data
if ($format === 'csv') {
    // ---- CSV EXPORT PROCESSOR ----
    $stmt = $db->prepare("
        SELECT p.*, u.full_name, u.contact_number, u.birthdate, u.sex, u.address, u.email,
               a.full_name AS staff_name
        FROM patients p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN admins a ON p.registered_by = a.id
        WHERE DATE(p.created_at) BETWEEN :f AND :t
        ORDER BY p.created_at ASC
    ");
    $stmt->execute([':f' => $from, ':t' => $to]);
    $patients = $stmt->fetchAll();

    $vStmt = $db->prepare("SELECT * FROM vaccines WHERE patient_id = :pid ORDER BY dose_number ASC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ABC_Connect_Report_' . $from . '_to_' . $to . '.csv"');

    $output = fopen('php://output', 'w');

    // Output headers
    fputcsv($output, [
        'Patient Code', 'Full Name', 'Contact Number', 'Email', 'Sex', 'Birthdate', 
        'Animal Type', 'Animal Ownership', 'Bite Date', 'Body Location', 'Exposure Category', 
        'Dose 1 (Day 0) Status', 'Dose 1 Date',
        'Dose 2 (Day 3) Status', 'Dose 2 Date',
        'Dose 3 (Day 7) Status', 'Dose 3 Date',
        'Dose 4 (Day 14) Status', 'Dose 4 Date',
        'Dose 5 (Day 28) Status', 'Dose 5 Date',
        'Treatment Status', 'Registered By', 'Report Date'
    ]);

    foreach ($patients as $p) {
        $vStmt->execute([':pid' => $p['id']]);
        $doses = $vStmt->fetchAll();
        
        $doseCols = [];
        for ($dn = 1; $dn <= 5; $dn++) {
            $found = null;
            foreach ($doses as $d) {
                if ((int)$d['dose_number'] === $dn) {
                    $found = $d;
                    break;
                }
            }
            if ($found) {
                $doseCols[] = ucfirst($found['status']);
                $doseCols[] = $found['administered_date'] ?: $found['scheduled_date'] ?: 'N/A';
            } else {
                $doseCols[] = 'N/A';
                $doseCols[] = 'N/A';
            }
        }
        
        fputcsv($output, array_merge([
            $p['patient_code'],
            $p['full_name'],
            $p['contact_number'],
            $p['email'] ?: 'N/A',
            $p['sex'],
            $p['birthdate'] ?: 'N/A',
            $p['animal_type'],
            $p['animal_ownership'],
            $p['bite_date'],
            $p['body_location'],
            'Category ' . $p['category'],
        ], $doseCols, [
            ucfirst($p['status']),
            $p['staff_name'] ?: 'Self-Registered',
            date('Y-m-d', strtotime($p['created_at']))
        ]));
    }

    fclose($output);
    exit;

} else {
    // ---- PRINT FRIENDLY PDF REPORT ----
    
    // Summary statistics
    $stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN :f AND :t");
    $stmt->execute([':f' => $from, ':t' => $to]);
    $newPatients = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM vaccines WHERE administered_date BETWEEN :f AND :t AND status='administered'");
    $stmt->execute([':f' => $from, ':t' => $to]);
    $dosesGiven = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM queue WHERE DATE(queued_at) BETWEEN :f AND :t AND status='no_show'");
    $stmt->execute([':f' => $from, ':t' => $to]);
    $noShows = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE status='completed' AND DATE(created_at) BETWEEN :f AND :t");
    $stmt->execute([':f' => $from, ':t' => $to]);
    $completed = (int)$stmt->fetchColumn();

    $completionRate = $newPatients > 0 ? round(($completed / $newPatients) * 100) : 0;

    // Category breakdown
    $catBreakdown = $db->prepare("SELECT category, COUNT(*) AS cnt FROM patients WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY category ORDER BY category");
    $catBreakdown->execute([':f' => $from, ':t' => $to]);
    $catRows = $catBreakdown->fetchAll();

    // Animal breakdown
    $animalBreakdown = $db->prepare("SELECT animal_type, COUNT(*) AS cnt FROM patients WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY animal_type ORDER BY cnt DESC");
    $animalBreakdown->execute([':f' => $from, ':t' => $to]);
    $animalRows = $animalBreakdown->fetchAll();

    // Patients detailed list
    $patStmt = $db->prepare("
        SELECT p.*, u.full_name, u.contact_number,
               (SELECT COUNT(*) FROM vaccines WHERE patient_id = p.id AND status = 'administered') AS administered_count,
               (SELECT COUNT(*) FROM vaccines WHERE patient_id = p.id) AS total_doses
        FROM patients p
        JOIN users u ON p.user_id = u.id
        WHERE DATE(p.created_at) BETWEEN :f AND :t
        ORDER BY p.created_at ASC
    ");
    $patStmt->execute([':f' => $from, ':t' => $to]);
    $patientsList = $patStmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>ABC Connect Clinical Report (<?= $from ?> to <?= $to ?>)</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Inter', sans-serif;
                color: #2D3748;
                background: #FFFFFF;
                margin: 0;
                padding: 40px;
                line-height: 1.5;
            }
            .header {
                border-bottom: 2px solid #E2E8F0;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .title {
                font-size: 24px;
                font-weight: 700;
                color: #1A202C;
                margin: 0 0 6px 0;
            }
            .meta {
                font-size: 13px;
                color: #718096;
            }
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 40px;
            }
            .stat-card {
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 16px;
                background: #F7FAFC;
            }
            .stat-card__label {
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #718096;
                margin-bottom: 6px;
            }
            .stat-card__value {
                font-size: 24px;
                font-weight: 700;
                color: #2D3748;
            }
            .sections-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-bottom: 40px;
            }
            h3 {
                font-size: 16px;
                font-weight: 700;
                color: #1A202C;
                margin-top: 0;
                margin-bottom: 12px;
                border-bottom: 1px solid #E2E8F0;
                padding-bottom: 8px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                font-size: 13px;
                margin-bottom: 20px;
            }
            th, td {
                padding: 10px 12px;
                border-bottom: 1px solid #E2E8F0;
            }
            th {
                font-weight: 600;
                background: #EDF2F7;
                color: #4A5568;
            }
            .badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .badge--active { background: #EBF8FF; color: #2B6CB0; }
            .badge--completed { background: #C6F6D5; color: #22543D; }
            .badge--defaulted { background: #FED7D7; color: #9B2C2C; }
            .footer-note {
                text-align: center;
                font-size: 11px;
                color: #A0AEC0;
                margin-top: 50px;
                border-top: 1px solid #E2E8F0;
                padding-top: 16px;
            }
            @media print {
                body { padding: 0; }
                .no-print { display: none; }
                button { display: none; }
            }
        </style>
    </head>
    <body>
        <div style="display: flex; justify-content: space-between; align-items: center;" class="no-print">
            <span style="font-size: 13px; color: #718096;">ABC Connect — Print Preview</span>
            <button onclick="window.print()" style="padding: 8px 16px; background: #008f7a; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: inherit;">
                Print Report
            </button>
        </div>

        <div class="header" style="margin-top: 20px;">
            <h1 class="title">ABC Connect — Animal Bite Center Clinical Report</h1>
            <div class="meta">
                <span><strong>Reporting Period:</strong> <?= date('F j, Y', strtotime($from)) ?> to <?= date('F j, Y', strtotime($to)) ?></span>
                <span style="margin: 0 10px;">|</span>
                <span><strong>Generated:</strong> <?= date('M j, Y, h:i A') ?> (Manila Time)</span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card__label">New Patients</div>
                <div class="stat-card__value"><?= $newPatients ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__label">Doses Administered</div>
                <div class="stat-card__value"><?= $dosesGiven ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__label">Treatment Completed</div>
                <div class="stat-card__value"><?= $completed ?> (<?= $completionRate ?>%)</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__label">No-Shows</div>
                <div class="stat-card__value"><?= $noShows ?></div>
            </div>
        </div>

        <div class="sections-grid">
            <div>
                <h3>Exposure Category Breakdown</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th style="text-align: right;">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catRows as $cr): ?>
                        <tr>
                            <td>Category <?= htmlspecialchars($cr['category']) ?></td>
                            <td style="text-align: right; font-weight: 600;"><?= $cr['cnt'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($catRows)): ?><tr><td colspan="2">No data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3>Bite Incidents by Animal Type</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Animal Type</th>
                            <th style="text-align: right;">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($animalRows as $ar): ?>
                        <tr>
                            <td><?= htmlspecialchars($ar['animal_type']) ?></td>
                            <td style="text-align: right; font-weight: 600;"><?= $ar['cnt'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($animalRows)): ?><tr><td colspan="2">No data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <h3>Patient Logs & Treatment Status</h3>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Bite Date</th>
                    <th>Animal</th>
                    <th>Category</th>
                    <th>Doses Given</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patientsList as $p): ?>
                <tr>
                    <td style="font-family: monospace; font-weight: 600;"><?= htmlspecialchars($p['patient_code']) ?></td>
                    <td style="font-weight: 500;"><?= htmlspecialchars($p['full_name']) ?></td>
                    <td><?= htmlspecialchars($p['contact_number']) ?></td>
                    <td><?= $p['bite_date'] ?></td>
                    <td><?= htmlspecialchars($p['animal_type']) ?> (<?= htmlspecialchars($p['animal_ownership']) ?>)</td>
                    <td>Cat <?= htmlspecialchars($p['category']) ?></td>
                    <td><?= $p['administered_count'] ?> of <?= $p['total_doses'] ?></td>
                    <td>
                        <span class="badge badge--<?= $p['status'] ?>">
                            <?= htmlspecialchars($p['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($patientsList)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: #718096;">No patients registered during this period.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer-note">
            This document is generated automatically by ABC Connect. Confidential clinical record.
        </div>

        <script>
            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.print();
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
}
