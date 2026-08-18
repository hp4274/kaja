<?php
/**
 * Merge two client records that are the same person.
 *
 * Additive, not destructive: everything the loser owned is repointed at the
 * survivor, and the loser is archived carrying a pointer to where its history
 * went. Nothing is deleted, so a merge made in error stays reconstructible.
 *
 * One transaction. A half-merged pair — sessions moved, notes not — is worse
 * than either outcome, because nothing on screen would show it happened.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/client-repo.php';

function mergeClients(PDO $db, $survivorId, $loserId, $userId = null) {
    $survivorId = (int) $survivorId;
    $loserId    = (int) $loserId;

    if ($survivorId === $loserId) {
        throw new InvalidArgumentException('A client cannot be merged into itself.');
    }

    $survivor = fetchClient($db, $survivorId);
    $loser    = fetchClient($db, $loserId);

    if ($survivor === null) {
        throw new RuntimeException('The client to keep was not found.');
    }
    if ($loser === null) {
        throw new RuntimeException('The client to merge was not found, or is already archived.');
    }

    $moved = ['sessions' => 0, 'notes' => 0, 'payments' => 0, 'documents' => 0];

    $db->beginTransaction();
    try {
        foreach ([
            'sessions'         => 'sessions',
            'client_notes'     => 'notes',
            'client_fees'      => 'payments',
            'client_documents' => 'documents',
        ] as $table => $key) {
            $stmt = $db->prepare('UPDATE `' . $table . '` SET `client_id` = :new WHERE `client_id` = :old');
            $stmt->execute([':new' => $survivorId, ':old' => $loserId]);
            $moved[$key] = $stmt->rowCount();
        }

        // The lead and any intake link that produced the loser now point at the
        // survivor, so nothing sends the admin back to an archived record.
        $db->prepare('UPDATE `leads` SET `client_id` = :new WHERE `client_id` = :old')
           ->execute([':new' => $survivorId, ':old' => $loserId]);

        $db->prepare('UPDATE `intake_links` SET `client_id` = :new WHERE `client_id` = :old')
           ->execute([':new' => $survivorId, ':old' => $loserId]);

        $db->prepare('UPDATE `clients` SET `archived_at` = NOW(), `merged_into_id` = :s WHERE `id` = :id')
           ->execute([':s' => $survivorId, ':id' => $loserId]);

        $desc = 'Merged client #' . $loserId . ' (' . $loser['first_name'] . ' ' . $loser['last_name'] . ')'
              . ' into #' . $survivorId . ': '
              . $moved['sessions'] . ' session(s), ' . $moved['notes'] . ' note(s), '
              . $moved['payments'] . ' payment(s), ' . $moved['documents'] . ' document(s)';

        $db->prepare('
            INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
            VALUES ("clients_merged", :d, "client", :rid)
        ')->execute([':d' => $desc, ':rid' => $survivorId]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $moved;
}
