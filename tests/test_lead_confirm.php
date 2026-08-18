<?php
require_once __DIR__ . '/../includes/lead-confirm.php';

function confirmFixtures() {
    resetTestTables(['intake_links', 'clients', 'lead_notes', 'activity_log', 'leads']);
}

test('confirm produces exactly one client and one token, and moves the lead', function () {
    confirmFixtures();
    $leadId = insertTestLead(['status' => 'contacted', 'name' => 'Anita Rao']);

    $result = confirmLead(testDb(), $leadId, null);

    assertTrue($result['client_id'] > 0, 'a client was created');
    assertSame('confirmed', fetchLead(testDb(), $leadId)['status'], 'the lead is confirmed');

    $clients = testDb()->query("SELECT COUNT(*) FROM `clients` WHERE `lead_id`=" . (int) $leadId)->fetchColumn();
    $tokens  = testDb()->query("SELECT COUNT(*) FROM `intake_links` WHERE `lead_id`=" . (int) $leadId)->fetchColumn();
    assertSame(1, (int) $clients, 'exactly one client');
    assertSame(1, (int) $tokens, 'exactly one token');
});

test('the new client starts pending, not active', function () {
    confirmFixtures();
    $leadId = insertTestLead(['status' => 'contacted']);
    $result = confirmLead(testDb(), $leadId, null);

    $status = testDb()->query("SELECT `status` FROM `clients` WHERE `id`=" . (int) $result['client_id'])->fetchColumn();
    assertSame('pending', $status, 'the questionnaire has not come back yet');
});

test('confirming twice does not create a second client', function () {
    confirmFixtures();
    $leadId = insertTestLead(['status' => 'contacted']);

    $first  = confirmLead(testDb(), $leadId, null);
    $second = confirmLead(testDb(), $leadId, null);

    assertSame(true, $second['already'], 'the second call reports it was already done');
    assertSame($first['client_id'], $second['client_id'], 'and hands back the same client');

    $clients = testDb()->query("SELECT COUNT(*) FROM `clients` WHERE `lead_id`=" . (int) $leadId)->fetchColumn();
    assertSame(1, (int) $clients, 'still exactly one client');
});

test('expiry is pinned from the setting at confirm time', function () {
    confirmFixtures();
    setSetting('intake_token_expiry_days', '7');
    $leadId = insertTestLead(['status' => 'contacted']);

    $result   = confirmLead(testDb(), $leadId, null);
    $expected = date('Y-m-d', strtotime($result['expires_at']));

    // Changing the setting afterwards must not move an already-issued link.
    setSetting('intake_token_expiry_days', '30');
    $stored = testDb()->query("SELECT `expires_at` FROM `intake_links` WHERE `lead_id`=" . (int) $leadId)->fetchColumn();
    assertSame($expected, date('Y-m-d', strtotime($stored)), 'the issued link did not move');

    setSetting('intake_token_expiry_days', '14');
});

test('a rejected lead cannot be confirmed', function () {
    confirmFixtures();
    $leadId = insertTestLead(['status' => 'rejected']);
    assertThrows(function () use ($leadId) {
        confirmLead(testDb(), $leadId, null);
    }, 'rejected is terminal');
});

test('a lead that does not exist cannot be confirmed', function () {
    confirmFixtures();
    assertThrows(function () {
        confirmLead(testDb(), 999999, null);
    }, 'missing lead must throw, not create an orphan client');
});

test('nothing is left behind when the transaction fails', function () {
    confirmFixtures();
    $leadId = insertTestLead(['status' => 'contacted']);

    // Hide intake_links so issueIntakeToken() fails partway through, after the
    // client row has already been inserted. A column-width violation would not
    // do: this MySQL is not in strict mode, so it truncates with a warning and
    // the transaction would commit happily.
    testDb()->exec("RENAME TABLE `intake_links` TO `intake_links_hidden`");
    try {
        confirmLead(testDb(), $leadId, null);
    } catch (Throwable $e) {
        // expected
    }
    testDb()->exec("RENAME TABLE `intake_links_hidden` TO `intake_links`");

    $clients = testDb()->query("SELECT COUNT(*) FROM `clients` WHERE `lead_id`=" . (int) $leadId)->fetchColumn();
    assertSame(0, (int) $clients, 'the half-finished client was rolled back');
    assertSame('contacted', fetchLead(testDb(), $leadId)['status'], 'and the lead did not move');
});
