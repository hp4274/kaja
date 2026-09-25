<?php
/**
 * Every email this application sends, in one list.
 *
 * Before this file the answer to "what do we send people?" was spread across
 * four call sites and a settings array: the acceptance mail was editable on
 * the Settings page, the three session mails were editable nowhere at all
 * despite having settings keys, and the intake reminder was a heredoc in
 * intake-token.php that no therapist could reach.
 *
 * The senders read the keys named here. The admin Emails page renders one
 * editor per entry from this same list, so an email that is added to the
 * application appears on that page by adding it here, and an email that is
 * NOT in this list is one nobody can edit -- which is the bug this file
 * exists to make visible.
 */

require_once __DIR__ . '/settings.php';
// siteBaseUrl() -- the one place the public address is worked out. That file
// requires this one back for the senders; require_once makes the loop safe.
require_once __DIR__ . '/intake-token.php';

/**
 * The catalogue.
 *
 * `vars` is the full set of placeholders the sender passes, mapped to a
 * realistic sample. The sample is what the admin preview fills in, so a
 * placeholder listed here with no sample would preview as a raw brace.
 *
 * `required` names placeholders the email is useless without. The acceptance
 * mail without {{intake_link}} is an invitation with nothing to accept.
 */
function emailTemplates() {
    $practice = getSetting('practice_name') ?: 'the practice';
    $expires  = date('d M Y', strtotime('+14 days'));
    $when     = date('l d M Y \a\t h:i A', strtotime('+3 days 15:00'));

    return [
        'lead_confirmed' => [
            'label'       => 'Lead accepted',
            'icon'        => 'bi-send-check',
            'trigger'     => 'When you accept a lead',
            'blurb'       => 'Carries their personal intake link. This is the first thing a new client hears from you.',
            'subject_key' => 'notify_lead_confirmed_subject',
            'body_key'    => 'notify_lead_confirmed_body',
            'bg_key'      => 'notify_lead_confirmed_bg',
            'to'          => 'the lead',
            'required'    => ['intake_link'],
            'vars'        => [
                'name'          => 'Ananya',
                'intake_link'   => siteBaseUrl() . 'intake.php?token=8f3c1a9e42',
                'expires'       => $expires,
                'practice_name' => $practice,
            ],
        ],

        'intake_reminder' => [
            'label'       => 'Intake reminder',
            'icon'        => 'bi-bell',
            'trigger'     => 'Hourly job, once per lead',
            'blurb'       => 'The one nudge a lead ever gets about an unfinished questionnaire. Written to be ignorable.',
            'subject_key' => 'notify_intake_reminder_subject',
            'body_key'    => 'notify_intake_reminder_body',
            'bg_key'      => 'notify_intake_reminder_bg',
            'to'          => 'the lead',
            'required'    => ['intake_link'],
            'vars'        => [
                'name'          => 'Ananya',
                'intake_link'   => siteBaseUrl() . 'intake.php?token=8f3c1a9e42',
                'expires'       => $expires,
                'practice_name' => $practice,
            ],
        ],

        'fee_reminder' => [
            'label'       => 'Payment reminder',
            'icon'        => 'bi-cash-stack',
            'trigger'     => 'Sent by hand, from the Fees tab',
            'blurb'       => 'The outstanding balance, and an invitation to say if it is wrong. Never sent on a schedule.',
            'subject_key' => 'notify_fee_reminder_subject',
            'body_key'    => 'notify_fee_reminder_body',
            'bg_key'      => 'notify_fee_reminder_bg',
            'to'          => 'the client',
            'required'    => ['amount_due'],
            'vars'        => [
                'client_name'   => 'Ananya Rao',
                'amount_due'    => '₹4,500.00',
                'oldest_date'   => date('d M Y', strtotime('-3 weeks')),
                'practice_name' => $practice,
            ],
        ],

        'session_confirmed' => [
            'label'       => 'Session confirmed',
            'icon'        => 'bi-calendar-check',
            'trigger'     => 'When a session is booked or confirmed',
            'blurb'       => 'The time, the format, and the joining link if there is one.',
            'subject_key' => 'notify_session_confirmed_subject',
            'body_key'    => 'notify_session_confirmed_body',
            'bg_key'      => 'notify_session_confirmed_bg',
            'to'          => 'the client',
            'required'    => [],
            'vars'        => [
                'client_name'   => 'Ananya Rao',
                'session_time'  => $when,
                'session_type'  => 'Online',
                'video_link'    => 'https://meet.example.com/ananya-rao',
                'practice_name' => $practice,
            ],
        ],

        'session_cancelled' => [
            'label'       => 'Session cancelled',
            'icon'        => 'bi-calendar-x',
            'trigger'     => 'When a session is cancelled',
            'blurb'       => 'Carries the reason if one was given, and an invitation to rebook.',
            'subject_key' => 'notify_session_cancelled_subject',
            'body_key'    => 'notify_session_cancelled_body',
            'bg_key'      => 'notify_session_cancelled_bg',
            'to'          => 'the client',
            'required'    => [],
            'vars'        => [
                'client_name'   => 'Ananya Rao',
                'session_time'  => $when,
                'session_type'  => 'Online',
                'video_link'    => 'https://meet.example.com/ananya-rao',
                'cancel_reason' => 'Something came up at the practice and we have to move this one.',
                'practice_name' => $practice,
            ],
        ],

        'session_reminder' => [
            'label'       => 'Session reminder',
            'icon'        => 'bi-alarm',
            'trigger'     => 'Before an upcoming session',
            'blurb'       => 'Sent ahead of the appointment, on the schedule set in Settings.',
            'subject_key' => 'notify_session_reminder_subject',
            'body_key'    => 'notify_session_reminder_body',
            'bg_key'      => 'notify_session_reminder_bg',
            'to'          => 'the client',
            'required'    => [],
            'vars'        => [
                'client_name'   => 'Ananya Rao',
                'session_time'  => $when,
                'session_type'  => 'Online',
                'video_link'    => 'https://meet.example.com/ananya-rao',
                'practice_name' => $practice,
            ],
        ],
    ];
}

/** Every settings key the Emails page is allowed to write. */
function emailTemplateKeys() {
    $keys = [];
    foreach (emailTemplates() as $template) {
        $keys[] = $template['subject_key'];
        $keys[] = $template['body_key'];
    }
    return $keys;
}

/**
 * Background images.
 *
 * Kept in the code tree, in images/email-backgrounds/, and referenced from the
 * email by absolute URL. Nothing is attached: the message stays a few
 * kilobytes whatever the picture weighs.
 */
function emailBackgroundDir() {
    return __DIR__ . '/../images/email-backgrounds';
}

/** Types accepted, by MIME. The extension comes from here, never from the upload. */
function emailBackgroundTypes() {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
}

/** Big enough for a good photograph, small enough to load on a phone. */
function emailBackgroundMaxBytes() {
    return 500 * 1024;
}

/**
 * The stored filename for a template, or '' if there is none worth using.
 *
 * The setting is validated against the shape this code generates rather than
 * trusted: a filename that came out of a database row must not be able to
 * name a path, and a file that has since been deleted must not produce a URL
 * that 404s inside somebody's inbox.
 */
function emailBackgroundFile($templateId) {
    $templates = emailTemplates();
    if (!isset($templates[$templateId])) {
        return '';
    }
    $file = (string) getSetting($templates[$templateId]['bg_key']);
    if (!preg_match('~^[a-z_]+-[a-f0-9]{8}\.(jpg|png|webp|gif)$~', $file)) {
        return '';
    }
    return is_file(emailBackgroundDir() . '/' . $file) ? $file : '';
}

/** What a recipient's mail client fetches: absolute, or '' for a plain mail. */
function emailBackgroundUrl($templateId) {
    $file = emailBackgroundFile($templateId);
    return $file === '' ? '' : siteBaseUrl() . 'images/email-backgrounds/' . rawurlencode($file);
}

/** Same file, addressed from the admin panel's own directory. */
function emailBackgroundPreviewUrl($templateId) {
    $file = emailBackgroundFile($templateId);
    return $file === '' ? '' : '../images/email-backgrounds/' . rawurlencode($file);
}

/** True when a recipient's mail app could not possibly reach $url. */
function emailUrlIsPrivate($url) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    return $host === 'localhost'
        || preg_match('~^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)~', $host) === 1
        || substr($host, -6) === '.local';
}
