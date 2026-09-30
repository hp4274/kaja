<?php
/** Shared by portal/api/session-*.php and portal/pages/sessions.php. */
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/session-mail.php';
require_once dirname(__DIR__, 2) . '/includes/booking-slots.php';
require_once dirname(__DIR__, 2) . '/includes/client-status.php';

const PORTAL_MIN_NOTICE = 86400;   // reschedule/cancel need >24h notice
const PORTAL_MAX_DAYS   = 90;      // how far ahead a client can book

function portalJson($ok, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok] + $data);
    exit;
}

/** Free "H:i" slots on $date for a session of $duration minutes ([] on holiday / out of range). */
function portalFreeSlots(PDO $db, $date, $duration, $excludeId = 0) {
    $ts = strtotime($date);
    if ($ts === false || date('Y-m-d', $ts) !== $date || $ts > strtotime('+' . PORTAL_MAX_DAYS . ' days') || isHoliday($db, $date)) {
        return [];
    }
    $free = [];
    foreach (bookingSlots() as $slot) {
        $w = sessionWindowFor($date . ' ' . $slot . ':00', $duration);
        if (strtotime($w['start']) <= time() || isSlotBlocked($db, $date, $slot)
            || findSessionConflict($db, $w['guard_start'], $w['guard_end'], $excludeId) !== null) {
            continue;
        }
        $free[] = $slot;
    }
    return $free;
}

/** Client's own session or null (ownership check). */
function portalOwnSession(PDO $db, array $client, $id) {
    $s = fetchSession($db, (int) $id);
    return ($s && (int) $s['client_id'] === (int) $client['id']) ? $s : null;
}

function portalActivity(PDO $db, $action, $desc, $clientId) {
    $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES (:a,:d,'client',:r)")
       ->execute([':a' => $action, ':d' => $desc, ':r' => (int) $clientId]);
}

/** Plain mail to the practice inbox (no template: keeps shared Settings files untouched). */
function portalNotifyAdmin(PDO $db, array $client, $subject, $body) {
    $to = trim((string) getSetting('practice_email'));
    if ($to === '') {
        return false;
    }
    $name = trim($client['first_name'] . ' ' . $client['last_name']);
    return sendMail($to, $subject . ' - ' . $name, $name . " (client portal)\n\n" . $body, $client['email'] ?? null);
}

function portalFmt($ts) {
    return date('l d M Y \a\t h:i A', is_int($ts) ? $ts : strtotime($ts));
}

/** Can the client still change this session? Returns an error string, or '' when allowed. */
function portalChangeBlock(array $s, $allowedStatuses) {
    if (!in_array($s['status'], $allowedStatuses, true)) {
        return 'This session can no longer be changed.';
    }
    if (strtotime($s['start_time']) - time() < PORTAL_MIN_NOTICE) {
        return 'Sessions can only be changed more than 24 hours before they start. Please contact the practice.';
    }
    return '';
}

function portalSessionErr(Throwable $e, $fallback) {
    return ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) ? $e->getMessage() : $fallback;
}
