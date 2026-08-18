<?php
require_once __DIR__ . '/../includes/lead-repo.php';

test('bulk update moves every legal lead and reports the ones it refused', function () {
    resetTestTables(['leads']);
    $a = insertTestLead(['email' => 'a@example.com', 'status' => 'new']);
    $b = insertTestLead(['email' => 'b@example.com', 'status' => 'new']);
    $c = insertTestLead(['email' => 'c@example.com', 'status' => 'spam']);

    $result = bulkUpdateLeadStatus(testDb(), [$a, $b, $c], 'contacted');

    assertSame(2, $result['updated'], 'the two new leads moved');
    assertSame([$c], $result['skipped'], 'spam is terminal, so it was refused');
    assertSame('contacted', fetchLead(testDb(), $a)['status'], 'lead a moved');
    assertSame('spam',      fetchLead(testDb(), $c)['status'], 'lead c did not move');
});

test('bulk update refuses an invalid target status outright', function () {
    resetTestTables(['leads']);
    $a = insertTestLead();
    assertThrows(function () use ($a) {
        bulkUpdateLeadStatus(testDb(), [$a], 'accepted');
    }, 'the retired vocabulary must be refused');
});

test('bulk update with no ids is a no-op, not a full-table update', function () {
    resetTestTables(['leads']);
    $a = insertTestLead(['status' => 'new']);
    $result = bulkUpdateLeadStatus(testDb(), [], 'contacted');
    assertSame(0, $result['updated'], 'nothing was updated');
    assertSame('new', fetchLead(testDb(), $a)['status'], 'the untouched lead is still new');
});

test('CSV has a header row and one row per lead', function () {
    resetTestTables(['leads']);
    insertTestLead(['name' => 'Anita Rao', 'email' => 'anita@example.com']);
    insertTestLead(['name' => 'Bhavik Shah', 'email' => 'bhavik@example.com']);

    $csv   = leadsCsv(fetchLeads(testDb(), []));
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    assertSame(3, count($lines), 'header plus two leads');
    assertTrue(strpos($lines[0], 'Name') !== false, 'first line is the header');
    assertTrue(strpos($csv, 'Anita Rao') !== false, 'Anita is in the export');
});

test('CSV neutralises formula injection', function () {
    resetTestTables(['leads']);
    insertTestLead(['name' => '=cmd|/c calc!A1']);
    $csv = leadsCsv(fetchLeads(testDb(), []));
    assertTrue(strpos($csv, "'=cmd") !== false, 'a leading = must be prefixed so Excel treats it as text');
});
