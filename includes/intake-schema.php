<?php
/**
 * What the intake form asked, per version.
 *
 * Append-only. Editing an existing version rewrites history for every record
 * filled under it — the answers stay, but the questions they were answers TO
 * would silently change. Add a version instead.
 *
 * The HTML remains the source of truth for presentation; this file is the
 * source of truth for validation and for rendering answers back. A test
 * asserts every field here has a matching input in the markup, so the two
 * cannot drift apart in silence.
 */

function intakeSchemaVersions() {
    return [1, 2];
}

/** The 36 yes/no items, identical across versions so far. */
function intakeQuestionnaireFields($set) {
    $fields = [];
    for ($i = 1; $i <= 18; $i++) {
        $fields[] = [
            'id'       => 'q' . $set . '_' . $i,
            'label'    => 'Questionnaire ' . $set . ', question ' . $i,
            'type'     => 'yesno',
            'required' => true,
        ];
    }
    return $fields;
}

function intakePersonalSection() {
    return [
        'title'  => 'Personal information',
        'fields' => [
            ['id' => 'first_name', 'label' => 'First name',      'type' => 'text', 'required' => true],
            ['id' => 'last_name',  'label' => 'Last name',       'type' => 'text', 'required' => true],
            ['id' => 'email',      'label' => 'Email',           'type' => 'text', 'required' => true],
            ['id' => 'phone',      'label' => 'Phone',           'type' => 'tel',  'required' => true],
            ['id' => 'city',       'label' => 'City',            'type' => 'text', 'required' => true],
            ['id' => 'occupation', 'label' => 'Occupation',      'type' => 'text', 'required' => true],
            ['id' => 'dob',        'label' => 'Date of birth',   'type' => 'date', 'required' => true],
            ['id' => 'concern',    'label' => 'Primary concern', 'type' => 'text', 'required' => true],
        ],
    ];
}

function intakePreferencesSection() {
    return [
        'title'  => 'Preferences',
        'fields' => [
            ['id' => 'pref_consult', 'label' => 'Consultation preference', 'type' => 'select', 'required' => true],
            ['id' => 'pref_date',    'label' => 'Preferred date',          'type' => 'date',   'required' => true],
            ['id' => 'pref_time',    'label' => 'Preferred time',          'type' => 'text',   'required' => true],
        ],
    ];
}

function intakeConsentSection() {
    return [
        'title'  => 'Consent',
        'fields' => [
            ['id' => 'consent_given', 'label' => 'Consent to treatment and confidentiality', 'type' => 'checkbox', 'required' => true],
        ],
    ];
}

function intakeSchema($version) {
    $version = (int) $version;

    if ($version === 1) {
        return [
            intakePersonalSection(),
            intakePreferencesSection(),
            ['title' => 'Questionnaire 1', 'fields' => intakeQuestionnaireFields(1)],
            ['title' => 'Questionnaire 2', 'fields' => intakeQuestionnaireFields(2)],
            intakeConsentSection(),
        ];
    }

    // Version 2. The emergency contact sits directly after personal details
    // because that is where someone expects to be asked for it, and the
    // background block is conditional so nobody answers questions that do not
    // apply to them.
    $v2 = [
        intakePersonalSection(),
        [
            'title'  => 'Emergency contact',
            'fields' => [
                ['id' => 'emergency_name',     'label' => 'Emergency contact name',  'type' => 'text', 'required' => true],
                ['id' => 'emergency_phone',    'label' => 'Emergency contact phone', 'type' => 'tel',  'required' => true],
                ['id' => 'emergency_relation', 'label' => 'Relationship to you',     'type' => 'text', 'required' => true],
            ],
        ],
        [
            'title'  => 'Why you are here',
            'fields' => [
                ['id' => 'presenting_concern', 'label' => 'What brings you to therapy, in your own words', 'type' => 'textarea', 'required' => true],
                ['id' => 'referral_source',    'label' => 'How did you hear about us',                     'type' => 'select',   'required' => false],
            ],
        ],
        [
            'title'  => 'Background',
            'fields' => [
                ['id' => 'seen_therapist_before', 'label' => 'Have you seen a therapist before', 'type' => 'yesno', 'required' => true],
                ['id' => 'previous_therapist',    'label' => 'Who did you see',      'type' => 'text',     'required' => true,
                 'reveal_when' => ['field' => 'seen_therapist_before', 'equals' => 'yes']],
                ['id' => 'reason_stopped',        'label' => 'What led you to stop', 'type' => 'textarea', 'required' => true,
                 'reveal_when' => ['field' => 'seen_therapist_before', 'equals' => 'yes']],
                ['id' => 'on_medication',         'label' => 'Are you currently taking any medication', 'type' => 'yesno', 'required' => true],
                ['id' => 'medication_list',       'label' => 'Which medication',     'type' => 'textarea', 'required' => true,
                 'reveal_when' => ['field' => 'on_medication', 'equals' => 'yes']],
            ],
        ],
        intakePreferencesSection(),
        ['title' => 'Questionnaire 1', 'fields' => intakeQuestionnaireFields(1)],
        ['title' => 'Questionnaire 2', 'fields' => intakeQuestionnaireFields(2)],
        intakeConsentSection(),
    ];

    // Unknown version: render against the newest we know. A blank record would
    // hide someone's answers entirely, which is worse than slightly wrong
    // labels on a record from a version we have never heard of.
    return $v2;
}

/** Flat id => field map for the version. */
function intakeSchemaFields($version) {
    $out = [];
    foreach (intakeSchema($version) as $section) {
        foreach ($section['fields'] as $field) {
            $out[$field['id']] = $field;
        }
    }
    return $out;
}

/** A field with no reveal rule is always visible. */
function intakeFieldIsVisible(array $field, array $answers) {
    if (empty($field['reveal_when'])) {
        return true;
    }
    $rule = $field['reveal_when'];
    $seen = isset($answers[$rule['field']]) ? $answers[$rule['field']] : null;
    return $seen === $rule['equals'];
}

/**
 * Which fields must be answered, given what has been answered so far.
 * A field nobody can see is never required — that is the whole point of
 * revealing it.
 */
function intakeRequiredFields($version, array $answers) {
    $required = [];
    foreach (intakeSchemaFields($version) as $id => $field) {
        if (empty($field['required'])) {
            continue;
        }
        if (!intakeFieldIsVisible($field, $answers)) {
            continue;
        }
        $required[] = $id;
    }
    return $required;
}
