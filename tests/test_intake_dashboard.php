<?php
require_once __DIR__ . '/../includes/intake-repo.php';

test('the links list joins the person each link belongs to', function () {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['name' => 'Anita Rao', 'status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`expires_at`)
        VALUES (:id, :tok, "opened", DATE_ADD(NOW(), INTERVAL 7 DAY))
    ')->execute([':id' => $leadId, ':tok' => str_repeat('9', 64)]);

    $rows = intakeLinksList(testDb());
    assertSame(1, count($rows), 'one link');
    assertSame('Anita Rao', $rows[0]['name'], 'with the lead name attached');
});

test('the list can be narrowed to one status', function () {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    foreach (['sent' => '7', 'submitted' => '8'] as $status => $ch) {
        testDb()->prepare('
            INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`expires_at`)
            VALUES (:id, :tok, :st, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ')->execute([':id' => $leadId, ':tok' => str_repeat($ch, 64), ':st' => $status]);
    }

    assertSame(1, count(intakeLinksList(testDb(), 'sent')), 'one sent');
    assertSame(2, count(intakeLinksList(testDb(), 'all')), 'all means all');
});

test('an unknown status filter shows everything rather than nothing', function () {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`expires_at`)
        VALUES (:id, :tok, "sent", DATE_ADD(NOW(), INTERVAL 7 DAY))
    ')->execute([':id' => $leadId, ':tok' => str_repeat('6', 64)]);

    assertSame(1, count(intakeLinksList(testDb(), 'nonsense')), 'garbage filter degrades to all');
});

test('the list never leaks the token itself into a page-level query', function () {
    // The admin list is a status view. The token is the credential; putting it
    // on screen would make a shoulder-glance enough to open someone's form.
    $src = file_get_contents(dirname(__DIR__) . '/admin/pages/intake.php');
    assertTrue(
        strpos($src, "\$il['token']") === false,
        'the intake page must not print the raw token'
    );
});
