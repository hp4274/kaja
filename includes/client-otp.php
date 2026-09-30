<?php
/**
 * Client portal one-time codes. All expiry maths runs on MySQL's clock (NOW()),
 * which is the clock the rest of the schema uses.
 */

require_once __DIR__ . '/email-templates.php';

const CLIENT_OTP_TTL_MIN      = 10;
const CLIENT_OTP_MAX_ATTEMPTS = 5;
const CLIENT_OTP_MAX_PER_HOUR = 5;

/** The one client (not archived/merged) whose email matches exactly, or null. */
function findPortalClient(PDO $db, $email) {
    $stmt = $db->prepare('
        SELECT * FROM `clients`
        WHERE LOWER(`email`) = LOWER(:e) AND `archived_at` IS NULL AND `merged_into_id` IS NULL
        LIMIT 2
    ');
    $stmt->execute([':e' => trim((string) $email)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * Issue and mail a code. Silent in every case (rate limited, unknown email): the
 * caller shows the same message regardless. Returns the code only when APP_DEBUG, else null.
 */
function requestClientOtp(PDO $db, $email, $ip) {
    $client   = findPortalClient($db, $email);
    $clientId = $client ? (int) $client['id'] : 0;

    // Unknown emails leave a burned row (client_id 0) so the per-IP limit covers them too.
    $count = $db->prepare('
        SELECT SUM(`client_id` = :c AND `client_id` > 0), SUM(`ip` = :ip) FROM `client_otps`
        WHERE `created_at` > NOW() - INTERVAL 1 HOUR
    ');
    $count->execute([':c' => $clientId, ':ip' => $ip]);
    [$perClient, $perIp] = array_map('intval', $count->fetch(PDO::FETCH_NUM));
    if ($perClient >= CLIENT_OTP_MAX_PER_HOUR || $perIp >= CLIENT_OTP_MAX_PER_HOUR) {
        return null;
    }

    if (!$client) {
        $db->prepare('INSERT INTO `client_otps` (`client_id`,`code_hash`,`expires_at`,`used_at`,`ip`)
                      VALUES (0, "", NOW(), NOW(), :ip)')->execute([':ip' => $ip]);
        return null;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->beginTransaction();
    $db->prepare('UPDATE `client_otps` SET `used_at` = NOW() WHERE `client_id` = :c AND `used_at` IS NULL')
       ->execute([':c' => $clientId]);
    $db->prepare('INSERT INTO `client_otps` (`client_id`,`code_hash`,`expires_at`,`ip`)
                  VALUES (:c, :h, NOW() + INTERVAL ' . CLIENT_OTP_TTL_MIN . ' MINUTE, :ip)')
       ->execute([':c' => $clientId, ':h' => password_hash($code, PASSWORD_DEFAULT), ':ip' => $ip]);
    $db->commit();

    $vars = [
        'client_name'     => trim($client['first_name'] . ' ' . $client['last_name']),
        'otp_code'        => $code,
        'expires_minutes' => CLIENT_OTP_TTL_MIN,
        'practice_name'   => getSetting('practice_name'),
    ];
    sendTemplatedMail('client_otp', $client['email'], $vars, false);

    // Development only (APP_DEBUG): local SMTP is often unreachable, so keep a copy
    // in storage/dev-mail.log and hand the code back to the caller. Never in production.
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $subject = renderNotificationTemplate(getSetting('notify_client_otp_subject'), $vars);
        $body    = renderNotificationTemplate(getSetting('notify_client_otp_body'), $vars);
        @file_put_contents(dirname(__DIR__) . '/storage/dev-mail.log',
            '[' . date('c') . "] To: {$client['email']}
Subject: $subject
$body
" . str_repeat('-', 40) . "
",
            FILE_APPEND | LOCK_EX);
        return $code;
    }
    return null;
}

/** Check a code. Returns the client row on success (code consumed), else null. */
function verifyClientOtp(PDO $db, $email, $code) {
    $client = findPortalClient($db, $email);
    if (!$client || !preg_match('/^\d{6}$/', (string) $code)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT * FROM `client_otps`
        WHERE `client_id` = :c AND `used_at` IS NULL AND `expires_at` > NOW() AND `attempts` < :max
        ORDER BY `id` DESC LIMIT 1
    ');
    $stmt->execute([':c' => $client['id'], ':max' => CLIENT_OTP_MAX_ATTEMPTS]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$otp) {
        return null;
    }
    if (!password_verify((string) $code, $otp['code_hash'])) {
        // Reaching the cap also retires the code.
        $db->prepare('UPDATE `client_otps` SET `attempts` = `attempts` + 1,
                      `used_at` = IF(`attempts` >= :m, NOW(), NULL) WHERE `id` = :id')
           ->execute([':m' => CLIENT_OTP_MAX_ATTEMPTS, ':id' => $otp['id']]);
        return null;
    }
    // Only the request that flips used_at wins, so a code cannot be replayed in parallel.
    $used = $db->prepare('UPDATE `client_otps` SET `used_at` = NOW() WHERE `id` = :id AND `used_at` IS NULL');
    $used->execute([':id' => $otp['id']]);
    return $used->rowCount() === 1 ? $client : null;
}
