<?php
require_once __DIR__ . '/../includes/intake-schema.php';

test('the handler validates against the schema, not a hand-written list', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    assertTrue(strpos($src, 'intakeRequiredFields') !== false, 'requirements come from the schema');
    assertTrue(strpos($src, 'saveClientIntakeData') !== false, 'answers are written as JSON');
});

test('submission leaves the client awaiting review, not active', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    assertTrue(strpos($src, "'review'") !== false, 'a human looks before the client is bookable');
    assertTrue(strpos($src, "'active')") === false, 'nothing still inserts a client straight to active');
});

test('a missing emergency contact fails validation for version 2', function () {
    $answers = ['first_name' => 'Anita', 'consent_given' => '1'];
    $missing = [];
    foreach (intakeRequiredFields(2, $answers) as $id) {
        if (empty($answers[$id])) $missing[] = $id;
    }
    assertContains('emergency_name', $missing, 'the emergency contact is not optional');
});

test('a version 1 submission is not held to version 2 requirements', function () {
    $required = intakeRequiredFields(1, []);
    assertTrue(!in_array('emergency_name', $required, true),
        'someone filling the old form cannot be blocked on a question it never asked');
});

test('the archive insert is built from the version 1 field set only', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    assertTrue(strpos($src, 'intakeSchemaFields(1)') !== false,
        'patient-intake only ever had v1 columns, so it is written from v1');
});

test('the therapist notification is sent after the commit', function () {
    $src    = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    $commit = strpos($src, '$db->commit();');
    $notify = strpos($src, 'Intake ready for review');
    assertTrue($commit !== false && $notify !== false, 'both exist');
    assertTrue($notify > $commit, 'a dead mail server must not undo a submission');
});
