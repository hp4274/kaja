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
require_once __DIR__ . '/../../includes/blocked-slots.php';

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
                            $ids[] = createSession($db, $clientId, date('Y-m-d H:i:s', $ts), $duration, $type, null, $_POST['video_link'] ?? null);
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
                        sendBookedSessionMail($db, $mid);
                    }

                    echo json_encode(['success' => true, 'session_id' => $ids[0],
                                      'booked' => count($ids), 'skipped' => $clashed]);
                    exit;
                }

                if ($repeat !== '' && $repeat !== 'none') {
                    $endType  = trim($_POST['repeat_end_type'] ?? 'count');
                    $endValue = trim($_POST['repeat_end_value'] ?? '4');

                    $ids = generateSeries($db, $clientId, $start, $duration, $type, [
                        'frequency' => $repeat, 'video_link' => $_POST['video_link'] ?? null,
                        'end'       => ['type' => $endType, 'value' => $endValue],
                    ]);

                    if (!$ids) {
                        echo json_encode(['success' => false, 'error' => 'Every occurrence clashed with an existing session']);
                        exit;
                    }
                    $sessionId = $ids[0];
                    $booked    = count($ids);
                    $allIds    = $ids;
                } else {
                    $sessionId = createSession($db, $clientId, $start, $duration, $type, null, $_POST['video_link'] ?? null);
                    $booked    = 1;
                    $allIds    = [$sessionId];
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
            // Confirmed sessions get the confirmation; pending ones get the
            // Accept/Decline links. A series mails every occurrence.
            $mailed = null;
            foreach ($allIds as $i => $mid) {
                $r = sendBookedSessionMail($db, $mid);
                if ($i === 0) { $mailed = $r; }
            }

            echo json_encode(['success' => true, 'session_id' => $sessionId,
                              'booked' => $booked, 'mail_sent' => $mailed]);
            break;

        case 'set_holiday':
            // Close or reopen the days picked on the calendar.
            //
            // Reopening never touches a session. Closing used to leave a
            // session sitting on a day nobody is coming in for, reported back
            // as an afterthought -- silently booking someone into a day the
            // practice will not be open is worse than asking first. The admin
            // now gets a real choice per batch: skip the days that still have
            // someone on them, or cancel those sessions (with the usual
            // cancellation mail) and move each one to the next available week at the same
            // time.
            $dates   = postedDates();
            $on      = ($_POST['on'] ?? '1') !== '0';
            $why     = trim($_POST['reason'] ?? '');
            $resolve = trim($_POST['resolve'] ?? '');

            if (!$dates) {
                echo json_encode(['success' => false, 'error' => 'Pick at least one date first']);
                exit;
            }

            if (!$on) {
                $changed = clearHolidays($db, $dates);
                if (!$changed) {
                    echo json_encode(['success' => false, 'error' => 'Those are not valid dates']);
                    exit;
                }
                $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('holiday_cleared',:d,'holiday',NULL)")
                   ->execute([':d' => count($changed) . ' day(s) reopened: ' . implode(', ', $changed)]);
                echo json_encode(['success' => true, 'dates' => $changed, 'on' => false, 'sessions' => 0]);
                exit;
            }

            $conflicts = sessionsOnDates($db, $dates);

            if ($conflicts && $resolve === '') {
                // Nothing written yet -- the caller shows this and asks.
                $byDate = [];
                foreach ($conflicts as $c) {
                    $d = date('Y-m-d', strtotime($c['start_time']));
                    $byDate[$d][] = [
                        'id'   => (int) $c['id'],
                        'name' => trim($c['client_name']) ?: 'Unknown',
                        'time' => date('h:i A', strtotime($c['start_time'])),
                    ];
                }
                echo json_encode(['success' => false, 'needs_resolution' => true, 'conflicts' => $byDate]);
                exit;
            }

            $datesToMark = $dates;
            $rescheduled = 0;
            $cancelled   = 0;

            if ($conflicts && $resolve === 'skip') {
                $conflictDates = array_values(array_unique(array_map(function ($c) {
                    return date('Y-m-d', strtotime($c['start_time']));
                }, $conflicts)));
                $datesToMark = array_values(array_diff($dates, $conflictDates));
                if (!$datesToMark) {
                    echo json_encode(['success' => false, 'error' => 'Every picked date has a session on it']);
                    exit;
                }
            } elseif ($conflicts && $resolve === 'reschedule') {
                foreach ($conflicts as $c) {
                    $sid = (int) $c['id'];
                    try {
                        cancelSession($db, $sid, 'Practice closed that day; moved to the next available week.');
                        $cancelled++;

                        $duration = (int) round((strtotime($c['end_time']) - strtotime($c['start_time'])) / 60);

                        // The following week at the same time first; if that
                        // is also a holiday or already taken, keep walking a
                        // week at a time rather than giving up on the first
                        // clash -- a run of closed or booked-out weeks should
                        // not turn into "not rescheduled" when a free one is
                        // sitting right after it.
                        $newId = null;
                        for ($weeksOut = 1; $weeksOut <= 8; $weeksOut++) {
                            $candidate = date('Y-m-d H:i:s', strtotime($c['start_time'] . ' +' . $weeksOut . ' weeks'));
                            try {
                                $newId = createSession($db, (int) $c['client_id'], $candidate, $duration, $c['session_type']);
                                break;
                            } catch (Throwable $e) {
                                continue;
                            }
                        }

                        if ($newId !== null) {
                            $rescheduled++;
                            // One mail saying the session moved (and why), not a
                            // cancellation followed by a separate confirmation.
                            notifySessionRescheduled($db, $newId, $c['start_time'],
                                'the practice is closed on ' . date('d M Y', strtotime($c['start_time'])) . ($why !== '' ? ' (' . $why . ')' : '') . '.');
                        } else {
                            sendSessionMail($db, $sid, 'cancellation');
                        }
                        // Eight weeks out with nothing free: the cancellation
                        // still stands, it is just not auto-rebooked. The
                        // therapist sees it was not rescheduled and can pick
                        // a time by hand.
                    } catch (Throwable $e) {
                        // Already cancelled or completed since the page loaded.
                    }
                }
            }
            // Any other $resolve value (or no conflicts at all) marks the
            // dates exactly as before.

            $changed = markHolidays($db, $datesToMark, $why, $_SESSION['user_id'] ?? null);
            if (!$changed) {
                echo json_encode(['success' => false, 'error' => 'Those are not valid dates']);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('holiday_marked',:d,'holiday',NULL)")
               ->execute([
                   ':d' => count($changed) . ' day(s) marked a holiday: ' . implode(', ', $changed)
                         . ($cancelled ? " ({$cancelled} session(s) cancelled, {$rescheduled} rescheduled to the next available week)" : '')
                         . ($why !== '' ? ' (' . $why . ')' : ''),
               ]);

            echo json_encode([
                'success'     => true,
                'dates'       => $changed,
                'on'          => true,
                'sessions'    => 0,
                'cancelled'   => $cancelled,
                'rescheduled' => $rescheduled,
            ]);
            break;

        case 'block_slot':
            $date = trim($_POST['slot_date'] ?? '');
            $time = trim($_POST['slot_time'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $ts = $date !== '' ? strtotime($date) : false;
            if ($ts === false || !isBookingSlot($time)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            $date = date('Y-m-d', $ts);

            // Blocking a slot that already holds a session cancels it first --
            // a blocked slot and a booked slot cannot both be true of the
            // same time.
            $occupied = sessionAtSlot($db, $date, $time);
            $cancelled = false;
            if ($occupied) {
                cancelSession($db, $occupied['id'], $reason);
                sendSessionMail($db, $occupied['id'], 'cancellation');
                $cancelled = true;
            }

            blockSlot($db, $date, $time, $reason, $_SESSION['user_id'] ?? null);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('slot_blocked',:d,'session',NULL)")
               ->execute([':d' => $date . ' ' . bookingSlotLabel($time) . ' blocked'
                                . ($cancelled ? ' (cancelled a booked session)' : '')
                                . ($reason !== '' ? ': ' . $reason : '')]);

            echo json_encode(['success' => true, 'cancelled' => $cancelled]);
            break;

        case 'unblock_slot':
            $date = trim($_POST['slot_date'] ?? '');
            $time = trim($_POST['slot_time'] ?? '');
            $ts = $date !== '' ? strtotime($date) : false;
            if ($ts === false || !isBookingSlot($time)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            $date = date('Y-m-d', $ts);

            unblockSlot($db, $date, $time);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('slot_unblocked',:d,'session',NULL)")
               ->execute([':d' => $date . ' ' . bookingSlotLabel($time) . ' reopened']);

            echo json_encode(['success' => true]);
            break;

        case 'cancel_sessions_on_dates':
            // The multi-select bar's other bulk action: cancel whatever is
            // booked on the picked dates without blocking the slot behind
            // it, so the time stays open for someone else.
            $dates = postedDates();
            if (!$dates) {
                echo json_encode(['success' => false, 'error' => 'Pick at least one date first']);
                exit;
            }

            $in   = implode(',', array_fill(0, count($dates), '?'));
            $stmt = $db->prepare("
                SELECT `id` FROM `sessions`
                WHERE DATE(`start_time`) IN ($in) AND `status` NOT IN ('cancelled','no-show')
            ");
            $stmt->execute($dates);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $changed = 0;
            foreach ($ids as $sid) {
                try {
                    cancelSession($db, $sid);
                    sendSessionMail($db, $sid, 'cancellation');
                    $changed++;
                } catch (Throwable $e) {
                    // Already moved on since the page loaded; not this
                    // request's problem.
                }
            }

            if ($changed) {
                $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_status_changed',:d,'session',NULL)")
                   ->execute([':d' => $changed . ' session(s) cancelled across ' . count($dates) . ' date(s)']);
            }

            echo json_encode(['success' => true, 'changed' => $changed]);
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
                $targets = ($scope !== 'one' && !empty($existing['recurring_series_id']))
                    ? applyToScope($db, $sessionId, $scope) : [$sessionId];
                if (count($targets) > 1) {
                    // Every later occurrence shifts by the same delta, so the
                    // rhythm of the series is preserved rather than collapsed
                    // onto one repeated date.
                    $delta   = strtotime($start) - strtotime($existing['start_time']);
                    $moved   = 0;

                    foreach ($targets as $tid) {
                        $t = fetchSession($db, $tid);
                        if ($t === null || sessionStatusIsTerminal($t['status'])) {
                            continue;
                        }
                        $newStart = date('Y-m-d H:i:s', strtotime($t['start_time']) + $delta);
                        $tDur     = (int) round((strtotime($t['end_time']) - strtotime($t['start_time'])) / 60);
                        rescheduleSession($db, $tid, $newStart, $tDur);
                        try { notifySessionRescheduled($db, $tid, $t['start_time']); } catch (Throwable $mailErr) { error_log('[sessions-api] reschedule mail: ' . $mailErr->getMessage()); }
                        $moved++;
                    }
                    echo json_encode(['success' => true, 'moved' => $moved]);
                    exit;
                }

                rescheduleSession($db, $sessionId, $start, $duration);
                try { notifySessionRescheduled($db, $sessionId, $existing['start_time']); } catch (Throwable $mailErr) { error_log('[sessions-api] reschedule mail: ' . $mailErr->getMessage()); }
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
