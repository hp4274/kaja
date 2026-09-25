<?php
/**
 * Every read and write against `sessions`.
 *
 * The conflict guard lives here, and nothing else may write to the table. A
 * guard that can be bypassed is not a guard: the whole point is that there is
 * exactly one door.
 *
 * MySQL has no exclusion constraint — that is a PostgreSQL feature — so the
 * real protection is a check and an insert inside one transaction, with the
 * candidate range locked by SELECT ... FOR UPDATE. Checking outside a
 * transaction is a race: two bookings can both find the hour free before
 * either writes, and there is no constraint to catch it afterwards.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/session-status.php';
require_once __DIR__ . '/holidays.php';

/**
 * The exclusion window for a candidate booking: the session itself, widened by
 * buffer_minutes on each side.
 *
 * The buffer is applied to the candidate rather than to every stored row, so
 * changing the setting never retroactively makes existing bookings clash.
 */
function sessionWindowFor($startTime, $durationMinutes, $bufferMinutes = null) {
    if ($bufferMinutes === null) {
        $bufferMinutes = getSettingInt('buffer_minutes');
    }

    $startTs = strtotime($startTime);
    if ($startTs === false) {
        return null;
    }

    $endTs = $startTs + ((int) $durationMinutes * 60);

    return [
        'start'        => date('Y-m-d H:i:s', $startTs),
        'end'          => date('Y-m-d H:i:s', $endTs),
        'guard_start'  => date('Y-m-d H:i:s', $startTs - ((int) $bufferMinutes * 60)),
        'guard_end'    => date('Y-m-d H:i:s', $endTs + ((int) $bufferMinutes * 60)),
    ];
}

/**
 * The first session overlapping this range, or null when it is free.
 *
 * Two ranges overlap when each starts before the other ends. Touching ends do
 * not overlap, so back-to-back bookings are allowed.
 *
 * $lock must be true inside a booking transaction — it is what stops a second
 * booking reading the same free hour before the first one commits.
 */
function findSessionConflict(PDO $db, $guardStart, $guardEnd, $excludeId = 0, $lock = false) {
    $sql = '
        SELECT s.`id`, s.`start_time`, s.`end_time`, s.`status`,
               c.`first_name`, c.`last_name`
        FROM `sessions` s
        JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE s.`status` NOT IN ("cancelled","no-show")
          AND s.`start_time` < :guard_end
          AND s.`end_time`   > :guard_start
          AND s.`id` <> :exclude
        ORDER BY s.`start_time` ASC
        LIMIT 1
    ';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':guard_start' => $guardStart,
        ':guard_end'   => $guardEnd,
        ':exclude'     => (int) $excludeId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function sessionConflictMessage(array $conflict) {
    return 'That time clashes with ' . trim($conflict['first_name'] . ' ' . $conflict['last_name'])
         . ' at ' . date('d M Y, h:i A', strtotime($conflict['start_time'])) . '.';
}

/**
 * Book a session. Throws on any overlap rather than booking it anyway: two
 * people told to arrive at the same hour is not fixable after the fact.
 */
function createSession(PDO $db, $clientId, $startTime, $durationMinutes, $type = 'online', $seriesId = null) {
    $window = sessionWindowFor($startTime, $durationMinutes);
    if ($window === null) {
        throw new InvalidArgumentException('That is not a valid start time.');
    }
    if (!in_array($type, ['online', 'inperson'], true)) {
        throw new InvalidArgumentException('Unknown session type: ' . $type);
    }
    if (!$clientId) {
        throw new InvalidArgumentException('A session must belong to a client.');
    }

    // A closed day refuses a booking the same way a clash does -- a
    // RuntimeException -- so a recurrence that lands on a holiday skips that
    // occurrence and keeps the rest, which is what generateSeries() already
    // does with a clash.
    $day = date('Y-m-d', strtotime($window['start']));
    if (isHoliday($db, $day)) {
        $why = holidayReason($db, $day);
        throw new RuntimeException(date('d M Y', strtotime($day)) . ' is marked a holiday'
            . ($why ? ' (' . $why . ')' : '') . '.');
    }

    // One static practice room, copied onto the row so the record keeps the
    // link it was actually sent with even if the setting changes later.
    $videoLink = ($type === 'online') ? (getSetting('practice_video_link', '') ?: null) : null;
    $status    = getSettingInt('auto_confirm_sessions', 0) ? 'confirmed' : 'pending';

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        $conflict = findSessionConflict($db, $window['guard_start'], $window['guard_end'], 0, true);
        if ($conflict !== null) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw new RuntimeException(sessionConflictMessage($conflict));
        }

        $db->prepare('
            INSERT INTO `sessions`
                (`client_id`,`start_time`,`end_time`,`session_type`,`status`,`video_link`,`recurring_series_id`)
            VALUES (:c,:s,:e,:t,:st,:v,:series)
        ')->execute([
            ':c'      => (int) $clientId,
            ':s'      => $window['start'],
            ':e'      => $window['end'],
            ':t'      => $type,
            ':st'     => $status,
            ':v'      => $videoLink,
            ':series' => $seriesId === null ? null : (int) $seriesId,
        ]);
        $id = (int) $db->lastInsertId();

        if ($ownTransaction) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Move a session. The same row is updated — never delete-and-recreate, because
 * the id anchors notes and documents and recreating it would orphan them.
 */
function rescheduleSession(PDO $db, $sessionId, $startTime, $durationMinutes) {
    $window = sessionWindowFor($startTime, $durationMinutes);
    if ($window === null) {
        throw new InvalidArgumentException('That is not a valid start time.');
    }

    $existing = fetchSession($db, $sessionId);
    if ($existing === null) {
        throw new RuntimeException('Session not found.');
    }

    // Moving a session onto a closed day is the same mistake as booking one
    // there, so it is refused in the same place and for the same reason.
    $day = date('Y-m-d', strtotime($window['start']));
    if (isHoliday($db, $day)) {
        $why = holidayReason($db, $day);
        throw new RuntimeException(date('d M Y', strtotime($day)) . ' is marked a holiday'
            . ($why ? ' (' . $why . ')' : '') . '.');
    }
    if (sessionStatusIsTerminal($existing['status'])) {
        throw new RuntimeException('A ' . sessionStatusLabel($existing['status'])
            . ' session cannot be rescheduled.');
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        // Excluding itself: a session always overlaps its own current slot.
        $conflict = findSessionConflict($db, $window['guard_start'], $window['guard_end'], (int) $sessionId, true);
        if ($conflict !== null) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw new RuntimeException(sessionConflictMessage($conflict));
        }

        $db->prepare('
            UPDATE `sessions`
            SET `start_time` = :s, `end_time` = :e,
                `rescheduled_count` = `rescheduled_count` + 1,
                `reminder_sent` = 0
            WHERE `id` = :id
        ')->execute([':s' => $window['start'], ':e' => $window['end'], ':id' => (int) $sessionId]);

        if ($ownTransaction) {
            $db->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function setSessionStatus(PDO $db, $sessionId, $status) {
    if (!isValidSessionStatus($status)) {
        throw new InvalidArgumentException('Unknown session status: ' . $status);
    }

    $existing = fetchSession($db, $sessionId);
    if ($existing === null) {
        throw new RuntimeException('Session not found.');
    }
    if (!sessionCanTransition($existing['status'], $status)) {
        throw new RuntimeException('Cannot move a ' . sessionStatusLabel($existing['status'])
            . ' session to ' . sessionStatusLabel($status) . '.');
    }

    $db->prepare('UPDATE `sessions` SET `status` = :s WHERE `id` = :id')
       ->execute([':s' => $status, ':id' => (int) $sessionId]);

    return true;
}

/** Cancelling always records why. A cancellation with no reason is a mystery. */
function cancelSession(PDO $db, $sessionId, $reason) {
    $reason = trim((string) $reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A cancellation needs a reason.');
    }

    setSessionStatus($db, $sessionId, 'cancelled');

    $db->prepare('UPDATE `sessions` SET `cancelled_reason` = :r WHERE `id` = :id')
       ->execute([':r' => mb_substr($reason, 0, 500), ':id' => (int) $sessionId]);

    return true;
}

function fetchSession(PDO $db, $id) {
    $stmt = $db->prepare('SELECT * FROM `sessions` WHERE `id` = :id');
    $stmt->execute([':id' => (int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function sessionsForClient(PDO $db, $clientId) {
    $stmt = $db->prepare('SELECT * FROM `sessions` WHERE `client_id` = :c ORDER BY `start_time` DESC');
    $stmt->execute([':c' => (int) $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Everything starting inside a window, for the calendar and the dashboard. */
function sessionsBetween(PDO $db, $from, $to) {
    $stmt = $db->prepare('
        SELECT s.*, CONCAT(c.`first_name`, " ", c.`last_name`) AS `client_name`
        FROM `sessions` s
        JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE s.`start_time` >= :from AND s.`start_time` <= :to
        ORDER BY s.`start_time` ASC
    ');
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * How many no-shows in a row, counting back from the most recent finished
 * session. A completed session breaks the run — the point is a current
 * pattern, not a lifetime tally.
 */
function consecutiveNoShows(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT `status` FROM `sessions`
        WHERE `client_id` = :c AND `status` IN ("completed","no-show")
        ORDER BY `start_time` DESC
    ');
    $stmt->execute([':c' => (int) $clientId]);

    $run = 0;
    foreach ($stmt as $row) {
        if ($row['status'] !== 'no-show') {
            break;
        }
        $run++;
    }
    return $run;
}

/**
 * End-of-day sweep: confirmed sessions whose end time has passed are completed.
 *
 * Only confirmed ones. A session nobody ever confirmed should not quietly
 * become a session that happened.
 */
function sweepCompletedSessions(PDO $db) {
    $stmt = $db->prepare('
        UPDATE `sessions` SET `status` = "completed"
        WHERE `status` = "confirmed" AND `end_time` < NOW()
    ');
    $stmt->execute();
    return $stmt->rowCount();
}
