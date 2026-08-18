<?php
require_once __DIR__ . '/../includes/client-merge.php';

function mergePair() {
    resetTestTables(['client_documents', 'client_fees', 'client_notes', 'sessions',
                     'intake_links', 'clients', 'leads']);
    $ids = [];
    foreach (['Keep', 'Dupe'] as $n) {
        $leadId = insertTestLead(['email' => strtolower($n) . '@example.com', 'status' => 'confirmed']);
        testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
                           VALUES (:l,:f,"Rao",:e,"active")')
            ->execute([':l' => $leadId, ':f' => $n, ':e' => strtolower($n) . '@example.com']);
        $clientId = (int) testDb()->lastInsertId();

        // Close the loop the confirm flow would have closed: the lead points at
        // the client it produced. Without this the repoint has nothing to move.
        testDb()->prepare('UPDATE `leads` SET `client_id` = :c WHERE `id` = :l')
                ->execute([':c' => $clientId, ':l' => $leadId]);

        $ids[strtolower($n)] = $clientId;
        $ids[strtolower($n) . '_lead'] = $leadId;
    }
    return $ids;
}

test('everything the loser owned moves to the survivor', function () {
    $ids = mergePair();

    testDb()->prepare('INSERT INTO `sessions` (`client_id`,`session_date`,`session_time`)
                       VALUES (:c,"2026-09-01","10:00:00")')->execute([':c' => $ids['dupe']]);
    testDb()->prepare('INSERT INTO `client_notes` (`client_id`,`content`)
                       VALUES (:c,"note on the duplicate")')->execute([':c' => $ids['dupe']]);
    testDb()->prepare('INSERT INTO `client_fees` (`client_id`,`amount`,`fee_date`)
                       VALUES (:c,100,"2026-08-01")')->execute([':c' => $ids['dupe']]);
    testDb()->prepare('INSERT INTO `client_documents` (`client_id`,`original_name`,`stored_name`,`mime_type`,`size_bytes`)
                       VALUES (:c,"scan.pdf",:s,"application/pdf",10)')
        ->execute([':c' => $ids['dupe'], ':s' => bin2hex(random_bytes(16)) . '.pdf']);

    $result = mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);

    assertSame(1, $result['sessions'], 'one session moved');
    assertSame(1, $result['notes'], 'one note moved');
    assertSame(1, $result['payments'], 'one payment moved');
    assertSame(1, $result['documents'], 'one document moved');

    $n = testDb()->query('SELECT COUNT(*) FROM `client_notes` WHERE `client_id`=' . $ids['keep'])->fetchColumn();
    assertSame(1, (int) $n, 'and is now on the survivor');
});

test('the loser is archived and points at the survivor', function () {
    $ids = mergePair();
    mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);

    $loser = fetchClient(testDb(), $ids['dupe'], true);
    assertTrue($loser['archived_at'] !== null, 'archived');
    assertSame($ids['keep'], (int) $loser['merged_into_id'], 'and explicable');
});

test('the loser lead is repointed so nothing links to an archived record', function () {
    $ids = mergePair();
    mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);

    $leadClient = testDb()->query('SELECT `client_id` FROM `leads` WHERE `id`=' . $ids['dupe_lead'])->fetchColumn();
    assertSame($ids['keep'], (int) $leadClient, 'the lead now points at the survivor');
});

test('a merged-away client is gone from the list', function () {
    $ids = mergePair();
    mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);
    assertSame(1, count(fetchClients(testDb(), [])), 'one client remains visible');
});

test('merging a client into itself is refused', function () {
    $ids = mergePair();
    assertThrows(function () use ($ids) {
        mergeClients(testDb(), $ids['keep'], $ids['keep'], null);
    }, 'that would archive the survivor');
});

test('merging an already-archived client is refused', function () {
    $ids = mergePair();
    archiveClient(testDb(), $ids['dupe']);
    assertThrows(function () use ($ids) {
        mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);
    }, 'nothing to merge from');
});

test('merging into a client that does not exist is refused', function () {
    $ids = mergePair();
    assertThrows(function () use ($ids) {
        mergeClients(testDb(), 999999, $ids['dupe'], null);
    }, 'no survivor, no merge');
});

test('a failed merge moves nothing', function () {
    $ids = mergePair();
    testDb()->prepare('INSERT INTO `client_notes` (`client_id`,`content`)
                       VALUES (:c,"stays put")')->execute([':c' => $ids['dupe']]);

    // Hide a table the merge writes to partway through, so it throws after the
    // sessions and notes updates have already run inside the transaction.
    testDb()->exec('RENAME TABLE `client_fees` TO `client_fees_hidden`');
    try {
        mergeClients(testDb(), $ids['keep'], $ids['dupe'], null);
    } catch (Throwable $e) {
        // expected
    }
    testDb()->exec('RENAME TABLE `client_fees_hidden` TO `client_fees`');

    $n = testDb()->query('SELECT COUNT(*) FROM `client_notes` WHERE `client_id`=' . $ids['dupe'])->fetchColumn();
    assertSame(1, (int) $n, 'the note never left the loser');

    $loser = fetchClient(testDb(), $ids['dupe']);
    assertTrue($loser !== null, 'and the loser was not archived');
});
