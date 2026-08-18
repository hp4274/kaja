<?php
/**
 * The client note thread. Append-only, without exception.
 *
 * There is no update and no delete in this file, and there must never be one.
 * A note is a contemporaneous clinical record; editing it destroys the only
 * evidence of what was believed at the time. A correction is a new entry
 * pointing at the one it corrects, so both stay readable.
 */

require_once __DIR__ . '/../db-config.php';

function clientNoteKinds() {
    return ['session', 'administrative'];
}

function addClientNote(PDO $db, $clientId, $userId, $content, $kind = 'session', $corrects = null) {
    $content = trim($content);
    if ($content === '') {
        throw new InvalidArgumentException('A note cannot be empty.');
    }
    if (!in_array($kind, clientNoteKinds(), true)) {
        throw new InvalidArgumentException('Unknown note kind: ' . $kind);
    }

    if ($corrects !== null) {
        // A correction reaching across clients would splice one person's
        // history into another's.
        $owner = $db->prepare('SELECT `client_id` FROM `client_notes` WHERE `id` = :id');
        $owner->execute([':id' => (int) $corrects]);
        $ownerId = $owner->fetchColumn();

        if ($ownerId === false || (int) $ownerId !== (int) $clientId) {
            throw new InvalidArgumentException('A correction must reference a note on the same client.');
        }
    }

    $db->prepare('
        INSERT INTO `client_notes` (`client_id`,`user_id`,`content`,`note_kind`,`corrects_note_id`)
        VALUES (:c,:u,:body,:k,:corr)
    ')->execute([
        ':c'    => (int) $clientId,
        ':u'    => ($userId === null || $userId === '') ? null : (int) $userId,
        ':body' => $content,
        ':k'    => $kind,
        ':corr' => $corrects === null ? null : (int) $corrects,
    ]);

    return (int) $db->lastInsertId();
}

/** A correction is just a note that knows what it is correcting. */
function correctClientNote(PDO $db, $clientId, $userId, $content, $correctsNoteId) {
    return addClientNote($db, $clientId, $userId, $content, 'administrative', $correctsNoteId);
}

function clientNotes(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT n.`id`, n.`content`, n.`note_kind`, n.`corrects_note_id`, n.`created_at`,
               COALESCE(u.`username`, "System") AS `author`
        FROM `client_notes` n
        LEFT JOIN `users` u ON u.`id` = n.`user_id`
        WHERE n.`client_id` = :c
        ORDER BY n.`created_at` DESC, n.`id` DESC
    ');
    $stmt->execute([':c' => (int) $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Which notes have been superseded, so the UI can mark them. */
function correctedNoteIds(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT DISTINCT `corrects_note_id` FROM `client_notes`
        WHERE `client_id` = :c AND `corrects_note_id` IS NOT NULL
    ');
    $stmt->execute([':c' => (int) $clientId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'corrects_note_id'));
}
