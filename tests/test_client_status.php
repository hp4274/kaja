<?php
require_once __DIR__ . '/../includes/client-status.php';

test('the vocabulary is the five lifecycle states', function () {
    assertSame(['pending', 'review', 'active', 'inactive', 'completed'],
        clientStatuses(), 'in lifecycle order');
});

test('only an active client is bookable', function () {
    assertSame(true, clientIsBookable('active'), 'active');
    foreach (['pending', 'review', 'inactive', 'completed'] as $s) {
        assertSame(false, clientIsBookable($s), $s . ' is not bookable');
    }
});

test('reactivation is allowed from both end states', function () {
    assertSame(true, clientCanTransition('inactive', 'active'), 'a pause can end');
    assertSame(true, clientCanTransition('completed', 'active'), 'people come back');
});

test('a transition to the state it is already in is refused', function () {
    assertSame(false, clientCanTransition('active', 'active'), 'nothing to log');
});

test('the retired vocabulary is refused', function () {
    assertSame(false, isValidClientStatus('discharged'), 'renamed to completed');
});

test('every status has a label and a badge class', function () {
    foreach (clientStatuses() as $s) {
        assertTrue(clientStatusLabel($s) !== '', $s . ' needs a label');
        assertTrue(clientStatusBadgeClass($s) !== '', $s . ' needs a badge class');
    }
});
