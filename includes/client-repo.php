<?php
/**
 * Every list read of `clients`.
 *
 * Archived rows are excluded here, once, rather than in each caller. A
 * soft-deleted client reappearing in a count because one query forgot the
 * filter is the exact failure soft delete exists to prevent.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/client-status.php';

function clientListSql(array $filters) {
    // Correlated subqueries rather than JOINs: joining sessions and notes would
    // multiply the client row by their counts, and every aggregate downstream
    // would silently be wrong.
    $sql = '
        SELECT c.*,
               (SELECT MIN(DATE(s.`start_time`)) FROM `sessions` s
                 WHERE s.`client_id` = c.`id`
                   AND s.`status` IN ("pending","confirmed")
                   AND s.`start_time` >= NOW())                  AS `next_appointment`,
               (SELECT MAX(n.`created_at`) FROM `client_notes` n
                 WHERE n.`client_id` = c.`id`)                   AS `last_note_at`,
               (SELECT COUNT(*) FROM `sessions` s2
                 WHERE s2.`client_id` = c.`id`)                  AS `session_count`
        FROM `clients` c
        WHERE c.`archived_at` IS NULL
    ';
    $params = [];

    $q = isset($filters['q']) ? trim($filters['q']) : '';
    if ($q !== '') {
        $sql .= ' AND (c.`first_name` LIKE :q1 OR c.`last_name` LIKE :q2
                       OR c.`email` LIKE :q3 OR c.`phone` LIKE :q4)';
        foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
            $params[':' . $k] = '%' . $q . '%';
        }
    }

    $status = isset($filters['status']) ? $filters['status'] : '';
    if ($status !== '' && $status !== 'all' && isValidClientStatus($status)) {
        $sql .= ' AND c.`status` = :status';
        $params[':status'] = $status;
    }

    $dir = (isset($filters['dir']) && strtolower($filters['dir']) === 'asc') ? 'ASC' : 'DESC';

    if (isset($filters['sort']) && $filters['sort'] === 'name') {
        $sql .= ' ORDER BY c.`first_name` ' . $dir . ', c.`last_name` ' . $dir;
    } else {
        // Last activity: the most recent of a note, an edit, or creation.
        $sql .= ' ORDER BY GREATEST(
                      COALESCE(`last_note_at`, c.`created_at`),
                      COALESCE(c.`updated_at`, c.`created_at`)
                  ) ' . $dir;
    }

    return ['sql' => $sql, 'params' => $params];
}

function fetchClients(PDO $db, array $filters) {
    $built = clientListSql($filters);
    $stmt  = $db->prepare($built['sql']);
    $stmt->execute($built['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** $includeArchived exists for the merge and audit paths only. */
function fetchClient(PDO $db, $id, $includeArchived = false) {
    $sql = 'SELECT * FROM `clients` WHERE `id` = :id';
    if (!$includeArchived) {
        $sql .= ' AND `archived_at` IS NULL';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => (int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function clientStatusCounts(PDO $db) {
    $counts = array_fill_keys(clientStatuses(), 0);
    $rows = $db->query('SELECT `status`, COUNT(*) AS n FROM `clients`
                        WHERE `archived_at` IS NULL GROUP BY `status`');
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['n'];
        }
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

/** Soft delete. The row stays; every list stops showing it. */
function archiveClient(PDO $db, $id, $mergedInto = null) {
    $db->prepare('UPDATE `clients` SET `archived_at` = NOW(), `merged_into_id` = :m
                  WHERE `id` = :id AND `archived_at` IS NULL')
       ->execute([':m' => $mergedInto === null ? null : (int) $mergedInto, ':id' => (int) $id]);
}

/**
 * Contact details only. Status has its own action so that every change to it is
 * logged; letting an edit form move it would lose that trail.
 */
function updateClientProfile(PDO $db, $id, array $fields) {
    $allowed = ['first_name', 'last_name', 'email', 'phone', 'city', 'occupation', 'dob', 'concern'];
    $set     = [];
    $params  = [':id' => (int) $id];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $fields)) {
            $set[] = '`' . $col . '` = :' . $col;
            $params[':' . $col] = ($fields[$col] === '') ? null : $fields[$col];
        }
    }
    if (!$set) {
        return;
    }

    $db->prepare('UPDATE `clients` SET ' . implode(', ', $set) . ' WHERE `id` = :id')
       ->execute($params);
}

/** Returns true only when the row actually moved, so nothing logs a no-op. */
function setClientStatus(PDO $db, $id, $status) {
    if (!isValidClientStatus($status)) {
        throw new InvalidArgumentException('Unknown client status: ' . $status);
    }
    $stmt = $db->prepare('UPDATE `clients` SET `status` = :s WHERE `id` = :id AND `status` <> :s2');
    $stmt->execute([':s' => $status, ':s2' => $status, ':id' => (int) $id]);
    return $stmt->rowCount() > 0;
}
