<?php
require_once __DIR__ . '/../includes/client-repo.php';

function seedClients() {
    resetTestTables(['client_documents', 'client_fees', 'client_notes', 'sessions', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    $ids = [];
    foreach ([['Anita','Rao','active'], ['Bhavik','Shah','pending'], ['Chirag','Nair','completed']] as $c) {
        testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`phone`,`status`)
                           VALUES (:l,:f,:s,:e,:p,:st)')
            ->execute([':l'=>$leadId, ':f'=>$c[0], ':s'=>$c[1],
                       ':e'=>strtolower($c[0]).'@example.com', ':p'=>'900'.count($ids), ':st'=>$c[2]]);
        $ids[strtolower($c[0])] = (int) testDb()->lastInsertId();
    }
    return $ids;
}

test('no filters returns every non-archived client', function () {
    seedClients();
    assertSame(3, count(fetchClients(testDb(), [])), 'all three');
});

test('an archived client disappears from the list and the counts', function () {
    $ids = seedClients();
    archiveClient(testDb(), $ids['anita']);

    assertSame(2, count(fetchClients(testDb(), [])), 'gone from the list');
    assertSame(0, clientStatusCounts(testDb())['active'], 'and from the counts');
});

test('an archived row is still reachable when asked for explicitly', function () {
    $ids = seedClients();
    archiveClient(testDb(), $ids['anita']);
    assertTrue(fetchClient(testDb(), $ids['anita'], true) !== null, 'the row never left');
    assertSame(null, fetchClient(testDb(), $ids['anita']), 'but the normal path cannot see it');
});

test('search matches first name, last name, email and phone', function () {
    seedClients();
    assertSame(1, count(fetchClients(testDb(), ['q' => 'anita'])), 'first name');
    assertSame(1, count(fetchClients(testDb(), ['q' => 'Nair'])), 'last name');
    assertSame(1, count(fetchClients(testDb(), ['q' => 'bhavik@'])), 'email');
    assertSame(1, count(fetchClients(testDb(), ['q' => '9002'])), 'phone');
});

test('search is not SQL injectable', function () {
    seedClients();
    assertSame(0, count(fetchClients(testDb(), ['q' => "' OR 1=1 --"])), 'the quote is data');
});

test('status filter narrows, an unknown one is ignored', function () {
    seedClients();
    assertSame(1, count(fetchClients(testDb(), ['status' => 'pending'])), 'one pending');
    assertSame(3, count(fetchClients(testDb(), ['status' => 'nonsense'])), 'garbage shows everything');
});

test('sort by name is alphabetical', function () {
    seedClients();
    $byName = fetchClients(testDb(), ['sort' => 'name', 'dir' => 'asc']);
    assertSame('Anita', $byName[0]['first_name'], 'A first');
});

test('the next appointment is the soonest future scheduled session', function () {
    $ids = seedClients();
    foreach ([['2020-01-01','confirmed'], ['2030-06-01','confirmed'], ['2027-01-01','cancelled']] as $s) {
        testDb()->prepare('INSERT INTO `sessions` (`client_id`,`start_time`,`end_time`,`status`)
                           VALUES (:c, CONCAT(:d," 10:00:00"), CONCAT(:d2," 11:00:00"), :st)')
            ->execute([':c'=>$ids['anita'], ':d'=>$s[0], ':d2'=>$s[0], ':st'=>$s[1]]);
    }
    $rows = fetchClients(testDb(), ['q' => 'anita']);
    assertSame('2030-06-01', $rows[0]['next_appointment'],
        'past and cancelled sessions are not the next appointment');
});

test('a client with no sessions has no next appointment rather than a zero date', function () {
    seedClients();
    $rows = fetchClients(testDb(), ['q' => 'bhavik']);
    assertSame(null, $rows[0]['next_appointment'], 'null, not 0000-00-00');
});

test('updating a profile changes contact details but never status', function () {
    $ids = seedClients();
    updateClientProfile(testDb(), $ids['anita'], ['first_name'=>'Anita','last_name'=>'Rao',
        'email'=>'new@example.com','phone'=>'999','city'=>'Pune','occupation'=>'Teacher']);

    $c = fetchClient(testDb(), $ids['anita']);
    assertSame('new@example.com', $c['email'], 'the edit landed');
    assertSame('active', $c['status'], 'status is moved by its own action, not by an edit form');
});

test('a profile edit cannot smuggle in a column that is not on the whitelist', function () {
    $ids = seedClients();
    updateClientProfile(testDb(), $ids['anita'], ['status' => 'completed', 'archived_at' => '2020-01-01']);

    $c = fetchClient(testDb(), $ids['anita']);
    assertSame('active', $c['status'], 'status ignored');
    assertTrue($c !== null, 'and the client was not archived out from under us');
});

test('setting the same status again reports that nothing moved', function () {
    $ids = seedClients();
    assertSame(false, setClientStatus(testDb(), $ids['anita'], 'active'), 'already there');
    assertSame(true,  setClientStatus(testDb(), $ids['anita'], 'inactive'), 'a real move');
});
