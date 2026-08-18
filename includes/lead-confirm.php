<?php
/**
 * Confirm: the one transition the admin does not spell out by hand.
 *
 * Everything below commits together — client, token, lead status. The failure
 * this guards against is a token issued against a lead that never moved, or a
 * client with no way to reach it: the dashboard would then be permanently
 * wrong with no event to explain it.
 *
 * Mail is sent by the CALLER, after commit. An SMTP hiccup must never roll
 * back correct database state.
 *
 * Invariant: one lead -> one client -> one open token. Always 1:1:1.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/intake-token.php';
require_once __DIR__ . '/lead-status.php';
require_once __DIR__ . '/lead-repo.php';

function confirmLead(PDO $db, $leadId, $userId = null) {
    $leadId = (int) $leadId;
    $lead   = fetchLead($db, $leadId);

    if ($lead === null) {
        throw new RuntimeException('Lead not found.');
    }

    // Already done. Idempotent by design: a stale page or a double click must
    // hand back the existing client rather than mint a second one.
    if (!empty($lead['client_id'])) {
        $link = $db->prepare('SELECT `token`, `expires_at` FROM `intake_links` WHERE `lead_id` = :id ORDER BY `created_at` DESC LIMIT 1');
        $link->execute([':id' => $leadId]);
        $existing = $link->fetch(PDO::FETCH_ASSOC);

        return [
            'client_id'  => (int) $lead['client_id'],
            'token'      => $existing ? $existing['token'] : null,
            'expires_at' => $existing ? $existing['expires_at'] : null,
            'intake_url' => $existing ? intakeFormUrl($existing['token']) : null,
            'already'    => true,
        ];
    }

    if (!leadCanTransition($lead['status'], 'confirmed')) {
        throw new RuntimeException(
            'A ' . leadStatusLabel($lead['status']) . ' lead cannot be confirmed.'
        );
    }

    $parts     = explode(' ', trim($lead['name']), 2);
    $firstName = $parts[0];
    $lastName  = isset($parts[1]) ? $parts[1] : '';

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`phone`,`status`)
            VALUES (:lid,:fn,:ln,:em,:ph,"pending")
        ');
        $stmt->execute([
            ':lid' => $leadId, ':fn' => $firstName, ':ln' => $lastName,
            ':em'  => $lead['email'], ':ph' => $lead['phone'],
        ]);
        $clientId = (int) $db->lastInsertId();

        // issueIntakeToken() reads intake_token_expiry_days and bakes the
        // result into expires_at using MySQL's clock. Changing the setting
        // later must not move a link already sitting in someone's inbox,
        // which is why the deadline is stored rather than computed at read.
        $issued = issueIntakeToken($db, $leadId, $clientId);

        $db->prepare('UPDATE `leads` SET `status`="confirmed", `client_id`=:cid WHERE `id`=:id')
           ->execute([':cid' => $clientId, ':id' => $leadId]);

        $desc = $lead['name'] . ' confirmed — intake link issued, expires '
              . date('d M Y', strtotime($issued['expires_at']));
        $db->prepare('
            INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
            VALUES ("lead_confirmed", :d, "lead", :rid)
        ')->execute([':d' => $desc, ':rid' => $leadId]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'client_id'  => $clientId,
        'token'      => $issued['token'],
        'expires_at' => $issued['expires_at'],
        'intake_url' => $issued['url'],
        'already'    => false,
    ];
}
