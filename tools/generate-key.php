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

echo "Set this as INTAKE_ENCRYPTION_KEY (env var, or db-config.php for local dev), then never change it:\n\n";
echo bin2hex(random_bytes(32)) . "\n\n";
echo "Back it up somewhere other than the database, because a database backup\n";
echo "alone cannot restore it.\n";
