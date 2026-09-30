<?php
require_once __DIR__ . '/../../includes/booking-slots.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/holidays.php';
require_once __DIR__ . '/../../includes/blocked-slots.php';
require_once __DIR__ . '/../../includes/calendar-cell.php';

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

// Slots the therapist has closed by hand, day by day rather than the whole
// day at once -- keyed the same shape as $calSessions so the grid and the
// day modal read both off the same JSON blob.
$monthBlocked = blockedSlotsBetween(
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
    'blocked'   => $monthBlocked,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?></script>
<script>window.CAL_MONTH = <?php echo json_encode(sprintf('%04d-%02d', $year, $month)); ?>;</script>
<!-- window.ALL_SLOTS is emitted by the shared booking modal include below. -->

<div class="content-grid is-stacked">
  <!-- Calendar -->
  <div class="panel is-calendar-lg">
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

        <?php $todayStr = date('Y-m-d'); ?>
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
          $hasSessions = isset($calSessions[$day]);
          $cellDate    = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
          $isHoliday   = in_array($cellDate, $monthHolidays, true);
          $isPast      = $cellDate < $todayStr;
        ?>
          <?php
          // Every day opens the same manage-day view now: what is booked, what
          // is blocked, and the option to book or block whatever is left. A
          // day with nothing on it is not a dead end -- it is the day most
          // likely to still need a slot closed ahead of time.
          $activeCount = 0;
          if ($hasSessions) {
              foreach ($calSessions[$day] as $cs) {
                  if (!in_array($cs['status'], ['cancelled', 'no-show'], true)) {
                      $activeCount++;
                  }
              }
          }
          $soloName = $activeCount === 1
              ? current(array_filter($calSessions[$day], function ($cs) {
                    return !in_array($cs['status'], ['cancelled', 'no-show'], true);
                }))['client_name']
              : null;
          // A day already gone cannot be booked or have its availability
          // touched -- there is nothing left to manage. It only stays
          // clickable when a real session is sitting on it, to look at or
          // cancel; an empty past day is just a fact, not an action.
          $clickable  = !$isPast || $activeCount > 0;
          $slotRows   = calendarSlotRows($hasSessions ? $calSessions[$day] : [], $monthBlocked[$cellDate] ?? [], $isHoliday);
          ?>
          <div class="cal-cell <?php echo ($isToday ? 'today' : '') . ($isHoliday ? ' holiday' : '') . (!$clickable ? ' is-past-empty' : ''); ?>"
               <?php echo $clickable ? 'role="button" tabindex="0"' : ''; ?>
               <?php echo $clickable ? 'data-date="' . $cellDate . '"' : ''; ?>
               <?php echo $isHoliday ? 'data-holiday="1"' : ''; ?>
               title="<?php echo $isHoliday ? 'Holiday - the practice is closed' : ($clickable ? 'Manage this day' : 'Nothing to manage on a past day'); ?>"
               <?php if ($clickable): ?>
               onclick="showDaySessions(<?php echo $day; ?>)"
               onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();showDaySessions(<?php echo $day; ?>);}"
               <?php endif; ?>>
            <div class="cal-date"><?php echo $day; ?></div>
            <?php echo calendarSlotRowsHtml($slotRows); ?>
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
    <div class="panel-body is-list is-scroll">
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

<!-- Day Sessions Modal, and the shared booking modal. Both live in
     admin/includes/session-booking-modal.php now, shared with the dashboard. -->
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
          <input type="date" class="form-input" name="session_date" id="reschedule_session_date" min="<?php echo date('Y-m-d'); ?>" required />
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

<?php include __DIR__ . "/../includes/series-scope-modal.php"; ?>

<script>
var calData = JSON.parse(document.getElementById('cal-data').textContent);
var calSessionsData = calData.sessions || {};
var calBlockedData = calData.blocked || {};
var calMonthName = calData.monthName || '';

// The grid can change month under this page without reloading it, so the data
// the modal reads has to change with it.
document.addEventListener('calendar:monthchanged', function (e) {
  calData = e.detail || {};
  calSessionsData = calData.sessions || {};
  calBlockedData = calData.blocked || {};
  calMonthName = calData.monthName || calMonthName;
});

// showDaySessions(), renderAvailability(), blockSlot() and unblockSlot() now
// live in the shared booking modal (admin/includes/session-booking-modal.php),
// so the dashboard gets exactly the same day-management view rather than a
// copy of it. They read calSessionsData/calBlockedData/calMonthName, which
// this page keeps updated above.

// createSession() and the repeat toggle also live in the shared booking modal.

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

async function updateSessionStatus(id, status) {
  var scope = await seriesScopeFor(id, status === 'cancelled' ? 'cancel' : 'change');
  if (scope === null) { location.reload(); return; }   // abandoned; undo the select

  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('session_id', id);
  fd.append('status', status);
  fd.append('scope', scope);

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
