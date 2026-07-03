<?php
// Error reporting — show only fatal errors on screen; log everything else.
// Deprecation notices from third-party libraries (phpqrcode) are suppressed from display.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ============================================================
// ABC Connect — Database Configuration
// ============================================================

// ---- Environment Auto-Detection ----
// Checks if the website is running locally (localhost or 127.0.0.1) or live on InfinityFree
$isLocalhost = (php_sapi_name() === 'cli') 
    || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']) 
    || (isset($_SERVER['HTTP_HOST']) && preg_match('/^(localhost|127\.0\.0\.1):\d+$/', $_SERVER['HTTP_HOST']));

if ($isLocalhost) {
    // ---------------- LOCAL DEVELOPMENT (XAMPP) ----------------
    define('APP_BASE', '/abc_connect');
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'abc_connect_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // ---------------- LIVE DEPLOYMENT (INFINITYFREE) ----------------
    define('APP_BASE', '');
    
    // IMPORTANT: Make sure to check these in your InfinityFree Client Area -> MySQL Databases!
    define('DB_HOST', 'sql105.infinityfree.com'); // E.g., sql123.infinityfree.com
    define('DB_NAME', 'if0_42170880_rabies_shield'); // E.g., if0_42170880_xxxx
    define('DB_USER', 'if0_42170880');           // Your InfinityFree username
    define('DB_PASS', 'Cw88NcjNSKVKu');          // Your InfinityFree MySQL password
}
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Auto-initialize tables if 'admins' table doesn't exist
            $check = $pdo->query("SHOW TABLES LIKE 'admins'");
            if ($check->rowCount() === 0) {
                $schemaFile = __DIR__ . '/../database/abc_connect_db.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    
                    // Remove CREATE DATABASE and USE statements
                    $sql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $sql);
                    $sql = preg_replace('/USE [^;]+;/i', '', $sql);
                    
                    // Execute the queries one by one
                    $queries = explode(';', $sql);
                    foreach ($queries as $q) {
                        $q = trim($q);
                        if (empty($q)) continue;
                        if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $q)) {
                            continue;
                        }
                        $pdo->exec($q);
                    }
                }
            }
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
