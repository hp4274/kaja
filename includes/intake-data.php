<?php
/**
 * The only code that touches clients.intake_data.
 *
 * Everything in that column is encrypted, and nothing else in the codebase
 * should know that or be able to decrypt it by accident. Keeping the cipher
 * behind one file is what makes "who can read intake answers" a question with
 * a short, checkable answer.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/intake-schema.php';

function saveClientIntakeData(PDO $db, $clientId, array $answers, $version) {
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Intake answers could not be encoded.');
    }

    $db->prepare('
        UPDATE `clients`
        SET `intake_data` = :data,
            `intake_form_version` = :ver,
            `intake_submitted_at` = NOW()
        WHERE `id` = :id
    ')->execute([
        ':data' => encryptSensitive($json),
        ':ver'  => (int) $version,
        ':id'   => (int) $clientId,
    ]);
}

function readClientIntakeData(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT `intake_data`, `intake_form_version`, `intake_submitted_at`
        FROM `clients` WHERE `id` = :id
    ');
    $stmt->execute([':id' => (int) $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['intake_data'])) {
        return null;
    }

    $json = decryptSensitive($row['intake_data']);
    if ($json === null) {
        // Wrong key, or a tampered row. Log it rather than rendering a blank
        // intake that would look like the person simply answered nothing.
        error_log('[intake-data] could not decrypt intake for client ' . (int) $clientId);
        return null;
    }

    $answers = json_decode($json, true);

    return [
        'version'      => (int) $row['intake_form_version'],
        'answers'      => is_array($answers) ? $answers : [],
        'submitted_at' => $row['intake_submitted_at'],
    ];
}

/**
 * Sections with label/answer pairs, ready to print.
 *
 * Conditional fields that were never revealed are omitted entirely. Showing
 * "Which medication: —" for someone who said they take none would read as an
 * unanswered question rather than one that was never asked.
 */
function renderClientIntake(PDO $db, $clientId) {
    $record = readClientIntakeData($db, $clientId);
    if ($record === null) {
        return [];
    }

    $out = [];
    foreach (intakeSchema($record['version']) as $section) {
        $answers = [];
        foreach ($section['fields'] as $field) {
            if (!intakeFieldIsVisible($field, $record['answers'])) {
                continue;
            }
            $raw = isset($record['answers'][$field['id']])
                ? trim((string) $record['answers'][$field['id']])
                : '';

            if ($field['type'] === 'checkbox') {
                $value = ($raw !== '' && $raw !== '0') ? 'Yes' : 'No';
            } elseif ($raw === '') {
                $value = '—';
            } elseif ($field['type'] === 'date') {
                $ts    = strtotime($raw);
                $value = $ts ? date('d M Y', $ts) : $raw;
            } else {
                $value = $raw;
            }

            $answers[] = ['label' => $field['label'], 'value' => $value, 'type' => $field['type']];
        }
        if ($answers) {
            $out[] = ['title' => $section['title'], 'answers' => $answers];
        }
    }

    return $out;
}

/**
 * Clients whose intake has landed but nobody has looked at yet. Oldest first:
 * the point of the list is whoever has been waiting longest to become
 * bookable.
 */
function clientsAwaitingReview(PDO $db) {
    return $db->query('
        SELECT `id`, `first_name`, `last_name`, `email`, `intake_submitted_at`
        FROM `clients`
        WHERE `status` = "review"
        ORDER BY `intake_submitted_at` ASC, `id` ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}
