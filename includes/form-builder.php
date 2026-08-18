<?php
/**
 * The form module: the intake question set, editable from the admin.
 *
 * `includes/intake-schema.php` remains the seed — it is where a version is
 * born — but once a version exists in `form_questions` the database is the
 * source of truth for labels, order, required flags and section grouping.
 *
 * Versions stay append-only. Publishing edits mints version N+1 and leaves
 * every earlier one untouched, because a link already sent and an answer
 * already given were both against a specific set of questions. Editing a live
 * version in place would silently change what a past client is recorded as
 * having been asked.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/intake-schema.php';

function formFieldTypes() {
    return ['text', 'tel', 'date', 'textarea', 'select', 'yesno', 'checkbox'];
}

/** Every version the database knows about, newest first. */
function formVersions(PDO $db) {
    $rows = $db->query('SELECT DISTINCT `form_version` FROM `form_questions` ORDER BY `form_version` DESC');
    return array_map('intval', array_column($rows->fetchAll(PDO::FETCH_ASSOC), 'form_version'));
}

function formVersionExists(PDO $db, $version) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM `form_questions` WHERE `form_version` = :v');
    $stmt->execute([':v' => (int) $version]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Copy a version out of the PHP schema into the database, once.
 *
 * This is how a version that predates the form module becomes editable
 * without rewriting history: the questions recorded are exactly the ones
 * intake-schema.php says were asked.
 */
function seedFormVersion(PDO $db, $version) {
    if (formVersionExists($db, $version)) {
        return 0;
    }

    $stmt = $db->prepare('
        INSERT INTO `form_questions`
            (`form_version`,`field_id`,`section`,`label`,`field_type`,`is_required`,
             `options`,`reveal_field`,`reveal_value`,`sort_order`)
        VALUES (:v,:fid,:sec,:label,:type,:req,:opts,:rf,:rv,:sort)
    ');

    $order = 0;
    foreach (intakeSchema($version) as $section) {
        foreach ($section['fields'] as $f) {
            $stmt->execute([
                ':v'     => (int) $version,
                ':fid'   => $f['id'],
                ':sec'   => $section['title'],
                ':label' => $f['label'],
                ':type'  => $f['type'],
                ':req'   => !empty($f['required']) ? 1 : 0,
                ':opts'  => isset($f['options']) ? json_encode($f['options']) : null,
                ':rf'    => isset($f['reveal_when']) ? $f['reveal_when']['field'] : null,
                ':rv'    => isset($f['reveal_when']) ? $f['reveal_when']['equals'] : null,
                ':sort'  => ++$order * 10,   // gaps, so a question can be moved between two others
            ]);
        }
    }
    return $order;
}

/** One version's questions, in display order. */
function formQuestions(PDO $db, $version) {
    $stmt = $db->prepare('
        SELECT * FROM `form_questions`
        WHERE `form_version` = :v
        ORDER BY `sort_order` ASC, `id` ASC
    ');
    $stmt->execute([':v' => (int) $version]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The same shape intakeSchema() returns, built from the database.
 *
 * Sections come out in the order their first question appears, so reordering
 * a question can move its whole section — which is what someone dragging it
 * would expect.
 */
function formSchemaFromDb(PDO $db, $version) {
    $sections = [];
    foreach (formQuestions($db, $version) as $q) {
        $title = $q['section'];
        if (!isset($sections[$title])) {
            $sections[$title] = ['title' => $title, 'fields' => []];
        }

        $field = [
            'id'       => $q['field_id'],
            'label'    => $q['label'],
            'type'     => $q['field_type'],
            'required' => (bool) $q['is_required'],
        ];
        if ($q['options'] !== null && $q['options'] !== '') {
            $decoded = json_decode($q['options'], true);
            if (is_array($decoded)) {
                $field['options'] = $decoded;
            }
        }
        if ($q['reveal_field']) {
            $field['reveal_when'] = ['field' => $q['reveal_field'], 'equals' => $q['reveal_value']];
        }

        $sections[$title]['fields'][] = $field;
    }
    return array_values($sections);
}

/**
 * Edit one question on a version.
 *
 * Refuses a version any client has already answered. That is the whole point
 * of versioning: those answers are recorded against these questions, and
 * changing the wording now would misrepresent what the person was asked.
 * Publish a new version instead.
 */
function updateFormQuestion(PDO $db, $questionId, array $fields) {
    $stmt = $db->prepare('SELECT * FROM `form_questions` WHERE `id` = :id');
    $stmt->execute([':id' => (int) $questionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new RuntimeException('Question not found.');
    }
    if (formVersionIsInUse($db, (int) $existing['form_version'])) {
        throw new RuntimeException(
            'Version ' . (int) $existing['form_version'] . ' has already been answered. '
          . 'Publish a new version to change it.'
        );
    }

    $allowed = ['label' => 's', 'section' => 's', 'field_type' => 't',
                'is_required' => 'b', 'sort_order' => 'i'];
    $set    = [];
    $params = [':id' => (int) $questionId];

    foreach ($allowed as $col => $kind) {
        if (!array_key_exists($col, $fields)) {
            continue;
        }
        $value = $fields[$col];

        if ($kind === 't' && !in_array($value, formFieldTypes(), true)) {
            throw new InvalidArgumentException('Unknown field type: ' . $value);
        }
        if ($kind === 's' && trim((string) $value) === '') {
            throw new InvalidArgumentException(ucfirst($col) . ' cannot be empty.');
        }

        $set[] = '`' . $col . '` = :' . $col;
        $params[':' . $col] = ($kind === 'b') ? (!empty($value) ? 1 : 0)
                            : (($kind === 'i') ? (int) $value : trim((string) $value));
    }

    if (!$set) {
        return false;
    }

    $db->prepare('UPDATE `form_questions` SET ' . implode(', ', $set) . ' WHERE `id` = :id')
       ->execute($params);
    return true;
}

/** True once any client's answers were recorded against this version. */
function formVersionIsInUse(PDO $db, $version) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM `clients` WHERE `intake_form_version` = :v');
    $stmt->execute([':v' => (int) $version]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    // A link already in someone's inbox is pinned to its version too: they are
    // about to answer these exact questions.
    $stmt = $db->prepare('
        SELECT COUNT(*) FROM `intake_links`
        WHERE `form_version` = :v AND `status` IN ("sent","opened","filled")
    ');
    $stmt->execute([':v' => (int) $version]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Copy a version into a new, higher one that can be edited freely.
 * Returns the new version number.
 */
function publishNewFormVersion(PDO $db, $fromVersion) {
    $fromVersion = (int) $fromVersion;
    if (!formVersionExists($db, $fromVersion)) {
        throw new RuntimeException('Version ' . $fromVersion . ' does not exist.');
    }

    $next = (int) $db->query('SELECT COALESCE(MAX(`form_version`), 0) + 1 FROM `form_questions`')
                     ->fetchColumn();

    $db->beginTransaction();
    try {
        $db->prepare('
            INSERT INTO `form_questions`
                (`form_version`,`field_id`,`section`,`label`,`field_type`,`is_required`,
                 `options`,`reveal_field`,`reveal_value`,`sort_order`)
            SELECT :new, `field_id`, `section`, `label`, `field_type`, `is_required`,
                   `options`, `reveal_field`, `reveal_value`, `sort_order`
            FROM `form_questions` WHERE `form_version` = :old
        ')->execute([':new' => $next, ':old' => $fromVersion]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $next;
}
