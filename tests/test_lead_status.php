<?php
require_once __DIR__ . '/../includes/lead-status.php';

test('the vocabulary is exactly the six spec statuses in pipeline order', function () {
    assertSame(
        ['new', 'contacted', 'confirmed', 'converted', 'rejected', 'spam'],
        leadStatuses(),
        'leadStatuses() should return the spec vocabulary in pipeline order'
    );
});

test('old vocabulary is rejected', function () {
    assertSame(false, isValidLeadStatus('accepted'), 'accepted is not a status any more');
    assertSame(false, isValidLeadStatus('declined'), 'declined is not a status any more');
    assertSame(true,  isValidLeadStatus('confirmed'), 'confirmed is a status');
});

test('every status has a label and a badge class', function () {
    foreach (leadStatuses() as $s) {
        assertTrue(leadStatusLabel($s) !== '', $s . ' should have a label');
        assertTrue(leadStatusBadgeClass($s) !== '', $s . ' should have a badge class');
    }
});

test('rejected and spam are terminal, the pipeline states are not', function () {
    assertSame(true,  leadStatusIsTerminal('rejected'), 'rejected is terminal');
    assertSame(true,  leadStatusIsTerminal('spam'), 'spam is terminal');
    assertSame(false, leadStatusIsTerminal('new'), 'new is not terminal');
    assertSame(false, leadStatusIsTerminal('converted'), 'converted is not a side-exit');
});

test('the pipeline moves forward one step at a time', function () {
    assertSame(true,  leadCanTransition('new', 'contacted'), 'new -> contacted');
    assertSame(true,  leadCanTransition('contacted', 'confirmed'), 'contacted -> confirmed');
    assertSame(false, leadCanTransition('new', 'converted'), 'new must not jump straight to converted');
    assertSame(false, leadCanTransition('converted', 'new'), 'converted never goes backwards');
});

test('rejected and spam are reachable from any live state and are one-way', function () {
    foreach (['new', 'contacted', 'confirmed', 'converted'] as $from) {
        assertSame(true, leadCanTransition($from, 'rejected'), $from . ' -> rejected');
        assertSame(true, leadCanTransition($from, 'spam'), $from . ' -> spam');
    }
    assertSame(false, leadCanTransition('rejected', 'new'), 'rejected is terminal');
    assertSame(false, leadCanTransition('spam', 'contacted'), 'spam is terminal');
});

test('a new lead older than 24h is aging, a fresh one is not', function () {
    $now = '2026-08-18 12:00:00';
    assertSame(true, leadIsAging(
        ['status' => 'new', 'created_at' => '2026-08-17 11:00:00'], $now
    ), '25h-old new lead is aging');
    assertSame(false, leadIsAging(
        ['status' => 'new', 'created_at' => '2026-08-18 09:00:00'], $now
    ), '3h-old new lead is not aging');
});

test('only leads still at new can age', function () {
    $now = '2026-08-18 12:00:00';
    assertSame(false, leadIsAging(
        ['status' => 'contacted', 'created_at' => '2026-08-01 09:00:00'], $now
    ), 'a contacted lead has been touched, so it never shows the aging badge');
});

test('next action follows the pipeline', function () {
    assertSame('review',  leadNextAction('new'), 'a new lead needs reviewing');
    assertSame('confirm', leadNextAction('contacted'), 'a contacted lead needs confirming');
    assertSame('convert', leadNextAction('confirmed'), 'a confirmed lead is waiting to convert');
    assertSame(null,      leadNextAction('converted'), 'a converted lead needs nothing');
    assertSame(null,      leadNextAction('spam'), 'spam needs nothing');
});
