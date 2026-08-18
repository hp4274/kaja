<?php
/**
 * Settings accessor.
 *
 * Upstream of every module and downstream of none: nothing in here reads
 * another module's tables. Callers use getSetting()/setSetting() rather than
 * querying `settings` directly, so a new key never needs a migration.
 *
 * All reads for one request are served from a single query (static cache).
 */

require_once __DIR__ . '/../db-config.php';

/**
 * Fallbacks used when a key has never been written to the table.
 * Seeding the table is optional — the app runs correctly on these.
 */
function settingDefaults() {
    return [
        // Intake tokens
        'intake_token_expiry_days' => '14',
        // Version 2 adds the emergency contact, presenting concern and
        // conditional background block. Links already sent keep their own.
        'intake_form_version'      => '2',
        'admin_reminder_hours'     => '48',

        // Calendar
        'default_session_duration' => '60',
        'buffer_minutes'           => '0',
        'min_notice_hours'         => '24',
        'max_advance_days'         => '60',

        // Practice identity (used in outbound mail)
        'practice_name'            => 'Rewire With Kajal',
        'practice_email'           => 'hello@rewirewithkajal.com',

        // Outbound copy for the confirm email. {{name}}, {{intake_link}},
        // {{expires}} and {{practice_name}} are substituted at send time.
        'notify_lead_confirmed_subject' => 'Complete your intake form - {{practice_name}}',
        'notify_lead_confirmed_body'    =>
            "Hello {{name}},

" .
            "Thank you for getting in touch. Please take a few moments to complete " .
            "your intake questionnaire using your personal link below:

" .
            "{{intake_link}}

" .
            "This link is unique to you, so please do not forward it. It stays " .
            "active until {{expires}}.

" .
            "Best regards,
{{practice_name}}",

        // Sessions
        // Off by default: a booking someone has not confirmed should say so
        // rather than claim to be locked in.
        'auto_confirm_sessions'    => '0',
        // One static practice room, reused every session. A per-session room
        // is a real API integration and only worth it when a client needs
        // session isolation.
        'practice_video_link'      => '',
        'session_reminder_hours'   => '24',

        // PHP defaults to UTC on this install while MySQL runs on local time.
        // Left alone, a session written with PHP's clock and compared against
        // MySQL's NOW() is hours out: the sweep completes future sessions and
        // reminders never fire. Every request aligns PHP to this.
        'practice_timezone'        => 'Asia/Kolkata',

        // Session mail. {{client_name}}, {{session_time}}, {{session_type}},
        // {{video_link}}, {{cancel_reason}} and {{practice_name}} substitute.
        'notify_session_confirmed_subject' => 'Your session on {{session_time}} - {{practice_name}}',
        'notify_session_confirmed_body'    =>
            "Hello {{client_name}},

" .
            "Your session is confirmed for {{session_time}}.

" .
            "Format: {{session_type}}
" .
            "Joining link: {{video_link}}

" .
            "If you need to change or cancel it, just reply to this email.

" .
            "Best regards,
{{practice_name}}",

        'notify_session_cancelled_subject' => 'Your session on {{session_time}} has been cancelled',
        'notify_session_cancelled_body'    =>
            "Hello {{client_name}},

" .
            "Your session on {{session_time}} has been cancelled.

" .
            "{{cancel_reason}}

" .
            "Reply to this email and we will find another time.

" .
            "Best regards,
{{practice_name}}",

        'notify_session_reminder_subject'  => 'Reminder: your session on {{session_time}}',
        'notify_session_reminder_body'     =>
            "Hello {{client_name}},

" .
            "This is a reminder of your session on {{session_time}}.

" .
            "Format: {{session_type}}
" .
            "Joining link: {{video_link}}

" .
            "Best regards,
{{practice_name}}",

        // Uploads. Shared with Patient Intake the day it gains a file
        // question -- one setting, two consumers.
        'upload_max_mb'         => '10',
        'upload_allowed_types'  => 'pdf,jpg,jpeg,png,doc,docx',
        // Blank = a sibling of the project directory. Never put this inside
        // the web root: client documents are consent forms and ID proof.
        'document_storage_path' => '',

        // Absolute base URL, e.g. "http://localhost/Kaja/".
        // Leave blank to auto-detect from the request; MUST be set for CLI
        // jobs, which have no HTTP_HOST to derive it from.
        'site_base_url'            => '',
    ];
}

/**
 * Every setting, loaded once per request.
 * A missing `settings` table degrades to defaults rather than fataling, so
 * the public site keeps working if the migration has not been run yet.
 */
function settingsAll($forceReload = false) {
    static $cache = null;

    if ($forceReload) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $db = getDbConnection();
        $rows = $db->query("SELECT `setting_key`, `setting_value` FROM `settings`");
        foreach ($rows as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        $cache = [];
    }

    return $cache;
}

/**
 * Read one setting. Precedence: stored value -> explicit $fallback -> default.
 * An empty stored string counts as "unset" so a blanked field falls back.
 */
function getSetting($key, $fallback = null) {
    $all = settingsAll();

    if (isset($all[$key]) && $all[$key] !== '') {
        return $all[$key];
    }
    if ($fallback !== null) {
        return $fallback;
    }

    $defaults = settingDefaults();
    return isset($defaults[$key]) ? $defaults[$key] : null;
}

function getSettingInt($key, $fallback = null) {
    $value = getSetting($key, $fallback === null ? null : (string) $fallback);
    return (int) $value;
}

/**
 * Write one setting. INSERT ... ON DUPLICATE KEY UPDATE so brand-new keys
 * work without a schema change. Busts the request cache on success.
 */
function setSetting($key, $value) {
    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO `settings` (`setting_key`, `setting_value`)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)
    ");
    $ok = $stmt->execute([':k' => $key, ':v' => (string) $value]);

    if ($ok) {
        settingsAll(true);
    }
    return $ok;
}

/**
 * Substitute {{placeholders}} in a notification template.
 *
 * One pass with strtr(), not a loop of str_replace(): a loop would let a value
 * containing "{{intake_link}}" be expanded by a later iteration, which is how
 * a person's typed name could turn into someone else's personal link.
 *
 * An unrecognised placeholder is left in place. Blanking it would hide the
 * typo; leaving it visible means whoever edits the template sees it.
 */
function renderNotificationTemplate($template, array $vars) {
    $map = [];
    foreach ($vars as $key => $value) {
        $map['{{' . $key . '}}'] = (string) $value;
    }
    return strtr((string) $template, $map);
}

/**
 * Put PHP on the same clock as the database.
 *
 * MySQL runs on the server's local time; PHP defaults to UTC unless
 * date.timezone is set in php.ini, which it is not here. That gap silently
 * breaks every comparison between a time PHP wrote and a time MySQL evaluates
 * — a session booked for an hour from now reads as hours in the past.
 *
 * Called once per request from this file, so anything that requires settings
 * gets it for free.
 */
function applyPracticeTimezone() {
    static $applied = false;
    if ($applied) {
        return;
    }
    $applied = true;

    $tz = getSetting('practice_timezone', 'Asia/Kolkata');
    if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

applyPracticeTimezone();
