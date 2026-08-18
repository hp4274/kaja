<?php
/**
 * The intake link lifecycle, in one place.
 *
 * Pure functions: no database, no clock read beyond the injected $now. The
 * beacon endpoints, the cron, the admin list and the dashboard all read their
 * rules from here.
 *
 * sent -> opened -> filled -> submitted, with expired as a timeout side-exit
 * from any live state. Nothing ever moves backwards.
 */

function intakeStatuses() {
    return ['sent', 'opened', 'filled', 'submitted', 'expired'];
}

function isValidIntakeStatus($status) {
    return in_array($status, intakeStatuses(), true);
}

function intakeStatusLabel($status) {
    $labels = [
        'sent'      => 'Sent',
        'opened'    => 'Opened',
        'filled'    => 'In progress',
        'submitted' => 'Submitted',
        'expired'   => 'Expired',
    ];
    return isset($labels[$status]) ? $labels[$status] : 'Unknown';
}

function intakeStatusBadgeClass($status) {
    $classes = [
        'sent'      => 'badge-intake-sent',
        'opened'    => 'badge-intake-opened',
        'filled'    => 'badge-intake-filled',
        'submitted' => 'badge-intake-submitted',
        'expired'   => 'badge-intake-expired',
    ];
    return isset($classes[$status]) ? $classes[$status] : 'badge-archived';
}

function intakeStatusIsTerminal($status) {
    return $status === 'submitted' || $status === 'expired';
}

/**
 * Forward along the lifecycle, or sideways into expiry.
 *
 * Skipping is allowed: someone who starts typing before the 'opened' write
 * lands should still reach 'filled'. Going backwards is not — a beacon
 * arriving late must never downgrade a submitted row.
 */
function intakeCanAdvance($from, $to) {
    if (!isValidIntakeStatus($from) || !isValidIntakeStatus($to)) {
        return false;
    }
    if (intakeStatusIsTerminal($from)) {
        return false;
    }
    if ($to === 'expired') {
        return true;
    }

    $order   = ['sent', 'opened', 'filled', 'submitted'];
    $fromIdx = array_search($from, $order, true);
    $toIdx   = array_search($to, $order, true);

    if ($fromIdx === false || $toIdx === false) {
        return false;
    }
    return $toIdx > $fromIdx;
}

/**
 * True when a link has sat unfinished past the reminder threshold and has not
 * already been chased once.
 */
function intakeLinkIsStale(array $link, $hours, $now = null) {
    $status = isset($link['status']) ? $link['status'] : 'sent';
    if ($status !== 'sent' && $status !== 'opened' && $status !== 'filled') {
        return false;
    }
    if (!empty($link['reminder_sent'])) {
        return false;
    }
    if (empty($link['created_at'])) {
        return false;
    }

    $nowTs     = ($now === null) ? time() : strtotime($now);
    $createdTs = strtotime($link['created_at']);
    if ($nowTs === false || $createdTs === false) {
        return false;
    }

    return ($nowTs - $createdTs) > ((int) $hours * 3600);
}
