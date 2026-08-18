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
