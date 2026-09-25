<?php
/**
 * The lead status vocabulary, in one place.
 *
 * Pure functions: no database, no clock read beyond the injected $now. The
 * list, the detail drawer, the API and the dashboard all read their rules from
 * here, so they cannot disagree about what a status means or what comes next.
 *
 * Pipeline:   new -> contacted -> confirmed -> converted
 * Side exit: rejected — reachable from any live state, terminal.
 */

/** A lead sitting at 'new' longer than this gets the aging flag in the list. */
const LEAD_AGING_HOURS = 24;

function leadStatuses() {
    return ['new', 'contacted', 'confirmed', 'converted', 'rejected'];
}

function isValidLeadStatus($status) {
    return in_array($status, leadStatuses(), true);
}

function leadStatusLabel($status) {
    $labels = [
        'new'       => 'New',
        'contacted' => 'Contacted',
        // Stored as 'confirmed'; called Accepted, because the button that
        // gets a lead here says Accept and one action keeps one name.
        'confirmed' => 'Accepted',
        'converted' => 'Converted',
        'rejected'  => 'Rejected',
    ];
    return isset($labels[$status]) ? $labels[$status] : 'Unknown';
}

function leadStatusBadgeClass($status) {
    $classes = [
        'new'       => 'badge-new',
        'contacted' => 'badge-contacted',
        'confirmed' => 'badge-confirmed',
        'converted' => 'badge-converted',
        'rejected'  => 'badge-rejected',
    ];
    return isset($classes[$status]) ? $classes[$status] : 'badge-archived';
}

/** Terminal means: nothing leaves this state, ever. */
function leadStatusIsTerminal($status) {
    return $status === 'rejected';
}

/**
 * Forward along the pipeline, or sideways into a terminal exit. Never
 * backwards — a status the admin regrets is a data-repair job, not a button,
 * because moving back out of 'converted' would orphan a client row.
 *
 * Written as a map rather than index arithmetic because the pipeline is not
 * quite a straight line: a lead can be confirmed straight from 'new'. Someone
 * who books through the site and is accepted on the spot was never "contacted"
 * as a separate act, and making the therapist click a step that did not happen
 * is how a status stops describing anything real.
 *
 * 'converted' is still only reachable from 'confirmed', because it is what the
 * returning intake sets, not a thing anyone announces by hand.
 */
function leadCanTransition($from, $to) {
    if (!isValidLeadStatus($from) || !isValidLeadStatus($to)) {
        return false;
    }
    if (leadStatusIsTerminal($from)) {
        return false;
    }
    if (leadStatusIsTerminal($to)) {
        return true;
    }

    $allowed = [
        'new'       => ['contacted', 'confirmed'],
        'contacted' => ['confirmed'],
        'confirmed' => ['converted'],
    ];

    return isset($allowed[$from]) && in_array($to, $allowed[$from], true);
}

/**
 * True when a lead is still untouched at 'new' past LEAD_AGING_HOURS.
 * Anything past 'new' has been acted on, so it never ages.
 */
function leadIsAging(array $lead, $now = null) {
    $status = isset($lead['status']) ? $lead['status'] : 'new';
    if ($status !== 'new') {
        return false;
    }
    if (empty($lead['created_at'])) {
        return false;
    }

    $nowTs     = ($now === null) ? time() : strtotime($now);
    $createdTs = strtotime($lead['created_at']);
    if ($nowTs === false || $createdTs === false) {
        return false;
    }

    return ($nowTs - $createdTs) > (LEAD_AGING_HOURS * 3600);
}

/** The single most useful next action, or null when nothing is pending. */
function leadNextAction($status) {
    $actions = [
        'new'       => 'review',
        'contacted' => 'confirm',
        'confirmed' => 'convert',
    ];
    return isset($actions[$status]) ? $actions[$status] : null;
}
