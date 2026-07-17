<?php
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
    WHERE MONTH(s.`session_date`)=:m AND YEAR(s.`session_date`)=:y
    ORDER BY s.`session_time` ASC
");
$calStmt->execute([':m'=>$month, ':y'=>$year]);
$allSessions = $calStmt->fetchAll(PDO::FETCH_ASSOC);

$calSessions = [];
foreach ($allSessions as $s) {
    $d = intval(date('j', strtotime($s['session_date'])));
    $calSessions[$d][] = $s;
}

// Upcoming sessions
$upcoming = $db->query("
    SELECT s.*, CONCAT(c.first_name, ' ', c.last_name) as client_name
    FROM `sessions` s
    LEFT JOIN `clients` c ON s.client_id = c.id
    WHERE s.`session_date` >= CURDATE() AND s.`status`='scheduled'
    ORDER BY s.`session_date` ASC, s.`session_time` ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// All clients for session form
$clientList = $db->query("SELECT `id`, `first_name`, `last_name` FROM `clients` WHERE `status`='active' ORDER BY `first_name` ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-grid">
  <!-- Calendar -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Session Calendar</div>
      <div style="display:flex;align-items:center;gap:0.35rem;">
        <a href="index.php?page=sessions&cm=<?php echo $prevMonth; ?>&cy=<?php echo $prevYear; ?>" class="btn btn-icon"><i class="bi bi-chevron-left"></i></a>
        <span style="font-size:0.9rem;font-weight:600;padding:0 0.5rem;"><?php echo $monthName; ?></span>
        <a href="index.php?page=sessions&cm=<?php echo $nextMonth; ?>&cy=<?php echo $nextYear; ?>" class="btn btn-icon"><i class="bi bi-chevron-right"></i></a>
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
        ?>
          <div class="cal-cell <?php echo $isToday ? 'today' : ''; ?>" <?php if($hasSessions): ?>onclick="showDaySessions(<?php echo $day; ?>)" style="cursor:pointer;"<?php endif; ?>>
            <div class="cal-date"><?php echo $day; ?></div>
            <?php if ($hasSessions): ?>
              <?php foreach (array_slice($calSessions[$day], 0, 3) as $cs):
                $dotClass = 'teal';
                if ($cs['status'] === 'scheduled') {
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
                <span style="font-size:0.65rem;color:var(--clr-text-muted);">+<?php echo count($calSessions[$day])-3; ?></span>
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
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('newSessionModal').classList.add('open')"><i class="bi bi-plus"></i> New</button>
    </div>
    <div class="panel-body" style="padding:0.5rem 1rem;">
      <?php if (empty($upcoming)): ?>
        <div class="empty-state" style="padding:2rem;"><i class="bi bi-calendar3"></i><p>No upcoming sessions</p></div>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($upcoming as $us): ?>
            <li class="activity-item" style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 0; border-bottom:1px solid var(--clr-border);">
              <div style="display:flex; align-items:center; gap:0.75rem; flex:1;">
                <div class="activity-icon <?php echo $us['session_type']==='online'?'teal':'amber'; ?>">
                  <i class="bi <?php echo $us['session_type']==='online'?'bi-camera-video':'bi-geo-alt'; ?>"></i>
                </div>
                <div>
                  <div class="activity-text" style="font-weight:500; font-size:0.88rem;"><?php echo htmlspecialchars($us['client_name'] ?? 'Unknown'); ?></div>
                  <div class="activity-time" style="font-size:0.78rem; color:var(--clr-text-muted);"><?php echo date('D, d M', strtotime($us['session_date'])) . ' · ' . date('h:i A', strtotime($us['session_time'])) . ' · ' . $us['duration_minutes'] . ' min'; ?></div>
                </div>
              </div>
              <div style="display:flex; align-items:center; gap:0.5rem; margin-left:0.5rem;">
                <span class="badge badge-<?php echo $us['session_type']; ?>"><?php echo $us['session_type']; ?></span>
                <select class="status-select" onchange="updateSessionStatus(<?php echo $us['id']; ?>, this.value)" style="margin:0;">
                  <option value="scheduled" <?php echo $us['status'] === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                  <option value="completed" <?php echo $us['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                  <option value="cancelled" <?php echo $us['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button class="btn btn-icon btn-sm" onclick="openRescheduleModal(<?php echo $us['id']; ?>, '<?php echo $us['session_date']; ?>', '<?php echo substr($us['session_time'], 0, 5); ?>')" title="Reschedule" style="padding:0.2rem 0.4rem; height:auto; width:auto;"><i class="bi bi-pencil-square"></i></button>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
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

<!-- New Session Modal -->
<div class="modal-overlay" id="newSessionModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Schedule New Session</div>
      <button class="modal-close" onclick="document.getElementById('newSessionModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="createSession(event)">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Client</label>
          <select class="form-select" name="client_id" required>
            <option value="">Select a client...</option>
            <?php foreach ($clientList as $cl): ?>
              <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['first_name'] . ' ' . $cl['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-input" name="session_date" required /></div>
          <div class="form-group"><label class="form-label">Time</label><input type="time" class="form-input" name="session_time" required /></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Duration (min)</label><input type="number" class="form-input" name="duration" value="60" min="15" step="15" /></div>
          <div class="form-group"><label class="form-label">Type</label><select class="form-select" name="session_type"><option value="online">Online</option><option value="inperson">In-Person</option></select></div>
        </div>
        <div class="form-group"><label class="form-label">Notes</label><textarea class="form-textarea" name="notes"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="document.getElementById('newSessionModal').classList.remove('open')">Cancel</button><button type="submit" class="btn btn-primary">Schedule</button></div>
    </form>
  </div>
</div>

<!-- Reschedule Session Modal -->
<div class="modal-overlay" id="rescheduleSessionModal">
  <div class="modal-box" style="max-width: 400px;">
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
          <label class="form-label">New Time</label>
          <input type="time" class="form-input" name="session_time" id="reschedule_session_time" required />
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
var calSessionsData = <?php echo json_encode($calSessions); ?>;

function showDaySessions(day) {
  var sessions = calSessionsData[day];
  if (!sessions) return;
  document.getElementById('daySessionsTitle').textContent = 'Sessions — <?php echo date('F', $firstDay); ?> ' + day;
  var html = '';
  sessions.forEach(function(s) {
    html += '<div class="activity-item" style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 0; border-bottom:1px solid var(--clr-border);">';
    html += '<div style="display:flex; align-items:center; gap:0.75rem; flex:1;">';
    html += '<div class="activity-icon ' + (s.session_type === 'online' ? 'teal' : 'amber') + '"><i class="bi ' + (s.session_type === 'online' ? 'bi-camera-video' : 'bi-geo-alt') + '"></i></div>';
    html += '<div>';
    html += '<div style="font-weight:500;font-size:0.88rem;">' + escapeHtml(s.client_name || 'Unknown') + '</div>';
    html += '<div style="font-size:0.78rem;color:var(--clr-text-muted);">' + s.session_time.substring(0,5) + ' · ' + s.duration_minutes + ' min · <span class="badge badge-' + s.status + '">' + s.status + '</span></div>';
    html += '</div>';
    html += '</div>';
    html += '<div style="display:flex; align-items:center; gap:0.5rem; margin-left:0.5rem;">';
    html += '<select class="status-select" onchange="updateSessionStatus(' + s.id + ', this.value)" style="margin:0;">';
    html += '<option value="scheduled"' + (s.status === 'scheduled' ? ' selected' : '') + '>Scheduled</option>';
    html += '<option value="completed"' + (s.status === 'completed' ? ' selected' : '') + '>Completed</option>';
    html += '<option value="cancelled"' + (s.status === 'cancelled' ? ' selected' : '') + '>Cancelled</option>';
    html += '</select>';
    html += '<button class="btn btn-icon btn-sm" onclick="openRescheduleModal(' + s.id + ', \'' + s.session_date + '\', \'' + s.session_time.substring(0,5) + '\')" title="Reschedule" style="padding:0.2rem 0.4rem; height:auto; width:auto;"><i class="bi bi-pencil-square"></i></button>';
    html += '</div>';
    html += '</div>';
  });
  document.getElementById('daySessionsContent').innerHTML = html;
  document.getElementById('daySessionsModal').classList.add('open');
}

function createSession(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'add_session');
  fetch('api/sessions.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) { if(d.success){showToast('Session scheduled');setTimeout(function(){location.reload();},600);}else{showToast(d.error||'Error','error');} })
    .catch(function(){ showToast('Network error','error'); });
}

function openRescheduleModal(id, date, time) {
  document.getElementById('reschedule_session_id').value = id;
  document.getElementById('reschedule_session_date').value = date;
  document.getElementById('reschedule_session_time').value = time;
  document.getElementById('rescheduleSessionModal').classList.add('open');
}

function submitReschedule(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'reschedule_session');
  fetch('api/sessions.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if(d.success) {
        showToast('Session rescheduled successfully');
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showToast(d.error || 'Error', 'error');
      }
    })
    .catch(function(){ showToast('Network error', 'error'); });
}

function updateSessionStatus(id, status) {
  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('session_id', id);
  fd.append('status', status);
  fetch('api/sessions.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if(d.success) {
        showToast('Session status updated');
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
