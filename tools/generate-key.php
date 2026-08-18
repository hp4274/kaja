<?php
/**
 * Prints a fresh encryption key. Paste it into db-config.php once.
 *
 *   php tools/generate-key.php
 *
 * Generating a NEW key when one is already in use makes every existing intake
 * record unreadable. There is no re-key path: this is a one-time setup step.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

echo "Add this line to db-config.php, then never change it:\n\n";
echo "define('INTAKE_ENCRYPTION_KEY', '" . bin2hex(random_bytes(32)) . "');\n\n";
echo "db-config.php is untracked by git. Back this key up somewhere other than\n";
echo "the database, because a database backup alone cannot restore it.\n";
