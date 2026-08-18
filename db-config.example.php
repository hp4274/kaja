<?php
/**
 * Database Connection Configuration -- EXAMPLE
 *
 * Copy this to db-config.php and fill in the credentials, then run
 * `php tools/generate-key.php` and paste its line in place of the empty
 * INTAKE_ENCRYPTION_KEY below. db-config.php is gitignored on purpose: it
 * holds both the database password and the intake encryption key.
 */

// Guarded so a caller that has already chosen a database — the test bootstrap
// pointing at kaja_db_test, for one — is not overridden here.
if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'kaja_db'); }
if (!defined('DB_USER')) { define('DB_USER', 'root'); }
if (!defined('DB_PASS')) { define('DB_PASS', ''); } // Default empty password for local development

// Encryption key for clients.intake_data (AES-256-GCM). Generated once by
// tools/generate-key.php. This file is untracked; back the key up somewhere
// other than the database, because a database backup alone cannot restore it.
if (!defined('INTAKE_ENCRYPTION_KEY')) {
    define('INTAKE_ENCRYPTION_KEY', '');
}

// Outbound SMTP. Kept here rather than in the settings table on purpose: a
// password that round-trips through a web form and a database has two more
// places to leak from. This file is gitignored.
//
// For Gmail this must be an App Password, not the account password:
// https://myaccount.google.com/apppasswords
if (!defined('SMTP_HOST')) { define('SMTP_HOST', 'smtp.gmail.com'); }
if (!defined('SMTP_PORT')) { define('SMTP_PORT', 587); }
if (!defined('SMTP_USER')) { define('SMTP_USER', ''); }
if (!defined('SMTP_PASS')) { define('SMTP_PASS', ''); }

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
