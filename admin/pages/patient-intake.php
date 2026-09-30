<?php
require_once __DIR__ . '/../../includes/lead-status.php';
require_once __DIR__ . '/../../includes/pagination.php';

$db = getDbConnection();
$stmtPI = $db->query("
    SELECT pi.*, 
           l.id as lead_id, 
           l.status as lead_status, 
           l.client_id as lead_client_id 
    FROM `patient-intake` pi
    LEFT JOIN `leads` l ON l.email = pi.email AND l.phone = pi.phone
    ORDER BY pi.created_at DESC
");
$patientIntakes = $stmtPI->fetchAll(PDO::FETCH_ASSOC);

// The drawer's piData JS blob (below) still carries every row, unsliced --
// only the visible table/grid is paginated, so opening a drawer for a form
// on another page still works without a second request.
$allPatientIntakes = $patientIntakes;
$pager             = paginate($patientIntakes);
$patientIntakes    = $pager['rows'];

/** Same shape as leadsUrl()/clientsUrl() -- this page just never had one. */
function patientIntakeUrl(array $overrides = []) {
    $params = array_merge(['page' => 'patient-intake', 'pp' => $_GET['pp'] ?? null], $overrides);
    $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
    return 'index.php?' . http_build_query($params);
}

// Score helper
function calcScore($row, $prefix, $count = 18) {
    $score = 0;
    for ($i = 1; $i <= $count; $i++) {
        $key = $prefix . '_' . $i;
        if (isset($row[$key]) && strtolower(trim($row[$key])) === 'yes') {
            $score++;
        }
    }
    return $score;
}

function scoreClass($score, $max = 18) {
    $pct = ($score / $max) * 100;
    if ($pct >= 67) return 'high';
    if ($pct >= 33) return 'mid';
    return 'low';
}
?>

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Patient Intake Forms (<?php echo count($allPatientIntakes); ?>)</div>
    <div class="view-toggle" data-page="patient-intake">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($patientIntakes)): ?>
      <div class="empty-state">
        <i class="bi bi-clipboard2-pulse"></i>
        <p>No patient intake forms submitted</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="th-num">#</th>
              <th>Date</th>
              <th>Name</th>
              <th>Email</th>
              <th>Concern</th>
              <th>Total</th>
              <th>Preferred date &amp; time</th>
              <th>Status</th>
              <th class="th-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Numbered against the whole filtered set: page 2 at 10/page starts at 11.
            $rowNum = ($pager['page'] - 1) * $pager['perPage'] + 1;
            foreach ($patientIntakes as $pi):
              $q1 = calcScore($pi, 'q1');
              $q2 = calcScore($pi, 'q2');
              $total = $q1 + $q2;
              $totalClass = scoreClass($total, 36);
              $lstatus = $pi['lead_status'] ?? 'new';
              $lclientId = $pi['lead_client_id'] ?? null;
            ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo $rowNum++; ?></td>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($pi['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($pi['first_name'] . ' ' . $pi['last_name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($pi['email']); ?>"><?php echo htmlspecialchars($pi['email']); ?></a></td>
                <td><?php echo htmlspecialchars($pi['concern']); ?></td>
                <td>
                  <span class="score-text <?php echo $totalClass; ?>"><?php echo $total; ?>/36</span>
                  <div class="score-bar is-compact">
                    <div class="score-bar-fill <?php echo $totalClass; ?>" style="width:<?php echo round(($total/36)*100); ?>%;"></div>
                  </div>
                </td>
                <td class="td-nowrap"><?php echo htmlspecialchars(trim(($pi['pref_date'] ?? '') . ' ' . ($pi['pref_time'] ?? ''))); ?></td>
                <td>
                  <!-- Read-only, like every other status in the admin. The actions beside
                       it are what move a lead; a dropdown here offered a second
                       way to write a status, with different rules from the buttons. -->
                  <span class="badge <?php echo leadStatusBadgeClass($lstatus); ?>"><?php echo leadStatusLabel($lstatus); ?></span>
                </td>
                <td class="td-actions">
                  <div class="row-actions">
                    <button class="btn btn-ghost btn-sm" onclick="openIntakeDrawer(<?php echo $pi['id']; ?>)"><i class="bi bi-eye"></i> Details</button>
                    <?php if ($lstatus !== 'converted'): ?>
                      <button class="btn btn-success btn-sm" onclick="convertIntake(<?php echo $pi['id']; ?>, '<?php echo htmlspecialchars(addslashes($pi['first_name'] . ' ' . $pi['last_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['phone']), ENT_QUOTES); ?>')">
                        <i class="bi bi-person-plus"></i> Convert
                      </button>
                    <?php else: ?>
                      <a href="index.php?page=client-profile&id=<?php echo $lclientId; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View Client</a>
                    <?php endif; ?>
                    <?php if ($lclientId): ?>
                      <button class="btn btn-primary btn-sm" onclick="openNewSessionModal('', <?php echo (int) $lclientId; ?>)"><i class="bi bi-calendar-plus"></i> Book appointment</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php $gridRowNum = ($pager['page'] - 1) * $pager['perPage'] + 1; ?>
        <?php foreach ($patientIntakes as $pi):
          $q1 = calcScore($pi, 'q1');
          $q2 = calcScore($pi, 'q2');
          $total = $q1 + $q2;
          $totalClass = scoreClass($total, 36);
          $lstatus = $pi['lead_status'] ?? 'new';
          $lclientId = $pi['lead_client_id'] ?? null;
          $fullName = htmlspecialchars($pi['first_name'] . ' ' . $pi['last_name']);
        ?>
          <div class="grid-card">
            <div class="grid-card-inner">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <i class="bi bi-clipboard2-pulse"></i>
                  <span><?php echo $fullName; ?></span>
                </div>
                <div class="grid-card-date">#<?php echo $gridRowNum++; ?> &middot; <?php echo date('d M Y', strtotime($pi['created_at'])); ?></div>
              </div>
              <div class="grid-card-body">
                <div class="grid-card-item" title="Email">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($pi['email']); ?>"><?php echo htmlspecialchars($pi['email']); ?></a>
                </div>
                <div class="grid-card-item" title="Primary Concern">
                  <i class="bi bi-heart-pulse"></i>
                  <span>Concern: <?php echo htmlspecialchars($pi['concern']); ?></span>
                </div>
                
                <!-- Score displays -->
                <div class="score-box">
                  <div class="score-box-total">
                    <strong>Total Score:</strong>
                    <div>
                      <span class="score-text <?php echo $totalClass; ?>"><?php echo $total; ?>/36</span>
                      <div class="score-bar is-compact">
                        <div class="score-bar-fill <?php echo $totalClass; ?>" style="width:<?php echo round(($total/36)*100); ?>%;"></div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="grid-card-item" title="Preferred date &amp; time">
                  <i class="bi bi-calendar-event"></i>
                  <span><?php echo htmlspecialchars(trim(($pi['pref_date'] ?? '') . ' ' . ($pi['pref_time'] ?? ''))); ?></span>
                </div>
                <div class="grid-card-item">
                  <i class="bi bi-flag"></i>
                  <span class="grid-card-label">Status:</span>
                  <!-- Read-only, like every other status in the admin. The actions beside
                       it are what move a lead; a dropdown here offered a second
                       way to write a status, with different rules from the buttons. -->
                  <span class="badge <?php echo leadStatusBadgeClass($lstatus); ?>"><?php echo leadStatusLabel($lstatus); ?></span>
                </div>
              </div>
            </div>
            <div class="grid-card-footer is-actions">
              <button class="btn btn-ghost btn-sm" onclick="openIntakeDrawer(<?php echo $pi['id']; ?>)"><i class="bi bi-eye"></i> Details</button>
              <?php if ($lstatus !== 'converted'): ?>
                <button class="btn btn-success btn-sm" onclick="convertIntake(<?php echo $pi['id']; ?>, '<?php echo htmlspecialchars(addslashes($pi['first_name'] . ' ' . $pi['last_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['phone']), ENT_QUOTES); ?>')">
                  <i class="bi bi-person-plus"></i> Convert
                </button>
              <?php else: ?>
                <a href="index.php?page=client-profile&id=<?php echo $lclientId; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View Client</a>
              <?php endif; ?>
              <?php if ($lclientId): ?>
                <button class="btn btn-primary btn-sm" onclick="openNewSessionModal('', <?php echo (int) $lclientId; ?>)"><i class="bi bi-calendar-plus"></i> Book appointment</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php echo paginationHtml($pager, 'patientIntakeUrl'); ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/session-booking-modal.php'; ?>

<?php include __DIR__ . '/../includes/intake-drawer.php'; ?>
<script>

async function convertIntake(id, name, email, phone) {
  if (!await showConfirm('Convert "' + name + '" to a client?')) return;
  var fd = new FormData();
  fd.append('action', 'convert_intake');
  fd.append('id', id);
  fd.append('name', name);
  fd.append('email', email);
  fd.append('phone', phone);
  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Converted to client!');
        setTimeout(function() { location.reload(); }, 800);
      } else {
        showToast(data.error || 'Error', 'error');
      }
    })
    .catch(function() { showToast('Network error', 'error'); });
}
</script>

<script>
(function() {
  const page = 'patient-intake';
  const container = document.querySelector('.panel');
  const toggleButtons = document.querySelectorAll('.view-toggle[data-page="' + page + '"] .view-toggle-btn');
  
  function setView(view) {
    localStorage.setItem('view-pref-' + page, view);
    toggleButtons.forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-view') === view);
    });
    if (view === 'grid') {
      container.classList.add('view-mode-grid');
      container.classList.remove('view-mode-list');
    } else {
      container.classList.add('view-mode-list');
      container.classList.remove('view-mode-grid');
    }
  }

  const savedView = localStorage.getItem('view-pref-' + page) || 'list';
  setView(savedView);

  toggleButtons.forEach(btn => {
    btn.addEventListener('click', function() {
      setView(this.getAttribute('data-view'));
    });
  });
})();
</script>
