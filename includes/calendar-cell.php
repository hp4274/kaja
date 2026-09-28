<?php
/**
 * The mini-schedule drawn inside one calendar day cell: one row per bookable
 * time of day, in the state that time is actually in. Both calendars render
 * the same list from the same rule, so a cell means one thing everywhere.
 */

require_once __DIR__ . '/booking-slots.php';

/**
 * @param array $daySessions   Every row from $calSessions[$day] (any status).
 * @param array $blockedTimes  "H:i" strings blocked on this date.
 * @param bool  $isHoliday     Whole day closed -- every slot reads as holiday
 *                             regardless of what is under it, rather than
 *                             collapsing the day to one line. The structure
 *                             (one row per bookable time) stays the same
 *                             everywhere; only the state each row is in changes.
 * @return array List of ['time','label','status','name'], one per slot, in
 *               bookingSlots() order. status is 'booked'|'cancelled'|'blocked'|'holiday'|'free'.
 */
function calendarSlotRows(array $daySessions, array $blockedTimes, $isHoliday = false) {
    $bySlot = [];
    foreach ($daySessions as $s) {
        $t = date('H:i', strtotime($s['start_time']));
        $isCancelled = in_array($s['status'], ['cancelled', 'no-show'], true);
        // An active booking always wins the slot over a cancelled one sitting
        // at the same time -- the cell should show what is actually true now.
        if (!isset($bySlot[$t]) || ($bySlot[$t]['status'] === 'cancelled' && !$isCancelled)) {
            $bySlot[$t] = ['status' => $isCancelled ? 'cancelled' : 'booked', 'name' => $s['client_name']];
        }
    }

    $rows = [];
    foreach (bookingSlots() as $slot) {
        if ($isHoliday) {
            $rows[] = ['time' => $slot, 'label' => bookingSlotLabel($slot), 'status' => 'holiday', 'name' => null];
        } elseif (isset($bySlot[$slot])) {
            $rows[] = ['time' => $slot, 'label' => bookingSlotLabel($slot), 'status' => $bySlot[$slot]['status'], 'name' => $bySlot[$slot]['name']];
        } elseif (in_array($slot, $blockedTimes, true)) {
            $rows[] = ['time' => $slot, 'label' => bookingSlotLabel($slot), 'status' => 'blocked', 'name' => null];
        } else {
            $rows[] = ['time' => $slot, 'label' => bookingSlotLabel($slot), 'status' => 'free', 'name' => null];
        }
    }
    return $rows;
}

/** The rows above, rendered as the cell's mini-schedule markup. */
function calendarSlotRowsHtml(array $rows) {
    $icons = ['blocked' => 'bi-lock-fill', 'cancelled' => 'bi-x-circle-fill', 'holiday' => 'bi-calendar-check'];
    $html = '<div class="cal-slot-list">';
    foreach ($rows as $row) {
        $html .= '<div class="cal-slot-row">';
        // A plain "H:i" reads faster in a column this narrow than a 12-hour
        // label with AM/PM competing for the same few pixels.
        $html .= '<span class="cal-slot-time">' . htmlspecialchars($row['time']) . '</span>';
        $html .= '<span class="cal-slot-pill is-' . $row['status'] . '">';
        if (isset($icons[$row['status']])) {
            $html .= '<i class="bi ' . $icons[$row['status']] . '"></i> ';
        }
        $html .= $row['status'] === 'free' ? '&mdash;' : htmlspecialchars($row['name'] ?: ucfirst($row['status']));
        $html .= '</span></div>';
    }
    $html .= '</div>';
    return $html;
}
