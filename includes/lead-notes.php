<?php
/**
 * The notes thread on a lead.
 *
 * Notes are append-only: there is no edit and no delete. A note records what
 * someone believed at a moment, and rewriting it destroys the reason the
 * thread exists.
 */

require_once __DIR__ . '/../db-config.php';

function addLeadNote(PDO $db, $leadId, $userId, $content) {
    $content = trim($content);
    if ($content === '') {
        throw new InvalidArgumentException('A note cannot be empty.');
    }

    $stmt = $db->prepare('
        INSERT INTO `lead_notes` (`lead_id`, `user_id`, `content`)
        VALUES (:lead_id, :user_id, :content)
    ');
    $stmt->execute([
        ':lead_id' => (int) $leadId,
        ':user_id' => ($userId === null || $userId === '') ? null : (int) $userId,
        ':content' => $content,
    ]);

    return (int) $db->lastInsertId();
}

function leadNotes(PDO $db, $leadId) {
    $stmt = $db->prepare('
        SELECT n.`id`, n.`content`, n.`created_at`,
               COALESCE(u.`username`, "System") AS `author`
        FROM `lead_notes` n
        LEFT JOIN `users` u ON u.`id` = n.`user_id`
        WHERE n.`lead_id` = :lead_id
        ORDER BY n.`created_at` DESC, n.`id` DESC
    ');
    $stmt->execute([':lead_id' => (int) $leadId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
