<?php
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/lead-repo.php';
require_once __DIR__ . '/../../includes/lead-status.php';
require_once __DIR__ . '/../../includes/intake-repo.php';
require_once __DIR__ . '/../../includes/intake-status.php';
require_once __DIR__ . '/../../includes/mail-queue.php';
require_once __DIR__ . '/../../includes/intake-data.php';
require_once __DIR__ . '/../../includes/session-repo.php';
require_once __DIR__ . '/../../includes/holidays.php';

$db = getDbConnection();

// KPI Queries
// Archived clients are excluded everywhere, including here. A soft-deleted
// client still counted on the dashboard is the failure soft delete exists to
// prevent.
$totalClients    = $db->query("SELECT COUNT(*) FROM `clients` WHERE `status`='active' AND `archived_at` IS NULL")->fetchColumn();
$upcomingSessions= $db->query("SELECT COUNT(*) FROM `sessions` WHERE `start_time` >= NOW() AND `status` IN ('pending','confirmed')")->fetchColumn();
// Every lead still at 'new', with no age cap. A lead ignored for five weeks
// is more urgent than one that arrived today, not less; the old 30-day window
// made it disappear from the number entirely.
$newLeads        = (int) $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='new'")->fetchColumn();
$pendingLeads    = dashboardPendingLeads($db);
$stalledIntakes  = staleIntakeLinks($db, getSettingInt('admin_reminder_hours', 48));
$queuedMail      = queuedMailCount();
$awaitingReview  = clientsAwaitingReview($db);

$todaysSessions = sessionsBetween($db, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));

$totalLeads      = $db->query("SELECT COUNT(*) FROM `leads`")->fetchColumn();
$totalIntakes    = $db->query("SELECT COUNT(*) FROM `patient-intake`")->fetchColumn();
$intakeRate      = $totalLeads > 0 ? round(($totalIntakes / $totalLeads) * 100) : 0;

// Calendar sessions (current month)
$month = isset($_GET['cm']) ? intval($_GET['cm']) : intval(date('n'));
$year  = isset($_GET['cy']) ? intval($_GET['cy']) : intval(date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
$firstDay   = mktime(0,0,0,$month,1,$year);
$daysInMonth= date('t', $firstDay);
$startDow   = date('w', $firstDay); // 0=Sun
$monthName  = date('F Y', $firstDay);

// Days the practice is closed this month. The grid shows them shut so a
// booking is never started on a day the guard is going to refuse.
$monthHolidays = holidayDates($db, date('Y-m-01', $firstDay), date('Y-m-t', $firstDay));

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

// Activity log — last 9
$actStmt = $db->query("SELECT * FROM `activity_log` ORDER BY `created_at` DESC LIMIT 9");
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent leads — last 5
$recentLeads = $db->query("SELECT * FROM `leads` ORDER BY `created_at` DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
?>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon-wrap teal"><i class="bi bi-people"></i></div>
    <div class="kpi-label"><i class="bi bi-arrow-up-right"></i> Active Clients</div>
    <div class="kpi-value"><?php echo $totalClients; ?></div>
    <div class="kpi-sub">Currently active</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap green"><i class="bi bi-calendar-check"></i></div>
    <div class="kpi-label"><i class="bi bi-clock"></i> Upcoming Sessions</div>
    <div class="kpi-value"><?php echo $upcomingSessions; ?></div>
    <div class="kpi-sub">Scheduled ahead</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap amber"><i class="bi bi-funnel"></i></div>
    <div class="kpi-label"><i class="bi bi-plus-circle"></i> New Leads</div>
    <div class="kpi-value"><?php echo $newLeads; ?></div>
    <div class="kpi-sub">Waiting for a reply</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap blue"><i class="bi bi-calendar-day"></i></div>
    <div class="kpi-label"><i class="bi bi-clock-history"></i> Today</div>
    <div class="kpi-value"><?php echo count($todaysSessions); ?></div>
    <div class="kpi-sub">Session<?php echo count($todaysSessions) === 1 ? '' : 's'; ?> today</div>
  </div>
</div>

<!-- Calendar + Activity -->
<div class="content-grid">
  <!-- Calendar -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Session Calendar</div>
      <div class="calendar-nav">
        <a href="index.php?page=dashboard&cm=<?php echo $prevMonth; ?>&cy=<?php echo $prevYear; ?>" class="btn btn-icon" title="Previous month" data-cal-nav><i class="bi bi-chevron-left"></i></a>
        <span class="calendar-title"><?php echo $monthName; ?></span>
        <a href="index.php?page=dashboard&cm=<?php echo $nextMonth; ?>&cy=<?php echo $nextYear; ?>" class="btn btn-icon" title="Next month" data-cal-nav><i class="bi bi-chevron-right"></i></a>
        <!-- The dashboard showed a calendar you could not book from. Same modal
             as the sessions page, so both surfaces book identically. -->
        <button class="btn btn-primary btn-sm" type="button" onclick="openNewSessionModal(selectedCalendarDate())"><i class="bi bi-plus"></i> New</button>
      </div>
    </div>
    <div class="panel-body">
      <div class="calendar-grid">
        <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dl): ?>
          <div class="cal-day-label"><?php echo $dl; ?></div>
        <?php endforeach; ?>

        <?php
        // Default selected day (today if current month, otherwise 1st)
        $isCurrentMonth = ($month == date('n') && $year == date('Y'));
        $defaultDay = $isCurrentMonth ? intval(date('j')) : 1;

        // Previous month padding
        for ($i = 0; $i < $startDow; $i++):
        ?>
          <div class="cal-cell other-month"><div class="cal-date">&nbsp;</div></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
          $hasSessions = isset($calSessions[$day]);
          $isSelected = ($day === $defaultDay);
          $cellDate   = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
          $isHoliday  = in_array($cellDate, $monthHolidays, true);
        ?>
          <div class="cal-cell <?php echo ($isToday ? 'today' : '') . ($isSelected ? ' selected' : '') . ($isHoliday ? ' holiday' : ''); ?>"
               data-date="<?php echo $cellDate; ?>"
               <?php echo $isHoliday ? 'data-holiday="1" title="Holiday - the practice is closed"' : ''; ?>
               onclick="selectCalendarDay(this, <?php echo $day; ?>)">
            <div class="cal-date"><?php echo $day; ?></div>
            <?php if ($hasSessions): ?>
              <?php foreach ($calSessions[$day] as $cs): ?>
                <span class="cal-dot <?php echo $cs['session_type']==='online'?'teal':'amber'; ?>"></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endfor; ?>

        <?php
        // Remaining cells to complete grid
        $totalCells = $startDow + $daysInMonth;
        $remaining = (7 - ($totalCells % 7)) % 7;
        for ($i = 0; $i < $remaining; $i++):
        ?>
          <div class="cal-cell other-month"><div class="cal-date">&nbsp;</div></div>
        <?php endfor; ?>
      </div>

      <!-- The day you picked, under the month you picked it from. -->
      <div class="day-sessions" id="day-sessions-inline-area">
        <div class="subsection-head">
          <h3 class="subsection-title" id="inline-sessions-title">Sessions</h3>
          <button class="btn btn-primary btn-sm" type="button" onclick="openNewSessionModal(selectedCalendarDate())"><i class="bi bi-plus"></i> Book</button>
        </div>
        <div id="inline-sessions-list">
          <!-- Populated dynamically via JavaScript -->
        </div>
      </div>
    </div>
  </div>

  <!-- Activity Feed -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Recent Activity</div>
    </div>
    <div class="panel-body is-list">
      <?php if (empty($activities)): ?>
        <div class="empty-state">
          <i class="bi bi-clock-history"></i>
          <p>No activity yet</p>
        </div>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($activities as $act):
            $iconMap = [
              'lead_created' => ['bi-funnel', 'amber'],
              'client_converted' => ['bi-person-check', 'green'],
              'session_scheduled' => ['bi-calendar-plus', 'teal'],
              'session_completed' => ['bi-check-circle', 'green'],
              'note_added' => ['bi-sticky', 'blue'],
              'fee_added' => ['bi-currency-rupee', 'amber'],
              'fee_reminder_sent' => ['bi-cash-stack', 'amber'],
              'status_changed' => ['bi-arrow-repeat', 'teal'],
              'lead_confirmed' => ['bi-send-check', 'teal'],
              'intake_reminder_sent' => ['bi-bell', 'amber'],
              'intake_submitted' => ['bi-clipboard-check', 'green'],
              'intake_reviewed' => ['bi-clipboard-check', 'green'],
              'clients_merged' => ['bi-arrow-left-right', 'teal'],
              'session_rescheduled' => ['bi-calendar-event', 'amber'],
              'session_status_changed' => ['bi-calendar-check', 'teal'],
              'client_archived' => ['bi-archive', 'amber'],
              'document_uploaded' => ['bi-file-earmark-arrow-up', 'blue'],
              'document_downloaded' => ['bi-file-earmark-arrow-down', 'blue'],
              'document_archived' => ['bi-file-earmark-x', 'amber'],
            ];
            $ic = $iconMap[$act['action']] ?? ['bi-circle', 'teal'];
            $timeAgo = '';
            $diff = time() - strtotime($act['created_at']);
            if ($diff < 60) $timeAgo = 'Just now';
            elseif ($diff < 3600) $timeAgo = floor($diff/60) . 'm ago';
            elseif ($diff < 86400) $timeAgo = floor($diff/3600) . 'h ago';
            else $timeAgo = date('d M, h:i A', strtotime($act['created_at']));
          ?>
            <li class="activity-item">
              <div class="activity-icon <?php echo $ic[1]; ?>"><i class="bi <?php echo $ic[0]; ?>"></i></div>
              <div>
                <div class="activity-text"><?php echo htmlspecialchars($act['description']); ?></div>
                <div class="activity-time"><?php echo $timeAgo; ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($queuedMail > 0): ?>
  <!-- A refused send is the one failure nobody notices: the database is
       correct, the screen said success, and the person never hears from us. -->
  <div class="bulk-bar is-danger">
    <i class="bi bi-envelope-exclamation"></i>
    <?php echo (int) $queuedMail; ?> email(s) failed to send and are waiting for the hourly job to retry them.
  </div>
<?php endif; ?>

<?php if (!empty($awaitingReview)): ?>
<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-clipboard-check icon-warning"></i> Intakes awaiting review</div>
  </div>
  <div class="panel-body-flush">
    <div class="data-table-wrap">
      <table class="data-table">
        <tbody>
          <?php foreach (array_slice($awaitingReview, 0, 5) as $ar): ?>
            <tr>
              <td class="td-name"><?php echo htmlspecialchars($ar['first_name'] . ' ' . $ar['last_name']); ?></td>
              <td class="td-nowrap td-muted">submitted <?php echo $ar['intake_submitted_at'] ? date('d M Y', strtotime($ar['intake_submitted_at'])) : 'recently'; ?></td>
              <td class="td-actions">
                <a href="index.php?page=client-profile&id=<?php echo (int) $ar['id']; ?>" class="btn btn-primary btn-sm">Review</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($stalledIntakes)): ?>
<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-hourglass-split icon-warning"></i> Intake forms not finished</div>
    <a href="index.php?page=intake&link_status=sent" class="btn btn-ghost btn-sm">View All <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="panel-body-flush">
    <div class="data-table-wrap">
      <table class="data-table">
        <tbody>
          <?php foreach (array_slice($stalledIntakes, 0, 5) as $si): ?>
            <tr>
              <td class="td-name"><?php echo htmlspecialchars($si['name']); ?></td>
              <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($si['email']); ?>"><?php echo htmlspecialchars($si['email']); ?></a></td>
              <td class="td-nowrap td-muted">expires <?php echo date('d M Y', strtotime($si['expires_at'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($pendingLeads)): ?>
<!-- Needs attention — new leads untouched past the aging threshold -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-exclamation-triangle icon-danger"></i> Needs attention</div>
    <a href="index.php?page=leads&status=new" class="btn btn-ghost btn-sm">View All <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="panel-body-flush">
    <div class="data-table-wrap">
      <table class="data-table">
        <tbody>
          <?php foreach (array_slice($pendingLeads, 0, 5) as $p): ?>
            <tr>
              <td class="td-name"><?php echo htmlspecialchars($p['name']); ?></td>
              <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($p['email']); ?>"><?php echo htmlspecialchars($p['email']); ?></a></td>
              <td class="td-nowrap td-muted">waiting since <?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
              <td class="td-actions">
                <a href="index.php?page=leads&status=new" class="btn btn-primary btn-sm">Review</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Recent Leads -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Recent Leads</div>
    <a href="index.php?page=leads" class="btn btn-ghost btn-sm">View All <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($recentLeads)): ?>
      <div class="empty-state">
        <i class="bi bi-funnel"></i>
        <p>No leads yet</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Name</th>
              <th>Email</th>
              <th>Preference</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentLeads as $l): ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($l['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($l['name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($l['email']); ?>"><?php echo htmlspecialchars($l['email']); ?></a></td>
                <td><span class="badge badge-<?php echo htmlspecialchars($l['preference'] ?? ''); ?>"><?php echo htmlspecialchars($l['preference'] ?? '-'); ?></span></td>
                <td><span class="badge <?php echo leadStatusBadgeClass($l['status'] ?? 'new'); ?>"><?php echo leadStatusLabel($l['status'] ?? 'new'); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- The month, as data rather than as statements. Changing month fetches
     this page and reads this block out of the response, so the grid and the
     sessions behind it stay in step without a reload. -->
<script type="application/json" id="cal-data"><?php echo json_encode([
    'month'      => sprintf('%04d-%02d', $year, $month),
    'monthName'  => date('F', $firstDay),
    'label'      => $monthName,
    'defaultDay' => (int) $defaultDay,
    'sessions'   => $calSessions,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?></script>
<script>window.CAL_MONTH = <?php echo json_encode(sprintf('%04d-%02d', $year, $month)); ?>;</script>

<script>
var calData = JSON.parse(document.getElementById('cal-data').textContent);
var calSessionsData = calData.sessions || {};
var calMonthName = calData.monthName || '';

// The day the therapist last clicked. Booking from this panel should land on
// the day they are looking at, not on whatever today happens to be.
var selectedCalendarDay = calData.defaultDay || 1;

function selectedCalendarDate() {
  return calendarDate(selectedCalendarDay);
}

function selectCalendarDay(element, day) {
  selectedCalendarDay = day;
  // Remove selected class from all calendar cells
  var cells = document.querySelectorAll('.cal-cell');
  cells.forEach(function(c) {
    c.classList.remove('selected');
  });
  
  // Add selected class to the clicked cell
  if (element) {
    element.classList.add('selected');
  }
  
  // Update the title
  document.getElementById('inline-sessions-title').textContent = 'Sessions for ' + calMonthName + ' ' + day;
  
  // Get sessions
  var sessions = calSessionsData[day] || [];
  
  // Build HTML
  var html = '';
  if (sessions.length === 0) {
    html += '<div class="empty-state">';
    html += '  <i class="bi bi-calendar-x"></i>';
    html += '  <p>No sessions scheduled for this day</p>';
    html += '</div>';
  } else {
    html += '<div class="session-list">';
    sessions.forEach(function(s) {
      var clientName = escapeHtml(s.client_name || 'Unknown Client');

      // Format time (e.g. "10:00:00") to 12-hour format
      var timeParts = s.start_time.split(' ')[1].split(':');
      var hours = parseInt(timeParts[0], 10);
      var minutes = timeParts[1];
      var ampm = hours >= 12 ? 'PM' : 'AM';
      hours = hours % 12;
      hours = hours ? hours : 12;
      var timeStr = hours + ':' + minutes + ' ' + ampm;

      var duration = Math.round((Date.parse(s.end_time.replace(' ', 'T')) - Date.parse(s.start_time.replace(' ', 'T'))) / 60000);
      var type = s.session_type;
      var status = s.status;
      var notes = s.notes || '';

      var isOnline = (type === 'online');
      var typeLabel = isOnline ? 'Online' : 'In person';

      html += '<div class="session-row">';
      html += '  <div class="session-row-main">';
      html += '    <div class="session-row-icon" title="' + typeLabel + '">';
      html += '      <i class="bi ' + (isOnline ? 'bi-camera-video' : 'bi-geo-alt') + '"></i>';
      html += '    </div>';
      html += '    <div class="session-row-body">';
      html += '      <div class="session-row-name">' + clientName + '</div>';
      html += '      <div class="session-row-meta">';
      html += '        <span>' + timeStr + '</span>';
      html += '        <span>' + duration + ' min</span>';
      html += '        <span>' + typeLabel + '</span>';
      html += '      </div>';
      if (notes) {
        html += '      <div class="session-row-note">Note: ' + escapeHtml(notes) + '</div>';
      }
      html += '    </div>';
      html += '  </div>';
      html += '  <span class="badge badge-' + status + '">' + status + '</span>';
      html += '</div>';
    });
    html += '</div>';
  }

  document.getElementById('inline-sessions-list').innerHTML = html;
}

function openSelectedDay() {
  var cell = document.querySelector('.cal-cell.selected[data-date]');
  selectCalendarDay(cell, selectedCalendarDay);
}

// Select default day on page load
document.addEventListener('DOMContentLoaded', openSelectedDay);

// ...and again whenever the month underneath it changes, against the sessions
// that arrived with the new month rather than the ones that are no longer on
// screen.
document.addEventListener('calendar:monthchanged', function (e) {
  calData = e.detail || {};
  calSessionsData = calData.sessions || {};
  calMonthName = calData.monthName || calMonthName;
  selectedCalendarDay = calData.defaultDay || 1;
  openSelectedDay();
});
</script>

<!-- Booking modal, shared with the sessions page. See admin/includes/session-booking-modal.php -->
<?php include __DIR__ . "/../includes/session-booking-modal.php"; ?>
