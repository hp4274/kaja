<?php
/**
 * The session lifecycle, in one place.
 *
 * pending -> confirmed -> completed, with cancelled and no-show as exits.
 *
 * no-show is deliberately not a flavour of cancelled. A cancellation came with
 * notice and freed the hour; a no-show did not, and the two need different
 * follow-up and separate reporting.
 */

function sessionStatuses() {
    return ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
}

function isValidSessionStatus($status) {
    return in_array($status, sessionStatuses(), true);
}

function sessionStatusLabel($status) {
    $labels = [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no-show'   => 'No-show',
    ];
    return isset($labels[$status]) ? $labels[$status] : 'Unknown';
}

function sessionStatusBadgeClass($status) {
    $classes = [
        'pending'   => 'badge-pending',
        'confirmed' => 'badge-scheduled',
        'completed' => 'badge-completed',
        'cancelled' => 'badge-cancelled',
        'no-show'   => 'badge-no-show',
    ];
    return isset($classes[$status]) ? $classes[$status] : 'badge-archived';
}

function sessionStatusIsTerminal($status) {
    return in_array($status, ['completed', 'cancelled', 'no-show'], true);
}

/**
 * A completed session cannot become a no-show: it already happened, and the
 * two claims cannot both be true of the same hour.
 */
function sessionCanTransition($from, $to) {
    if (!isValidSessionStatus($from) || !isValidSessionStatus($to) || $from === $to) {
        return false;
    }
    if (sessionStatusIsTerminal($from)) {
        return false;
    }

    $allowed = [
        'pending'   => ['confirmed', 'completed', 'cancelled', 'no-show'],
        'confirmed' => ['completed', 'cancelled', 'no-show'],
    ];
    return isset($allowed[$from]) && in_array($to, $allowed[$from], true);
}

/**
 * Whether a session in this status still occupies its hour.
 *
 * A cancelled slot is free to rebook. A no-show only becomes one after the
 * time has passed, but once set it stops blocking anything.
 */
function sessionHoldsSlot($status) {
    return !in_array($status, ['cancelled', 'no-show'], true);
}
