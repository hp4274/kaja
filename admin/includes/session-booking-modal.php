<?php
/**
 * The booking modal, shared by the dashboard and the sessions calendar.
 *
 * It lived only in the sessions page before, tucked into the "Upcoming
 * Sessions" panel header — so the dashboard showed a calendar you could click
 * but never book from, and clicking a day on either calendar opened a
 * read-only list. Both surfaces now open the same modal, from the same code,
 * with the clicked day already filled in.
 *
 * Requires $db in the including scope. The including page must also emit
 * window.CAL_MONTH (a "YYYY-MM" string) so a clicked day number can be turned
 * into a real date.
 */

require_once __DIR__ . '/../../includes/booking-slots.php';

// Only bookable clients belong in the picker -- see clientIsBookable(): intake
// in, a human has read it, treatment live.
$bookableClients = $db->query("
    SELECT `id`, `first_name`, `last_name` FROM `clients`
    WHERE `status` = 'active' ORDER BY `first_name` ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Everyone held short of bookable, so the picker can say why it is short or
// empty instead of just looking broken.
$heldClients    = $db->query("
    SELECT `status`, COUNT(*) AS n FROM `clients`
    WHERE `status` IN ('pending','review') GROUP BY `status`
")->fetchAll(PDO::FETCH_KEY_PAIR);
$awaitingReview = (int) ($heldClients['review'] ?? 0);
$awaitingIntake = (int) ($heldClients['pending'] ?? 0);

// Per client: last session (any status) and the preferred date/time from their
// intake (falling back to the lead). Drives the hint under the client picker.
$clientBookingInfo = [];
try {
    foreach ($db->query("SELECT `client_id`, MAX(`start_time`) AS last_at FROM `sessions` GROUP BY `client_id`") as $r) {
        $clientBookingInfo[(int) $r['client_id']]['last'] = $r['last_at'];
    }
    foreach ($db->query("
        SELECT l.`client_id`, COALESCE(NULLIF(pi.`pref_date`, ''), l.`preferred_date`) AS pd,
               COALESCE(NULLIF(pi.`pref_time`, ''), l.`preferred_time`) AS pt
        FROM `leads` l
        LEFT JOIN `patient-intake` pi ON pi.`email` = l.`email` AND pi.`phone` = l.`phone`
        WHERE l.`client_id` IS NOT NULL ORDER BY l.`id` ASC
    ") as $r) {
        $clientBookingInfo[(int) $r['client_id']]['pd'] = $r['pd'];
        $clientBookingInfo[(int) $r['client_id']]['pt'] = $r['pt'];
    }
} catch (Exception $e) { /* hint is optional */ }
?>
<!-- Day management: what is booked, what is blocked, on the day clicked.
     Shared by both calendars -- see showDaySessions() below. -->
<div class="modal-overlay" id="daySessionsModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" id="daySessionsTitle">Manage day</div>
      <button class="modal-close" onclick="closeDayModal()">&times;</button>
    </div>
    <div class="modal-body" id="daySessionsContent"></div>
  </div>
</div>

<!-- Same per-slot list, applied to every date in a multi-day selection at
     once instead of one day. Opened from the selection bar's "Manage
     availability" button -- see openBulkAvailability() below. -->
<div class="modal-overlay" id="bulkAvailabilityModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Manage availability</div>
      <button class="modal-close" onclick="closeBulkModal()">&times;</button>
    </div>
    <div class="modal-body">
      <p class="prose" id="bulkAvailabilitySub"></p>
      <div id="bulkAvailabilityContent"></div>
    </div>
  </div>
</div>

<!-- A holiday that would land on a day someone already has a session --
     asked, never assumed. See setHoliday() / showHolidayConflictPopup(). -->
<div class="modal-overlay" id="holidayConflictModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Sessions on these days</div>
      <button class="modal-close" onclick="document.getElementById('holidayConflictModal').classList.remove('open')">&times;</button>
    </div>
    <div class="modal-body" id="holidayConflictContent"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('holidayConflictModal').classList.remove('open'); calendarSelection.setHoliday(true, 'skip');">Skip those days</button>
      <button type="button" class="btn btn-danger" onclick="document.getElementById('holidayConflictModal').classList.remove('open'); calendarSelection.setHoliday(true, 'reschedule');">Cancel &amp; reschedule next week</button>
    </div>
  </div>
</div>

<script>window.CLIENT_BOOKING_INFO = <?php echo json_encode((object) $clientBookingInfo); ?>;</script>
<script>window.ALL_SLOTS = <?php echo json_encode(array_map(function ($s) {
    return ['time' => $s, 'label' => bookingSlotLabel($s)];
}, bookingSlots())); ?>;</script>

<div class="modal-overlay" id="newSessionModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Schedule New Session</div>
      <button class="modal-close" type="button" onclick="closeNewSessionModal()" aria-label="Close">&times;</button>
    </div>
    <form onsubmit="createSession(event)">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="new-session-client">Client</label>
          <select class="form-select" id="new-session-client" name="client_id" required <?php echo $bookableClients ? '' : 'disabled'; ?>>
            <option value="">Select a client...</option>
            <?php foreach ($bookableClients as $cl): ?>
              <option value="<?php echo (int) $cl['id']; ?>"><?php echo htmlspecialchars($cl['first_name'] . ' ' . $cl['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <small class="hint is-block" id="new-session-client-hint" hidden></small>
          <?php if ($awaitingReview || $awaitingIntake): ?>
            <?php
            // A client missing from this list reads as a broken page unless the
            // page says where they are instead. Both holds clear in one click,
            // so the note links to where that happens.
            $held = [];
            if ($awaitingReview) { $held[] = $awaitingReview . ' awaiting review'; }
            if ($awaitingIntake) { $held[] = $awaitingIntake . ' still to send intake back'; }
            ?>
            <small class="hint is-block">
              Only active clients can be booked. <?php echo htmlspecialchars(implode(', ', $held)); ?> &mdash;
              <a href="index.php?page=clients">open Clients</a> to move them on.
            </small>
          <?php elseif (!$bookableClients): ?>
            <small class="hint is-block">
              No active clients yet. A client becomes bookable once their intake is in and you have marked it reviewed.
            </small>
          <?php endif; ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="new-session-date">Date</label>
            <input type="date" class="form-input" id="new-session-date" name="session_date" min="<?php echo date('Y-m-d'); ?>" required />
            <!-- Every date picked on the calendar, first one included. Empty
                 unless the therapist ctrl- or shift-clicked more than one. -->
            <input type="hidden" id="new-session-dates" name="session_dates" value="" />
            <small id="new-session-dates-note" class="hint is-block" hidden></small>
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-time">Time</label>
            <!-- The same slots the public forms offer. A free time input here
                 let the calendar hold appointments at times no visitor was ever
                 shown, which is how the two halves of the practice drifted. -->
            <select class="form-select" id="new-session-time" name="session_time" required>
              <option value="" disabled selected hidden>Select a time</option>
              <?php foreach (bookingSlots() as $slot): ?>
                <option value="<?php echo htmlspecialchars($slot); ?>" data-label="<?php echo htmlspecialchars(bookingSlotLabel($slot)); ?>"><?php echo htmlspecialchars(bookingSlotLabel($slot)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="new-session-duration">Duration (min)</label>
            <input type="number" class="form-input" id="new-session-duration" name="duration" value="<?php echo (int) getSettingInt('default_session_duration', 60); ?>" min="15" step="15" />
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-type">Type</label>
            <select class="form-select" id="new-session-type" name="session_type"
                    onchange="document.getElementById('new-session-link-group').hidden = this.value !== 'online'">
              <option value="online">Online</option>
              <option value="inperson">In-Person</option>
            </select>
          </div>
          <div class="form-group" id="new-session-link-group">
            <label class="form-label" for="new-session-video-link">Meeting link</label>
            <input type="url" class="form-input" id="new-session-video-link" name="video_link"
                   value="<?php echo htmlspecialchars(getSetting('practice_video_link', '')); ?>"
                   placeholder="https://meet.google.com/..." />
            <div class="hint">Sent to the client in the booking, reminder and confirmation mails.</div>
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-repeat">Repeat</label>
            <select class="form-select" id="new-session-repeat" name="repeat">
              <option value="none">Does not repeat</option>
              <option value="weekly">Weekly</option>
              <option value="fortnightly">Fortnightly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
        </div>

        <div class="form-group" id="new-session-repeat-end" hidden>
          <label class="form-label" for="new-session-repeat-end-type">Until</label>
          <div class="form-inline">
            <select class="form-select form-grow" id="new-session-repeat-end-type" name="repeat_end_type">
              <option value="count">After N sessions</option>
              <option value="date">On a date</option>
              <option value="open">Keep going (I will stop it)</option>
            </select>
            <input class="form-input form-grow" name="repeat_end_value" value="4" aria-label="Repeat until value" />
          </div>
          <small class="hint">Occurrences that clash with an existing session are skipped, not booked.</small>
        </div>

        <div class="form-group">
          <label class="form-label" for="new-session-notes">Notes</label>
          <textarea class="form-textarea" id="new-session-notes" name="notes"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeNewSessionModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" <?php echo $bookableClients ? '' : 'disabled'; ?>>Schedule</button>
      </div>
    </form>
  </div>
</div>

<script>
/**
 * Booking, shared by every surface that includes this file.
 *
 * openNewSessionModal() takes an optional "YYYY-MM-DD". Passing the day the
 * therapist actually clicked is the whole point: retyping a date you just
 * pointed at is how the wrong day gets booked.
 */
function openNewSessionModal(dateStr, clientId) {
  var modal = document.getElementById('newSessionModal');
  if (!modal) return;

  var dates = calendarSelection.dates();
  if (dates.length > 1) {
    // The calendar selection wins over the single day passed in: it is the
    // more specific thing the therapist just pointed at.
    dateStr = dates[0];
  }

  var dateField = document.getElementById('new-session-date');
  if (dateField && dateStr) dateField.value = dateStr;

  var clientSel = document.getElementById('new-session-client');
  if (clientSel && clientId) clientSel.value = String(clientId);
  showClientBookingHint(!dateStr && dates.length < 2);

  applyDateSelection(dates);
  syncBlockedTimeOptions();
  modal.classList.add('open');

  var client = document.getElementById('new-session-client');
  if (client && !client.disabled) client.focus();
}

/**
 * First booking: show (and, when no day was pointed at, prefill) the client's
 * preferred date/time. Once they have any session: show the last session date.
 */
function showClientBookingHint(prefill) {
  var sel  = document.getElementById('new-session-client');
  var hint = document.getElementById('new-session-client-hint');
  if (!hint) return;
  var info = (sel && window.CLIENT_BOOKING_INFO || {})[sel.value];
  hint.hidden = true;
  if (!info) return;

  var fmt = function (d) { return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }); };
  if (info.last) {
    hint.textContent = 'Last session: ' + fmt(new Date(info.last.replace(' ', 'T')));
    hint.hidden = false;
    return;
  }
  if (!info.pd && !info.pt) return;

  var text = [];
  var dateOk = /^\d{4}-\d{2}-\d{2}$/.test(info.pd || '');
  if (dateOk) text.push(fmt(new Date(info.pd + 'T00:00')));
  if (info.pt) text.push(info.pt);
  hint.textContent = 'Preferred date & time: ' + text.join(' at ');
  hint.hidden = false;

  if (!prefill) return;
  var dateField = document.getElementById('new-session-date');
  if (dateOk && dateField && info.pd >= dateField.min) dateField.value = info.pd;
  var timeField = document.getElementById('new-session-time');
  if (info.pt && timeField) {
    Array.prototype.forEach.call(timeField.options, function (o) {
      if (o.value && (o.value.substring(0, 5) === String(info.pt).substring(0, 5) || o.dataset.label === info.pt)) timeField.value = o.value;
    });
  }
}

function closeNewSessionModal() {
  var modal = document.getElementById('newSessionModal');
  if (modal) modal.classList.remove('open');
}

/**
 * A time already closed for the picked date is not an option -- it would
 * only come back from the server as a refusal after the form was filled in.
 * Disabled in place rather than removed, so the list of times on offer does
 * not visibly shrink and grow as the date changes.
 */
function syncBlockedTimeOptions() {
  var dateField = document.getElementById('new-session-date');
  var timeField = document.getElementById('new-session-time');
  if (!dateField || !timeField) return;

  var blocked = (window.calBlockedData || {})[dateField.value] || [];
  Array.prototype.forEach.call(timeField.options, function (opt) {
    if (!opt.value) return;   // the "Select a time" placeholder
    var isBlocked = blocked.indexOf(opt.value) !== -1;
    opt.disabled = isBlocked;
    opt.textContent = opt.dataset.label + (isBlocked ? ' (blocked)' : '');
  });

  if (timeField.value && blocked.indexOf(timeField.value) !== -1) {
    timeField.value = '';
  }
}

/**
 * Show the picked dates in the modal and post them with the booking.
 *
 * One date behaves exactly as before. Several turn Repeat off: a recurrence
 * per date is two different ways of saying "more sessions" at once, and the
 * result is never what either input looked like it meant.
 */
function applyDateSelection(dates) {
  var hidden = document.getElementById('new-session-dates');
  var note   = document.getElementById('new-session-dates-note');
  var repeat = document.getElementById('new-session-repeat');
  var multi  = dates.length > 1;

  if (hidden) hidden.value = multi ? dates.join(',') : '';

  if (note) {
    note.hidden = !multi;
    if (multi) {
      note.textContent = dates.length + ' dates selected — ' + calendarDateSummary(dates)
                       + ' — one session at the chosen time on each.';
    }
  }

  if (repeat) {
    if (multi) { repeat.value = 'none'; }
    repeat.disabled = multi;
    var end = document.getElementById('new-session-repeat-end');
    if (end && multi) end.hidden = true;
  }
}

/**
 * A list of dates as "Aug (2, 9, 23)".
 *
 * Repeating the month once per day ("2 Aug, 9 Aug, 23 Aug") is three words of
 * noise for one fact; the days are what differ, so they are what the eye gets.
 * A selection spanning months keeps each month's own bracket: "Aug (28, 30),
 * Sep (4)".
 */
function calendarDateSummary(dates) {
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var order  = [];
  var byMonth = {};

  dates.forEach(function (iso) {
    var parts = String(iso).split('-');
    if (parts.length !== 3) return;
    var key = months[parseInt(parts[1], 10) - 1] || parts[1];
    if (!byMonth[key]) { byMonth[key] = []; order.push(key); }
    byMonth[key].push(parseInt(parts[2], 10));
  });

  return order.map(function (m) {
    return m + ' (' + byMonth[m].join(', ') + ')';
  }).join(', ');
}

/** Turn a day number from the rendered calendar into a real date. */
function calendarDate(day) {
  var month = window.CAL_MONTH || '';
  if (!month) return '';
  return month + '-' + (day < 10 ? '0' + day : String(day));
}

/**
 * Manage one day: its sessions, and every bookable slot on it.
 *
 * Both calendars call this with the same globals -- calSessionsData,
 * calBlockedData, calMonthName -- which each page keeps in step with the
 * month on screen. It is the "one more option" on a day click: view and
 * change what is booked, and close or reopen whatever slot is left.
 */
// True once a block/unblock happened while a manage-availability popup was
// open. Each toggle re-renders the popup from updated in-memory data instead
// of reloading, so several slots can be closed in one sitting; the page only
// catches up with the server once the popup that changed something closes.
var availabilityDirty = false;

function closeDayModal() {
  document.getElementById('daySessionsModal').classList.remove('open');
  if (availabilityDirty) { availabilityDirty = false; location.reload(); }
}

function closeBulkModal() {
  document.getElementById('bulkAvailabilityModal').classList.remove('open');
  if (availabilityDirty) { availabilityDirty = false; location.reload(); }
}

function markSlotBlockedLocal(date, time, blocked) {
  window.calBlockedData = window.calBlockedData || {};
  var arr = window.calBlockedData[date] = window.calBlockedData[date] || [];
  var at  = arr.indexOf(time);
  if (blocked && at === -1) { arr.push(time); }
  if (!blocked && at !== -1) { arr.splice(at, 1); }
}

function markSessionCancelledLocal(date, time) {
  var day = parseInt(date.split('-')[2], 10);
  ((window.calSessionsData || {})[day] || []).forEach(function (s) {
    if (s.start_time.split(' ')[1].substring(0, 5) === time) { s.status = 'cancelled'; }
  });
}

function showDaySessions(day) {
  var sessions = (window.calSessionsData || {})[day] || [];
  var date     = calendarDate(day);
  var isPast   = date < new Date().toISOString().slice(0, 10);
  document.getElementById('daySessionsTitle').textContent = 'Manage — ' + (window.calMonthName || '') + ' ' + day;
  var html = '';

  if (!sessions.length) {
    html += '<div class="empty-state"><i class="bi bi-calendar3"></i><p>No sessions this day</p></div>';
  }

  sessions.forEach(function(s) {
    html += '<div class="session-row is-plain">';
    html += '<div class="session-row-main">';
    html += '<div class="session-row-icon ' + (s.session_type === 'online' ? 'teal' : 'amber') + '"><i class="bi ' + (s.session_type === 'online' ? 'bi-camera-video' : 'bi-geo-alt') + '"></i></div>';
    html += '<div class="session-row-body">';
    html += '<div class="session-row-name">' + escapeHtml(s.client_name || 'Unknown') + '</div>';
    var startsAt = s.start_time.split(' ')[1].substring(0, 5);
    var mins = Math.round((Date.parse(s.end_time.replace(' ', 'T')) - Date.parse(s.start_time.replace(' ', 'T'))) / 60000);
    html += '<div class="session-row-meta">' + startsAt + ' · ' + mins + ' min · <span class="badge badge-session-' + s.status + '">' + s.status + '</span></div>';
    html += '</div>';
    html += '</div>';
    html += '<div class="session-row-controls">';
    html += '<select class="status-select" aria-label="Session status" onchange="updateSessionStatus(' + s.id + ', this.value)">';
    html += '<option value="pending"' + (s.status === 'pending' ? ' selected' : '') + '>Pending</option>';
    html += '<option value="confirmed"' + (s.status === 'confirmed' ? ' selected' : '') + '>Confirmed</option>';
    html += '<option value="completed"' + (s.status === 'completed' ? ' selected' : '') + '>Completed</option>';
    html += '<option value="cancelled"' + (s.status === 'cancelled' ? ' selected' : '') + '>Cancelled</option>';
    html += '</select>';
    html += '<button class="btn btn-icon" onclick="openRescheduleModal(' + s.id + ', \'' + s.start_time.split(' ')[0] + '\', \'' + startsAt + '\')" title="Reschedule"><i class="bi bi-pencil-square"></i></button>';
    html += '</div>';
    html += '</div>';
  });

  if (!isPast) {
    // A day that already holds sessions is the most likely day to want
    // another one on, so the booking action lives right here rather than
    // only behind the panel header button. Neither this nor the slot list
    // below means anything for a day that has already happened.
    html += '<div class="modal-day-book">';
    html += '<button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById(\'daySessionsModal\').classList.remove(\'open\'); openNewSessionModal(calendarDate(' + day + '));">';
    html += '<i class="bi bi-plus"></i> Book on this day</button>';
    html += '</div>';

    html += renderAvailability(day, sessions);
  }

  document.getElementById('daySessionsContent').innerHTML = html;
  document.getElementById('daySessionsModal').classList.add('open');
}

/**
 * One row per bookable time of day. A free or blocked slot is a plain
 * on/off toggle -- removing the block IS reopening it, so there is nothing
 * for a separate "Reopen" button to do. A slot with a session on it keeps an
 * explicit button instead, because cancelling someone's appointment is not a
 * flip you want to trigger by the same reflex as closing an empty hour.
 */
function renderAvailability(day, sessions) {
  var date = calendarDate(day);
  var blocked = (window.calBlockedData || {})[date] || [];
  var html = '<div class="section-title">Manage availability</div>';
  html += '<div class="availability-list">';

  (window.ALL_SLOTS || []).forEach(function(slot) {
    var occupied = sessions.filter(function(s) {
      return s.status !== 'cancelled' && s.status !== 'no-show'
        && s.start_time.split(' ')[1].substring(0, 5) === slot.time;
    })[0];
    var isBlocked = blocked.indexOf(slot.time) !== -1;

    html += '<div class="availability-row">';
    html += '<span class="availability-time">' + slot.label + '</span>';
    if (occupied) {
      html += '<span class="badge badge-scheduled">Booked — ' + escapeHtml(occupied.client_name || 'Unknown') + '</span>';
      html += '<button type="button" class="btn btn-danger btn-sm" onclick="blockSlot(\'' + date + '\', \'' + slot.time + '\', true, ' + day + ')">Cancel &amp; block</button>';
    } else {
      html += '<span class="badge ' + (isBlocked ? 'badge-inactive' : 'badge-active') + '">' + (isBlocked ? 'Blocked' : 'Available') + '</span>';
      html += '<label class="switch" title="' + (isBlocked ? 'Reopen this time' : 'Block this time') + '">'
            + '<input type="checkbox" ' + (isBlocked ? 'checked' : '') + ' onchange="toggleSlot(this, \'' + date + '\', \'' + slot.time + '\', ' + day + ')" />'
            + '<span class="switch-track"></span></label>';
    }
    html += '</div>';
  });

  html += '</div>';
  return html;
}

/** The plain on/off case: no session in the way, just flip the slot. */
async function toggleSlot(checkbox, date, time, day) {
  var blocking = checkbox.checked;
  checkbox.disabled = true;

  var fd = new FormData();
  fd.append('action', blocking ? 'block_slot' : 'unblock_slot');
  fd.append('slot_date', date);
  fd.append('slot_time', time);

  try {
    var r = await fetch('api/sessions.php', { method: 'POST', body: fd });
    var d = await r.json();
    if (!d.success) { showToast(d.error || 'Error', 'error'); checkbox.checked = !blocking; checkbox.disabled = false; return; }
    markSlotBlockedLocal(date, time, blocking);
    availabilityDirty = true;
    showToast(blocking ? 'Slot blocked' : 'Slot reopened');
    showDaySessions(day);   // re-render from the updated data -- no reload
  } catch (e) {
    showToast('Network error', 'error');
    checkbox.checked = !blocking;
    checkbox.disabled = false;
  }
}

/** The occupied case: an explicit click, because it cancels a session too. */
async function blockSlot(date, time, hasSession, day) {
  if (hasSession) {
    if (!await showConfirm('This cancels the session booked at this time. Block it anyway?', { danger: true, okText: 'Cancel & block' })) return;
  }

  var fd = new FormData();
  fd.append('action', 'block_slot');
  fd.append('slot_date', date);
  fd.append('slot_time', time);

  fetch('api/sessions.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      markSlotBlockedLocal(date, time, true);
      if (d.cancelled) { markSessionCancelledLocal(date, time); }
      availabilityDirty = true;
      showToast(d.cancelled ? 'Session cancelled and slot blocked' : 'Slot blocked');
      showDaySessions(day);
    })
    .catch(function() { showToast('Network error', 'error'); });
}

/**
 * The same per-slot list as a single day, run over every date in a
 * multi-select at once. A slot booked on ANY of the picked dates counts as
 * booked; a slot free everywhere is available; blocked only when every picked
 * date already has it blocked -- a slot half-open across the selection reads
 * as open, since that is the state a click on it would actually change.
 */
function openBulkAvailability() {
  var dates = calendarSelection.dates();
  if (!dates.length) { document.getElementById('bulkAvailabilityModal').classList.remove('open'); return; }

  document.getElementById('bulkAvailabilitySub').textContent =
    dates.length + (dates.length === 1 ? ' date selected' : ' dates selected') + ': ' + calendarDateSummary(dates);

  var html = '<div class="availability-list">';
  (window.ALL_SLOTS || []).forEach(function (slot) {
    var bookedOn = dates.filter(function (date) {
      var day = parseInt(date.split('-')[2], 10);
      return (window.calSessionsData[day] || []).some(function (s) {
        return s.status !== 'cancelled' && s.status !== 'no-show'
          && s.start_time.split(' ')[1].substring(0, 5) === slot.time;
      });
    });
    var blockedOn = dates.filter(function (date) {
      return ((window.calBlockedData || {})[date] || []).indexOf(slot.time) !== -1;
    });
    var allBlocked = blockedOn.length === dates.length;

    html += '<div class="availability-row">';
    html += '<span class="availability-time">' + slot.label + '</span>';
    if (bookedOn.length) {
      html += '<span class="badge badge-scheduled">Booked on ' + bookedOn.length + '/' + dates.length + '</span>';
      html += '<button type="button" class="btn btn-danger btn-sm" onclick="calendarSelection.blockSlot(\'' + slot.time + '\').then(function(ok){ if (ok) openBulkAvailability(); })">Cancel &amp; block</button>';
    } else {
      html += '<span class="badge ' + (allBlocked ? 'badge-inactive' : 'badge-active') + '">' + (allBlocked ? 'Blocked' : 'Available') + '</span>';
      html += '<label class="switch" title="' + (allBlocked ? 'Reopen this time' : 'Block this time') + '">'
            + '<input type="checkbox" ' + (allBlocked ? 'checked' : '') + ' onchange="toggleBulkSlot(this, \'' + slot.time + '\')" />'
            + '<span class="switch-track"></span></label>';
    }
    html += '</div>';
  });
  html += '</div>';

  document.getElementById('bulkAvailabilityContent').innerHTML = html;
  document.getElementById('bulkAvailabilityModal').classList.add('open');
}

/** Same on/off toggle as a single day, run across every picked date. */
async function toggleBulkSlot(checkbox, time) {
  checkbox.disabled = true;
  var ok = checkbox.checked
    ? await calendarSelection.blockSlot(time)
    : await calendarSelection.unblockSlot(time);
  if (!ok) { checkbox.checked = !checkbox.checked; }
  openBulkAvailability();   // re-render from the updated data -- no reload
}

function createSession(e) {
  e.preventDefault();

  var form = e.target;
  var btn  = form.querySelector('button[type="submit"]');
  var label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Scheduling...'; }

  var fd = new FormData(form);
  fd.append('action', 'add_session');

  fetch('api/sessions.php', { method: 'POST', body: fd })
    .then(function (r) { return r.text(); })
    .then(function (text) {
      var data;
      try {
        data = JSON.parse(text);
      } catch (err) {
        // A PHP warning printed ahead of the JSON turns a booking that actually
        // worked into a silent failure. Show what came back instead of "Error".
        throw new Error('Unexpected server response: ' + text.slice(0, 160));
      }
      if (!data.success) {
        throw new Error(data.error || 'The session could not be booked.');
      }

      var msg = data.booked > 1 ? (data.booked + ' sessions scheduled') : 'Session scheduled';
      // A day that clashed was skipped, not booked. Saying so here is the only
      // place the therapist finds out before the calendar reloads without it.
      if (data.skipped && data.skipped.length) {
        msg += ' — skipped ' + calendarDateSummary(data.skipped) + ' (already booked)';
      }
      showToast(msg);
      calendarSelection.clear();
      // Both calendars are rendered server-side, so a reload is what puts the
      // new session on the dashboard and the sessions page at once.
      setTimeout(function () { location.reload(); }, 600);
    })
    .catch(function (err) {
      showToast(err.message || 'Network error', 'error');
      if (btn) { btn.disabled = false; btn.textContent = label; }
    });
}

(function () {
  var repeat = document.getElementById('new-session-repeat');
  var end    = document.getElementById('new-session-repeat-end');
  if (repeat && end) {
    repeat.addEventListener('change', function () {
      end.hidden = (repeat.value === 'none');
    });
  }

  var dateField = document.getElementById('new-session-date');
  if (dateField) { dateField.addEventListener('change', syncBlockedTimeOptions); }
  var clientField = document.getElementById('new-session-client');
  if (clientField) { clientField.addEventListener('change', function () { showClientBookingHint(false); }); }

  var modal = document.getElementById('newSessionModal');
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeNewSessionModal();
    });
  }

  // Backdrop click and Escape both count as "close" for the two
  // availability popups, and both have to run through the same dirty-check
  // as the X button or a change made just before closing would be lost from
  // the rest of the page until the next unrelated reload.
  var dayModal  = document.getElementById('daySessionsModal');
  var bulkModal = document.getElementById('bulkAvailabilityModal');
  if (dayModal)  { dayModal.addEventListener('click', function (e) { if (e.target === dayModal) closeDayModal(); }); }
  if (bulkModal) { bulkModal.addEventListener('click', function (e) { if (e.target === bulkModal) closeBulkModal(); }); }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (dayModal && dayModal.classList.contains('open')) { closeDayModal(); }
    if (bulkModal && bulkModal.classList.contains('open')) { closeBulkModal(); }
  });
})();
</script>

<style>
/* A day held in a multi-date selection. Distinct from the dashboard's single
   ".selected" outline, because the two mean different things: one is "the day
   you are looking at", this one is "a day you are about to book". */
.cal-cell.multi-selected {
  background: var(--clr-primary-light);
  box-shadow: inset 0 0 0 2px var(--clr-primary);
}
.cal-cell.multi-selected .cal-date { font-weight: 700; }

/* The day cells are focusable buttons, and nothing styled :focus -- so the
   browser's own ring stayed on the last day clicked and read as a seventh
   selected date sitting next to the six real ones. A ring that says "selected"
   must only ever appear on a selected day, so the focus ring is now dashed,
   inset, and shown only to keyboard users, who are the ones it is for. */
.cal-cell:focus:not(:focus-visible) { outline: none; box-shadow: none; }
.cal-cell:focus-visible {
  outline: 2px dashed var(--clr-text-muted);
  outline-offset: -4px;
}
/* Belt and braces with the blur() above: whatever a browser draws on a
   mouse-focused day, it is not allowed to look like the selection ring. */
.cal-cell:not(.multi-selected):focus:not(:focus-visible) {
  background: inherit;
}
.calendar-selection-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 0.75rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-md, 8px);
  background: var(--clr-primary-light);
  font-size: 0.82rem;
}
.calendar-selection-bar[hidden] { display: none; }
/* The flex lives here, not in a style attribute: an inline display outranks
   the [hidden] attribute, so the row stayed on screen with an unlabelled
   button in it whenever the selection was empty. */
.calendar-selection-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.calendar-selection-actions[hidden] { display: none; }
/* Nothing picked yet: the bar is only telling you the gesture exists, so it
   steps back out of the way instead of sitting there looking like a state. */
.calendar-selection-bar.is-hint {
  background: transparent;
  border-color: transparent;
  padding: 0.25rem 0;
  color: var(--clr-text-muted);
  font-size: 0.78rem;
}

/* A day the practice is closed. Struck through rather than merely tinted: the
   grid is read at a glance and a colour alone is a thing you have to learn. */
.cal-cell.holiday {
  /* The hatch is drawn in a border token, not a fixed grey, so it stays a
     faint texture in both themes instead of a white stripe on a black page. */
  background: repeating-linear-gradient(
    45deg,
    var(--clr-border-light),
    var(--clr-border-light) 6px,
    transparent 6px,
    transparent 12px
  );
  color: var(--clr-text-muted);
}
.cal-cell.holiday .cal-date { text-decoration: line-through; }
</style>

<script>
/**
 * Ctrl/Cmd- and Shift-click multi-selection on the month calendars.
 *
 * The cells already had a plain click behaviour (open the day, or book it), so
 * a modifier-click has to be caught before that runs. This listens on the grid
 * in the CAPTURE phase and stops the event there: the cell's own inline
 * onclick never fires, and a modified click means "select", never "open".
 *
 * Ctrl/Cmd toggles one day. Shift extends from the last day touched to the one
 * clicked. A plain click clears the selection, which is the only way out that
 * does not need a second thing to learn.
 */
var calendarSelection = (function () {
  var picked = [];    // "YYYY-MM-DD", in calendar order
  var anchor = null;  // where a Shift range measures from

  // Bulk booking and bulk blocking are both about what happens next -- a
  // past date sitting in the selection would just make every "N dates"
  // action fail or lie about what it did, so multi-select only ever
  // considers today and later, session or no session.
  function cells() {
    var today = new Date().toISOString().slice(0, 10);
    return Array.prototype.slice.call(
      document.querySelectorAll('.calendar-grid .cal-cell[data-date]')
    ).filter(function (c) { return c.dataset.date >= today; });
  }

  function render() {
    cells().forEach(function (cell) {
      cell.classList.toggle('multi-selected', picked.indexOf(cell.dataset.date) !== -1);
      // Screen readers get the same fact the outline gives everyone else.
      if (picked.indexOf(cell.dataset.date) !== -1) {
        cell.setAttribute('aria-selected', 'true');
      } else {
        cell.removeAttribute('aria-selected');
      }
    });
    renderBars();
  }

  /**
   * One bar per calendar on the page, created the first time it is needed.
   *
   * It appears for a single picked day as well as for a run, because marking
   * one day a holiday is at least as common as marking a week of them.
   */
  function renderBars() {
    document.querySelectorAll('.calendar-grid').forEach(function (grid) {
      var bar = grid.parentNode.querySelector('.calendar-selection-bar');
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'calendar-selection-bar';
        bar.hidden = true;
        bar.innerHTML = '<span class="calendar-selection-count"></span>'
          + '<span class="calendar-selection-actions">'
          + '<button type="button" class="btn btn-ghost btn-sm" data-cal-clear>Clear</button>'
          + '<button type="button" class="btn btn-ghost btn-sm" data-cal-holiday></button>'
          + '<button type="button" class="btn btn-ghost btn-sm" data-cal-manage-availability><i class="bi bi-sliders"></i> Manage availability</button>'
          + '<button type="button" class="btn btn-danger btn-sm" data-cal-cancel-sessions><i class="bi bi-calendar-x"></i> Cancel sessions</button>'
          + '<button type="button" class="btn btn-primary btn-sm" data-cal-book>Book these dates</button>'
          + '</span>';
        bar.querySelector('[data-cal-clear]').addEventListener('click', function () { clear(); });
        bar.querySelector('[data-cal-book]').addEventListener('click', function () {
          if (typeof openNewSessionModal === 'function') openNewSessionModal(picked[0]);
        });
        bar.querySelector('[data-cal-holiday]').addEventListener('click', function () {
          setHoliday(!allPickedAreHolidays());
        });
        bar.querySelector('[data-cal-manage-availability]').addEventListener('click', openBulkAvailability);
        bar.querySelector('[data-cal-cancel-sessions]').addEventListener('click', cancelPickedSessions);
        grid.parentNode.insertBefore(bar, grid.nextSibling);
      }

      var closing = !allPickedAreHolidays();
      var actions = bar.querySelector('.calendar-selection-actions');

      bar.hidden   = false;
      bar.classList.toggle('is-hint', picked.length < 1);
      actions.hidden = picked.length < 1;

      bar.querySelector('.calendar-selection-count').textContent = picked.length
        ? picked.length + (picked.length === 1 ? ' date: ' : ' dates: ') + calendarDateSummary(picked)
        : 'Ctrl-click days to pick them, Shift-click for a range.';

      // Labelled every pass, never behind an early return: a button with no
      // text on it is a bug the user sees before anyone else does.
      bar.querySelector('[data-cal-holiday]').textContent = closing ? 'Mark as holiday' : 'Remove holiday';
      // Booking a day that is already closed is refused server-side; the button
      // says so up front instead of letting the modal be filled in for nothing.
      bar.querySelector('[data-cal-book]').disabled = !closing;
    });
  }

  /**
   * Block the same time of day across every picked date, in one go. A session
   * already sitting in one of those slots is cancelled along with it -- same
   * rule as blocking a single day's slot, just run over the whole selection.
   */
  /**
   * Blocks one time across every picked date. Returns whether anything
   * actually got blocked, so a caller (a toggle switch) can undo its own UI
   * state on a "no" or a failure. Never reloads and never clears the
   * selection itself -- the popup that called this re-renders from the
   * updated data and stays open, so several slots can be closed in the same
   * sitting; the page only catches up once that popup is closed.
   */
  async function blockPickedSlots(time) {
    if (!picked.length) return false;

    var fd = function (date) {
      var f = new FormData();
      f.append('action', 'block_slot');
      f.append('slot_date', date);
      f.append('slot_time', time);
      return f;
    };

    var results = await Promise.all(picked.map(function (date) {
      return fetch('api/sessions.php', { method: 'POST', body: fd(date) })
        .then(function (r) { return r.json(); })
        .then(function (d) { return { date: date, ok: d.success, cancelled: d.cancelled, error: d.error }; })
        .catch(function () { return { date: date, ok: false, error: 'Network error' }; });
    }));

    var okCount = 0, failed = [];
    results.forEach(function (r) {
      if (r.ok) {
        okCount++;
        markSlotBlockedLocal(r.date, time, true);
        if (r.cancelled) { markSessionCancelledLocal(r.date, time); }
      } else {
        failed.push(r);
      }
    });

    var msg = okCount + (okCount === 1 ? ' slot blocked' : ' slots blocked');
    if (failed.length) {
      msg += ', ' + failed.length + ' skipped (' + (failed[0].error || 'error') + ')';
    }
    showToast(msg, failed.length && !okCount ? 'error' : undefined);
    if (okCount) { availabilityDirty = true; }
    return okCount > 0;
  }

  /**
   * The other bulk option: cancel whatever is booked across the picked
   * dates, every time of day, without closing the slots behind them --
   * the time stays bookable for someone else. Blocking (above) is for
   * closing a time down; this is just clearing what is on the calendar.
   */
  async function cancelPickedSessions() {
    if (!picked.length) return;
    if (!await showConfirm('Cancel every session booked on ' + picked.length + ' picked date(s)? The times stay open for rebooking.', { danger: true, okText: 'Cancel sessions' })) return;

    var fd = new FormData();
    fd.append('action', 'cancel_sessions_on_dates');
    fd.append('session_dates', picked.join(','));

    fetch('api/sessions.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
        showToast(d.changed + (d.changed === 1 ? ' session cancelled' : ' sessions cancelled'));
        clear();
        setTimeout(function () { location.reload(); }, 600);
      })
      .catch(function () { showToast('Network error', 'error'); });
  }

  /** Reopen the same time of day across every picked date. */
  async function unblockPickedSlots(time) {
    if (!picked.length) return false;

    var results = await Promise.all(picked.map(function (date) {
      var f = new FormData();
      f.append('action', 'unblock_slot');
      f.append('slot_date', date);
      f.append('slot_time', time);
      return fetch('api/sessions.php', { method: 'POST', body: f })
        .then(function (r) { return r.json(); })
        .then(function (d) { return { date: date, ok: d.success }; })
        .catch(function () { return { date: date, ok: false }; });
    }));

    var okCount = 0;
    results.forEach(function (r) { if (r.ok) { okCount++; markSlotBlockedLocal(r.date, time, false); } });
    if (okCount) { availabilityDirty = true; showToast('Slots reopened'); }
    return okCount > 0;
  }

  /** True when every picked day is already closed, i.e. the button reopens. */
  function allPickedAreHolidays() {
    if (!picked.length) return false;
    return picked.every(function (date) {
      var cell = document.querySelector('.cal-cell[data-date="' + date + '"]');
      return !!(cell && cell.dataset.holiday === '1');
    });
  }

  /**
   * Close or reopen the picked days.
   *
   * Sessions already booked on a day being closed are left alone -- the server
   * reports how many there are and the toast passes that on, because a click
   * on a calendar must not quietly cancel somebody's appointment.
   */
  /**
   * Reopening never asks. Closing a day that still has someone booked on it
   * does: the server refuses with needs_resolution and the list of who is on
   * it, and the popup below sends the same request again with `resolve` set
   * to whichever of the two options was picked.
   */
  function setHoliday(on, resolve) {
    if (!picked.length) return;

    var fd = new FormData();
    fd.append('action', 'set_holiday');
    fd.append('session_dates', picked.join(','));
    fd.append('on', on ? '1' : '0');
    if (resolve) { fd.append('resolve', resolve); }

    fetch('api/sessions.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success && data.needs_resolution) {
          showHolidayConflictPopup(data.conflicts);
          return;
        }
        if (!data.success) throw new Error(data.error || 'Those days could not be changed.');

        var msg = data.dates.length + (data.dates.length === 1 ? ' day ' : ' days ')
                + (on ? 'marked a holiday' : 'reopened');
        if (data.cancelled) {
          msg += ' — ' + data.cancelled + ' session(s) cancelled, ' + data.rescheduled + ' rescheduled to the next available week';
        }
        showToast(msg);
        setTimeout(function () { location.reload(); }, 800);
      })
      .catch(function (err) { showToast(err.message || 'Network error', 'error'); });
  }

  /** One row per session standing in the way of the holiday just requested. */
  function showHolidayConflictPopup(conflicts) {
    var html = '<p class="prose">These days already have a session booked. Skip them, or cancel those sessions and rebook each one at the same time the next week that is actually free.</p>';
    html += '<div class="availability-list">';
    Object.keys(conflicts).sort().forEach(function (date) {
      conflicts[date].forEach(function (s) {
        html += '<div class="availability-row">';
        html += '<span class="availability-time">' + date + '</span>';
        html += '<span class="badge badge-scheduled">' + s.time + ' — ' + escapeHtml(s.name) + '</span>';
        html += '</div>';
      });
    });
    html += '</div>';
    document.getElementById('holidayConflictContent').innerHTML = html;
    document.getElementById('holidayConflictModal').classList.add('open');
  }

  function order(dates) {
    var all = cells().map(function (c) { return c.dataset.date; });
    return all.filter(function (d) { return dates.indexOf(d) !== -1; });
  }

  function toggle(date) {
    var at = picked.indexOf(date);
    if (at === -1) { picked.push(date); } else { picked.splice(at, 1); }
    picked = order(picked);
    anchor = date;
    render();
  }

  /**
   * Shift-click: every day from the anchor to the day clicked, inclusive.
   *
   * The anchor is the last day clicked plainly or ctrl-clicked. Clicking a
   * date and then shift-clicking a later one is the whole gesture, so a plain
   * click has to leave an anchor behind or a range has nothing to measure
   * from. It also stays put across repeated shift-clicks, so moving the far
   * end redraws the run instead of piling ranges on top of each other.
   *
   * `add` keeps whatever was already picked (ctrl+shift); a plain shift-click
   * replaces the selection with the run.
   */
  function extendTo(date, add) {
    var all = cells().map(function (c) { return c.dataset.date; });
    if (anchor === null || all.indexOf(anchor) === -1) anchor = date;

    var from = all.indexOf(anchor);
    var to   = all.indexOf(date);
    if (from === -1 || to === -1) return;
    if (from > to) { var swap = from; from = to; to = swap; }

    var range = all.slice(from, to + 1);
    picked = add
      ? order(picked.concat(range.filter(function (d) { return picked.indexOf(d) === -1; })))
      : range;
    render();
  }

  function clear() {
    picked = [];
    render();
    if (typeof applyDateSelection === 'function') applyDateSelection([]);
  }

  /**
   * Start again on a different month.
   *
   * clear() deliberately keeps the anchor, because the click that clears is
   * the same click that sets it. Changing month is the one case where the
   * anchor has to go too: it points at a day that is no longer on screen, and
   * a shift-click would measure a range from somewhere nobody can see.
   */
  function reset() {
    picked = [];
    anchor = null;
    render();
    var opened = document.querySelector('.cal-cell.selected[data-date], .cal-cell.today[data-date]');
    if (opened) anchor = opened.dataset.date;
    if (typeof applyDateSelection === 'function') applyDateSelection([]);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.calendar-grid').forEach(function (grid) {
      grid.addEventListener('click', function (e) {
        var cell = e.target.closest ? e.target.closest('.cal-cell[data-date]') : null;
        if (!cell || !grid.contains(cell)) return;

        // The cells are focusable, so a mouse click leaves the clicked day
        // holding focus and wearing a ring that reads as "still selected" --
        // which is exactly the complaint. Styling :focus was not enough,
        // because the ring is drawn by whichever rule wins the cascade on the
        // day; not holding the focus at all is the fix that does not depend on
        // that. e.detail is 0 for a keyboard-triggered click, so keyboard
        // focus -- the only kind the ring is actually for -- is left alone.
        if (e.detail > 0 && typeof cell.blur === 'function') cell.blur();

        if (e.shiftKey) {
          // Stop here, in capture, or the cell's own onclick opens a day
          // modal on top of the selection the therapist is building.
          e.preventDefault();
          e.stopPropagation();
          // A shift-drag across days selects text otherwise.
          if (window.getSelection) window.getSelection().removeAllRanges();
          extendTo(cell.dataset.date, e.ctrlKey || e.metaKey);
          return;
        }

        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          e.stopPropagation();
          toggle(cell.dataset.date);
          return;
        }

        // A plain click keeps its old behaviour and drops any selection, but
        // it still leaves an anchor behind: "click a date, shift-click a later
        // one" is how a range gets picked, and the first click is half of it.
        if (picked.length) clear();
        anchor = cell.dataset.date;
      }, true);

      // Keyboard parity. The cells are focusable buttons on the sessions page,
      // and a selection you can only make with a mouse is not one everybody
      // can make.
      grid.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var cell = e.target.closest ? e.target.closest('.cal-cell[data-date]') : null;
        if (!cell || !grid.contains(cell)) return;

        if (e.shiftKey) {
          e.preventDefault();
          e.stopPropagation();
          extendTo(cell.dataset.date, e.ctrlKey || e.metaKey);
        } else if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          e.stopPropagation();
          toggle(cell.dataset.date);
        }
      }, true);
    });

    // The day the page opens on anchors the first range, so a shift-click
    // works before anything has been clicked at all.
    var opened = document.querySelector('.cal-cell.selected[data-date], .cal-cell.today[data-date]');
    if (opened) anchor = opened.dataset.date;

    renderBars();
  });

  return {
    dates: function () { return picked.slice(); },
    clear: clear,
    reset: reset,
    blockSlot: blockPickedSlots,
    unblockSlot: unblockPickedSlots,
    setHoliday: setHoliday
  };
})();

/**
 * Previous / next month, without reloading the page.
 *
 * The month grid is built in PHP -- which weekday the first falls on, which
 * days are holidays, which days hold sessions -- so the honest way to get the
 * next month is to ask the server for it, not to rebuild that logic in
 * JavaScript and have two versions of the same calendar to keep in step. The
 * page is fetched and the parts that changed are lifted out of it.
 *
 * The links stay real links. Middle-click and ctrl-click still open a month in
 * a new tab, the URL still names the month it is showing, and with JavaScript
 * off the whole thing degrades to the page load it used to be.
 */
var calendarNav = (function () {
  var busy = false;

  function readData(root) {
    var node = (root || document).getElementById('cal-data');
    if (!node) { return null; }
    try { return JSON.parse(node.textContent); } catch (e) { return null; }
  }

  function apply(doc) {
    var incoming = doc.querySelector('.calendar-grid');
    var grid     = document.querySelector('.calendar-grid');
    if (!incoming || !grid) { return false; }

    // innerHTML, not the element: the click and keydown listeners that drive
    // multi-selection are bound to the grid itself, and swapping the node out
    // would take them with it.
    grid.innerHTML = incoming.innerHTML;

    var title = document.querySelector('.calendar-title');
    var freshTitle = doc.querySelector('.calendar-title');
    if (title && freshTitle) { title.textContent = freshTitle.textContent; }

    var links = document.querySelectorAll('[data-cal-nav]');
    var freshLinks = doc.querySelectorAll('[data-cal-nav]');
    for (var i = 0; i < links.length && i < freshLinks.length; i++) {
      links[i].setAttribute('href', freshLinks[i].getAttribute('href'));
    }

    var here  = document.getElementById('cal-data');
    var fresh = doc.getElementById('cal-data');
    if (here && fresh) { here.textContent = fresh.textContent; }

    var data = readData(document) || {};
    if (data.month) { window.CAL_MONTH = data.month; }
    calendarSelection.reset();
    document.dispatchEvent(new CustomEvent('calendar:monthchanged', { detail: data }));
    return true;
  }

  function go(url, push) {
    if (busy) { return; }
    busy = true;
    var grid = document.querySelector('.calendar-grid');
    if (grid) { grid.classList.add('is-loading'); }

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (!apply(doc)) { throw new Error('no calendar in the response'); }
        if (push) { history.pushState({ calendar: true }, '', url); }
        busy = false;
        if (grid) { grid.classList.remove('is-loading'); }
      })
      .catch(function () {
        // A month that will not load is not a month worth trapping anyone on:
        // fall back to the plain navigation the link would have done anyway.
        window.location.href = url;
      });
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('[data-cal-nav]') : null;
    // Anything that means "open this somewhere else" is left alone.
    if (!link || e.defaultPrevented) { return; }
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    e.preventDefault();
    go(link.getAttribute('href'), true);
  });

  // Back and forward have to move the calendar, or the URL and the month on
  // screen stop agreeing with each other.
  window.addEventListener('popstate', function () {
    if (document.querySelector('.calendar-grid')) { go(window.location.href, false); }
  });

  return { data: function () { return readData(document); } };
})();
</script>
