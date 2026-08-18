<?php
/**
 * Intake link tokens.
 *
 * A token is the visitor's only way into the intake form. Two values are
 * PINNED onto the row at creation and never re-read afterwards:
 *
 *   expires_at   - baked from intake_token_expiry_days at SEND time. Changing
 *                  the setting later must not move the goalposts on links
 *                  already in someone's inbox.
 *   form_version - whichever questionnaire version was live at SEND time. Edit
 *                  the form after mailing 50 links and all 50 in-flight ones
 *                  still record the version their answers were given against.
 *
 * issueIntakeToken() runs no transaction of its own so the caller can bundle
 * it with the client/lead writes in one atomic commit.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';

/**
 * Absolute base URL of the install, with trailing slash.
 *
 * Prefers the site_base_url setting; CLI jobs have no HTTP_HOST and MUST rely
 * on it. Otherwise derives the sub-path by diffing the project directory
 * against DOCUMENT_ROOT, which survives being called from any depth
 * (admin/api/... included) unlike parsing REQUEST_URI.
 */
function siteBaseUrl() {
    $configured = getSetting('site_base_url', '');
    if ($configured !== '' && $configured !== null) {
        return rtrim($configured, '/') . '/';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    $projectRoot = realpath(__DIR__ . '/..');
    $docRoot     = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

    $path = '';
    if ($projectRoot !== false && $docRoot !== false && strpos($projectRoot, $docRoot) === 0) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($projectRoot, strlen($docRoot)));
    }

    return $scheme . '://' . $host . '/' . trim($path, '/') . (trim($path, '/') === '' ? '' : '/');
}

function intakeFormUrl($token) {
    return siteBaseUrl() . 'intake.php?token=' . urlencode($token);
}

/**
 * Create an intake link. Caller supplies an open transaction if it needs one.
 *
 * $clientId may be null: the short-intake path knows only an email address, so
 * there is no client row to point at until the form actually comes back.
 */
function issueIntakeToken(PDO $db, $leadId, $clientId = null) {
    $token       = bin2hex(random_bytes(32));
    $expiryDays  = max(1, getSettingInt('intake_token_expiry_days'));
    $formVersion = max(1, getSettingInt('intake_form_version'));

    // expires_at is computed by the DATABASE, not PHP.
    //
    // PHP and MySQL do not necessarily share a timezone (on this install they
    // differ by hours), and opened_at/submitted_at are already written with
    // MySQL's NOW(). Deriving the deadline from PHP's clock would put two
    // columns of the same row on two different clocks and shorten or lengthen
    // every link by the offset. One clock owns time here: the database.
    $stmt = $db->prepare("
        INSERT INTO `intake_links`
            (`lead_id`, `client_id`, `token`, `form_version`, `status`, `expires_at`)
        VALUES (:lead_id, :client_id, :token, :form_version, 'sent',
                DATE_ADD(NOW(), INTERVAL :expiry_days DAY))
    ");
    $stmt->execute([
        ':lead_id'      => $leadId,
        ':client_id'    => $clientId,
        ':token'        => $token,
        ':form_version' => $formVersion,
        ':expiry_days'  => $expiryDays,
    ]);

    $id = (int) $db->lastInsertId();

    $read = $db->prepare("SELECT `expires_at` FROM `intake_links` WHERE `id` = :id");
    $read->execute([':id' => $id]);
    $expiresAt = $read->fetchColumn();

    return [
        'id'           => $id,
        'token'        => $token,
        'url'          => intakeFormUrl($token),
        'expires_at'   => $expiresAt,
        'form_version' => $formVersion,
    ];
}

/** Fetch a link row by token. $forUpdate locks it for the submit transaction. */
function findIntakeLink(PDO $db, $token, $forUpdate = false) {
    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }

    // is_expired is evaluated by MySQL against its own clock, for the same
    // single-clock reason expires_at is written by MySQL.
    $sql = "SELECT *, (`expires_at` < NOW()) AS is_expired
            FROM `intake_links` WHERE `token` = :t LIMIT 1";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Usability of a link row: 'valid' | 'submitted' | 'expired' | 'not_found'.
 * Time is evaluated here rather than in SQL so both the renderer and the
 * writer reach the same verdict from the same row.
 */
function intakeLinkState($row) {
    if (!$row) {
        return 'not_found';
    }
    if ($row['status'] === 'submitted') {
        return 'submitted';
    }
    if ($row['status'] === 'expired') {
        return 'expired';
    }
    // Prefer the database's verdict; fall back to PHP only for rows that did
    // not come through findIntakeLink().
    if (array_key_exists('is_expired', $row)) {
        return !empty($row['is_expired']) ? 'expired' : 'valid';
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return 'expired';
    }
    return 'valid';
}

/** Flip a lapsed row to 'expired' so the dashboard stops counting it as open. */
function markIntakeLinkExpired(PDO $db, $linkId) {
    $db->prepare("UPDATE `intake_links` SET `status` = 'expired' WHERE `id` = :id AND `status` IN ('sent','opened')")
       ->execute([':id' => $linkId]);
}

/** First open stamps opened_at; later opens leave it alone. */
function markIntakeLinkOpened(PDO $db, $linkId) {
    $db->prepare("UPDATE `intake_links` SET `status` = 'opened', `opened_at` = NOW() WHERE `id` = :id AND `status` = 'sent'")
       ->execute([':id' => $linkId]);
}

/**
 * Mail the link. Called AFTER the DB transaction commits — an SMTP hiccup must
 * never roll back correct database state. Returns false so the caller can
 * surface "saved, but email failed" and offer the URL for manual sending.
 */
function sendIntakeLinkEmail($toEmail, $recipientName, $url, $expiresAt) {
    $practiceName  = getSetting('practice_name');
    $practiceEmail = getSetting('practice_email');
    $greetingName  = trim((string) $recipientName);
    $greeting      = $greetingName !== '' ? $greetingName : 'there';
    $expiryLabel   = date('d M Y', strtotime($expiresAt));

    // Copy comes from Settings so the therapist can reword it without a
    // deploy. The defaults in settingDefaults() are the fallback.
    $vars = [
        'name'          => $greeting,
        'intake_link'   => $url,
        'expires'       => $expiryLabel,
        'practice_name' => $practiceName,
    ];

    $subject = renderNotificationTemplate(getSetting('notify_lead_confirmed_subject'), $vars);
    $body    = renderNotificationTemplate(getSetting('notify_lead_confirmed_body'), $vars);

    // Headers are the mailer's job now; it has to set From to the
    // authenticated account regardless, because Gmail rewrites it anyway.
    return sendMail($toEmail, $subject, $body, $practiceEmail);
}

/**
 * The one nudge a lead ever gets about an unfinished intake form.
 * Same transport as sendIntakeLinkEmail(); only the copy differs, and it is
 * written to be ignorable — someone who no longer wants an appointment should
 * not have to reply to make it stop.
 */
function sendIntakeReminderEmail($toEmail, $recipientName, $url, $expiresAt) {
    $practiceName  = getSetting('practice_name');
    $practiceEmail = getSetting('practice_email');
    $greetingName  = trim((string) $recipientName);
    $greeting      = $greetingName !== '' ? $greetingName : 'there';
    $expiryLabel   = date('d M Y', strtotime($expiresAt));

    $subject = "A reminder about your intake form - {$practiceName}";

    $body  = "Hello {$greeting},\n\n";
    $body .= "We are still holding your intake questionnaire open. It takes about ten minutes:\n\n";
    $body .= $url . "\n\n";
    $body .= "This link is unique to you, so please do not forward it. It expires on {$expiryLabel}.\n\n";
    $body .= "If you no longer need an appointment, you can ignore this message.\n\n";
    $body .= "Best regards,\n{$practiceName}";

    // Headers are the mailer's job now; it has to set From to the
    // authenticated account regardless, because Gmail rewrites it anyway.
    return sendMail($toEmail, $subject, $body, $practiceEmail);
}
