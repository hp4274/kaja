<?php
require_once __DIR__ . '/../includes/intake-data.php';

function seedClient($status = 'pending') {
    resetTestTables(['clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
        VALUES (:l, "Anita", "Rao", "anita@example.com", :s)
    ')->execute([':l' => $leadId, ':s' => $status]);
    return (int) testDb()->lastInsertId();
}

test('answers round-trip through the encrypted column', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['first_name' => 'Anita', 'q1_1' => 'yes'], 2);

    $read = readClientIntakeData(testDb(), $id);
    assertSame('Anita', $read['answers']['first_name'], 'answers come back');
    assertSame(2, $read['version'], 'and so does the version they belong to');
});

test('what lands in the column is not readable', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['presenting_concern' => 'panic attacks at work'], 2);

    $raw = testDb()->query('SELECT `intake_data` FROM `clients` WHERE `id`=' . $id)->fetchColumn();
    assertTrue(strpos($raw, 'panic') === false, 'the plaintext is not in the database');
});

test('saving stamps the submission time', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['first_name' => 'Anita'], 2);
    assertTrue(readClientIntakeData(testDb(), $id)['submitted_at'] !== null, 'stamped');
});

test('a client with no intake reads as null, not as an empty record', function () {
    $id = seedClient();
    assertSame(null, readClientIntakeData(testDb(), $id), 'nothing there is null');
});

test('render groups answers by section with their labels', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, [
        'first_name' => 'Anita', 'emergency_name' => 'Ravi Rao',
    ], 2);

    $sections = renderClientIntake(testDb(), $id);
    $titles   = array_column($sections, 'title');
    assertContains('Emergency contact', $titles, 'sections survive');

    $found = null;
    foreach ($sections as $s) {
        foreach ($s['answers'] as $a) {
            if ($a['label'] === 'Emergency contact name') $found = $a['value'];
        }
    }
    assertSame('Ravi Rao', $found, 'label and answer are paired');
});

test('a field the person never answered renders as an em dash', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['first_name' => 'Anita'], 2);

    $blank = null;
    foreach (renderClientIntake(testDb(), $id) as $s) {
        foreach ($s['answers'] as $a) {
            if ($a['label'] === 'Last name') $blank = $a['value'];
        }
    }
    assertSame('—', $blank, 'unanswered is shown, not hidden');
});

test('a hidden conditional field is left out of the render entirely', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['on_medication' => 'no'], 2);

    $labels = [];
    foreach (renderClientIntake(testDb(), $id) as $s) {
        foreach ($s['answers'] as $a) { $labels[] = $a['label']; }
    }
    assertTrue(!in_array('Which medication', $labels, true),
        'a question that was never asked must not appear as unanswered');
});

test('an old record renders against its own version', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['first_name' => 'Anita'], 1);

    $titles = array_column(renderClientIntake(testDb(), $id), 'title');
    assertTrue(!in_array('Emergency contact', $titles, true),
        'v1 never asked for one, so it is not shown as missing');
});

test('a consent checkbox renders as Yes or No, not as a raw 1', function () {
    $id = seedClient();
    saveClientIntakeData(testDb(), $id, ['consent_given' => '1'], 2);

    $consent = null;
    foreach (renderClientIntake(testDb(), $id) as $s) {
        foreach ($s['answers'] as $a) {
            if ($a['type'] === 'checkbox') $consent = $a['value'];
        }
    }
    assertSame('Yes', $consent, 'readable, not a database artefact');
});
