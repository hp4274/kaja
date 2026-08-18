<?php
require_once __DIR__ . '/../includes/session-repo.php';

test('a booking lands with a real end time derived from the duration', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    assertSame('2030-06-01 11:00:00', fetchSession(testDb(), $id)['end_time'], 'an hour later');
});

test('an overlapping booking is refused', function () {
    freshSessions();
    $c = sessionClient();
    createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    assertThrows(function () use ($c) {
        createSession(testDb(), $c, '2030-06-01 10:30:00', 60, 'online');
    }, 'a half-hour overlap is still an overlap');
});

test('a booking that starts exactly when another ends is allowed', function () {
    freshSessions();
    $c = sessionClient();
    createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    $id = createSession(testDb(), $c, '2030-06-01 11:00:00', 60, 'online');
    assertTrue($id > 0, 'back-to-back is not a clash');
});

test('the buffer setting widens the exclusion zone', function () {
    freshSessions();
    setSetting('buffer_minutes', '15');
    $c = sessionClient();
    createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    assertThrows(function () use ($c) {
        createSession(testDb(), $c, '2030-06-01 11:05:00', 60, 'online');
    }, 'five minutes after is inside a fifteen-minute buffer');
    setSetting('buffer_minutes', '0');
});

test('a cancelled session frees its slot', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    cancelSession(testDb(), $id, 'Client asked to move it');

    $again = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    assertTrue($again > 0, 'the hour is rebookable');
});

test('a session can cross midnight', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 23:30:00', 60, 'online');
    assertSame('2030-06-02 00:30:00', fetchSession(testDb(), $id)['end_time'],
        'the old day-clamped maths could not express this');
});

test('rescheduling moves the same row and counts the move', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    rescheduleSession(testDb(), $id, '2030-06-02 14:00:00', 60);
    $s = fetchSession(testDb(), $id);

    assertSame('2030-06-02 14:00:00', $s['start_time'], 'moved');
    assertSame(1, (int) $s['rescheduled_count'], 'and counted');
    assertSame($id, (int) $s['id'], 'the id never changes, so notes stay attached');
});

test('rescheduling does not collide with itself', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    rescheduleSession(testDb(), $id, '2030-06-01 10:30:00', 60);
    assertSame('2030-06-01 10:30:00', fetchSession(testDb(), $id)['start_time'], 'nudged by 30 minutes');
});

test('rescheduling onto someone else slot is refused', function () {
    freshSessions();
    $c = sessionClient();
    $a = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    createSession(testDb(), $c, '2030-06-01 14:00:00', 60, 'online');

    assertThrows(function () use ($a) {
        rescheduleSession(testDb(), $a, '2030-06-01 14:30:00', 60);
    }, 'the destination is taken');
});

test('a cancelled session cannot be rescheduled', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    cancelSession(testDb(), $id, 'Client unwell');

    assertThrows(function () use ($id) {
        rescheduleSession(testDb(), $id, '2030-06-02 10:00:00', 60);
    }, 'move a live session, book a new one otherwise');
});

test('a cancellation records its reason and refuses a blank one', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    assertThrows(function () use ($id) { cancelSession(testDb(), $id, '   '); },
        'a cancellation with no reason is a mystery');

    cancelSession(testDb(), $id, 'Client unwell');
    $s = fetchSession(testDb(), $id);
    assertSame('cancelled', $s['status'], 'cancelled');
    assertSame('Client unwell', $s['cancelled_reason'], 'with the reason kept');
});

test('an illegal status move is refused', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    setSessionStatus(testDb(), $id, 'completed');

    assertThrows(function () use ($id) {
        setSessionStatus(testDb(), $id, 'pending');
    }, 'a completed session cannot go back to pending');
});

test('auto-confirm decides where a new booking lands', function () {
    freshSessions();
    $c = sessionClient();
    assertSame('pending', fetchSession(testDb(), createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online'))['status'],
        'off by default');

    setSetting('auto_confirm_sessions', '1');
    assertSame('confirmed', fetchSession(testDb(), createSession(testDb(), $c, '2030-06-01 12:00:00', 60, 'online'))['status'],
        'on when the setting says so');
    setSetting('auto_confirm_sessions', '0');
});

test('an online session carries the practice video link, an in-person one does not', function () {
    freshSessions();
    setSetting('practice_video_link', 'https://meet.example.com/kaja');
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    assertSame('https://meet.example.com/kaja', fetchSession(testDb(), $id)['video_link'], 'copied in');

    $inPerson = createSession(testDb(), $c, '2030-06-01 12:00:00', 60, 'inperson');
    assertSame(null, fetchSession(testDb(), $inPerson)['video_link'], 'not on an in-person one');
    setSetting('practice_video_link', '');
});

test('a session must belong to a client', function () {
    freshSessions();
    assertThrows(function () { createSession(testDb(), 0, '2030-06-01 10:00:00', 60, 'online'); },
        'client_id is never nullable');
});

test('consecutive no-shows are counted from the most recent backwards', function () {
    freshSessions();
    $c = sessionClient();
    $a = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    $b = createSession(testDb(), $c, '2030-06-02 10:00:00', 60, 'online');
    $d = createSession(testDb(), $c, '2030-06-03 10:00:00', 60, 'online');

    setSessionStatus(testDb(), $a, 'no-show');
    setSessionStatus(testDb(), $b, 'completed');
    setSessionStatus(testDb(), $d, 'no-show');

    assertSame(1, consecutiveNoShows(testDb(), $c),
        'a completed session in between breaks the run');
});

test('two no-shows in a row are counted as two', function () {
    freshSessions();
    $c = sessionClient();
    $a = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    $b = createSession(testDb(), $c, '2030-06-02 10:00:00', 60, 'online');
    setSessionStatus(testDb(), $a, 'no-show');
    setSessionStatus(testDb(), $b, 'no-show');

    assertSame(2, consecutiveNoShows(testDb(), $c), 'a run of two');
});

test('the sweep completes only confirmed sessions whose end time has passed', function () {
    freshSessions();
    $c = sessionClient();

    $past = createSession(testDb(), $c, date('Y-m-d H:i:s', time() - 7200), 60, 'online');
    setSessionStatus(testDb(), $past, 'confirmed');
    $future = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    setSessionStatus(testDb(), $future, 'confirmed');

    assertSame(1, sweepCompletedSessions(testDb()), 'one swept');
    assertSame('completed', fetchSession(testDb(), $past)['status'], 'the past one');
    assertSame('confirmed', fetchSession(testDb(), $future)['status'], 'the future one is untouched');
});

test('the sweep leaves pending sessions alone', function () {
    freshSessions();
    $c    = sessionClient();
    $past = createSession(testDb(), $c, date('Y-m-d H:i:s', time() - 7200), 60, 'online');

    assertSame(0, sweepCompletedSessions(testDb()), 'nothing swept');
    assertSame('pending', fetchSession(testDb(), $past)['status'],
        'a session nobody confirmed did not silently happen');
});
