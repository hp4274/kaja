<?php
require_once __DIR__ . '/../cron/intake-reminders.php';

function seedStale($hoursAgo, $linkStatus, $reminderSent = 0) {
    resetTestTables(['intake_links', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('UPDATE `leads` SET `reminder_sent` = :r WHERE `id` = :id')
            ->execute([':r' => $reminderSent, ':id' => $leadId]);

    $sentAt = date('Y-m-d H:i:s', time() - ($hoursAgo * 3600));
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`expires_at`,`status`,`created_at`)
        VALUES (:id, :token, DATE_ADD(NOW(), INTERVAL 14 DAY), :st, :created)
    ')->execute([
        ':id' => $leadId, ':token' => str_repeat('b', 64),
        ':st' => $linkStatus, ':created' => $sentAt,
    ]);
    return $leadId;
}

test('a link sent longer ago than the threshold is stale', function () {
    $leadId = seedStale(72, 'sent');
    $stale  = array_map('intval', array_column(staleIntakeLeads(testDb(), 48), 'lead_id'));
    assertContains($leadId, $stale, '72h old beats a 48h threshold');
});

test('an opened but unsubmitted link is still stale', function () {
    $leadId = seedStale(72, 'opened');
    $stale  = array_map('intval', array_column(staleIntakeLeads(testDb(), 48), 'lead_id'));
    assertContains($leadId, $stale, 'opened is not submitted');
});

test('a link inside the threshold is left alone', function () {
    seedStale(2, 'sent');
    assertSame(0, count(staleIntakeLeads(testDb(), 48)), 'two hours is not stale');
});

test('a submitted link is never chased', function () {
    seedStale(500, 'submitted');
    assertSame(0, count(staleIntakeLeads(testDb(), 48)), 'they already filled it in');
});

test('a lead already reminded is never reminded again', function () {
    seedStale(500, 'sent', 1);
    assertSame(0, count(staleIntakeLeads(testDb(), 48)), 'reminder_sent is a permanent gate');
});

test('markLeadReminded closes the gate', function () {
    $leadId = seedStale(72, 'sent');
    assertSame(1, count(staleIntakeLeads(testDb(), 48)), 'stale before');

    markLeadReminded(testDb(), $leadId);
    assertSame(0, count(staleIntakeLeads(testDb(), 48)), 'and not stale after');
});

test('only the most recent link decides staleness', function () {
    // A resend leaves the old row behind. Judging on the old one would chase
    // someone who was handed a fresh link an hour ago.
    $leadId = seedStale(500, 'sent');
    testDb()->prepare('
        INSERT INTO `intake_links` (`lead_id`,`token`,`expires_at`,`status`,`created_at`)
        VALUES (:id, :token, DATE_ADD(NOW(), INTERVAL 14 DAY), "sent", NOW())
    ')->execute([':id' => $leadId, ':token' => str_repeat('c', 64)]);

    assertSame(0, count(staleIntakeLeads(testDb(), 48)), 'the fresh resend is what counts');
});

test('the runner does not fire when the file is merely required', function () {
    // Requiring this file from a test must not send mail to real addresses.
    assertTrue(defined('INTAKE_REMINDERS_LOADED'), 'the file loaded');
    assertSame(false, defined('INTAKE_REMINDERS_RAN'), 'but the runner stayed put');
});
