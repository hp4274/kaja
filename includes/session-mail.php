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
require_once __DIR__ . '/session-repo.php';

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
 * Send one templated session mail. $kind is confirmation, cancellation or
 * reminder. Returns true when it went out, false when it was queued.
 */
function sendSessionMail(PDO $db, $sessionId, $kind) {
    $keys = [
        'confirmation' => ['notify_session_confirmed_subject', 'notify_session_confirmed_body'],
        'cancellation' => ['notify_session_cancelled_subject', 'notify_session_cancelled_body'],
        'reminder'     => ['notify_session_reminder_subject',  'notify_session_reminder_body'],
    ];
    if (!isset($keys[$kind])) {
        throw new InvalidArgumentException('Unknown session mail kind: ' . $kind);
    }

    $payload = sessionMailVars($db, $sessionId);
    if ($payload === null || $payload['to'] === '') {
        return false;
    }

    $subject = renderNotificationTemplate(getSetting($keys[$kind][0]), $payload['vars']);
    $body    = renderNotificationTemplate(getSetting($keys[$kind][1]), $payload['vars']);
    $from    = getSetting('practice_email');
    $headers = 'From: ' . $from . "\r\nReply-To: " . $from
             . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    if (@mail($payload['to'], $subject, $body, $headers)) {
        return true;
    }

    queueFailedMail([
        'to'      => $payload['to'],
        'subject' => $subject,
        'body'    => $body,
        'kind'    => 'session_mail',
    ], 'mail() returned false');

    return false;
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

function markSessionReminded(PDO $db, $sessionId) {
    $db->prepare('UPDATE `sessions` SET `reminder_sent` = 1 WHERE `id` = :id')
       ->execute([':id' => (int) $sessionId]);
}
