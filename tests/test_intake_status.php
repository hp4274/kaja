<?php
require_once __DIR__ . '/../includes/intake-status.php';

test('the vocabulary is the five lifecycle states in order', function () {
    assertSame(
        ['sent', 'opened', 'filled', 'submitted', 'expired'],
        intakeStatuses(),
        'lifecycle order, with expired last as the side-exit'
    );
});

test('every status has a label and a badge class', function () {
    foreach (intakeStatuses() as $s) {
        assertTrue(intakeStatusLabel($s) !== '', $s . ' needs a label');
        assertTrue(intakeStatusBadgeClass($s) !== '', $s . ' needs a badge class');
    }
});

test('submitted and expired are terminal', function () {
    assertSame(true,  intakeStatusIsTerminal('submitted'), 'submitted is terminal');
    assertSame(true,  intakeStatusIsTerminal('expired'), 'expired is terminal');
    assertSame(false, intakeStatusIsTerminal('opened'), 'opened is not');
});

test('the lifecycle only moves forward', function () {
    assertSame(true,  intakeCanAdvance('sent', 'opened'), 'sent -> opened');
    assertSame(true,  intakeCanAdvance('opened', 'filled'), 'opened -> filled');
    assertSame(true,  intakeCanAdvance('sent', 'filled'), 'a fast typist can skip opened');
    assertSame(false, intakeCanAdvance('filled', 'opened'), 'never backwards');
    assertSame(false, intakeCanAdvance('submitted', 'filled'), 'submitted is locked');
});

test('expired is reachable from any live state but leads nowhere', function () {
    foreach (['sent', 'opened', 'filled'] as $from) {
        assertSame(true, intakeCanAdvance($from, 'expired'), $from . ' -> expired');
    }
    assertSame(false, intakeCanAdvance('submitted', 'expired'), 'a submitted form cannot expire');
    assertSame(false, intakeCanAdvance('expired', 'opened'), 'expired is terminal');
});

test('a link past the reminder threshold and still unfilled is stale', function () {
    $now = '2026-08-18 12:00:00';
    assertSame(true, intakeLinkIsStale(
        ['status' => 'sent', 'created_at' => '2026-08-15 09:00:00', 'reminder_sent' => 0], 48, $now
    ), 'three days at sent is stale against a 48h threshold');
    assertSame(false, intakeLinkIsStale(
        ['status' => 'sent', 'created_at' => '2026-08-18 09:00:00', 'reminder_sent' => 0], 48, $now
    ), 'three hours is not');
});

test('an already-reminded or already-submitted link is never stale', function () {
    $now = '2026-08-18 12:00:00';
    assertSame(false, intakeLinkIsStale(
        ['status' => 'sent', 'created_at' => '2026-07-01 09:00:00', 'reminder_sent' => 1], 48, $now
    ), 'one reminder per link, ever');
    assertSame(false, intakeLinkIsStale(
        ['status' => 'submitted', 'created_at' => '2026-07-01 09:00:00', 'reminder_sent' => 0], 48, $now
    ), 'they already filled it in');
});
