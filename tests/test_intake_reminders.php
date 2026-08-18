<?php
require_once __DIR__ . '/../cron/intake-reminders.php';

function seedReminderLink($hoursAgo, $status, $reminderSent = 0) {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);

    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`created_at`,`expires_at`,`reminder_sent`)
        VALUES (:id, :token, :st, :created, DATE_ADD(NOW(), INTERVAL 14 DAY), :rem)
    ')->execute([
        ':id' => $leadId, ':token' => str_repeat('b', 64), ':st' => $status,
        ':created' => date('Y-m-d H:i:s', time() - ($hoursAgo * 3600)),
        ':rem' => $reminderSent,
    ]);
    return (int) testDb()->lastInsertId();
}

test('a link past the threshold is stale', function () {
    $id    = seedReminderLink(72, 'sent');
    $stale = array_map('intval', array_column(staleIntakeLinks(testDb(), 48), 'id'));
    assertContains($id, $stale, '72h beats a 48h threshold');
});

test('opened and filled links are still chased', function () {
    foreach (['opened', 'filled'] as $status) {
        $id    = seedReminderLink(72, $status);
        $stale = array_map('intval', array_column(staleIntakeLinks(testDb(), 48), 'id'));
        assertContains($id, $stale, $status . ' is not submitted');
    }
});

test('a link inside the threshold is left alone', function () {
    seedReminderLink(2, 'sent');
    assertSame(0, count(staleIntakeLinks(testDb(), 48)), 'two hours is not stale');
});

test('a submitted link is never chased', function () {
    seedReminderLink(500, 'submitted');
    assertSame(0, count(staleIntakeLinks(testDb(), 48)), 'they already filled it in');
});

test('an expired link is never chased', function () {
    seedReminderLink(500, 'expired');
    assertSame(0, count(staleIntakeLinks(testDb(), 48)), 'a dead link is not worth an email');
});

test('a link already reminded is never reminded again', function () {
    seedReminderLink(500, 'sent', 1);
    assertSame(0, count(staleIntakeLinks(testDb(), 48)), 'one reminder per link');
});

test('a resend earns its own reminder', function () {
    // The per-lead gate this replaced would have silenced the new link forever.
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);

    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`created_at`,`expires_at`,`reminder_sent`)
        VALUES (:id, :t1, "sent", DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 1)
    ')->execute([':id' => $leadId, ':t1' => str_repeat('1', 64)]);
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`status`,`created_at`,`expires_at`,`reminder_sent`)
        VALUES (:id, :t2, "sent", DATE_SUB(NOW(), INTERVAL 72 HOUR), DATE_ADD(NOW(), INTERVAL 14 DAY), 0)
    ')->execute([':id' => $leadId, ':t2' => str_repeat('2', 64)]);

    assertSame(1, count(staleIntakeLinks(testDb(), 48)), 'the fresh link is chased on its own merits');
});

test('the runner does not fire when the file is merely required', function () {
    assertTrue(defined('INTAKE_REMINDERS_LOADED'), 'the file loaded');
    assertSame(false, defined('INTAKE_REMINDERS_RAN'), 'but the runner stayed put');
});
