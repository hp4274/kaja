<?php
require_once __DIR__ . '/../includes/intake-repo.php';

function seedLink($status = 'sent', $overrides = []) {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);

    $row = array_merge([
        'created_at'    => date('Y-m-d H:i:s'),
        'expires_at'    => date('Y-m-d H:i:s', time() + 14 * 86400),
        'reminder_sent' => 0,
    ], $overrides);

    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`created_at`,`expires_at`,`reminder_sent`)
        VALUES (:lid, :tok, :st, :created, :expires, :rem)
    ')->execute([
        ':lid' => $leadId, ':tok' => str_repeat('d', 64), ':st' => $status,
        ':created' => $row['created_at'], ':expires' => $row['expires_at'],
        ':rem' => $row['reminder_sent'],
    ]);
    return (int) testDb()->lastInsertId();
}

function linkRow($id) {
    return testDb()->query('SELECT * FROM `intake_links` WHERE `id`=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
}

test('advancing sets the status and stamps the matching time column', function () {
    $id = seedLink('sent');
    assertSame(true, advanceIntakeLink(testDb(), $id, 'opened'), 'the row moved');

    $row = linkRow($id);
    assertSame('opened', $row['status'], 'status advanced');
    assertTrue($row['opened_at'] !== null, 'opened_at was stamped');
});

test('advancing twice does not move the timestamp', function () {
    $id = seedLink('sent');
    advanceIntakeLink(testDb(), $id, 'opened');
    $first = linkRow($id)['opened_at'];

    assertSame(false, advanceIntakeLink(testDb(), $id, 'opened'), 'already there, nothing moved');
    assertSame($first, linkRow($id)['opened_at'], 'the original open time stands');
});

test('a late beacon can never downgrade a submitted row', function () {
    $id = seedLink('submitted');
    assertSame(false, advanceIntakeLink(testDb(), $id, 'filled'), 'refused');
    assertSame('submitted', linkRow($id)['status'], 'and the row did not change');
});

test('filled is reachable straight from sent', function () {
    $id = seedLink('sent');
    assertSame(true, advanceIntakeLink(testDb(), $id, 'filled'), 'skipping opened is allowed');
    assertTrue(linkRow($id)['filled_at'] !== null, 'filled_at stamped');
});

test('a draft round-trips and is cleared on demand', function () {
    $id = seedLink('opened');
    saveIntakeDraft(testDb(), $id, ['first_name' => 'Anita', 'q1_1' => '3']);

    $draft = readIntakeDraft(testDb(), $id);
    assertSame('Anita', $draft['first_name'], 'draft came back');

    clearIntakeDraft(testDb(), $id);
    assertSame(0, count(readIntakeDraft(testDb(), $id)), 'draft is gone after clearing');
});

test('a draft is refused once the form is submitted', function () {
    $id = seedLink('submitted');
    assertSame(false, saveIntakeDraft(testDb(), $id, ['first_name' => 'Too late']), 'refused');
    assertSame(0, count(readIntakeDraft(testDb(), $id)), 'nothing was written');
});

test('reading a draft that was never written returns an empty array', function () {
    $id = seedLink('sent');
    assertSame([], readIntakeDraft(testDb(), $id), 'no draft is an empty array, not null');
});

test('overdue links expire, submitted ones do not', function () {
    $past = date('Y-m-d H:i:s', time() - 86400);
    $a = seedLink('opened', ['expires_at' => $past]);

    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`expires_at`)
        VALUES ((SELECT `id` FROM `leads` LIMIT 1), :tok, "submitted", :exp)
    ')->execute([':tok' => str_repeat('e', 64), ':exp' => $past]);

    assertSame(1, expireOverdueIntakeLinks(testDb()), 'exactly one row expired');
    assertSame('expired', linkRow($a)['status'], 'the overdue open link expired');
});

test('stale links are the unfinished, unreminded ones past the threshold', function () {
    $old = date('Y-m-d H:i:s', time() - 72 * 3600);
    $id  = seedLink('opened', ['created_at' => $old]);

    $stale = array_map('intval', array_column(staleIntakeLinks(testDb(), 48), 'id'));
    assertContains($id, $stale, '72h beats a 48h threshold');

    markIntakeLinkReminded(testDb(), $id);
    assertSame(0, count(staleIntakeLinks(testDb(), 48)), 'and the gate closes');
});
