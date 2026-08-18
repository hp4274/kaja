<?php
/**
 * Reads and writes against `intake_links`.
 *
 * Every lifecycle write carries its precondition in the WHERE clause. A SELECT
 * followed by an UPDATE would let two concurrent beacons interleave — one
 * reading 'opened', the other committing 'submitted', the first then writing
 * 'filled' over it. The database decides, not PHP.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/intake-status.php';

/**
 * Move a link forward. Returns true only when a row actually changed, so the
 * caller can tell "advanced" from "was already past this point".
 */
function advanceIntakeLink(PDO $db, $linkId, $to) {
    if (!isValidIntakeStatus($to)) {
        throw new InvalidArgumentException('Unknown intake status: ' . $to);
    }

    // The states this move is legal from, derived from the same rules the rest
    // of the module uses rather than hand-listed here.
    $from = [];
    foreach (intakeStatuses() as $candidate) {
        if (intakeCanAdvance($candidate, $to)) {
            $from[] = $candidate;
        }
    }
    if (!$from) {
        return false;
    }

    $stampColumn = [
        'opened'    => '`opened_at`',
        'filled'    => '`filled_at`',
        'submitted' => '`submitted_at`',
    ];

    $sql = 'UPDATE `intake_links` SET `status` = ?';
    if (isset($stampColumn[$to])) {
        // COALESCE keeps the FIRST time this happened. A second beacon must
        // not rewrite when the person actually started.
        $sql .= ', ' . $stampColumn[$to] . ' = COALESCE(' . $stampColumn[$to] . ', NOW())';
    }
    $sql .= ' WHERE `id` = ? AND `status` IN (' . implode(',', array_fill(0, count($from), '?')) . ')';

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$to, (int) $linkId], $from));

    return $stmt->rowCount() > 0;
}

/**
 * Autosaved partial answers. Working state, not a record: refused once the
 * form is submitted, and cleared when it is.
 */
function saveIntakeDraft(PDO $db, $linkId, array $answers) {
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $stmt = $db->prepare('
        UPDATE `intake_links` SET `draft_answers` = :json
        WHERE `id` = :id AND `status` IN ("sent","opened","filled")
    ');
    $stmt->execute([':json' => $json, ':id' => (int) $linkId]);

    return $stmt->rowCount() > 0;
}

function readIntakeDraft(PDO $db, $linkId) {
    $stmt = $db->prepare('SELECT `draft_answers` FROM `intake_links` WHERE `id` = :id');
    $stmt->execute([':id' => (int) $linkId]);
    $json = $stmt->fetchColumn();

    if ($json === false || $json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function clearIntakeDraft(PDO $db, $linkId) {
    $db->prepare('UPDATE `intake_links` SET `draft_answers` = NULL WHERE `id` = :id')
       ->execute([':id' => (int) $linkId]);
}

/**
 * Flip everything whose deadline has passed and that nobody finished.
 * Returns the number of rows moved, for the cron's log line.
 */
function expireOverdueIntakeLinks(PDO $db) {
    $stmt = $db->prepare('
        UPDATE `intake_links`
        SET `status` = "expired"
        WHERE `expires_at` < NOW()
          AND `status` IN ("sent","opened","filled")
    ');
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Unfinished, unreminded links past the threshold, newest link per lead only.
 * A resend leaves the old row behind; chasing it would nag someone who was
 * handed a fresh link an hour ago.
 */
function staleIntakeLinks(PDO $db, $hours) {
    $stmt = $db->prepare('
        SELECT il.`id`, il.`lead_id`, il.`token`, il.`expires_at`,
               l.`name`, l.`email`
        FROM `intake_links` il
        JOIN `leads` l ON l.`id` = il.`lead_id`
        WHERE il.`reminder_sent` = 0
          AND il.`status` IN ("sent","opened","filled")
          AND il.`created_at` < DATE_SUB(NOW(), INTERVAL :hours HOUR)
          AND il.`id` = (
              SELECT `id` FROM `intake_links`
              WHERE `lead_id` = il.`lead_id`
              ORDER BY `created_at` DESC, `id` DESC LIMIT 1
          )
    ');
    $stmt->bindValue(':hours', (int) $hours, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function markIntakeLinkReminded(PDO $db, $linkId) {
    $db->prepare('UPDATE `intake_links` SET `reminder_sent` = 1 WHERE `id` = :id')
       ->execute([':id' => (int) $linkId]);
}

/** The admin list: every link with the person it belongs to. */
function intakeLinksList(PDO $db, $status = '') {
    $sql = '
        SELECT il.*, l.`name`, l.`email`
        FROM `intake_links` il
        JOIN `leads` l ON l.`id` = il.`lead_id`
    ';
    $params = [];
    if ($status !== '' && $status !== 'all' && isValidIntakeStatus($status)) {
        $sql .= ' WHERE il.`status` = :status';
        $params[':status'] = $status;
    }
    $sql .= ' ORDER BY il.`created_at` DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
