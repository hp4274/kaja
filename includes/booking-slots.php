<?php
/**
 * The bookable times of day, in one place.
 *
 * Before this file there were three lists: the appointment page offered
 * 9/11/2/4/6, the intake questionnaire offered 9/10.30/1/3.30/5, and the admin
 * calendar offered a free `<input type="time">`. A visitor could therefore ask
 * for a slot the practice does not run, and two visitors filling two different
 * forms were shown two different practices.
 *
 * The list is a setting rather than a constant because opening hours are a
 * business decision, not a code one.
 *
 * Stored as a comma-separated list of 24-hour H:i times. 24-hour because that
 * is what `<input type="time">` and MySQL both speak; the 12-hour rendering is
 * a presentation concern and lives in bookingSlotLabel().
 */

require_once __DIR__ . '/settings.php';

/**
 * Canonical slots as 24-hour "H:i" strings, in order, deduplicated.
 *
 * Anything unparseable in the setting is dropped rather than passed on: a
 * malformed slot reaching a form would render as an option nobody can book.
 */
function bookingSlots() {
    $raw   = (string) getSetting('booking_slots');
    $slots = [];

    foreach (explode(',', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        $ts = strtotime('1970-01-01 ' . $piece);
        if ($ts === false) {
            continue;
        }
        $slots[] = date('H:i', $ts);
    }

    $slots = array_values(array_unique($slots));
    sort($slots);

    // A practice with no slots at all cannot take a booking, and a form with an
    // empty dropdown explains nothing. Fall back to the shipped default.
    if (!$slots) {
        return ['09:00', '11:00', '13:00', '15:00', '17:00'];
    }

    return $slots;
}

/** "13:00" -> "01:00 PM". The form-facing spelling, used for both label and value. */
function bookingSlotLabel($slot) {
    $ts = strtotime('1970-01-01 ' . $slot);
    return $ts === false ? (string) $slot : date('h:i A', $ts);
}

/** True when a 24-hour "H:i" time is one the practice actually offers. */
function isBookingSlot($time) {
    $ts = strtotime('1970-01-01 ' . $time);
    return $ts !== false && in_array(date('H:i', $ts), bookingSlots(), true);
}

/**
 * Options for an admin time picker.
 *
 * $current is the time a session already sits at. Sessions booked before the
 * slot list changed — or moved to an off-grid time deliberately — must still
 * be reschedulable, so an unrecognised current time is carried into the list
 * rather than silently dropped, which would leave the picker showing a time
 * the session is not actually at.
 */
function bookingSlotOptions($current = '') {
    $slots = bookingSlots();

    $current = trim((string) $current);
    if ($current !== '') {
        $ts = strtotime('1970-01-01 ' . $current);
        if ($ts !== false && !in_array(date('H:i', $ts), $slots, true)) {
            $slots[] = date('H:i', $ts);
            sort($slots);
        }
    }

    $options = [];
    foreach ($slots as $slot) {
        $options[$slot] = bookingSlotLabel($slot);
    }
    return $options;
}
