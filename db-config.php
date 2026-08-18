<?php
/**
 * Database Connection Configuration
 */

// Guarded so a caller that has already chosen a database — the test bootstrap
// pointing at kaja_db_test, for one — is not overridden here.
if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'kaja_db'); }
if (!defined('DB_USER')) { define('DB_USER', 'root'); }
if (!defined('DB_PASS')) { define('DB_PASS', ''); } // Default empty password for local development

function getDbConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Return JSON error response to AJAX requests
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed: ' . $e->getMessage()
        ]);
        exit;
    }
}
