<?php
/**
 * Database + secrets configuration -- TEMPLATE. The one place config lives.
 *
 * Copy to db-config.php (gitignored, never committed) on each install.
 *
 * PRODUCTION: leave the blanks and set real values as environment variables
 * (Apache SetEnv, PHP-FPM env[], or the host panel) so no secret sits in the
 * web root:
 *
 *   APP_ENV  DB_HOST  DB_NAME  DB_USER  DB_PASS
 *   INTAKE_ENCRYPTION_KEY  SMTP_HOST  SMTP_PORT  SMTP_USER  SMTP_PASS
 *
 * APP_ENV is production unless the code sits under a XAMPP folder (override with
 * the APP_ENV variable). Not derived from the Host header: a client controls that. Production refuses to start with a root or
 * blank-password database login, and logs an error if the encryption key is
 * missing.
 *
 * LOCAL DEV: fill the values; a XAMPP path is detected as development.
 * Key: `php tools/generate-key.php`, once, never changed. Back it up outside
 * the database -- a DB backup alone cannot restore the encrypted intake data.
 * Gmail SMTP_PASS must be an App Password: https://myaccount.google.com/apppasswords
 */

$_local = [
    'APP_ENV'               => stripos(__DIR__, 'xampp') !== false ? 'development' : 'production',
    'DB_HOST'               => 'localhost',
    'DB_NAME'               => 'rewirewithkajal',
    'DB_USER'               => 'rewire_user',
    'DB_PASS'               => '123456',
    'INTAKE_ENCRYPTION_KEY' => '',
    'SMTP_HOST'             => 'smtp.gmail.com',
    'SMTP_PORT'             => 587,
    'SMTP_USER'             => 'harshlpatel.4274@gmail.com',
    'SMTP_PASS'             => 'ttkbisjsvghapcem',
];

// Environment wins over this file. Guarded so a caller that already chose a
// value (a CLI tool pointing DB_NAME elsewhere) is not overridden.
foreach ($_local as $_k => $_v) {
    if (defined($_k)) { continue; }
    $_env = getenv($_k);
    $_val = ($_env !== false && $_env !== '') ? $_env : $_v;
    define($_k, $_k === 'SMTP_PORT' ? (int) $_val : $_val);
}
unset($_local, $_k, $_v, $_env, $_val);

define('APP_DEBUG', APP_ENV === 'development');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

function configFail($why) {
    error_log('[config] ' . $why);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $why . "\n"); exit(1); }
    http_response_code(500);
    exit('Service unavailable.');
}

if (!APP_DEBUG) {
    if (DB_NAME === '' || DB_USER === '' || DB_USER === 'root' || DB_PASS === '') {
        configFail('production needs DB_NAME, DB_USER (not root) and DB_PASS');
    }
    if (strlen(INTAKE_ENCRYPTION_KEY) < 64) {
        // Only intake needs the key; log loudly rather than take the site down.
        error_log('[config] INTAKE_ENCRYPTION_KEY missing (php tools/generate-key.php)');
    }
}

/**
 * Message safe to show a client. Domain errors pass through; database errors
 * carry table and column names, so they are logged and masked.
 */
function publicError(Throwable $e) {
    if ($e instanceof PDOException) {
        error_log('[db] ' . $e->getMessage());
        return 'A database error occurred.';
    }
    return $e->getMessage();
}

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
        error_log('[db] connection failed: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Service temporarily unavailable.']);
        exit;
    }
}
