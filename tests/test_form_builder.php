<?php
require_once __DIR__ . '/../includes/form-builder.php';

function freshForm() {
    resetTestTables(['form_questions', 'intake_links', 'clients', 'leads']);
}

test('seeding copies a version out of the PHP schema', function () {
    freshForm();
    $n = seedFormVersion(testDb(), 2);
    assertTrue($n > 0, 'questions were copied');
    assertSame(count(intakeSchemaFields(2)), count(formQuestions(testDb(), 2)),
        'every field in the schema became a row');
});

test('seeding twice does not duplicate a version', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $before = count(formQuestions(testDb(), 2));

    assertSame(0, seedFormVersion(testDb(), 2), 'the second call is a no-op');
    assertSame($before, count(formQuestions(testDb(), 2)), 'and nothing was added');
});

test('the database rebuilds the same shape the PHP schema returns', function () {
    freshForm();
    seedFormVersion(testDb(), 2);

    $fromDb  = formSchemaFromDb(testDb(), 2);
    $fromPhp = intakeSchema(2);

    assertSame(array_column($fromPhp, 'title'), array_column($fromDb, 'title'),
        'same sections in the same order');
    assertSame(count($fromPhp[0]['fields']), count($fromDb[0]['fields']),
        'and the same fields inside the first one');
});

test('a conditional question keeps its reveal rule through the round trip', function () {
    freshForm();
    seedFormVersion(testDb(), 2);

    $fields = [];
    foreach (formSchemaFromDb(testDb(), 2) as $sec) {
        foreach ($sec['fields'] as $f) { $fields[$f['id']] = $f; }
    }
    assertTrue(isset($fields['medication_list']['reveal_when']), 'the rule survived');
    assertSame('on_medication', $fields['medication_list']['reveal_when']['field'], 'pointing at the right question');
});

test('a question can be reworded on a version nobody has answered', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $q = formQuestions(testDb(), 2)[0];

    assertSame(true, updateFormQuestion(testDb(), $q['id'], ['label' => 'Your legal first name']), 'edited');
    assertSame('Your legal first name', formQuestions(testDb(), 2)[0]['label'], 'and it stuck');
});

test('editing a version a client has already answered is refused', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`intake_form_version`)
                       VALUES (:l,"Anita","Rao","a@example.com",2)')->execute([':l' => $leadId]);

    $q = formQuestions(testDb(), 2)[0];
    assertThrows(function () use ($q) {
        updateFormQuestion(testDb(), $q['id'], ['label' => 'Rewriting history']);
    }, 'those answers are recorded against these questions');
});

test('editing a version with a live link out is refused', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`form_version`,`status`,`expires_at`)
        VALUES (:l, :t, 2, "sent", DATE_ADD(NOW(), INTERVAL 14 DAY))
    ')->execute([':l' => $leadId, ':t' => str_repeat('c', 64)]);

    $q = formQuestions(testDb(), 2)[0];
    assertThrows(function () use ($q) {
        updateFormQuestion(testDb(), $q['id'], ['label' => 'Changing it mid-flight']);
    }, 'somebody is about to answer exactly these questions');
});

test('a submitted link no longer blocks editing', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`form_version`,`status`,`expires_at`)
        VALUES (:l, :t, 2, "submitted", DATE_ADD(NOW(), INTERVAL 14 DAY))
    ')->execute([':l' => $leadId, ':t' => str_repeat('d', 64)]);

    $q = formQuestions(testDb(), 2)[0];
    assertSame(true, updateFormQuestion(testDb(), $q['id'], ['label' => 'Fine to edit']),
        'the client record is what pins it, and there is none here');
});

test('publishing copies the version and leaves the original alone', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $original = count(formQuestions(testDb(), 2));

    $new = publishNewFormVersion(testDb(), 2);
    assertSame(3, $new, 'the next number up');
    assertSame($original, count(formQuestions(testDb(), $new)), 'with every question copied');
    assertSame($original, count(formQuestions(testDb(), 2)), 'and version 2 untouched');
});

test('the new version can be edited even when the old one is locked', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`intake_form_version`)
                       VALUES (:l,"Anita","Rao","a@example.com",2)')->execute([':l' => $leadId]);

    $new = publishNewFormVersion(testDb(), 2);
    $q   = formQuestions(testDb(), $new)[0];

    assertSame(true, updateFormQuestion(testDb(), $q['id'], ['label' => 'Reworded for the next person']),
        'this is the whole point of publishing');
});

test('an unknown field type is refused', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $q = formQuestions(testDb(), 2)[0];

    assertThrows(function () use ($q) {
        updateFormQuestion(testDb(), $q['id'], ['field_type' => 'signature']);
    }, 'the enum would coerce it, so it is checked first');
});

test('a blank label is refused', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $q = formQuestions(testDb(), 2)[0];

    assertThrows(function () use ($q) {
        updateFormQuestion(testDb(), $q['id'], ['label' => '   ']);
    }, 'a question with no text is not a question');
});

test('reordering moves a question without renumbering the rest', function () {
    freshForm();
    seedFormVersion(testDb(), 2);
    $qs = formQuestions(testDb(), 2);

    // Sort orders are spaced by ten, so there is room to land between two.
    updateFormQuestion(testDb(), $qs[2]['id'], ['sort_order' => 5]);
    assertSame($qs[2]['field_id'], formQuestions(testDb(), 2)[0]['field_id'], 'now first');
});
