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

/**
 * The 36 yes/no items, in the wording the form actually asks.
 *
 * These used to be generated as "Questionnaire 1, question 7" -- fine as a
 * storage key and useless everywhere a person reads it: the form module, a
 * client's answers on their profile, an exported record. The text below is the
 * text in patient-intake-form.html, and a test holds the two together so
 * neither can be reworded on its own.
 */
function intakeQuestionnaireText() {
    return [
        1 => [
            'Have you ever walked in your sleep during your adult life?',
            'As a teenager did you feel comfortable expressing your feelings to one or both of your parents or friends?',
            'Do you have a tendency to look directly into a person\'s eyes and /or move closely to them when discussing an interesting subject?',
            'Do you feel that most people, when you first meet them, are uncritical of your appearance?',
            'In a group situation, with people that you have just met, would you feel comfortable drawing attention to yourself by initiating a conversation?',
            'Do you feel comfortable holding hands or hugging someone you are in a relationship with in front of other people?',
            'When someone talks about feeling warm physically, do you begin to feel warm also?',
            'Do you tend to occasionally tune out when someone is talking to you because you are anxious to come up with your side, and at times not hear what the other person said?',
            'Do you feel that you learn and comprehend better by seeing and/or reading than by hearing?',
            'In a new class or lecture situation do you usually feel comfortable asking questions in front of the group?',
            'When expressing your ideas do you find it important to relate all the details leading up to the subject so the other person can understand it completely.',
            'Do you enjoy relating to children?',
            'Do you find it easy to be at ease and comfortable with your body movements, even when faced with unfamiliar people and circumstances?',
            'Do you prefer reading fiction rather than non-fiction?',
            'If you were to imagine sucking on a sour, bitter, juicy, yellow lemon, would your mouth water?',
            'If you feel that you deserve to be complimented for something well done, do you feel comfortable if the compliment is given to you in front of other people?',
            'Do you feel that you are a good conversationalist?',
            'Do you feel comfortable when complimentary attention is drawn to your physical body or appearance?',
        ],
        2 => [
            'Have you ever awakened in the middle of the night and felt that you could not move your body and or talk?',
            'As a child did you feel that you were more affected by the tone of voice of your parents than by what they actually said?',
            'If someone you are associated with talks about a fear that you too have experienced, do you have a tendency to have an apprehensive or fearful feeling also?',
            'After having an argument with someone, do you have a tendency to dwell on what you could or should have said?',
            'Do you tend to occasionally tune out when someone is talking to you and do not hear what was said because your mind drifts to something totally unrelated?',
            'Do you sometimes desire to be complimented for a job done well done, but feel embarrassed or uncomfortable when complimented?',
            'Do you often have a fear or dread of not being able to carry on a conversation with someone you just met?',
            'Do you feel self conscious, when attention is drawn to your physical body or appearance?',
            'If you have a choice, would you rather avoid being around children most of the time?',
            'Do you feel that you are relaxed or loose in body movement, especially when faced with unfamiliar people or circumstances?',
            'Do you prefer reading non-fiction rather than fiction?',
            'If someone describes a very bitter taste, do you have difficulty experiencing the physical feeling of it?',
            'Do you generally feel that you see yourself less favorably than others see you?',
            'Do you tend to feel awkward or self- conscious initiating touch ( holding hands, kissing, etc.) with someone you are in relationship with in front of other people?',
            'In a new class or lecture situation do you usually feel comfortable asking questions in front of group even though you may desire further explanation?',
            'Do you feel uneasy if someone you have just met looks directly in the eyes when talking to you, especially if the conversation is all about you?',
            'In a group situation with people you have just met, would you feel uncomfortable drawing attention to yourself by initiating a conversation?',
            'If you are in a relationship, or are very close to someone, do you find it difficult or embarrassing to verbalize your love for them?',
        ],
    ];
}

/** The 36 yes/no items, identical across versions so far. */
function intakeQuestionnaireFields($set) {
    $text   = intakeQuestionnaireText();
    $fields = [];
    for ($i = 1; $i <= 18; $i++) {
        $fields[] = [
            'id'       => 'q' . $set . '_' . $i,
            'label'    => isset($text[$set][$i - 1])
                ? $text[$set][$i - 1]
                : 'Questionnaire ' . $set . ', question ' . $i,
            'type'     => 'yesno',
            'required' => true,
        ];
    }
    return $fields;
}

/**
 * Sections the form module does not get to edit.
 *
 * These are not questions in the ordinary sense. Personal information IS the
 * client record -- first_name, email, dob and the rest are columns on
 * `clients`, read by name all over the admin -- and the emergency contact is
 * the one block whose absence is a safety problem rather than a gap in a
 * questionnaire. Reword or reorder either and something downstream stops
 * finding what it reads by id.
 *
 * They are still asked, still seeded, still rendered. They are simply not on
 * offer in the builder, and the builder's own writes refuse them.
 */
function intakeFixedSections() {
    return ['Personal information', 'Emergency contact'];
}

function intakeSectionIsFixed($title) {
    return in_array(trim((string) $title), intakeFixedSections(), true);
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

/**
 * The database wins when the form module has a copy of this version.
 *
 * Seeding is what puts it there, so an untouched install keeps running off the
 * PHP map below and nothing changes until someone opens the form module.
 *
 * Cached per request: intakeSchema() is called once per section per render and
 * the answer cannot change mid-request.
 */
function intakeSchemaOverride($version) {
    static $cache = [];
    $version = (int) $version;

    if (array_key_exists($version, $cache)) {
        return $cache[$version];
    }
    $cache[$version] = null;

    // A missing table means the migration has not run; fall back rather than
    // taking the public intake form down over an admin feature.
    try {
        require_once __DIR__ . '/../db-config.php';
        $db = getDbConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM `form_questions` WHERE `form_version` = :v');
        $stmt->execute([':v' => $version]);
        if ((int) $stmt->fetchColumn() > 0) {
            require_once __DIR__ . '/form-builder.php';
            $cache[$version] = formSchemaFromDb($db, $version);
        }
    } catch (Throwable $e) {
        $cache[$version] = null;
    }

    return $cache[$version];
}

/**
 * What the form asks today: the database copy if there is one, else the map
 * this file ships with.
 */
function intakeSchema($version) {
    $override = intakeSchemaOverride((int) $version);
    if ($override !== null && $override !== []) {
        return $override;
    }
    return intakeSchemaShipped($version);
}

/**
 * The map this file ships with, whatever the database currently holds.
 *
 * Kept separate from intakeSchema() because there is one job that must not ask
 * the database: putting the sections back into the order they were designed
 * in. Reading the override there compares the stored order against itself and
 * concludes, every time, that nothing needs moving.
 */
function intakeSchemaShipped($version) {
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
