<?php
/**
 * The client lifecycle, in one place.
 *
 * pending -> review -> active -> inactive | completed, with reactivation back
 * to active from either end state.
 *
 * Unlike leads, every transition here is permitted. A therapist reclassifying
 * someone is a clinical judgement, not a workflow violation, and a system that
 * argued with it would just get worked around. The one thing that cannot
 * happen is deletion.
 */

function clientStatuses() {
    return ['pending', 'review', 'active', 'inactive', 'completed'];
}

function isValidClientStatus($status) {
    return in_array($status, clientStatuses(), true);
}

function clientStatusLabel($status) {
    $labels = [
        'pending'   => 'Pending intake',
        'review'    => 'Awaiting review',
        'active'    => 'Active',
        'inactive'  => 'Inactive',
        'completed' => 'Completed',
    ];
    return isset($labels[$status]) ? $labels[$status] : 'Unknown';
}

function clientStatusBadgeClass($status) {
    $classes = [
        'pending'   => 'badge-pending',
        'review'    => 'badge-intake-filled',
        'active'    => 'badge-active',
        'inactive'  => 'badge-inactive',
        'completed' => 'badge-client-completed',
    ];
    return isset($classes[$status]) ? $classes[$status] : 'badge-archived';
}

/**
 * Bookable means: intake is in, a human has looked at it, and treatment is
 * live. Everything else is a reason not to put them in the calendar.
 */
function clientIsBookable($status) {
    return $status === 'active';
}

function clientCanTransition($from, $to) {
    if (!isValidClientStatus($from) || !isValidClientStatus($to)) {
        return false;
    }
    return $from !== $to;
}
