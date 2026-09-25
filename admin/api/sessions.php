<?php
/**
 * Session actions.
 *
 * Every write delegates to includes/session-repo.php, which owns the conflict
 * guard. Nothing here writes to `sessions` directly — a guard with a second
 * door is not a guard.
 *
 * The form posts a date and a time separately because that is the right pair
 * of inputs to show; they are combined into one start_time here.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/session-repo.php';
require_once __DIR__ . '/../../includes/session-recurring.php';
require_once __DIR__ . '/../../includes/session-mail.php';
require_once __DIR__ . '/../../includes/holidays.php';

$db     = getDbConnection();
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

/**
 * Every date the booking form is asking for, in calendar order.
 *
 * The form posts session_dates only when the therapist ctrl- or shift-clicked
 * more than one day on the calendar. Its absence must keep the single-date
 * path exactly as it was, so it falls back to the single posted date.
 */
function postedDates() {
    $many = trim($_POST['session_dates'] ?? '');
    if ($many !== '') {
        $dates = array_values(array_unique(array_filter(array_map('trim', explode(',', $many)))));
        sort($dates);
        return $dates;
    }
    $one = trim($_POST['session_date'] ?? '');
    return $one === '' ? [] : [$one];
}

/** A date input plus a time input is one instant. */
function postedStart($dateKey = 'session_date', $timeKey = 'session_time') {
    $date = trim($_POST[$dateKey] ?? '');
    $time = trim($_POST[$timeKey] ?? '');
    if ($date === '' || $time === '') {
        return null;
    }
    $ts = strtotime($date . ' ' . $time);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function sessionLabel(PDO $db, $sessionId) {
    $stmt = $db->prepare('
        SELECT s.`start_time`, c.`first_name`, c.`last_name`
        FROM `sessions` s JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE s.`id` = :id
    ');
    $stmt->execute([':id' => (int) $sessionId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r
        ? trim($r['first_name'] . ' ' . $r['last_name']) . ' on ' . date('d M Y, h:i A', strtotime($r['start_time']))
        : 'Session #' . (int) $sessionId;
}

try {
    switch ($action) {
        case 'add_session':
            $clientId = intval($_POST['client_id'] ?? 0);
            $dates    = postedDates();
            $time     = trim($_POST['session_time'] ?? '');
            $start    = postedStart();
            // Both booking forms post this as `duration`. Reading it under any
            // other name silently discards it and books every session at the
            // default length, which is what happened here until now.
            $duration = intval($_POST['duration'] ?? 0);
            if ($duration < 1) {
                $duration = getSettingInt('default_session_duration', 60);
            }
            $type     = trim($_POST['session_type'] ?? 'online');
            $notes    = trim($_POST['notes'] ?? '');
            $repeat   = trim($_POST['repeat'] ?? '');

            if (!$clientId || $start === null) {
                echo json_encode(['success' => false, 'error' => 'A client, a date and a time are all required']);
                exit;
            }

            try {
                if (count($dates) > 1) {
                    // One session per picked day, at the same time. Repeat is
                    // disabled in the UI while several days are selected: the
                    // two are different ways of asking for more sessions and
                    // combining them means neither input reads as it looks.
                    $ids     = [];
                    $clashed = [];
                    foreach ($dates as $d) {
                        $ts = strtotime($d . ' ' . $time);
                        if ($ts === false) { continue; }
                        try {
                            $ids[] = createSession($db, $clientId, date('Y-m-d H:i:s', $ts), $duration, $type);
                        } catch (Throwable $e) {
                            // One clashing day must not lose the other four.
                            // Kept as a plain date: the browser groups the list
                            // into "Aug (2, 9)" for display.
                            $clashed[] = date('Y-m-d', $ts);
                        }
                    }

                    if (!$ids) {
                        echo json_encode(['success' => false,
                                          'error' => 'None of the selected dates could be booked: '
                                                     . implode(', ', array_map(function ($d) {
                                                           return date('d M', strtotime($d));
                                                       }, $clashed))]);
                        exit;
                    }

                    if ($notes !== '') {
                        $noteStmt = $db->prepare('UPDATE `sessions` SET `notes` = :n WHERE `id` = :id');
                        foreach ($ids as $nid) {
                            $noteStmt->execute([':n' => $notes, ':id' => $nid]);
                        }
                    }

                    $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_scheduled',:d,'client',:rid)")
                       ->execute([':d' => count($ids) . ' session(s) booked for ' . sessionLabel($db, $ids[0]), ':rid' => $clientId]);

                    foreach ($ids as $mid) {
                        if (fetchSession($db, $mid)['status'] === 'confirmed') {
                            sendSessionMail($db, $mid, 'confirmation');
                        }
                    }

                    echo json_encode(['success' => true, 'session_id' => $ids[0],
                                      'booked' => count($ids), 'skipped' => $clashed]);
                    exit;
                }

                if ($repeat !== '' && $repeat !== 'none') {
                    $endType  = trim($_POST['repeat_end_type'] ?? 'count');
                    $endValue = trim($_POST['repeat_end_value'] ?? '4');

                    $ids = generateSeries($db, $clientId, $start, $duration, $type, [
                        'frequency' => $repeat,
                        'end'       => ['type' => $endType, 'value' => $endValue],
                    ]);

                    if (!$ids) {
                        echo json_encode(['success' => false, 'error' => 'Every occurrence clashed with an existing session']);
                        exit;
                    }
                    $sessionId = $ids[0];
                    $booked    = count($ids);
                } else {
                    $sessionId = createSession($db, $clientId, $start, $duration, $type);
                    $booked    = 1;
                }
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            if ($notes !== '') {
                $db->prepare('UPDATE `sessions` SET `notes` = :n WHERE `id` = :id')
                   ->execute([':n' => $notes, ':id' => $sessionId]);
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_scheduled',:d,'client',:rid)")
               ->execute([':d' => $booked . ' session(s) booked for ' . sessionLabel($db, $sessionId), ':rid' => $clientId]);

            // After the write, never inside it. A dead mail server must not
            // undo a booking that is already correct.
            $mailed = null;
            if (fetchSession($db, $sessionId)['status'] === 'confirmed') {
                $mailed = sendSessionMail($db, $sessionId, 'confirmation');
            }

            echo json_encode(['success' => true, 'session_id' => $sessionId,
                              'booked' => $booked, 'mail_sent' => $mailed]);
            break;

        case 'set_holiday':
            // Close or reopen the days picked on the calendar. Sessions already
            // standing on a closed day are left exactly where they are and
            // reported back: cancelling somebody's appointment as a side effect
            // of a calendar click is not a thing the therapist can undo.
            $dates = postedDates();
            $on    = ($_POST['on'] ?? '1') !== '0';
            $why   = trim($_POST['reason'] ?? '');

            if (!$dates) {
                echo json_encode(['success' => false, 'error' => 'Pick at least one date first']);
                exit;
            }

            $booked  = $on ? sessionsOnDates($db, $dates) : [];
            $changed = $on
                ? markHolidays($db, $dates, $why, $_SESSION['user_id'] ?? null)
                : clearHolidays($db, $dates);

            if (!$changed) {
                echo json_encode(['success' => false, 'error' => 'Those are not valid dates']);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES (:a,:d,'holiday',NULL)")
               ->execute([
                   ':a' => $on ? 'holiday_marked' : 'holiday_cleared',
                   ':d' => count($changed) . ' day(s) ' . ($on ? 'marked a holiday' : 'reopened')
                         . ': ' . implode(', ', $changed) . ($why !== '' ? ' (' . $why . ')' : ''),
               ]);

            echo json_encode([
                'success'  => true,
                'dates'    => $changed,
                'on'       => $on,
                // The count, not the rows: the caller only needs to know that
                // something is standing there and how much of it.
                'sessions' => count($booked),
            ]);
            break;

        case 'update_status':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $status    = trim($_POST['status'] ?? '');
            $scope     = trim($_POST['scope'] ?? 'one');
            $reason    = trim($_POST['cancelled_reason'] ?? '');

            if (!$sessionId || !isValidSessionStatus($status)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            try {
                // Scope is taken from the request, never inferred. The UI is
                // required to ask whenever the session belongs to a series.
                $targets = applyToScope($db, $sessionId, $scope);
            } catch (InvalidArgumentException $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $label   = sessionLabel($db, $sessionId);
            $changed = 0;
            $skipped = [];

            foreach ($targets as $tid) {
                try {
                    if ($status === 'cancelled') {
                        cancelSession($db, $tid, $reason);
                    } else {
                        setSessionStatus($db, $tid, $status);
                    }
                    $changed++;
                } catch (Throwable $e) {
                    // A later occurrence already completed or cancelled is not
                    // an error for the batch; report it rather than abort.
                    $skipped[] = (int) $tid;
                }
            }

            if ($changed === 0) {
                echo json_encode(['success' => false, 'error' => 'Nothing could be changed']);
                exit;
            }

            // Mail goes out per session that actually moved, after the writes.
            if ($status === 'confirmed' || $status === 'cancelled') {
                $kind = ($status === 'confirmed') ? 'confirmation' : 'cancellation';
                foreach ($targets as $tid) {
                    if (in_array((int) $tid, $skipped, true)) {
                        continue;
                    }
                    sendSessionMail($db, $tid, $kind);
                }
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_status_changed',:d,'session',:rid)")
               ->execute([':d' => $label . ' marked ' . sessionStatusLabel($status)
                                . ($changed > 1 ? ' (' . $changed . ' occurrences)' : ''), ':rid' => $sessionId]);

            echo json_encode(['success' => true, 'changed' => $changed, 'skipped' => $skipped]);
            break;

        case 'reschedule_session':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $start     = postedStart();
            $scope     = trim($_POST['scope'] ?? 'one');

            if (!$sessionId || $start === null) {
                echo json_encode(['success' => false, 'error' => 'A date and a time are required']);
                exit;
            }

            $existing = fetchSession($db, $sessionId);
            if ($existing === null) {
                echo json_encode(['success' => false, 'error' => 'Session not found']);
                exit;
            }

            $duration = (int) round(
                (strtotime($existing['end_time']) - strtotime($existing['start_time'])) / 60
            );

            try {
                if ($scope === 'future' && !empty($existing['recurring_series_id'])) {
                    // Every later occurrence shifts by the same delta, so the
                    // rhythm of the series is preserved rather than collapsed
                    // onto one repeated date.
                    $delta   = strtotime($start) - strtotime($existing['start_time']);
                    $targets = applyToScope($db, $sessionId, 'future');
                    $moved   = 0;

                    foreach ($targets as $tid) {
                        $t = fetchSession($db, $tid);
                        if ($t === null || sessionStatusIsTerminal($t['status'])) {
                            continue;
                        }
                        $newStart = date('Y-m-d H:i:s', strtotime($t['start_time']) + $delta);
                        $tDur     = (int) round((strtotime($t['end_time']) - strtotime($t['start_time'])) / 60);
                        rescheduleSession($db, $tid, $newStart, $tDur);
                        $moved++;
                    }
                    echo json_encode(['success' => true, 'moved' => $moved]);
                    exit;
                }

                rescheduleSession($db, $sessionId, $start, $duration);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_rescheduled',:d,'session',:rid)")
               ->execute([':d' => 'Rescheduled to ' . date('d M Y, h:i A', strtotime($start)), ':rid' => $sessionId]);

            echo json_encode(['success' => true, 'moved' => 1]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[sessions-api] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
