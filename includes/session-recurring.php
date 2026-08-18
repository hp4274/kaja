<?php
/**
 * Recurring session series.
 *
 * Generation inserts N real rows upfront rather than storing a rule evaluated
 * at read time. Editing a single occurrence is then an ordinary update, and
 * the calendar does not have to expand anything to know what is booked.
 *
 * Scope is never guessed. Reschedule and cancel ask "this one" or "this and
 * every later one", because silently doing the wrong one is the defining bug
 * of recurring appointments — and both wrong answers are invisible until
 * somebody turns up to an appointment that was cancelled without them.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/session-repo.php';

/** An open-ended series still has to stop somewhere. One year of weeks. */
const SERIES_OPEN_LIMIT = 52;

function seriesFrequencies() {
    return ['weekly', 'fortnightly', 'monthly'];
}

function seriesIntervalSpec($frequency) {
    $map = [
        'weekly'      => '+1 week',
        'fortnightly' => '+2 weeks',
        'monthly'     => '+1 month',
    ];
    return isset($map[$frequency]) ? $map[$frequency] : null;
}

/**
 * Generate a series and return the ids actually booked.
 *
 * An occurrence that clashes is SKIPPED, not fatal. A recurring booking that
 * refused outright because week six hits a holiday would make the feature
 * unusable; the admin can see what landed and fill the gap by hand.
 */
function generateSeries(PDO $db, $clientId, $firstStart, $durationMinutes, $type, array $recurrence) {
    $frequency = isset($recurrence['frequency']) ? $recurrence['frequency'] : 'weekly';
    $step      = seriesIntervalSpec($frequency);
    if ($step === null) {
        throw new InvalidArgumentException('Unknown recurrence frequency: ' . $frequency);
    }

    $end     = isset($recurrence['end']) ? $recurrence['end'] : ['type' => 'count', 'value' => 1];
    $endType = isset($end['type']) ? $end['type'] : 'count';

    $occurrences = [];
    $cursor      = strtotime($firstStart);
    if ($cursor === false) {
        throw new InvalidArgumentException('That is not a valid start time.');
    }

    if ($endType === 'count') {
        $count = max(1, min((int) $end['value'], SERIES_OPEN_LIMIT));
        for ($i = 0; $i < $count; $i++) {
            $occurrences[] = date('Y-m-d H:i:s', $cursor);
            $cursor = strtotime($step, $cursor);
        }
    } elseif ($endType === 'date') {
        $until = strtotime($end['value'] . ' 23:59:59');
        if ($until === false) {
            throw new InvalidArgumentException('That is not a valid end date.');
        }
        $guard = 0;
        while ($cursor <= $until && $guard < SERIES_OPEN_LIMIT) {
            $occurrences[] = date('Y-m-d H:i:s', $cursor);
            $cursor = strtotime($step, $cursor);
            $guard++;
        }
    } else {
        // Open: the admin stops it later. Bounded anyway — an unbounded
        // generator is not a feature, it is a way to fill a table.
        for ($i = 0; $i < SERIES_OPEN_LIMIT; $i++) {
            $occurrences[] = date('Y-m-d H:i:s', $cursor);
            $cursor = strtotime($step, $cursor);
        }
    }

    if (!$occurrences) {
        return [];
    }

    // The series id is the first booked session's id. No separate table: the
    // series is exactly the set of rows carrying it.
    $seriesId = null;
    $booked   = [];

    foreach ($occurrences as $start) {
        try {
            $id = createSession($db, $clientId, $start, $durationMinutes, $type, $seriesId);
        } catch (RuntimeException $e) {
            continue;   // clash: skip this week, keep the rest
        }

        if ($seriesId === null) {
            $seriesId = $id;
            $db->prepare('UPDATE `sessions` SET `recurring_series_id` = :s WHERE `id` = :id')
               ->execute([':s' => $seriesId, ':id' => $id]);
        }
        $booked[] = $id;
    }

    return $booked;
}

function seriesSessions(PDO $db, $seriesId) {
    $stmt = $db->prepare('SELECT * FROM `sessions` WHERE `recurring_series_id` = :s ORDER BY `start_time` ASC');
    $stmt->execute([':s' => (int) $seriesId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * This occurrence and every later one in its series.
 *
 * Ordered by start_time, not by id: a rescheduled occurrence can sit later in
 * time than one booked after it, and "future" means later in the calendar,
 * not later in the table.
 */
function laterInSeries(PDO $db, $sessionId) {
    $session = fetchSession($db, $sessionId);
    if ($session === null || empty($session['recurring_series_id'])) {
        return $session === null ? [] : [(int) $session['id']];
    }

    $stmt = $db->prepare('
        SELECT `id` FROM `sessions`
        WHERE `recurring_series_id` = :s AND `start_time` >= :from
        ORDER BY `start_time` ASC
    ');
    $stmt->execute([':s' => (int) $session['recurring_series_id'], ':from' => $session['start_time']]);

    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

/**
 * Which sessions an action should touch, given an explicit scope.
 *
 * Only 'one' and 'future' exist. There is deliberately no "all in series":
 * reaching backwards would rewrite appointments that have already happened.
 */
function applyToScope(PDO $db, $sessionId, $scope) {
    if ($scope === 'future') {
        return laterInSeries($db, $sessionId);
    }
    if ($scope !== 'one') {
        throw new InvalidArgumentException('Scope must be "one" or "future".');
    }
    return [(int) $sessionId];
}
