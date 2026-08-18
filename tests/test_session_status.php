<?php
require_once __DIR__ . '/../includes/session-status.php';

test('the vocabulary is the five session states', function () {
    assertSame(['pending', 'confirmed', 'completed', 'cancelled', 'no-show'],
        sessionStatuses(), 'in lifecycle order');
});

test('the lifecycle moves forward and exits sideways', function () {
    assertSame(true,  sessionCanTransition('pending', 'confirmed'), 'pending -> confirmed');
    assertSame(true,  sessionCanTransition('confirmed', 'completed'), 'confirmed -> completed');
    assertSame(true,  sessionCanTransition('pending', 'cancelled'), 'cancel before confirming');
    assertSame(true,  sessionCanTransition('confirmed', 'no-show'), 'they did not turn up');
    assertSame(false, sessionCanTransition('completed', 'pending'), 'never backwards');
    assertSame(false, sessionCanTransition('cancelled', 'confirmed'), 'cancelled is terminal');
});

test('a completed session cannot become a no-show', function () {
    assertSame(false, sessionCanTransition('completed', 'no-show'),
        'both claims cannot be true of the same hour');
});

test('cancelled and no-show release the slot, everything else holds it', function () {
    assertSame(false, sessionHoldsSlot('cancelled'), 'cancelled frees the hour');
    assertSame(false, sessionHoldsSlot('no-show'), 'so does a no-show, after the fact');
    foreach (['pending', 'confirmed', 'completed'] as $s) {
        assertSame(true, sessionHoldsSlot($s), $s . ' holds the hour');
    }
});

test('the retired vocabulary is refused', function () {
    assertSame(false, isValidSessionStatus('scheduled'), 'scheduled became confirmed');
});

test('every status has a label and a badge class', function () {
    foreach (sessionStatuses() as $s) {
        assertTrue(sessionStatusLabel($s) !== '', $s . ' needs a label');
        assertTrue(sessionStatusBadgeClass($s) !== '', $s . ' needs a badge class');
    }
});
