<?php
require_once __DIR__ . '/../includes/session-mail.php';

test('the session mail templates exist and use their placeholders', function () {
    $d = settingDefaults();
    foreach (['confirmed', 'cancelled', 'reminder'] as $k) {
        assertTrue(isset($d['notify_session_' . $k . '_subject']), $k . ' subject');
        assertTrue(isset($d['notify_session_' . $k . '_body']), $k . ' body');
    }
    assertTrue(strpos($d['notify_session_confirmed_body'], '{{video_link}}') !== false,
        'the confirmation carries the joining link');
    assertTrue(strpos($d['notify_session_reminder_body'], '{{session_time}}') !== false,
        'the reminder says when');
});

test('mail variables come from the session and its client', function () {
    freshSessions();
    setSetting('practice_video_link', 'https://meet.example.com/kaja');
    $c  = sessionClient('Anita', 'anita@example.com');
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');

    $payload = sessionMailVars(testDb(), $id);
    assertSame('anita@example.com', $payload['to'], 'addressed to the client');
    assertSame('Anita Rao', $payload['vars']['client_name'], 'named');
    assertSame('https://meet.example.com/kaja', $payload['vars']['video_link'], 'with the link');
    setSetting('practice_video_link', '');
});

test('an in-person session says so rather than leaving the link blank', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'inperson');

    $vars = sessionMailVars(testDb(), $id)['vars'];
    assertTrue(strpos($vars['video_link'], 'in-person') !== false,
        'an empty line where a URL should be reads like a bug');
});

test('a session that does not exist yields no mail payload', function () {
    freshSessions();
    assertSame(null, sessionMailVars(testDb(), 999999), 'nothing to send');
});

test('an unknown mail kind is refused', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    assertThrows(function () use ($id) { sendSessionMail(testDb(), $id, 'gossip'); },
        'only the three templates that exist');
});

test('only confirmed sessions inside the window are due a reminder', function () {
    freshSessions();
    $c = sessionClient();

    $soon = createSession(testDb(), $c, date('Y-m-d H:i:s', time() + 3600), 60, 'online');
    setSessionStatus(testDb(), $soon, 'confirmed');

    $later = createSession(testDb(), $c, date('Y-m-d H:i:s', time() + 86400 * 5), 60, 'online');
    setSessionStatus(testDb(), $later, 'confirmed');

    $unconfirmed = createSession(testDb(), $c, date('Y-m-d H:i:s', time() + 7200), 60, 'online');

    $due = sessionsDueReminder(testDb(), 24);
    assertContains($soon, $due, 'the one an hour away');
    assertTrue(!in_array($later, $due, true), 'not the one five days out');
    assertTrue(!in_array($unconfirmed, $due, true),
        'reminding about an unconfirmed session promises an appointment that is not booked');
});

test('a reminded session is never due again', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, date('Y-m-d H:i:s', time() + 3600), 60, 'online');
    setSessionStatus(testDb(), $id, 'confirmed');

    assertSame(1, count(sessionsDueReminder(testDb(), 24)), 'due once');
    markSessionReminded(testDb(), $id);
    assertSame(0, count(sessionsDueReminder(testDb(), 24)), 'and not again');
});

test('rescheduling re-arms the reminder', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, date('Y-m-d H:i:s', time() + 3600), 60, 'online');
    setSessionStatus(testDb(), $id, 'confirmed');
    markSessionReminded(testDb(), $id);

    rescheduleSession(testDb(), $id, date('Y-m-d H:i:s', time() + 7200), 60);
    assertSame(1, count(sessionsDueReminder(testDb(), 24)),
        'a moved session needs a fresh reminder, the old one named the wrong time');
});

test('a past session is never due a reminder', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, date('Y-m-d H:i:s', time() - 3600), 60, 'online');
    setSessionStatus(testDb(), $id, 'confirmed');

    assertSame(0, count(sessionsDueReminder(testDb(), 24)), 'it already happened');
});
