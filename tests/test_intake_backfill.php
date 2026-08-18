<?php
require_once __DIR__ . '/../tools/backfill-intake.php';
require_once __DIR__ . '/../includes/intake-data.php';

test('a legacy row maps onto the version 1 schema', function () {
    $row = ['first_name' => 'Anita', 'last_name' => 'Rao', 'email' => 'a@example.com',
            'phone' => '9990001111', 'city' => 'Pune', 'occupation' => 'Teacher',
            'dob' => '1990-05-05', 'concern' => 'Anxiety',
            'pref_consult' => 'Online', 'pref_date' => '2026-09-01', 'pref_time' => '10:00 AM',
            'consent_given' => 1];
    for ($s = 1; $s <= 2; $s++) { for ($i = 1; $i <= 18; $i++) { $row["q{$s}_{$i}"] = 'no'; } }

    $answers = backfillIntakeRow($row);
    assertSame('Anita', $answers['first_name'], 'personal details carry over');
    assertSame('no', $answers['q2_18'], 'and so does the last questionnaire item');
});

test('a column the legacy row does not have becomes an empty string', function () {
    $answers = backfillIntakeRow(['first_name' => 'Anita']);
    assertSame('', $answers['q1_1'], 'missing is empty, not absent');
});

test('the backfill never invents version 2 answers', function () {
    $answers = backfillIntakeRow(['first_name' => 'Anita']);
    assertTrue(!isset($answers['emergency_name']),
        'nobody was asked for an emergency contact under v1');
});

test('the backfill query hands back the CLIENT id, not the archive row id', function () {
    // Regression. The query selects `c.id AS <alias>, pi.*`, and
    // `patient-intake` has its own client_id column. Aliasing to client_id
    // let pi.client_id overwrite it with NULL, so every UPDATE matched zero
    // rows and the backfill silently did nothing at all.
    resetTestTables(['clients', 'patient-intake', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);

    $cols = ['first_name'=>'Legacy','last_name'=>'Person','email'=>'l@example.com','phone'=>'900',
             'city'=>'Pune','occupation'=>'X','dob'=>'1990-01-01','concern'=>'Y',
             'pref_consult'=>'Online','pref_date'=>'2026-09-01','pref_time'=>'10:00'];
    for ($s = 1; $s <= 2; $s++) { for ($i = 1; $i <= 18; $i++) { $cols["q{$s}_{$i}"] = 'yes'; } }

    $names = implode(',', array_map(function ($c) { return '`' . $c . '`'; }, array_keys($cols)));
    $ph    = implode(',', array_map(function ($c) { return ':' . $c; }, array_keys($cols)));
    $bind  = [];
    foreach ($cols as $k => $v) { $bind[':' . $k] = $v; }

    testDb()->prepare("INSERT INTO `patient-intake` ({$names}) VALUES ({$ph})")->execute($bind);
    $intakeId = (int) testDb()->lastInsertId();

    testDb()->prepare('
        INSERT INTO `clients` (`lead_id`,`patient_intake_id`,`first_name`,`last_name`,`email`,`status`)
        VALUES (:l, :pi, "Legacy", "Person", "l@example.com", "active")
    ')->execute([':l' => $leadId, ':pi' => $intakeId]);
    $clientId = (int) testDb()->lastInsertId();

    $rows = testDb()->query('
        SELECT c.`id` AS backfill_client_id, pi.*
        FROM `clients` c
        JOIN `patient-intake` pi ON pi.`id` = c.`patient_intake_id`
        WHERE c.`intake_data` IS NULL
    ')->fetchAll(PDO::FETCH_ASSOC);

    assertSame(1, count($rows), 'the join finds the legacy pair');
    assertSame($clientId, (int) $rows[0]['backfill_client_id'], 'and hands back the client id');

    saveClientIntakeData(testDb(), (int) $rows[0]['backfill_client_id'], backfillIntakeRow($rows[0]), 1);

    $rec = readClientIntakeData(testDb(), $clientId);
    assertSame(1, $rec['version'], 'written as version 1');
    assertSame('Legacy', $rec['answers']['first_name'], 'with the legacy answers');

    $again = testDb()->query('
        SELECT COUNT(*) FROM `clients` c
        JOIN `patient-intake` pi ON pi.`id` = c.`patient_intake_id`
        WHERE c.`intake_data` IS NULL
    ')->fetchColumn();
    assertSame(0, (int) $again, 'a second run finds nothing left to do');
});

test('the runner does not fire when the file is merely required', function () {
    // Requiring this from a test must not rewrite anyone's intake data.
    assertTrue(function_exists('backfillIntakeRow'), 'the mapper loaded');
    assertSame(false, function_exists('saveClientIntakeData') && isset($GLOBALS['__backfill_ran']),
        'but nothing was written');
});
