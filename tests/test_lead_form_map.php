<?php
require_once __DIR__ . '/../includes/lead-form-map.php';

test('version 1 describes the booking form as it shipped', function () {
    $keys = array_column(leadFormFields(1), 'key');
    foreach (['name', 'email', 'phone', 'preferred_date', 'preferred_time', 'preference', 'message'] as $k) {
        assertContains($k, $keys, 'version 1 should include ' . $k);
    }
});

test('answers are rendered against the version pinned on the lead', function () {
    $lead = [
        'form_version_id' => 1,
        'name'            => 'Anita Rao',
        'email'           => 'anita@example.com',
        'phone'           => '9990001111',
        'preferred_date'  => '2026-09-01',
        'preferred_time'  => '14:30:00',
        'preference'      => 'online',
        'message'         => 'Looking for evening sessions.',
    ];
    $answers = leadAnswers($lead);
    $labels  = array_column($answers, 'label');

    assertContains('Full name', $labels, 'the label comes from the field map, not the column name');
    assertSame('Anita Rao', $answers[0]['value'], 'first answer is the name');
});

test('a time is rendered in the same 12-hour form the list uses', function () {
    $answers = leadAnswers(['form_version_id' => 1, 'preferred_time' => '14:30:00']);
    $byLabel = [];
    foreach ($answers as $a) { $byLabel[$a['label']] = $a['value']; }
    assertSame('02:30 PM', $byLabel['Preferred time'], 'afternoon must not read as morning');
});

test('a field the lead has no value for still renders, as an em dash', function () {
    $answers = leadAnswers(['form_version_id' => 1, 'name' => 'Anita Rao']);
    $byLabel = [];
    foreach ($answers as $a) { $byLabel[$a['label']] = $a['value']; }
    assertSame('—', $byLabel['Message'], 'a blank answer is shown as blank, not omitted');
});

test('an unknown version falls back to version 1 rather than rendering nothing', function () {
    $answers = leadAnswers(['form_version_id' => 99, 'name' => 'Anita Rao']);
    assertTrue(count($answers) > 0, 'an unrecognised version must not blank the drawer');
});
