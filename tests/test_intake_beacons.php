<?php
require_once __DIR__ . '/../includes/intake-repo.php';
require_once __DIR__ . '/../includes/intake-token.php';

function seedBeaconLink($status = 'sent') {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    $token  = str_repeat('f', 64);

    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`expires_at`)
        VALUES (:lid, :tok, :st, DATE_ADD(NOW(), INTERVAL 14 DAY))
    ')->execute([':lid' => $leadId, ':tok' => $token, ':st' => $status]);

    return $token;
}

test('a valid token can be resolved to its link', function () {
    $token = seedBeaconLink('opened');
    $link  = findIntakeLink(testDb(), $token);
    assertTrue(!empty($link), 'the link resolves');
    assertSame('opened', $link['status'], 'with its current status');
});

test('an unknown token resolves to nothing', function () {
    seedBeaconLink();
    $link = findIntakeLink(testDb(), str_repeat('0', 64));
    assertTrue(empty($link), 'a guessed token gets nowhere');
});

test('the filled beacon advances a sent link', function () {
    $token = seedBeaconLink('sent');
    $link  = findIntakeLink(testDb(), $token);
    assertSame(true, advanceIntakeLink(testDb(), (int) $link['id'], 'filled'), 'advanced');
});

test('the filled beacon is inert against a submitted link', function () {
    $token = seedBeaconLink('submitted');
    $link  = findIntakeLink(testDb(), $token);
    assertSame(false, advanceIntakeLink(testDb(), (int) $link['id'], 'filled'), 'refused');
});

test('a draft saved against a live link comes back intact', function () {
    $token = seedBeaconLink('opened');
    $link  = findIntakeLink(testDb(), $token);

    saveIntakeDraft(testDb(), (int) $link['id'], ['first_name' => 'Anita', 'city' => 'Pune']);
    $draft = readIntakeDraft(testDb(), (int) $link['id']);

    assertSame('Pune', $draft['city'], 'the draft round-trips');
});

test('both endpoints refuse anything other than POST', function () {
    foreach (['mark_filled.php', 'save_draft.php'] as $file) {
        $src = file_get_contents(dirname(__DIR__) . '/' . $file);
        assertTrue(strpos($src, "REQUEST_METHOD'] !== 'POST'") !== false, $file . ' checks the method');
        assertTrue(strpos($src, '$_SESSION') === false, $file . ' authenticates by token only, never by session');
    }
});
