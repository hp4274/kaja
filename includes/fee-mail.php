<?php
/**
 * The payment reminder.
 *
 * Sent by hand from a client's Fees tab, never on a schedule. Chasing money
 * automatically is a decision about a relationship, and this practice's
 * relationships are the product -- so the therapist presses the button or
 * nobody gets chased.
 *
 * Amounts stay strings until the database adds them up. Money that has been
 * through a float is money that no longer adds up.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail-queue.php';
require_once __DIR__ . '/email-templates.php';

/**
 * What a client still owes: the total, the count, and the oldest unpaid date.
 *
 * Summed by the database, which owns the DECIMAL column these live in.
 * 'waived' is excluded on purpose -- a waived fee is one the practice decided
 * not to collect, and chasing it would be asking for money already forgiven.
 */
function clientPendingFees(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT COALESCE(SUM(`amount`), 0) AS `total`,
               COUNT(*)                   AS `count`,
               MIN(`fee_date`)            AS `oldest`
        FROM `client_fees`
        WHERE `client_id` = :c AND `status` = "pending"
    ');
    $stmt->execute([':c' => (int) $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total'  => $row['total'] ?? '0',
        'count'  => (int) ($row['count'] ?? 0),
        'oldest' => $row['oldest'] ?? null,
    ];
}

/**
 * Send one client their outstanding balance.
 *
 * Returns ['sent' => bool, 'error' => string]. A client with nothing pending
 * is refused rather than sent an invoice for zero: the commonest way to lose
 * somebody's trust over money is to ask for money they do not owe.
 */
function sendFeeReminder(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT `first_name`, `last_name`, `email`
        FROM `clients`
        WHERE `id` = :c AND `archived_at` IS NULL
    ');
    $stmt->execute([':c' => (int) $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        return ['sent' => false, 'error' => 'That client no longer exists.'];
    }
    if (trim((string) $client['email']) === '') {
        return ['sent' => false, 'error' => 'This client has no email address on file.'];
    }

    $pending = clientPendingFees($db, $clientId);
    if ($pending['count'] === 0 || (float) $pending['total'] <= 0) {
        return ['sent' => false, 'error' => 'Nothing is outstanding, so there is nothing to remind them about.'];
    }

    $vars = [
        'client_name'   => trim($client['first_name'] . ' ' . $client['last_name']),
        'amount_due'    => '₹' . number_format((float) $pending['total'], 2),
        'oldest_date'   => $pending['oldest'] ? date('d M Y', strtotime($pending['oldest'])) : '',
        'practice_name' => getSetting('practice_name'),
    ];

    $subject = renderNotificationTemplate(getSetting('notify_fee_reminder_subject'), $vars);
    $body    = renderNotificationTemplate(getSetting('notify_fee_reminder_body'), $vars);

    $background = emailBackgroundUrl('fee_reminder');

    if (sendMail($client['email'], $subject, $body, getSetting('practice_email'), $background)) {
        return ['sent' => true, 'error' => '', 'amount' => $vars['amount_due']];
    }

    // Queued rather than lost. A mail server down for an hour must not turn
    // into a client who was never told what they owe.
    queueFailedMail([
        'to'      => $client['email'],
        'subject' => $subject,
        'body'    => $body,
        'kind'    => 'fee_reminder',
        // Carried in the queue: the body is already rendered, so the replay has
        // to be told what to put behind it.
        'background' => $background,
    ], 'mail() returned false');

    return ['sent' => false, 'error' => 'The mail server refused it. It is queued and will be retried.'];
}
