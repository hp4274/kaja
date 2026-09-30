<?php
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/pagination.php';

$db      = getDbConnection();
$counts  = clientStatusCounts($db);

$filters = [
    'q'      => isset($_GET['q']) ? trim($_GET['q']) : '',
    'status' => isset($_GET['status']) ? trim($_GET['status']) : '',
    'sort'   => (isset($_GET['sort']) && $_GET['sort'] === 'name') ? 'name' : 'activity',
    'dir'    => (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc',
];

$search       = $filters['q'];
$statusFilter = $filters['status'];
$clients      = fetchClients($db, $filters);

$pager   = paginate($clients, 10);
$clients = $pager['rows'];

/** Rebuild the query string with one key changed, for the tabs and sort links. */
function clientsUrl(array $filters, array $overrides = []) {
    $params = array_merge(['page' => 'clients', 'pp' => $_GET['pp'] ?? null], $filters, $overrides);
    $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
    return 'index.php?' . http_build_query($params);
}
?>

<div class="toolbar">
  <div class="toolbar-left">
    <a href="<?php echo clientsUrl($filters, ['status' => '']); ?>" class="filter-btn <?php echo !$statusFilter ? 'active' : ''; ?>">All (<?php echo $counts['all']; ?>)</a>
    <?php foreach (clientStatuses() as $cs): ?>
      <a href="<?php echo clientsUrl($filters, ['status' => $cs]); ?>" class="filter-btn <?php echo $statusFilter === $cs ? 'active' : ''; ?>"><?php echo clientStatusLabel($cs); ?> (<?php echo $counts[$cs]; ?>)</a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <form method="get" action="index.php" class="search-bar">
      <input type="hidden" name="page" value="clients" />
      <i class="bi bi-search"></i>
      <input type="text" name="q" placeholder="Search clients..." value="<?php echo htmlspecialchars($search); ?>" />
    </form>
    <div class="view-toggle" data-page="clients">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-body-flush">
    <?php if (empty($clients)): ?>
      <div class="empty-state">
        <i class="bi bi-people"></i>
        <p>No clients found</p>
        <p>Convert a lead to create your first client record.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="th-num">#</th>
              <th>
                <a href="<?php echo clientsUrl($filters, ['sort' => 'name', 'dir' => ($filters['sort'] === 'name' && $filters['dir'] === 'asc') ? 'desc' : 'asc']); ?>" class="th-sort">
                  Client <i class="bi bi-arrow-down-up"></i>
                </a>
              </th>
              <th>Email</th>
              <th>Phone</th>
              <th>Next appointment</th>
              <th>Intake</th>
              <th>Status</th>
              <th>
                <a href="<?php echo clientsUrl($filters, ['sort' => 'activity', 'dir' => ($filters['sort'] === 'activity' && $filters['dir'] === 'desc') ? 'asc' : 'desc']); ?>" class="th-sort">
                  Last activity <i class="bi bi-arrow-down-up"></i>
                </a>
              </th>
              <th class="th-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Numbered against the whole filtered set: page 2 at 10/page starts at 11.
            $rowNum = ($pager['page'] - 1) * $pager['perPage'] + 1;
            foreach ($clients as $c): ?>
              <tr class="client-item" data-name="<?php echo htmlspecialchars(strtolower($c['first_name'] . ' ' . $c['last_name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($c['email'])); ?>">
                <td class="td-nowrap td-muted"><?php echo $rowNum++; ?></td>
                <td>
                  <div class="cell-person">
                    <div class="avatar-sm"><?php echo strtoupper(substr($c['first_name'],0,1) . substr($c['last_name'],0,1)); ?></div>
                    <span class="td-name"><?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></span>
                  </div>
                </td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a></td>
                <td class="td-nowrap"><?php echo htmlspecialchars($c['phone'] ?? '-'); ?></td>
                <td class="td-nowrap td-muted"><?php echo $c['next_appointment'] ? date('d M Y', strtotime($c['next_appointment'])) : '—'; ?></td>
                <td class="td-nowrap"><?php echo $c['intake_submitted_at']
                    ? '<span class="badge badge-intake-submitted">Submitted</span>'
                    : '<span class="badge badge-intake-sent">Not yet</span>'; ?></td>
                <td><span class="badge <?php echo clientStatusBadgeClass($c['status']); ?>"><?php echo clientStatusLabel($c['status']); ?></span></td>
                <td class="td-nowrap td-muted"><?php
                    $lastActivity = max(
                        strtotime($c['last_note_at'] ?: $c['created_at']),
                        strtotime($c['updated_at'] ?: $c['created_at'])
                    );
                    echo date('d M Y', $lastActivity);
                ?></td>
                <td class="td-actions">
                  <div class="row-actions">
                    <a href="index.php?page=client-profile&id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-person-lines-fill"></i> Profile</a>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="launchPortal(<?php echo (int) $c['id']; ?>)" title="View Client Portal"><i class="bi bi-box-arrow-up-right"></i> Portal</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteClient(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['first_name'] . ' ' . $c['last_name']), ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="clients-no-matches" class="no-matches" style="display:none;">
              <td colspan="9">
                <i class="bi bi-search"></i>
                <p>No clients match your search criteria</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php $gridRowNum = ($pager['page'] - 1) * $pager['perPage'] + 1; ?>
        <?php foreach ($clients as $c):
          $initials = strtoupper(substr($c['first_name'],0,1) . substr($c['last_name'],0,1));
          $fullName = htmlspecialchars($c['first_name'] . ' ' . $c['last_name']);
          $concernText = htmlspecialchars($c['concern'] ?: '-');
        ?>
          <div class="grid-card client-item" data-name="<?php echo htmlspecialchars(strtolower($c['first_name'] . ' ' . $c['last_name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($c['email'])); ?>">
            <div class="grid-card-inner">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <span class="grid-card-num">#<?php echo $gridRowNum++; ?></span>
                  <div class="avatar-sm"><?php echo $initials; ?></div>
                  <span><?php echo $fullName; ?></span>
                </div>
                <span class="badge <?php echo clientStatusBadgeClass($c['status']); ?>"><?php echo clientStatusLabel($c['status']); ?></span>
              </div>
              <div class="grid-card-body">
                <div class="grid-card-item" title="Email">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a>
                </div>
                <?php if ($c['phone']): ?>
                  <div class="grid-card-item" title="Phone">
                    <i class="bi bi-telephone"></i>
                    <span><?php echo htmlspecialchars($c['phone']); ?></span>
                  </div>
                <?php endif; ?>
                <div class="grid-card-item" title="Primary Concern">
                  <i class="bi bi-heart-pulse"></i>
                  <span>Concern: <?php echo $concernText; ?></span>
                </div>
              </div>
            </div>
            <div class="grid-card-footer">
              <span class="grid-card-label"><i class="bi bi-calendar3"></i> <?php echo $c['session_count']; ?> Sessions</span>
              <div class="row-actions">
                <a href="index.php?page=client-profile&id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-person-lines-fill"></i> Profile</a>
                <button type="button" class="btn btn-ghost btn-sm" onclick="launchPortal(<?php echo (int) $c['id']; ?>)" title="View Client Portal"><i class="bi bi-box-arrow-up-right"></i> Portal</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="deleteClient(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['first_name'] . ' ' . $c['last_name']), ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <div id="clients-grid-no-matches" class="no-matches-card" style="display:none;">
          <i class="bi bi-search"></i>
          <p>No clients match your search criteria</p>
        </div>
      </div>

      <?php echo paginationHtml($pager, function ($ov) use ($filters) { return clientsUrl($filters, $ov); }); ?>
    <?php endif; ?>
  </div>
</div>

<script>
async function deleteClient(id, name) {
  if (!await showConfirm('Delete ' + name + ' permanently? Their intake data, sessions, notes, fees and documents are all removed.', { danger: true, okText: 'Delete' })) return;
  var fd = new FormData();
  fd.append('action', 'delete');
  fd.append('client_id', id);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) { showToast('Client deleted'); setTimeout(function() { location.reload(); }, 700); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function launchPortal(cid) {
  var fd = new FormData();
  fd.append('client_id', cid);
  fetch('api/client-portal-launch.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        window.open('../portal/index.php', '_blank');
      } else {
        showToast(d.error || 'Failed to launch portal', 'error');
      }
    })
    .catch(function() { showToast('Network error launching portal', 'error'); });
}


(function() {
  const page = 'clients';
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

  // Real-time Search Filtering
  const searchInput = document.querySelector('.search-bar input[name="q"]');
  if (searchInput) {
    const searchForm = searchInput.closest('form');
    if (searchForm) {
      searchForm.addEventListener('submit', function(e) {
        e.preventDefault();
      });
    }

    searchInput.addEventListener('input', function() {
      const query = this.value.toLowerCase().trim();
      const items = document.querySelectorAll('.client-item');
      let visibleCount = 0;

      items.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const email = item.getAttribute('data-email') || '';
        if (name.includes(query) || email.includes(query)) {
          item.style.setProperty('display', '', 'important');
          visibleCount++;
        } else {
          item.style.setProperty('display', 'none', 'important');
        }
      });

      // Show/hide no matches placeholder
      const listNoMatch = document.getElementById('clients-no-matches');
      const gridNoMatch = document.getElementById('clients-grid-no-matches');
      
      if (visibleCount === 0) {
        if (listNoMatch) listNoMatch.style.setProperty('display', '', 'important');
        if (gridNoMatch) gridNoMatch.style.setProperty('display', 'block', 'important');
      } else {
        if (listNoMatch) listNoMatch.style.setProperty('display', 'none', 'important');
        if (gridNoMatch) gridNoMatch.style.setProperty('display', 'none', 'important');
      }
    });

    if (searchInput.value) {
      searchInput.dispatchEvent(new Event('input'));
    }
  }
})();
</script>
