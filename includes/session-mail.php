<?php
/**
 * Outbound session mail.
 *
 * Templated from Settings so the copy can change without a deploy, sent after
 * the database write rather than inside it, and queued on failure. A session
 * that is correctly booked but whose confirmation vanished is the failure this
 * guards against: the calendar is right and the client knows nothing.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail-queue.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/session-repo.php';
require_once __DIR__ . '/email-templates.php';
require_once __DIR__ . '/session-token.php';

/** The variables every session template can use. */
function sessionMailVars(PDO $db, $sessionId) {
    $stmt = $db->prepare('
        SELECT s.*, c.`first_name`, c.`last_name`, c.`email`
        FROM `sessions` s JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE s.`id` = :id
    ');
    $stmt->execute([':id' => (int) $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'to'   => $row['email'],
        'vars' => [
            'client_name'   => trim($row['first_name'] . ' ' . $row['last_name']),
            'session_time'  => date('l d M Y \a\t h:i A', strtotime($row['start_time'])),
            'session_type'  => $row['session_type'] === 'online' ? 'Online' : 'In person',
            // An in-person session has no link; saying so beats an empty line
            // where a URL should be.
            'video_link'    => $row['video_link'] ?: 'Not applicable for an in-person session',
            'cancel_reason' => $row['cancelled_reason'] ?: '',
            'practice_name' => getSetting('practice_name'),
        ],
    ];
}

/**
 * Send one templated session mail. $kind is confirmation, cancellation,
 * reminder, pending (Accept/Decline links), rescheduled or admin_rejected
 * (to the practice). $extra overrides/adds placeholders, e.g. old_time.
 * Returns true when it went out, false when it was queued or not sendable.
 */
function sendSessionMail(PDO $db, $sessionId, $kind, array $extra = []) {
    $templates = [
        'confirmation'   => 'session_confirmed',
        'cancellation'   => 'session_cancelled',
        'reminder'       => 'session_reminder',
        'pending'        => 'session_pending',
        'rescheduled'    => 'session_rescheduled',
        'admin_rejected' => 'session_rejected_admin',
    ];
    if (!isset($templates[$kind])) {
        throw new InvalidArgumentException('Unknown session mail kind: ' . $kind);
    }

    $payload = sessionMailVars($db, $sessionId);
    if ($payload === null) {
        return false;
    }
    $vars = $payload['vars'];
    $to   = $kind === 'admin_rejected' ? (string) getSetting('practice_email') : (string) $payload['to'];

    $status = fetchSession($db, $sessionId)['status'];
    $needsAnswer = in_array($status, ['pending', 'rejected'], true);
    if ($kind === 'pending' || ($kind === 'rescheduled' && $needsAnswer)) {
        $token = issueSessionToken($db, $sessionId);
        $vars['accept_link'] = sessionResponseUrl($token, 'accept');
        $vars['reject_link'] = sessionResponseUrl($token, 'reject');
    }
    if ($kind === 'rescheduled') {
        $vars['status_note'] = $needsAnswer
            ? "Please let us know whether this new time works:\nAccept: " . $vars['accept_link'] . "\nDecline: " . $vars['reject_link']
            : 'This time is confirmed.';
        $vars += ['old_time' => '', 'reschedule_reason' => ''];
    }

    return sendTemplatedMail($templates[$kind], $to, array_merge($vars, $extra));
}

/**
 * Confirmed sessions starting inside the reminder window that have not been
 * chased yet.
 *
 * Only confirmed ones: reminding somebody about a session nobody has confirmed
 * would promise an appointment that is not actually booked.
 */
function sessionsDueReminder(PDO $db, $hours) {
    $stmt = $db->prepare('
        SELECT `id` FROM `sessions`
        WHERE `status` = "confirmed"
          AND `reminder_sent` = 0
          AND `start_time` > NOW()
          AND `start_time` <= DATE_ADD(NOW(), INTERVAL :hours HOUR)
        ORDER BY `start_time` ASC
    ');
    $stmt->bindValue(':hours', (int) $hours, PDO::PARAM_INT);
    $stmt->execute();
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

/** Confirmed sessions starting within the next 30 minutes, not yet nudged. */
function sessionsDueSoon(PDO $db) {
    $stmt = $db->query('
        SELECT `id` FROM `sessions`
        WHERE `status` = "confirmed" AND `reminder30_sent` = 0
          AND `start_time` > NOW()
          AND `start_time` <= DATE_ADD(NOW(), INTERVAL 30 MINUTE)
    ');
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

function markSessionRemindedSoon(PDO $db, $sessionId) {
    $db->prepare('UPDATE `sessions` SET `reminder30_sent` = 1 WHERE `id` = :id')
       ->execute([':id' => (int) $sessionId]);
}

function markSessionReminded(PDO $db, $sessionId) {
    $db->prepare('UPDATE `sessions` SET `reminder_sent` = 1 WHERE `id` = :id')
       ->execute([':id' => (int) $sessionId]);
}

/** After a booking: confirmed sessions get the confirmation, pending ones the Accept/Decline mail. */
function sendBookedSessionMail(PDO $db, $sessionId) {
    $s = fetchSession($db, $sessionId);
    if ($s === null) {
        return false;
    }
    return sendSessionMail($db, $sessionId, $s['status'] === 'confirmed' ? 'confirmation' : 'pending');
}

/**
 * After a session has moved. A session the client had declined goes back to
 * pending (new time, new answer needed) before the mail is built, so the mail
 * carries fresh Accept/Decline links.
 */
function notifySessionRescheduled(PDO $db, $sessionId, $oldStart, $reason = '') {
    $s = fetchSession($db, $sessionId);
    if ($s !== null && $s['status'] === 'rejected') {
        setSessionStatus($db, $sessionId, 'pending');
    }
    return sendSessionMail($db, $sessionId, 'rescheduled', [
        'old_time'          => date('l d M Y \a\t h:i A', strtotime($oldStart)),
        'reschedule_reason' => $reason !== '' ? 'Reason: ' . $reason : '',
    ]);
}
