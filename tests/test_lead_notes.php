<?php
require_once __DIR__ . '/../includes/lead-notes.php';

test('a note is stored against the lead and comes back with its author', function () {
    resetTestTables(['lead_notes', 'leads']);
    $leadId = insertTestLead();
    $userId = (int) testDb()->query("SELECT `id` FROM `users` LIMIT 1")->fetchColumn();

    addLeadNote(testDb(), $leadId, $userId, 'Called, left a voicemail.');
    $notes = leadNotes(testDb(), $leadId);

    assertSame(1, count($notes), 'one note');
    assertSame('Called, left a voicemail.', $notes[0]['content'], 'content round-trips');
    assertTrue($notes[0]['author'] !== '', 'the note is attributed');
});

test('notes come back newest first', function () {
    resetTestTables(['lead_notes', 'leads']);
    $leadId = insertTestLead();
    addLeadNote(testDb(), $leadId, null, 'First note');
    addLeadNote(testDb(), $leadId, null, 'Second note');

    $notes = leadNotes(testDb(), $leadId);
    assertSame('Second note', $notes[0]['content'], 'newest first');
});

test('an unattributed note reads as System rather than blank', function () {
    resetTestTables(['lead_notes', 'leads']);
    $leadId = insertTestLead();
    addLeadNote(testDb(), $leadId, null, 'Written by the cron job');
    assertSame('System', leadNotes(testDb(), $leadId)[0]['author'], 'null user is System');
});

test('an empty note is refused', function () {
    resetTestTables(['lead_notes', 'leads']);
    $leadId = insertTestLead();
    assertThrows(function () use ($leadId) {
        addLeadNote(testDb(), $leadId, null, '   ');
    }, 'whitespace-only notes must be refused');
});

test('deleting a lead takes its notes with it', function () {
    resetTestTables(['lead_notes', 'leads']);
    $leadId = insertTestLead();
    addLeadNote(testDb(), $leadId, null, 'Note that should not outlive its lead');

    testDb()->exec('DELETE FROM `leads` WHERE `id`=' . (int) $leadId);
    assertSame(0, count(leadNotes(testDb(), $leadId)), 'the cascade removed the note');
});
