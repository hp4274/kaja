<?php
/**
 * Hourly stale-intake reminder.
 *
 *   php cron/intake-reminders.php
 *
 * One reminder per lead, ever. `leads.reminder_sent` is the gate, and it is
 * set whether or not the mail actually went out. A mail server down for an
 * hour must not turn into a lead being chased every hour after it comes back.
 *
 * `site_base_url` MUST be set in Settings for this to build working links — a
 * CLI process has no HTTP_HOST to derive one from.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/intake-token.php';

define('INTAKE_REMINDERS_LOADED', true);

/**
 * Leads whose most recent intake link has sat unsubmitted longer than $hours,
 * and who have never been reminded.
 *
 * "Most recent" matters: a resend leaves the old row behind, and judging on
 * the old one would chase someone who was handed a fresh link an hour ago.
 */
function staleIntakeLeads(PDO $db, $hours) {
    $stmt = $db->prepare('
        SELECT l.`id` AS `lead_id`, l.`name`, l.`email`,
               il.`token`, il.`expires_at`
        FROM `leads` l
        JOIN `intake_links` il ON il.`lead_id` = l.`id`
        WHERE l.`reminder_sent` = 0
          AND il.`status` IN ("sent", "opened")
          AND il.`created_at` < DATE_SUB(NOW(), INTERVAL :hours HOUR)
          AND il.`id` = (
              SELECT `id` FROM `intake_links`
              WHERE `lead_id` = l.`id`
              ORDER BY `created_at` DESC, `id` DESC LIMIT 1
          )
    ');
    // Bound as an int: INTERVAL will not take a quoted string.
    $stmt->bindValue(':hours', (int) $hours, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function markLeadReminded(PDO $db, $leadId) {
    $db->prepare('UPDATE `leads` SET `reminder_sent` = 1 WHERE `id` = :id')
       ->execute([':id' => (int) $leadId]);
}

// ---------------------------------------------------------------------------
// Runner. Guarded so requiring this file from a test does not send real mail.
// ---------------------------------------------------------------------------

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    define('INTAKE_REMINDERS_RAN', true);

    $db    = getDbConnection();
    $hours = getSettingInt('admin_reminder_hours', 48);
    $stale = staleIntakeLeads($db, $hours);

    echo count($stale) . " stale intake(s) past {$hours}h\n";

    foreach ($stale as $row) {
        $url  = intakeFormUrl($row['token']);
        $sent = sendIntakeReminderEmail($row['email'], $row['name'], $url, $row['expires_at']);

        // The gate closes either way. See the file header.
        markLeadReminded($db, (int) $row['lead_id']);

        $db->prepare('
            INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
            VALUES ("intake_reminder_sent", :d, "lead", :rid)
        ')->execute([
            ':d'   => 'Intake reminder ' . ($sent ? 'sent to ' : 'FAILED for ') . $row['email'],
            ':rid' => (int) $row['lead_id'],
        ]);

        echo '  lead #' . $row['lead_id'] . ' ' . ($sent ? 'reminded' : 'MAIL FAILED') . "\n";
    }
}
