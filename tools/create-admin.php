<?php
/**
 * Creates an admin login, or resets its password if the username exists.
 *
 *   php tools/create-admin.php <username> <email>
 *
 * The password is read from stdin, never the command line, so it stays out of
 * shell history and the process list.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require_once __DIR__ . '/../db-config.php';

if ($argc < 3) {
    fwrite(STDERR, "usage: php tools/create-admin.php <username> <email>\n");
    exit(1);
}
[, $username, $email] = $argv;

echo 'Password (12+ characters, visible as you type): ';
$password = trim((string) fgets(STDIN));

if (strlen($password) < 12) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}

getDbConnection()->prepare(
    'INSERT INTO users (username, email, password) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE password = VALUES(password)'
)->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

echo "Admin '$username' saved.\n";
