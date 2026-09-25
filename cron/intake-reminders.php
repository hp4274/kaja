<?php
/**
 * Hourly stale-intake reminder.
 *
 *   php cron/intake-reminders.php
 *
 * Despite the name this is the practice's single hourly job: it also sweeps
 * finished sessions to completed and sends session reminders.
 *
 * Three passes, in order: retry queued mail, expire overdue links, then chase
 * the stale ones. Retrying first clears a transient outage before we decide
 * who else to chase; expiring second means nobody is chased with a link that
 * will refuse to open by the time they click it.
 *
 * One reminder per LINK, ever. `intake_links.reminder_sent` is the gate, and
 * it is set whether or not the mail actually went out — a mail server down for
 * an hour must not turn into someone chased every hour after it recovers. A
 * resent link is a new row, so it earns its own single reminder.
 *
 * `site_base_url` MUST be set in Settings for this to build working links — a
 * CLI process has no HTTP_HOST to derive one from.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/intake-token.php';
require_once __DIR__ . '/../includes/intake-repo.php';
require_once __DIR__ . '/../includes/mail-queue.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/session-mail.php';

define('INTAKE_REMINDERS_LOADED', true);

// ---------------------------------------------------------------------------
// Runner. Guarded so requiring this file from a test does not send real mail.
// ---------------------------------------------------------------------------

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    define('INTAKE_REMINDERS_RAN', true);

    $db = getDbConnection();

    $drained = drainQueuedMail(function (array $payload) {
        // Both are queued already rendered, so the replay only has to send.
        // fee_reminder used to fall through to the intake-link branch below,
        // which reads a name and a URL a payment reminder does not have.
        if (in_array($payload['kind'] ?? '', ['session_mail', 'fee_reminder'], true)) {
            return sendMail($payload['to'], $payload['subject'], $payload['body'],
                            null, $payload['background'] ?? '');
        }
        if (($payload['kind'] ?? '') === 'intake_review') {
            return sendMail($payload['to'],
                'Intake ready for review - ' . $payload['name'],
                'Review: ' . $payload['url'] . PHP_EOL);
        }
        if (($payload['kind'] ?? '') === 'intake_reminder') {
            return sendIntakeReminderEmail($payload['to'], $payload['name'], $payload['url'], $payload['expires']);
        }
        return sendIntakeLinkEmail($payload['to'], $payload['name'], $payload['url'], $payload['expires']);
    });
    echo $drained['sent'] . ' queued mail sent, ' . $drained['kept'] . ' still failing' . PHP_EOL;

    $expired = expireOverdueIntakeLinks($db);
    echo $expired . ' link(s) expired' . PHP_EOL;

    // Sessions whose end time has passed become completed. Only confirmed
    // ones: a session nobody confirmed should not silently become one that
    // happened.
    $swept = sweepCompletedSessions($db);
    echo $swept . ' session(s) marked completed' . PHP_EOL;

    // Session reminders, one per session ever.
    $reminderHours = getSettingInt('session_reminder_hours', 24);
    $due = sessionsDueReminder($db, $reminderHours);
    echo count($due) . ' session reminder(s) due inside ' . $reminderHours . 'h' . PHP_EOL;

    foreach ($due as $sid) {
        $sent = sendSessionMail($db, $sid, 'reminder');
        // The flag is set either way: a mail server down for an hour must not
        // become the same client reminded every hour after it recovers.
        markSessionReminded($db, $sid);
        echo '  session #' . $sid . ' ' . ($sent ? 'reminded' : 'MAIL FAILED (queued)') . PHP_EOL;
    }

    $hours = getSettingInt('admin_reminder_hours', 48);
    $stale = staleIntakeLinks($db, $hours);
    echo count($stale) . ' stale intake(s) past ' . $hours . 'h' . PHP_EOL;

    foreach ($stale as $row) {
        $url  = intakeFormUrl($row['token']);
        $sent = sendIntakeReminderEmail($row['email'], $row['name'], $url, $row['expires_at']);

        // The gate closes either way. See the file header.
        markIntakeLinkReminded($db, (int) $row['id']);

        if (!$sent) {
            queueFailedMail([
                'to'      => $row['email'],
                'name'    => $row['name'],
                'url'     => $url,
                'expires' => $row['expires_at'],
                'kind'    => 'intake_reminder',
            ], 'mail() returned false');
        }

        $db->prepare('
            INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
            VALUES ("intake_reminder_sent", :d, "lead", :rid)
        ')->execute([
            ':d'   => 'Intake reminder ' . ($sent ? 'sent to ' : 'FAILED for ') . $row['email'],
            ':rid' => (int) $row['lead_id'],
        ]);

        echo '  link #' . $row['id'] . ' ' . ($sent ? 'reminded' : 'MAIL FAILED (queued)') . PHP_EOL;
    }
}
