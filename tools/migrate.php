<?php
/**
 * Idempotent schema update: session accept/decline, 30-minute reminder.
 *
 * Safe to run repeatedly: every step checks for its own presence first, so
 * this never drops or rewrites existing data. Only the steps added since the last
 * production release are kept. (schema.sql has the full schema for fresh installs.)
 *
 *   php tools/migrate.php
 *
 * Command line only: a schema-changing script has no business being web-reachable.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once dirname(__DIR__) . '/db-config.php';

$db = getDbConnection();
$applied = [];
$skipped = [];

function tableExists(PDO $db, $table) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, $table, $column) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * True when a named index is already on the table.
 * ADD INDEX IF NOT EXISTS is not portable across the MySQL/MariaDB builds this
 * ships on, so the presence check happens here instead.
 */
/** The declared type of a column, so an ENUM can be inspected before it is changed. */
function columnType(PDO $db, $table, $column) {
    $stmt = $db->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn();
}

function indexExists(PDO $db, $table, $index) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i
    ");
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** True when an ENUM column already offers $value, so we can skip the ALTER. */
function enumHasValue(PDO $db, $table, $column, $value) {
    $stmt = $db->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    $type = $stmt->fetchColumn();
    if ($type === false) {
        return false;
    }
    return strpos($type, "'" . $value . "'") !== false;
}

function step($label, $condition, callable $work) {
    global $db, $applied, $skipped;
    if (!$condition) {
        $skipped[] = $label;
        return;
    }
    $work($db);
    $applied[] = $label;
}

try {
    // Client can decline a booked session from the emailed link. 'rejected'
    // still holds the slot until the admin reschedules or cancels it.
    step("add 'rejected' to `sessions`.`status`",
        tableExists($db, 'sessions') && !enumHasValue($db, 'sessions', 'status', 'rejected'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions` MODIFY `status`
                ENUM('pending','confirmed','rejected','completed','cancelled','no-show')
                NOT NULL DEFAULT 'pending'
            ");
        }
    );

    step('sessions.response_token and responded_at',
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'response_token'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                ADD COLUMN `response_token` CHAR(64) DEFAULT NULL,
                ADD COLUMN `responded_at` DATETIME DEFAULT NULL,
                ADD UNIQUE KEY `uniq_response_token` (`response_token`)
            ");
        }
    );

    step('sessions.reminder30_sent',
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'reminder30_sent'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD COLUMN `reminder30_sent` TINYINT(1) NOT NULL DEFAULT 0");
        }
    );

} catch (PDOException $e) {
    http_response_code(500);
    echo "MIGRATION FAILED\n" . $e->getMessage() . "\n";
    echo "\nApplied before failure:\n";
    foreach ($applied as $a) { echo "  + {$a}\n"; }
    exit(1);
}

echo "Migration complete.\n\n";
echo "Applied (" . count($applied) . "):\n";
foreach ($applied as $a) { echo "  + {$a}\n"; }
if (!$applied) { echo "  (nothing - schema already current)\n"; }
echo "\nSkipped (" . count($skipped) . "):\n";
foreach ($skipped as $s) { echo "  . {$s}\n"; }
