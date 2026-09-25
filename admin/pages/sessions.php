<?php
require_once __DIR__ . '/../../includes/booking-slots.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/holidays.php';

$db = getDbConnection();

// Calendar
$month = isset($_GET['cm']) ? intval($_GET['cm']) : intval(date('n'));
$year  = isset($_GET['cy']) ? intval($_GET['cy']) : intval(date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
$firstDay   = mktime(0,0,0,$month,1,$year);
$daysInMonth= date('t', $firstDay);
$startDow   = date('w', $firstDay);
$monthName  = date('F Y', $firstDay);

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// Fetch sessions with client names
$calStmt = $db->prepare("
    SELECT s.*, CONCAT(c.first_name, ' ', c.last_name) as client_name
    FROM `sessions` s
    LEFT JOIN `clients` c ON s.client_id = c.id
    WHERE MONTH(s.`start_time`)=:m AND YEAR(s.`start_time`)=:y
    ORDER BY s.`start_time` ASC
");
$calStmt->execute([':m'=>$month, ':y'=>$year]);
$allSessions = $calStmt->fetchAll(PDO::FETCH_ASSOC);

$calSessions = [];
foreach ($allSessions as $s) {
    $d = intval(date('j', strtotime($s['start_time'])));
    $calSessions[$d][] = $s;
}

// Days the practice is closed this month, so the grid can show them shut
// rather than letting a booking be started on one and refused at the door.
$monthHolidays = holidayDates(
    $db,
    date('Y-m-01', $firstDay),
    date('Y-m-t', $firstDay)
);

// Upcoming sessions
$upcoming = $db->query("
    SELECT s.*, CONCAT(c.first_name, ' ', c.last_name) as client_name
    FROM `sessions` s
    LEFT JOIN `clients` c ON s.client_id = c.id
    WHERE s.`start_time` >= NOW() AND s.`status` IN ('pending','confirmed')
    ORDER BY s.`start_time` ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// The client picker and its "why is this list short" note now live in the
// shared booking modal, which both this page and the dashboard include.
?>
<!-- The month, as data rather than as statements. Changing month fetches
     this page and reads this block out of the response, so the grid and the
     sessions behind it stay in step without a reload. -->
<script type="application/json" id="cal-data"><?php echo json_encode([
    'month'     => sprintf('%04d-%02d', $year, $month),
    'monthName' => date('F', $firstDay),
    'label'     => $monthName,
    'sessions'  => $calSessions,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?></script>
<script>window.CAL_MONTH = <?php echo json_encode(sprintf('%04d-%02d', $year, $month)); ?>;</script>

<div class="content-grid">
  <!-- Calendar -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Session Calendar</div>
      <div class="calendar-nav">
        <a href="index.php?page=sessions&cm=<?php echo $prevMonth; ?>&cy=<?php echo $prevYear; ?>" class="btn btn-icon" title="Previous month" data-cal-nav><i class="bi bi-chevron-left"></i></a>
        <span class="calendar-title"><?php echo $monthName; ?></span>
        <a href="index.php?page=sessions&cm=<?php echo $nextMonth; ?>&cy=<?php echo $nextYear; ?>" class="btn btn-icon" title="Next month" data-cal-nav><i class="bi bi-chevron-right"></i></a>
      </div>
    </div>
    <div class="panel-body">
      <div class="calendar-grid">
        <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dl): ?>
          <div class="cal-day-label"><?php echo $dl; ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $startDow; $i++): ?>
          <div class="cal-cell other-month"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
          $hasSessions = isset($calSessions[$day]);
          $cellDate    = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
          $isHoliday   = in_array($cellDate, $monthHolidays, true);
        ?>
          <?php
          // Every day is clickable, not only days that already hold something.
          // The onclick used to be printed only when $hasSessions, so on an
          // empty calendar there was nothing to click at all and no way to book
          // from the calendar -- which is the whole reason to have one.
          $cellAction = $hasSessions
              ? 'showDaySessions(' . $day . ')'
              : "openNewSessionModal('" . $cellDate . "')";
          ?>
          <div class="cal-cell <?php echo ($isToday ? 'today' : '') . ($isHoliday ? ' holiday' : ''); ?>" role="button" tabindex="0"
               data-date="<?php echo $cellDate; ?>"
               <?php echo $isHoliday ? 'data-holiday="1"' : ''; ?>
               title="<?php echo $isHoliday ? 'Holiday - the practice is closed' : ($hasSessions ? 'View sessions' : 'Book a session'); ?>"
               onclick="<?php echo $cellAction; ?>"
               onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();<?php echo $cellAction; ?>;}">
            <div class="cal-date"><?php echo $day; ?></div>
            <?php if ($hasSessions): ?>
              <?php foreach (array_slice($calSessions[$day], 0, 3) as $cs):
                $dotClass = 'teal';
                if (in_array($cs['status'], ['pending', 'confirmed'], true)) {
                    $dotClass = 'amber';
                } elseif ($cs['status'] === 'completed') {
                    $dotClass = 'green';
                } elseif ($cs['status'] === 'cancelled') {
                    $dotClass = 'red';
                }
              ?>
                <span class="cal-dot <?php echo $dotClass; ?>"></span>
              <?php endforeach; ?>
              <?php if (count($calSessions[$day]) > 3): ?>
                <span class="cal-more">+<?php echo count($calSessions[$day])-3; ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endfor; ?>

        <?php $remaining = (7 - (($startDow + $daysInMonth) % 7)) % 7; for ($i = 0; $i < $remaining; $i++): ?>
          <div class="cal-cell other-month"></div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <!-- Upcoming Sessions -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Upcoming Sessions</div>
      <button class="btn btn-primary btn-sm" type="button" onclick="openNewSessionModal()"><i class="bi bi-plus"></i> New</button>
    </div>
    <div class="panel-body is-list">
      <?php if (empty($upcoming)): ?>
        <div class="empty-state"><i class="bi bi-calendar3"></i><p>No upcoming sessions</p></div>
      <?php else: ?>
        <div class="session-list">
          <?php foreach ($upcoming as $us): ?>
            <div class="session-row is-plain">
              <div class="session-row-main">
                <div class="session-row-icon <?php echo $us['session_type']==='online'?'teal':'amber'; ?>">
                  <i class="bi <?php echo $us['session_type']==='online'?'bi-camera-video':'bi-geo-alt'; ?>"></i>
                </div>
                <div class="session-row-body">
                  <div class="session-row-name"><?php echo htmlspecialchars($us['client_name'] ?? 'Unknown'); ?></div>
                  <div class="session-row-meta"><?php echo date('D, d M', strtotime($us['start_time'])) . ' · ' . date('h:i A', strtotime($us['start_time']))
              . ' · ' . round((strtotime($us['end_time']) - strtotime($us['start_time'])) / 60) . ' min'; ?></div>
                </div>
              </div>
              <div class="session-row-controls">
                <span class="badge badge-<?php echo $us['session_type']; ?>"><?php echo $us['session_type']; ?></span>
                <select class="status-select" aria-label="Session status" onchange="updateSessionStatus(<?php echo $us['id']; ?>, this.value)">
                  <option value="pending" <?php echo $us['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                  <option value="confirmed" <?php echo $us['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                  <option value="completed" <?php echo $us['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                  <option value="cancelled" <?php echo $us['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button class="btn btn-icon" onclick="openRescheduleModal(<?php echo $us['id']; ?>, '<?php echo date('Y-m-d', strtotime($us['start_time'])); ?>', '<?php echo date('H:i', strtotime($us['start_time'])); ?>')" title="Reschedule"><i class="bi bi-pencil-square"></i></button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Day Sessions Modal -->
<div class="modal-overlay" id="daySessionsModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" id="daySessionsTitle">Sessions</div>
      <button class="modal-close" onclick="document.getElementById('daySessionsModal').classList.remove('open')">&times;</button>
    </div>
    <div class="modal-body" id="daySessionsContent"></div>
  </div>
</div>

<!-- Booking modal, shared with the dashboard. See admin/includes/session-booking-modal.php -->
<?php include __DIR__ . "/../includes/session-booking-modal.php"; ?>

<!-- Reschedule Session Modal -->
<?php
// session id -> series id, so the page knows which sessions need the scope
// question. Only sessions that actually repeat appear here.
$seriesMap = [];
foreach ($db->query('SELECT `id`, `recurring_series_id` FROM `sessions` WHERE `recurring_series_id` IS NOT NULL') as $srow) {
    $seriesMap[(int) $srow['id']] = (int) $srow['recurring_series_id'];
}
?>
<script>window.SESSION_SERIES = <?php echo json_encode($seriesMap); ?>;</script>
<div class="modal-overlay" id="rescheduleSessionModal">
  <div class="modal-box is-narrow">
    <div class="modal-header">
      <div class="modal-title">Reschedule Session</div>
      <button class="modal-close" onclick="document.getElementById('rescheduleSessionModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitReschedule(event)">
      <input type="hidden" name="session_id" id="reschedule_session_id" />
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">New Date</label>
          <input type="date" class="form-input" name="session_date" id="reschedule_session_date" required />
        </div>
        <div class="form-group">
          <label class="form-label" for="reschedule_session_time">New Time</label>
          <select class="form-select" name="session_time" id="reschedule_session_time" required>
            <?php foreach (bookingSlots() as $slot): ?>
              <option value="<?php echo htmlspecialchars($slot); ?>"><?php echo htmlspecialchars(bookingSlotLabel($slot)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Inside .modal-body, not after it. This block used to sit outside the
             body, so it fell through the body's padding and scroll container. -->
        <div class="form-group" id="reschedule_scope_row" hidden>
          <label class="form-label">This session repeats. What should move?</label>
          <label class="choice">
            <input type="radio" name="scope" value="one" /> Only this occurrence
          </label>
          <label class="choice">
            <input type="radio" name="scope" value="future" /> This one and every later one
          </label>
          <small class="hint">Nothing is preselected on purpose — choosing for you is how the wrong sessions get moved.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('rescheduleSessionModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
var calData = JSON.parse(document.getElementById('cal-data').textContent);
var calSessionsData = calData.sessions || {};
var calMonthName = calData.monthName || '';

// The grid can change month under this page without reloading it, so the data
// the modal reads has to change with it.
document.addEventListener('calendar:monthchanged', function (e) {
  calData = e.detail || {};
  calSessionsData = calData.sessions || {};
  calMonthName = calData.monthName || calMonthName;
});

function showDaySessions(day) {
  var sessions = calSessionsData[day];
  if (!sessions) return;
  document.getElementById('daySessionsTitle').textContent = 'Sessions — ' + calMonthName + ' ' + day;
  var html = '';
  sessions.forEach(function(s) {
    html += '<div class="session-row is-plain">';
    html += '<div class="session-row-main">';
    html += '<div class="session-row-icon ' + (s.session_type === 'online' ? 'teal' : 'amber') + '"><i class="bi ' + (s.session_type === 'online' ? 'bi-camera-video' : 'bi-geo-alt') + '"></i></div>';
    html += '<div class="session-row-body">';
    html += '<div class="session-row-name">' + escapeHtml(s.client_name || 'Unknown') + '</div>';
    var startsAt = s.start_time.split(' ')[1].substring(0, 5);
    var mins = Math.round((Date.parse(s.end_time.replace(' ', 'T')) - Date.parse(s.start_time.replace(' ', 'T'))) / 60000);
    html += '<div class="session-row-meta">' + startsAt + ' · ' + mins + ' min · <span class="badge badge-' + s.status + '">' + s.status + '</span></div>';
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
  // A day that already holds sessions is the most likely day to want another
  // one on, so the booking action lives right here rather than only behind the
  // panel header button.
  html += '<div class="modal-day-book">';
  html += '<button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById(\'daySessionsModal\').classList.remove(\'open\'); openNewSessionModal(calendarDate(' + day + '));">';
  html += '<i class="bi bi-plus"></i> Book on this day</button>';
  html += '</div>';

  document.getElementById('daySessionsContent').innerHTML = html;
  document.getElementById('daySessionsModal').classList.add('open');
}

// createSession() and the repeat toggle now live in the shared booking modal,
// so the dashboard gets exactly the same behaviour rather than a copy of it.

// ─── Series scope ────────────────────────────────────────────────────────
//
// Whenever a session belongs to a recurring series, the admin is asked whether
// an action applies to this occurrence or to this and every later one.
// Guessing is the defining bug of recurring appointments, and both wrong
// answers stay invisible until somebody turns up to an appointment that was
// cancelled without them.
//
// SESSION_SERIES maps session id -> series id, emitted by the page.
function seriesScopeFor(id, verb) {
  if (!window.SESSION_SERIES || !SESSION_SERIES[id]) {
    return 'one';   // not part of a series: nothing to ask
  }
  var answer = window.prompt(
    'This session repeats.\n\n' +
    'Type "one" to ' + verb + ' only this occurrence,\n' +
    'or "future" to ' + verb + ' this one and every later one in the series.',
    'one'
  );
  if (answer === null) return null;              // cancelled the prompt
  answer = answer.trim().toLowerCase();
  return (answer === 'one' || answer === 'future') ? answer : null;
}

// A session booked before the slot list changed sits at a time the list no
// longer offers. Dropping it would leave the picker showing a time the session
// is not actually at, so the current time is carried in as a one-off option.
function selectSessionTime(sel, time) {
  if (!sel) return;
  var carried = sel.querySelector('option[data-carried]');
  if (carried) carried.remove();

  if (!sel.querySelector('option[value="' + time + '"]')) {
    var opt = document.createElement('option');
    opt.value = time;
    opt.textContent = time + ' (current time)';
    opt.setAttribute('data-carried', '1');
    sel.insertBefore(opt, sel.firstChild);
  }
  sel.value = time;
}

function openRescheduleModal(id, date, time) {
  document.getElementById('reschedule_session_id').value = id;
  document.getElementById('reschedule_session_date').value = date;
  selectSessionTime(document.getElementById('reschedule_session_time'), time);

  var scopeRow = document.getElementById('reschedule_scope_row');
  if (scopeRow) {
    scopeRow.hidden = !(window.SESSION_SERIES && SESSION_SERIES[id]);
  }
  document.getElementById('rescheduleSessionModal').classList.add('open');
}

function submitReschedule(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'reschedule_session');

  var id = document.getElementById('reschedule_session_id').value;
  if (window.SESSION_SERIES && SESSION_SERIES[id]) {
    var chosen = document.querySelector('input[name="scope"]:checked');
    if (!chosen) { showToast('Choose whether this applies to one session or the whole series', 'error'); return; }
    fd.set('scope', chosen.value);
  } else {
    fd.set('scope', 'one');
  }

  fetch('api/sessions.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast(d.moved > 1 ? (d.moved + ' sessions rescheduled') : 'Session rescheduled');
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showToast(d.error || 'Error', 'error');
      }
    })
    .catch(function(){ showToast('Network error', 'error'); });
}

function updateSessionStatus(id, status) {
  var scope = seriesScopeFor(id, status === 'cancelled' ? 'cancel' : 'change');
  if (scope === null) { location.reload(); return; }   // abandoned; undo the select

  var reason = '';
  if (status === 'cancelled') {
    // A cancellation with no reason is a mystery six months later, and the
    // repo refuses one anyway.
    reason = window.prompt('Why is this session being cancelled?', '');
    if (reason === null || reason.trim() === '') { location.reload(); return; }
  }

  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('session_id', id);
  fd.append('status', status);
  fd.append('scope', scope);
  fd.append('cancelled_reason', reason);

  fetch('api/sessions.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        var msg = 'Session status updated';
        if (d.changed > 1) msg = d.changed + ' sessions updated';
        if (d.skipped && d.skipped.length) msg += ', ' + d.skipped.length + ' skipped';
        showToast(msg);
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showToast(d.error || 'Error', 'error');
      }
    })
    .catch(function(){ showToast('Network error', 'error'); });
}

document.querySelectorAll('.modal-overlay').forEach(function(m) {
  m.addEventListener('click', function(e) { if(e.target===m) m.classList.remove('open'); });
});

</script>
