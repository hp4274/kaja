<?php
/**
 * Days the practice is closed.
 *
 * A holiday is a date, not a session: it says nothing was ever going to happen
 * that day, which is why it lives in its own table rather than as a row in
 * `sessions` with a fake client attached to it.
 *
 * Marking a day closed does not touch sessions already booked on it. Deleting
 * or cancelling someone's appointment as a side effect of a calendar click is
 * not something a therapist can undo, so the caller is told what is already
 * there and decides what to do about it.
 */

require_once __DIR__ . '/../db-config.php';

/** Reject anything that is not a plain calendar date before it reaches SQL. */
function normaliseHolidayDate($date) {
    $date = trim((string) $date);
    $ts   = strtotime($date);
    if ($ts === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/** Every closed day in a range, as "YYYY-MM-DD", in calendar order. */
function holidayDates(PDO $db, $from, $to) {
    $stmt = $db->prepare('SELECT `holiday_date` FROM `holidays`
                          WHERE `holiday_date` BETWEEN :f AND :t
                          ORDER BY `holiday_date` ASC');
    $stmt->execute([':f' => $from, ':t' => $to]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** The reason a day is closed, or null when it is an ordinary working day. */
function holidayReason(PDO $db, $date) {
    $date = normaliseHolidayDate($date);
    if ($date === null) {
        return null;
    }
    $stmt = $db->prepare('SELECT `reason` FROM `holidays` WHERE `holiday_date` = :d');
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : (string) $row['reason'];
}

function isHoliday(PDO $db, $date) {
    $date = normaliseHolidayDate($date);
    if ($date === null) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM `holidays` WHERE `holiday_date` = :d');
    $stmt->execute([':d' => $date]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Close a set of days. Marking a day that is already closed just updates the
 * reason, so clicking twice is never an error.
 */
function markHolidays(PDO $db, array $dates, $reason = '', $userId = null) {
    $stmt = $db->prepare('INSERT INTO `holidays` (`holiday_date`,`reason`,`created_by`)
                          VALUES (:d,:r,:u)
                          ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`)');
    $done = [];
    foreach ($dates as $raw) {
        $date = normaliseHolidayDate($raw);
        if ($date === null || in_array($date, $done, true)) {
            continue;
        }
        $stmt->execute([
            ':d' => $date,
            ':r' => ($reason === '' ? null : $reason),
            ':u' => $userId === null ? null : (int) $userId,
        ]);
        $done[] = $date;
    }
    return $done;
}

/** Reopen a set of days. Clearing a day that was never closed is not an error. */
function clearHolidays(PDO $db, array $dates) {
    $stmt = $db->prepare('DELETE FROM `holidays` WHERE `holiday_date` = :d');
    $done = [];
    foreach ($dates as $raw) {
        $date = normaliseHolidayDate($raw);
        if ($date === null || in_array($date, $done, true)) {
            continue;
        }
        $stmt->execute([':d' => $date]);
        $done[] = $date;
    }
    return $done;
}

/**
 * Sessions already standing on these days, so closing them can say what it is
 * about to strand instead of silently leaving appointments on a shut door.
 */
function sessionsOnDates(PDO $db, array $dates) {
    $clean = array_values(array_filter(array_map('normaliseHolidayDate', $dates)));
    if (!$clean) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($clean), '?'));
    $stmt = $db->prepare('
        SELECT s.`id`, s.`start_time`, CONCAT(c.`first_name`, " ", c.`last_name`) AS client_name
        FROM `sessions` s
        LEFT JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE DATE(s.`start_time`) IN (' . $in . ')
          AND s.`status` NOT IN ("cancelled","no-show")
        ORDER BY s.`start_time` ASC
    ');
    $stmt->execute($clean);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
