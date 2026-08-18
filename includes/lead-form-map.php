<?php
/**
 * What the public form asked, per version.
 *
 * The lead's `form_version_id` is pinned at submit time, so the drawer renders
 * the questions that were actually on screen when this person filled the form
 * — not the questions that are on it today. When a field is added or a label
 * is reworded, add a NEW version here and leave the old one untouched;
 * editing an existing version rewrites history for every lead that used it.
 */

function leadFormVersions() {
    return [1];
}

function leadFormFields($version) {
    $versions = [
        1 => [
            ['key' => 'name',           'label' => 'Full name',      'type' => 'text'],
            ['key' => 'email',          'label' => 'Email',          'type' => 'text'],
            ['key' => 'phone',          'label' => 'Phone',          'type' => 'text'],
            ['key' => 'preferred_date', 'label' => 'Preferred date', 'type' => 'date'],
            ['key' => 'preferred_time', 'label' => 'Preferred time', 'type' => 'time'],
            ['key' => 'preference',     'label' => 'Session format', 'type' => 'text'],
            ['key' => 'message',        'label' => 'Message',        'type' => 'longtext'],
        ],
    ];

    $version = (int) $version;
    // An unrecognised version means data from a future or hand-edited row.
    // Falling back to v1 shows something useful; returning [] would show a
    // blank drawer and hide the lead's answers entirely.
    return isset($versions[$version]) ? $versions[$version] : $versions[1];
}

function leadAnswers(array $lead) {
    $version = isset($lead['form_version_id']) ? (int) $lead['form_version_id'] : 1;
    $answers = [];

    foreach (leadFormFields($version) as $field) {
        $raw = isset($lead[$field['key']]) ? trim((string) $lead[$field['key']]) : '';

        if ($raw === '') {
            $value = '—';
        } elseif ($field['type'] === 'date') {
            $ts    = strtotime($raw);
            $value = $ts ? date('d M Y', $ts) : $raw;
        } elseif ($field['type'] === 'time') {
            $ts    = strtotime($raw);
            $value = $ts ? date('h:i A', $ts) : $raw;
        } else {
            $value = $raw;
        }

        $answers[] = ['label' => $field['label'], 'value' => $value, 'type' => $field['type']];
    }

    return $answers;
}
