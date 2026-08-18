<?php
require_once __DIR__ . '/../includes/lead-timeline.php';

test('the timeline merges activity log entries, notes and intake events', function () {
    resetTestTables(['lead_notes', 'intake_links', 'activity_log', 'leads']);
    $leadId = insertTestLead(['created_at' => '2026-08-01 09:00:00']);

    testDb()->prepare("
        INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`,`created_at`)
        VALUES ('status_changed','Lead moved to Contacted','lead',:id,'2026-08-02 10:00:00')
    ")->execute([':id' => $leadId]);

    testDb()->prepare("
        INSERT INTO `intake_links` (`lead_id`,`token`,`expires_at`,`status`,`created_at`)
        VALUES (:id, :token, '2026-09-01 00:00:00', 'sent', '2026-08-03 11:00:00')
    ")->execute([':id' => $leadId, ':token' => str_repeat('a', 64)]);

    addLeadNote(testDb(), $leadId, null, 'Spoke on the phone.');

    $timeline = leadTimeline(testDb(), $leadId);
    assertTrue(count($timeline) >= 4, 'created + status change + intake sent + note');
});

test('the timeline is newest first', function () {
    resetTestTables(['lead_notes', 'intake_links', 'activity_log', 'leads']);
    $leadId = insertTestLead(['created_at' => '2026-08-01 09:00:00']);
    addLeadNote(testDb(), $leadId, null, 'Most recent thing');

    $timeline = leadTimeline(testDb(), $leadId);
    assertSame('Most recent thing', $timeline[0]['text'], 'the note is newer than the creation event');
});

test('the lead creation event is always present, even with no other history', function () {
    resetTestTables(['lead_notes', 'intake_links', 'activity_log', 'leads']);
    $leadId   = insertTestLead();
    $timeline = leadTimeline(testDb(), $leadId);

    assertSame(1, count($timeline), 'exactly one entry');
    assertTrue(strpos($timeline[0]['text'], 'submitted') !== false, 'and it is the submission');
});

test('another lead history never leaks in', function () {
    resetTestTables(['lead_notes', 'intake_links', 'activity_log', 'leads']);
    $mine   = insertTestLead(['email' => 'mine@example.com']);
    $theirs = insertTestLead(['email' => 'theirs@example.com']);
    addLeadNote(testDb(), $theirs, null, 'Not my note');

    foreach (leadTimeline(testDb(), $mine) as $entry) {
        assertTrue($entry['text'] !== 'Not my note', 'timeline must be scoped to one lead');
    }
});

test('a lead that does not exist has an empty timeline rather than a fatal', function () {
    resetTestTables(['lead_notes', 'intake_links', 'activity_log', 'leads']);
    assertSame(0, count(leadTimeline(testDb(), 999999)), 'missing lead yields nothing');
});
