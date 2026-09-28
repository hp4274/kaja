<?php
/**
 * Per-slot availability, one level finer than a holiday.
 *
 * A holiday closes a whole day; this closes one time on one day, e.g. the
 * therapist is out 3pm-4pm on the 12th but still seeing people the rest of
 * that day. Blocking a slot that already holds a session cancels it first —
 * a blocked slot and a booked slot cannot both be true of the same time.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/booking-slots.php';

/** "H:i" for a slot, tolerant of "H:i:s" from the database. */
function normaliseSlotTime($time) {
    $ts = strtotime('1970-01-01 ' . trim((string) $time));
    return $ts === false ? null : date('H:i', $ts);
}

/** Every blocked time on one date, as "H:i" strings. */
function blockedSlotsForDate(PDO $db, $date) {
    $stmt = $db->prepare('SELECT `slot_time` FROM `blocked_slots` WHERE `slot_date` = :d');
    $stmt->execute([':d' => $date]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Every blocked slot in a date range, keyed by "YYYY-MM-DD" -> ["H:i", ...]. */
function blockedSlotsBetween(PDO $db, $from, $to) {
    $stmt = $db->prepare('SELECT `slot_date`, `slot_time` FROM `blocked_slots`
                          WHERE `slot_date` BETWEEN :f AND :t');
    $stmt->execute([':f' => $from, ':t' => $to]);
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['slot_date']][] = $row['slot_time'];
    }
    return $out;
}

function isSlotBlocked(PDO $db, $date, $time) {
    $time = normaliseSlotTime($time);
    if ($time === null) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM `blocked_slots` WHERE `slot_date` = :d AND `slot_time` = :t');
    $stmt->execute([':d' => $date, ':t' => $time]);
    return $stmt->fetchColumn() !== false;
}

function blockSlot(PDO $db, $date, $time, $reason = '', $userId = null) {
    $time = normaliseSlotTime($time);
    if ($time === null) {
        return false;
    }
    $db->prepare('INSERT IGNORE INTO `blocked_slots` (`slot_date`,`slot_time`,`reason`,`created_by`)
                  VALUES (:d,:t,:r,:u)')
       ->execute([
           ':d' => $date, ':t' => $time,
           ':r' => ($reason === '' ? null : $reason),
           ':u' => $userId === null ? null : (int) $userId,
       ]);
    return true;
}

function unblockSlot(PDO $db, $date, $time) {
    $time = normaliseSlotTime($time);
    if ($time === null) {
        return false;
    }
    $db->prepare('DELETE FROM `blocked_slots` WHERE `slot_date` = :d AND `slot_time` = :t')
       ->execute([':d' => $date, ':t' => $time]);
    return true;
}
