<?php
/**
 * One-time backfill: the `patient-intake` archive into clients.intake_data.
 *
 *   php tools/backfill-intake.php          report only
 *   php tools/backfill-intake.php --write  actually write
 *
 * Idempotent: a client that already has intake_data is skipped, so a second
 * run cannot overwrite a real submission with an older archive row.
 *
 * Everything backfilled is version 1. Those people answered the form as it was
 * then, and claiming otherwise would put version 2 labels on version 1
 * answers.
 */

require_once __DIR__ . '/../includes/intake-schema.php';

/** Map one legacy row onto the v1 answer set. */
function backfillIntakeRow(array $row) {
    $answers = [];
    foreach (intakeSchemaFields(1) as $id => $field) {
        $answers[$id] = isset($row[$id]) ? (string) $row[$id] : '';
    }
    return $answers;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/../includes/intake-data.php';

    $write = in_array('--write', $argv, true);
    $db    = getDbConnection();

    // The alias must not collide with anything in pi.*. `patient-intake` has
    // its own client_id column, and selecting it after the alias silently
    // overwrites it with NULL -- which makes the UPDATE below match no rows
    // and the whole backfill quietly do nothing.
    $rows = $db->query('
        SELECT c.`id` AS backfill_client_id, pi.*
        FROM `clients` c
        JOIN `patient-intake` pi ON pi.`id` = c.`patient_intake_id`
        WHERE c.`intake_data` IS NULL
    ')->fetchAll(PDO::FETCH_ASSOC);

    echo count($rows) . ' client(s) to backfill' . PHP_EOL;

    foreach ($rows as $row) {
        $clientId = (int) $row['backfill_client_id'];
        if ($write) {
            saveClientIntakeData($db, $clientId, backfillIntakeRow($row), 1);
        }
        echo '  client #' . $clientId . ($write ? ' written' : ' (dry run)') . PHP_EOL;
    }

    if (!$write && $rows) {
        echo PHP_EOL . 'Nothing was written. Re-run with --write to apply.' . PHP_EOL;
    }
}
