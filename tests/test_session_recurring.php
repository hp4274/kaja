<?php
require_once __DIR__ . '/../includes/session-recurring.php';

test('a fixed-count series generates that many sessions a week apart', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 4]]);

    assertSame(4, count($ids), 'four sessions');
    assertSame('2030-06-24 10:00:00', fetchSession(testDb(), $ids[3])['start_time'], 'three weeks on');
});

test('every session in a series shares one series id', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 3]]);

    $seriesId = (int) fetchSession(testDb(), $ids[0])['recurring_series_id'];
    assertTrue($seriesId > 0, 'a series id was assigned');
    foreach ($ids as $id) {
        assertSame($seriesId, (int) fetchSession(testDb(), $id)['recurring_series_id'], 'shared');
    }
});

test('an end-date series stops on or before that date', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'date', 'value' => '2030-06-20']]);

    assertSame(3, count($ids), 'the 3rd, 10th and 17th');
});

test('a fortnightly series steps two weeks at a time', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'fortnightly', 'end' => ['type' => 'count', 'value' => 2]]);

    assertSame('2030-06-17 10:00:00', fetchSession(testDb(), $ids[1])['start_time'], 'two weeks on');
});

test('an open series generates a bounded first batch rather than forever', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'open']]);

    assertTrue(count($ids) > 0 && count($ids) <= 52, 'bounded: an unbounded loop is not an option');
});

test('an unknown frequency is refused', function () {
    freshSessions();
    $c = sessionClient();
    assertThrows(function () use ($c) {
        generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                       ['frequency' => 'hourly', 'end' => ['type' => 'count', 'value' => 2]]);
    }, 'only the frequencies we can step');
});

test('a series skips occurrences that would clash instead of failing outright', function () {
    freshSessions();
    $c = sessionClient();
    createSession(testDb(), $c, '2030-06-10 10:00:00', 60, 'online');

    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 3]]);

    assertSame(2, count($ids), 'the clashing week is skipped, the rest are booked');
});

test('later-in-series returns this occurrence and every one after it', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 4]]);

    assertSame(3, count(laterInSeries(testDb(), $ids[1])), 'the second, third and fourth');
});

test('scope "one" touches exactly one session', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 3]]);

    assertSame([$ids[1]], applyToScope(testDb(), $ids[1], 'one'), 'just that one');
});

test('scope "future" never reaches backwards into the past of the series', function () {
    freshSessions();
    $c   = sessionClient();
    $ids = generateSeries(testDb(), $c, '2030-06-03 10:00:00', 60, 'online',
                          ['frequency' => 'weekly', 'end' => ['type' => 'count', 'value' => 3]]);

    $targets = applyToScope(testDb(), $ids[1], 'future');
    assertTrue(!in_array($ids[0], $targets, true),
        'cancelling from the middle must not cancel a session that already happened');
});

test('a session with no series treats future as itself alone', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    assertSame([$id], applyToScope(testDb(), $id, 'future'), 'no series, no spread');
});

test('an unrecognised scope is refused rather than guessed', function () {
    freshSessions();
    $c  = sessionClient();
    $id = createSession(testDb(), $c, '2030-06-01 10:00:00', 60, 'online');
    assertThrows(function () use ($id) { applyToScope(testDb(), $id, 'all'); },
        'guessing the scope is the bug this feature is named after');
});
