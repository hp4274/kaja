<?php
require_once __DIR__ . '/../includes/client-notes.php';

function noteClient($first = 'Anita', $email = 'a@example.com') {
    $leadId = insertTestLead(['email' => $email, 'status' => 'confirmed']);
    testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
                       VALUES (:l,:f,"Rao",:e,"active")')
            ->execute([':l' => $leadId, ':f' => $first, ':e' => $email]);
    return (int) testDb()->lastInsertId();
}

test('a note records its author, kind and time', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c = noteClient();
    $userId = (int) testDb()->query('SELECT `id` FROM `users` LIMIT 1')->fetchColumn();
    addClientNote(testDb(), $c, $userId, 'First session went well.', 'session');

    $notes = clientNotes(testDb(), $c);
    assertSame(1, count($notes), 'one note');
    assertSame('session', $notes[0]['note_kind'], 'tagged as clinical');
    assertTrue($notes[0]['author'] !== '', 'attributed');
});

test('an unattributed note reads as System', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c = noteClient();
    addClientNote(testDb(), $c, null, 'Written by a job', 'administrative');
    assertSame('System', clientNotes(testDb(), $c)[0]['author'], 'not blank');
});

test('a correction is a new note that points at the original', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c     = noteClient();
    $first = addClientNote(testDb(), $c, null, 'Session on the 3rd.', 'session');
    $fix   = correctClientNote(testDb(), $c, null, 'Correction: it was the 4th.', $first);

    $notes = clientNotes(testDb(), $c);
    assertSame(2, count($notes), 'both entries survive');

    $byId = [];
    foreach ($notes as $n) { $byId[(int) $n['id']] = $n; }
    assertSame($first, (int) $byId[$fix]['corrects_note_id'], 'the correction points back');
    assertSame('Session on the 3rd.', $byId[$first]['content'], 'the original is untouched');
});

test('a corrected note is reported as superseded', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c     = noteClient();
    $first = addClientNote(testDb(), $c, null, 'Wrong date.', 'session');
    correctClientNote(testDb(), $c, null, 'Right date.', $first);

    assertContains($first, correctedNoteIds(testDb(), $c), 'the UI can mark it');
});

test('a correction cannot point at a note belonging to someone else', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $a         = noteClient('Anita', 'anita@example.com');
    $strayNote = addClientNote(testDb(), $a, null, 'Anita note', 'session');
    $b         = noteClient('Other', 'other@example.com');

    assertThrows(function () use ($b, $strayNote) {
        correctClientNote(testDb(), $b, null, 'Not mine to correct', $strayNote);
    }, 'a correction must stay inside one client history');
});

test('a correction pointing at a note that does not exist is refused', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c = noteClient();
    assertThrows(function () use ($c) {
        correctClientNote(testDb(), $c, null, 'Correcting thin air', 999999);
    }, 'no dangling corrections');
});

test('an empty note and an unknown kind are both refused', function () {
    resetTestTables(['client_notes', 'clients', 'leads']);
    $c = noteClient();
    assertThrows(function () use ($c) { addClientNote(testDb(), $c, null, '   ', 'session'); },
        'whitespace is not a note');
    assertThrows(function () use ($c) { addClientNote(testDb(), $c, null, 'ok', 'gossip'); },
        'the enum would coerce it, so it is checked first');
});

test('the notes module contains no update or delete path at all', function () {
    // Append-only is a property of the file, not a habit. If an UPDATE ever
    // appears here, the clinical record stops being contemporaneous.
    $src = file_get_contents(dirname(__DIR__) . '/includes/client-notes.php');
    assertTrue(stripos($src, 'UPDATE `client_notes`') === false, 'no update');
    assertTrue(stripos($src, 'DELETE FROM `client_notes`') === false, 'no delete');
});
