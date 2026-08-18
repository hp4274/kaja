<?php
require_once __DIR__ . '/../../includes/lead-repo.php';
require_once __DIR__ . '/../../includes/lead-status.php';

$db = getDbConnection();

// KPI Queries
$totalClients    = $db->query("SELECT COUNT(*) FROM `clients` WHERE `status`='active'")->fetchColumn();
$upcomingSessions= $db->query("SELECT COUNT(*) FROM `sessions` WHERE `session_date` >= CURDATE() AND `status`='scheduled'")->fetchColumn();
// Every lead still at 'new', with no age cap. A lead ignored for five weeks
// is more urgent than one that arrived today, not less; the old 30-day window
// made it disappear from the number entirely.
$newLeads        = (int) $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='new'")->fetchColumn();
$pendingLeads    = dashboardPendingLeads($db);
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
    <div class="kpi-sub">Last 30 days</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap blue"><i class="bi bi-clipboard2-check"></i></div>
    <div class="kpi-label"><i class="bi bi-percent"></i> Intake Rate</div>
    <div class="kpi-value"><?php echo $intakeRate; ?>%</div>
    <div class="kpi-sub"><?php echo $totalIntakes; ?> of <?php echo $totalLeads; ?> leads</div>
  </div>
</div>

<!-- Calendar + Activity -->
<div class="content-grid">
  <!-- Calendar -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Session Calendar</div>
      <div class="calendar-nav">
        <a href="index.php?page=dashboard&cm=<?php echo $prevMonth; ?>&cy=<?php echo $prevYear; ?>" class="btn btn-icon" title="Previous"><i class="bi bi-chevron-left"></i></a>
        <span class="calendar-title" style="padding:0 0.5rem;font-size:0.88rem;"><?php echo $monthName; ?></span>
        <a href="index.php?page=dashboard&cm=<?php echo $nextMonth; ?>&cy=<?php echo $nextYear; ?>" class="btn btn-icon" title="Next"><i class="bi bi-chevron-right"></i></a>
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
        ?>
          <div class="cal-cell <?php echo ($isToday ? 'today' : '') . ($isSelected ? ' selected' : ''); ?>" onclick="selectCalendarDay(this, <?php echo $day; ?>)">
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

      <!-- Spacing / Divider -->
      <hr style="border:0; border-top:1px solid var(--clr-border-light); margin:1.5rem 0;" />

      <!-- Day Sessions Inline Area -->
      <div id="day-sessions-inline-area">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
          <h3 style="font-size:0.92rem; font-weight:600; color:var(--clr-text); margin:0;" id="inline-sessions-title">
            Sessions
          </h3>
          <span id="inline-sessions-count" style="background:var(--clr-primary-light); color:var(--clr-primary); font-size:0.75rem; font-weight:600; padding:0.15rem 0.5rem; border-radius:var(--radius-full); min-width:20px; text-align:center;">0</span>
        </div>
        <div id="inline-sessions-list">
          <!-- Populated dynamically via JavaScript -->
        </div>
      </div>
    </div>
  </div>

  <style>
  .cal-cell.selected {
    outline: 2px solid var(--clr-primary);
    outline-offset: -2px;
    z-index: 5;
  }
  </style>

  <!-- Activity Feed -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Recent Activity</div>
    </div>
    <div class="panel-body" style="padding:0.75rem 1.25rem;">
      <?php if (empty($activities)): ?>
        <div class="empty-state" style="padding:2rem;">
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
              'status_changed' => ['bi-arrow-repeat', 'teal'],
              'lead_confirmed' => ['bi-send-check', 'teal'],
              'intake_reminder_sent' => ['bi-bell', 'amber'],
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

<?php if (!empty($pendingLeads)): ?>
<!-- Needs attention — new leads untouched past the aging threshold -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-exclamation-triangle" style="color:var(--clr-danger);"></i> Needs attention</div>
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
              <td style="text-align:right;">
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

<script>
var calSessionsData = <?php echo json_encode($calSessions); ?>;

function selectCalendarDay(element, day) {
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
  var monthName = '<?php echo date('F', $firstDay); ?>';
  document.getElementById('inline-sessions-title').textContent = 'Sessions for ' + monthName + ' ' + day;
  
  // Get sessions
  var sessions = calSessionsData[day] || [];
  
  // Update badge count
  document.getElementById('inline-sessions-count').textContent = sessions.length;
  
  // Build HTML
  var html = '';
  if (sessions.length === 0) {
    html += '<div class="empty-state" style="padding:1.5rem; text-align:center; color:var(--clr-text-muted);">';
    html += '  <i class="bi bi-calendar-x" style="font-size:1.5rem; display:block; margin-bottom:0.5rem; color:var(--clr-text-muted);"></i>';
    html += '  <p style="font-size:0.85rem; margin:0;">No sessions scheduled for this day</p>';
    html += '</div>';
  } else {
    html += '<div style="display:flex; flex-direction:column; gap:0.75rem;">';
    sessions.forEach(function(s) {
      var clientName = escapeHtml(s.client_name || 'Unknown Client');
      
      // Format time (e.g. "10:00:00") to 12-hour format
      var timeParts = s.session_time.split(':');
      var hours = parseInt(timeParts[0], 10);
      var minutes = timeParts[1];
      var ampm = hours >= 12 ? 'PM' : 'AM';
      hours = hours % 12;
      hours = hours ? hours : 12;
      var timeStr = hours + ':' + minutes + ' ' + ampm;
      
      var duration = parseInt(s.duration_minutes, 10);
      var type = s.session_type;
      var status = s.status;
      var notes = s.notes || '';
      
      var typeBadgeClass = (type === 'online') ? 'badge-online' : 'badge-inperson';
      var statusBadgeClass = 'badge-' + status;
      
      html += '<div style="display:flex; align-items:flex-start; justify-content:space-between; padding:0.75rem; border:1px solid var(--clr-border-light); border-radius:var(--radius-md); background:var(--clr-bg); transition:transform var(--transition-fast);">';
      html += '  <div style="display:flex; align-items:flex-start; gap:0.75rem; flex:1; min-width:0;">';
      html += '    <div style="margin-top:0.15rem; background:var(--clr-surface); color:var(--clr-primary); width:32px; height:32px; border-radius:var(--radius-sm); border:1px solid var(--clr-border); display:flex; align-items:center; justify-content:center; flex-shrink:0;">';
      html += '      <i class="bi ' + (type === 'online' ? 'bi-camera-video' : 'bi-geo-alt') + '" style="font-size:0.95rem;"></i>';
      html += '    </div>';
      html += '    <div style="flex:1; min-width:0;">';
      html += '      <div style="font-weight:600; font-size:0.88rem; color:var(--clr-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + clientName + '</div>';
      html += '      <div style="font-size:0.78rem; color:var(--clr-text-secondary); margin-top:0.15rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">';
      html += '        <span><i class="bi bi-clock"></i> ' + timeStr + '</span>';
      html += '        <span style="color:var(--clr-text-muted);">•</span>';
      html += '        <span>' + duration + ' min</span>';
      html += '      </div>';
      if (notes) {
        html += '      <div style="font-size:0.75rem; color:var(--clr-text-secondary); margin-top:0.35rem; font-style:italic; border-left:2px solid var(--clr-primary); padding-left:0.5rem;">Note: ' + escapeHtml(notes) + '</div>';
      }
      html += '    </div>';
      html += '  </div>';
      html += '  <div style="display:flex; flex-direction:column; align-items:flex-end; gap:0.35rem; flex-shrink:0;">';
      html += '    <span class="badge ' + statusBadgeClass + '">' + status + '</span>';
      html += '    <span class="badge ' + typeBadgeClass + '">' + type + '</span>';
      html += '  </div>';
      html += '</div>';
    });
    html += '</div>';
  }
  
  document.getElementById('inline-sessions-list').innerHTML = html;
}

// Select default day on page load
document.addEventListener('DOMContentLoaded', function() {
  var defaultDay = <?php echo $defaultDay; ?>;
  var selectedCell = document.querySelector('.cal-cell.selected');
  selectCalendarDay(selectedCell, defaultDay);
});
</script>
