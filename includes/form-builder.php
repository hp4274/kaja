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
    if (intakeSectionIsFixed($existing['section'])) {
        throw new RuntimeException(
            $existing['section'] . ' is a fixed section and cannot be edited.'
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

/**
 * Add a question to a section.
 *
 * Yes or no, required, at the end of its section. That is the only kind of
 * question this form asks that anyone adds: the shape is fixed, so the admin
 * is asked for the one thing that actually varies -- the wording.
 *
 * The field id is derived from the wording rather than typed. It is a storage
 * key, not something a person should have to invent, and a hand-typed one that
 * collides with an existing question silently overwrites an answer.
 */
function addFormQuestion(PDO $db, $version, $section, $label) {
    $version = (int) $version;
    $section = trim((string) $section);
    $label   = trim((string) $label);

    if ($label === '') {
        throw new InvalidArgumentException('A question needs some wording.');
    }
    if (formVersionIsInUse($db, $version)) {
        throw new RuntimeException(
            'Version ' . $version . ' has already been answered. Publish a new version to change it.'
        );
    }
    if (intakeSectionIsFixed($section)) {
        throw new RuntimeException($section . ' is a fixed section and cannot be added to.');
    }

    $all = formQuestions($db, $version);
    if (!$all) {
        throw new RuntimeException('That version has no questions.');
    }

    $sectionExists = false;
    $lastOfSection = null;
    $taken         = [];
    foreach ($all as $q) {
        $taken[$q['field_id']] = true;
        if ($q['section'] === $section) {
            $sectionExists = true;
            $lastOfSection = (int) $q['sort_order'];
        }
    }
    if (!$sectionExists) {
        throw new RuntimeException('There is no section called ' . $section . ' in version ' . $version . '.');
    }

    $fieldId = formFieldIdFrom($label, $taken);

    $db->prepare('
        INSERT INTO `form_questions`
            (`form_version`,`field_id`,`section`,`label`,`field_type`,`is_required`,`sort_order`)
        VALUES (:v,:fid,:sec,:label,"yesno",1,:sort)
    ')->execute([
        ':v'     => $version,
        ':fid'   => $fieldId,
        ':sec'   => $section,
        ':label' => $label,
        // Just past the last question of its section, inside the gap seeding
        // left. The next drag renumbers the version cleanly anyway.
        ':sort'  => $lastOfSection + 1,
    ]);

    return ['id' => (int) $db->lastInsertId(), 'field_id' => $fieldId];
}

/**
 * A storage key from a question's wording: lowercase, words joined by
 * underscores, never starting with a digit, never colliding with one already
 * taken on this version.
 */
function formFieldIdFrom($label, array $taken) {
    $slug = strtolower(trim((string) $label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    $slug = substr($slug, 0, 44);

    if ($slug === '' || ctype_digit($slug[0])) {
        $slug = 'q_' . $slug;
    }
    $slug = rtrim($slug, '_');

    if (!isset($taken[$slug])) {
        return $slug;
    }
    // A collision is not an error -- two questions can reasonably read alike --
    // but it must never resolve to the same key, which would overwrite an
    // answer with another question's.
    for ($n = 2; $n < 500; $n++) {
        $candidate = $slug . '_' . $n;
        if (!isset($taken[$candidate])) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not find a free field id for that wording.');
}

/**
 * Put one section's questions in the given order.
 *
 * The admin used to type a number into every row and press Save on each one,
 * which is a sort key spelled out by hand: two rows could hold the same number,
 * a gap could be closed by accident, and nothing said what the result would be.
 * Dragging says the order directly, and the numbers are derived from it here.
 *
 * The whole version is renumbered in one pass rather than just this section,
 * so section order -- which formSchemaFromDb() reads from the first question in
 * each -- cannot drift when one section's numbers are rewritten.
 */
function reorderFormQuestions(PDO $db, $version, $section, array $orderedIds) {
    $version = (int) $version;

    if (formVersionIsInUse($db, $version)) {
        throw new RuntimeException(
            'Version ' . $version . ' has already been answered. Publish a new version to change it.'
        );
    }
    if (intakeSectionIsFixed($section)) {
        throw new RuntimeException($section . ' is a fixed section and cannot be reordered.');
    }

    $all = formQuestions($db, $version);
    if (!$all) {
        throw new RuntimeException('That version has no questions.');
    }

    // The ids offered must be exactly this section's, no more and no fewer:
    // a partial list would silently drop whatever it left out to the end.
    $inSection = [];
    foreach ($all as $q) {
        if ($q['section'] === $section) {
            $inSection[] = (int) $q['id'];
        }
    }
    $offered = array_values(array_unique(array_map('intval', $orderedIds)));
    sort($inSection);
    $check = $offered;
    sort($check);
    if ($check !== $inSection) {
        throw new InvalidArgumentException('The order given does not match the questions in ' . $section . '.');
    }

    // Rebuild the whole run: sections keep the order they are in now, and the
    // target section takes the order just given.
    $bySection = [];
    $order     = [];
    foreach ($all as $q) {
        if (!isset($bySection[$q['section']])) {
            $bySection[$q['section']] = [];
            $order[] = $q['section'];
        }
        $bySection[$q['section']][] = (int) $q['id'];
    }
    $bySection[$section] = $offered;

    $stmt = $db->prepare('UPDATE `form_questions` SET `sort_order` = :s WHERE `id` = :id AND `form_version` = :v');
    $n    = 0;

    $db->beginTransaction();
    try {
        foreach ($order as $title) {
            foreach ($bySection[$title] as $id) {
                // Gaps of ten, the same as seeding: a question can still be
                // dropped between two others without renumbering the world.
                $stmt->execute([':s' => ++$n * 10, ':id' => $id, ':v' => $version]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $n;
}

/**
 * Put the sections back in the order the shipped schema defines.
 *
 * Section order is read from the first question in each section, so a hand-
 * typed sort number could drop one section into the middle of another: on this
 * install the emergency contact had drifted to sit after Preferences, in the
 * middle of the form, because someone typed a 14 next to a 10.
 *
 * Within a section the current order is kept -- that part is the admin's own
 * arrangement and there is nothing wrong with it. Only the sections move, and
 * everything is renumbered cleanly on the way out.
 */
function normaliseFormOrder(PDO $db, $version) {
    $version = (int) $version;

    if (formVersionIsInUse($db, $version)) {
        throw new RuntimeException(
            'Version ' . $version . ' has already been answered. Publish a new version to change it.'
        );
    }

    $all = formQuestions($db, $version);
    if (!$all) {
        throw new RuntimeException('That version has no questions.');
    }

    $bySection = [];
    $present   = [];
    foreach ($all as $q) {
        if (!isset($bySection[$q['section']])) {
            $bySection[$q['section']] = [];
            $present[] = $q['section'];
        }
        $bySection[$q['section']][] = (int) $q['id'];
    }

    // The shipped order first, then anything the admin has since added, in the
    // order it currently sits -- a section we do not recognise still has to
    // land somewhere predictable.
    $order = [];
    foreach (intakeSchemaShipped($version) as $section) {
        if (isset($bySection[$section['title']]) && !in_array($section['title'], $order, true)) {
            $order[] = $section['title'];
        }
    }
    foreach ($present as $title) {
        if (!in_array($title, $order, true)) {
            $order[] = $title;
        }
    }

    $stmt = $db->prepare('UPDATE `form_questions` SET `sort_order` = :s WHERE `id` = :id AND `form_version` = :v');
    $n    = 0;

    $db->beginTransaction();
    try {
        foreach ($order as $title) {
            foreach ($bySection[$title] as $id) {
                $stmt->execute([':s' => ++$n * 10, ':id' => $id, ':v' => $version]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $order;
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
