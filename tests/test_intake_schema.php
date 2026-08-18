<?php
require_once __DIR__ . '/../includes/intake-schema.php';

test('version 1 is the form as it originally shipped', function () {
    $fields = intakeSchemaFields(1);
    foreach (['first_name', 'email', 'dob', 'pref_consult', 'q1_1', 'q2_18', 'consent_given'] as $id) {
        assertTrue(isset($fields[$id]), 'v1 should define ' . $id);
    }
    assertTrue(!isset($fields['emergency_name']), 'v1 predates the emergency contact');
});

test('version 2 adds the new sections without disturbing version 1', function () {
    $v2 = intakeSchemaFields(2);
    foreach (['emergency_name', 'emergency_phone', 'emergency_relation',
              'presenting_concern', 'referral_source',
              'seen_therapist_before', 'previous_therapist', 'reason_stopped',
              'on_medication', 'medication_list'] as $id) {
        assertTrue(isset($v2[$id]), 'v2 should define ' . $id);
    }
    assertTrue(isset($v2['q1_1']), 'and still carries the original questions');
});

test('an unknown version falls back to the newest rather than to nothing', function () {
    assertTrue(count(intakeSchemaFields(99)) > 0, 'never render a blank record');
});

test('every field has an id, a label and a known type', function () {
    foreach (intakeSchemaVersions() as $v) {
        foreach (intakeSchemaFields($v) as $id => $f) {
            assertTrue($f['label'] !== '', $id . ' needs a label');
            assertContains($f['type'],
                ['text', 'tel', 'date', 'textarea', 'select', 'yesno', 'checkbox'],
                $id . ' has a known type');
        }
    }
});

test('conditional fields are hidden until their trigger answer arrives', function () {
    $fields = intakeSchemaFields(2);
    $med    = $fields['medication_list'];

    assertSame(false, intakeFieldIsVisible($med, []), 'hidden with no answer');
    assertSame(false, intakeFieldIsVisible($med, ['on_medication' => 'no']), 'hidden on no');
    assertSame(true,  intakeFieldIsVisible($med, ['on_medication' => 'yes']), 'revealed on yes');
});

test('a hidden field is not required', function () {
    $required = intakeRequiredFields(2, ['on_medication' => 'no']);
    assertTrue(!in_array('medication_list', $required, true), 'not required while hidden');

    $required = intakeRequiredFields(2, ['on_medication' => 'yes']);
    assertContains('medication_list', $required, 'required once revealed');
});

test('consent and emergency contact are required in version 2 regardless of answers', function () {
    $required = intakeRequiredFields(2, []);
    foreach (['consent_given', 'emergency_name', 'emergency_phone', 'emergency_relation'] as $id) {
        assertContains($id, $required, $id . ' is never optional');
    }
});

test('every schema field exists in the form markup', function () {
    // The HTML is the source of truth for presentation, the schema for
    // validation. This is what stops them drifting apart in silence.
    $html = file_get_contents(dirname(__DIR__) . '/patient-intake-form.html');
    foreach (intakeSchemaFields(2) as $id => $f) {
        assertTrue(
            strpos($html, 'name="' . $id . '"') !== false,
            'the form is missing an input named ' . $id
        );
    }
});
