<?php
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-status.php';

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

/** Rebuild the query string with one key changed, for the tabs and sort links. */
function clientsUrl(array $filters, array $overrides = []) {
    $params = array_merge(['page' => 'clients'], $filters, $overrides);
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
    <div class="view-toggle" data-page="clients" style="margin-left: 0.5rem;">
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
        <p style="font-size:0.78rem;">Convert a lead to create your first client record.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
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
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $c): ?>
              <tr class="client-item" data-name="<?php echo htmlspecialchars(strtolower($c['first_name'] . ' ' . $c['last_name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($c['email'])); ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:0.65rem;">
                    <div class="sidebar-avatar" style="width:32px;height:32px;font-size:0.75rem;"><?php echo strtoupper(substr($c['first_name'],0,1) . substr($c['last_name'],0,1)); ?></div>
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
                <td>
                  <a href="index.php?page=client-profile&id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-person-lines-fill"></i> Profile</a>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="clients-no-matches" style="display: none;">
              <td colspan="8" style="text-align: center; padding: 2.5rem 1rem; color: var(--clr-text-muted);">
                <i class="bi bi-search" style="font-size: 1.75rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                <p style="margin: 0; font-size: 0.9rem;">No clients match your search criteria</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php foreach ($clients as $c): 
          $initials = strtoupper(substr($c['first_name'],0,1) . substr($c['last_name'],0,1));
          $fullName = htmlspecialchars($c['first_name'] . ' ' . $c['last_name']);
          $concernText = htmlspecialchars($c['concern'] ?: '-');
        ?>
          <div class="grid-card client-item" data-name="<?php echo htmlspecialchars(strtolower($c['first_name'] . ' ' . $c['last_name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($c['email'])); ?>">
            <div style="flex:1; display:flex; flex-direction:column;">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <div class="sidebar-avatar" style="width:32px;height:32px;font-size:0.75rem;margin-right:0.25rem;"><?php echo $initials; ?></div>
                  <span><?php echo $fullName; ?></span>
                </div>
                <span class="badge <?php echo clientStatusBadgeClass($c['status']); ?>"><?php echo clientStatusLabel($c['status']); ?></span>
              </div>
              <div class="grid-card-body" style="margin-top:0.5rem;">
                <div class="grid-card-item" title="Email" style="margin-bottom:0.25rem;">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a>
                </div>
                <?php if ($c['phone']): ?>
                  <div class="grid-card-item" title="Phone" style="margin-bottom:0.25rem;">
                    <i class="bi bi-telephone"></i>
                    <span><?php echo htmlspecialchars($c['phone']); ?></span>
                  </div>
                <?php endif; ?>
                <div class="grid-card-item" title="Primary Concern" style="margin-bottom:0.25rem;">
                  <i class="bi bi-heart-pulse"></i>
                  <span>Concern: <?php echo $concernText; ?></span>
                </div>
              </div>
            </div>
            <div class="grid-card-footer">
              <span style="font-size:0.78rem;color:var(--clr-text-secondary);"><i class="bi bi-calendar3"></i> <?php echo $c['session_count']; ?> Sessions</span>
              <a href="index.php?page=client-profile&id=<?php echo $c['id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-person-lines-fill"></i> Profile</a>
            </div>
          </div>
        <?php endforeach; ?>
        <div id="clients-grid-no-matches" style="display: none; grid-column: 1 / -1; text-align: center; padding: 3.5rem 1.5rem; color: var(--clr-text-muted); width: 100%;">
          <i class="bi bi-search" style="font-size: 2.25rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
          <p style="margin: 0; font-size: 0.9rem;">No clients match your search criteria</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
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
