<?php
$db = getDbConnection();

// Counts per status
$counts = [];
foreach (['new','accepted','converted','declined'] as $s) {
    $counts[$s] = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='{$s}'")->fetchColumn();
}
$counts['all'] = array_sum($counts);

// Handle status filter
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Auto-select "New" if status filter not specified and new count > 0
if (!isset($_GET['status']) && !isset($_GET['q'])) {
    if ($counts['new'] > 0) {
        $statusFilter = 'new';
    } else {
        $statusFilter = 'all';
    }
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "SELECT * FROM `leads`";
$params = [];
$where = [];

if ($statusFilter && $statusFilter !== 'all' && in_array($statusFilter, ['new','accepted','converted','declined'])) {
    $where[] = "`status` = :status";
    $params[':status'] = $statusFilter;
}
if ($search) {
    $where[] = "(`name` LIKE :q OR `email` LIKE :q2)";
    $params[':q'] = "%{$search}%";
    $params[':q2'] = "%{$search}%";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY `created_at` DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Toolbar -->
<div class="toolbar">
  <div class="toolbar-left">
    <a href="index.php?page=leads&status=all" class="filter-btn <?php echo $statusFilter==='all' || !$statusFilter ? 'active' : ''; ?>">All (<span id="count-all"><?php echo $counts['all']; ?></span>)</a>
    <a href="index.php?page=leads&status=new" class="filter-btn <?php echo $statusFilter==='new' ? 'active' : ''; ?>">New (<span id="count-new"><?php echo $counts['new']; ?></span>)</a>
    <a href="index.php?page=leads&status=accepted" class="filter-btn <?php echo $statusFilter==='accepted' ? 'active' : ''; ?>">Accepted (<span id="count-accepted"><?php echo $counts['accepted']; ?></span>)</a>
    <a href="index.php?page=leads&status=converted" class="filter-btn <?php echo $statusFilter==='converted' ? 'active' : ''; ?>">Converted (<span id="count-converted"><?php echo $counts['converted']; ?></span>)</a>
    <a href="index.php?page=leads&status=declined" class="filter-btn <?php echo $statusFilter==='declined' ? 'active' : ''; ?>">Declined (<span id="count-declined"><?php echo $counts['declined']; ?></span>)</a>
  </div>
  <div class="toolbar-right">
    <form method="get" action="index.php" class="search-bar">
      <input type="hidden" name="page" value="leads" />
      <i class="bi bi-search"></i>
      <input type="text" name="q" placeholder="Search leads..." value="<?php echo htmlspecialchars($search); ?>" />
    </form>
    <div class="view-toggle" data-page="leads" style="margin-left: 0.5rem;">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
</div>

<!-- Leads Table -->
<div class="panel">
  <div class="panel-body-flush">
    <?php if (empty($leads)): ?>
      <div class="empty-state">
        <i class="bi bi-funnel"></i>
        <p>No leads found</p>
        <p style="font-size:0.78rem;">Leads will appear here when visitors submit the appointment or booking form.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Pref. Date & Time</th>
              <th>Preference</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($leads as $l): ?>
              <tr id="lead-row-<?php echo $l['id']; ?>" class="lead-item" data-status="<?php echo htmlspecialchars($l['status'] ?? 'new'); ?>" data-name="<?php echo htmlspecialchars(strtolower($l['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($l['email'])); ?>">
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($l['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($l['name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($l['email']); ?>"><?php echo htmlspecialchars($l['email']); ?></a></td>
                <td class="td-nowrap"><?php echo htmlspecialchars(($l['country_code'] ?? '') . ' ' . ($l['phone'] ?? '')); ?></td>
                <td class="td-nowrap td-muted">
                  <?php
                  if ($l['preferred_date']) echo date('d M Y', strtotime($l['preferred_date']));
                  if ($l['preferred_time']) echo ' at ' . date('h:i A', strtotime($l['preferred_time']));
                  ?>
                </td>
                <td><span class="badge badge-<?php echo htmlspecialchars($l['preference'] ?? ''); ?>"><?php echo htmlspecialchars($l['preference'] ?? '-'); ?></span></td>
                <td>
                  <select class="status-select" onchange="updateLeadStatus(<?php echo $l['id']; ?>, this.value)" <?php echo ($l['status'] ?? '') === 'converted' ? 'disabled' : ''; ?>>
                    <?php if (($l['status'] ?? '') === 'converted'): ?>
                      <option value="converted" selected>Converted</option>
                    <?php else: ?>
                      <option value="new" <?php echo ($l['status'] ?? 'new') === 'new' ? 'selected' : ''; ?>>New</option>
                      <option value="accepted" <?php echo ($l['status'] ?? '') === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                      <option value="declined" <?php echo ($l['status'] ?? '') === 'declined' ? 'selected' : ''; ?>>Declined</option>
                    <?php endif; ?>
                  </select>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <div style="display:inline-flex; align-items:center; gap:0.5rem; justify-content:flex-end;">
                    <?php if (($l['status'] ?? 'new') !== 'converted'): ?>
                      <button class="btn btn-success btn-sm" onclick="convertLead(<?php echo $l['id']; ?>, '<?php echo htmlspecialchars(addslashes($l['name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($l['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($l['phone'] ?? ''), ENT_QUOTES); ?>')">
                        <i class="bi bi-person-plus"></i> Convert
                      </button>
                    <?php else: ?>
                      <a href="index.php?page=client-profile&id=<?php echo $l['client_id']; ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:0.25rem;"><i class="bi bi-eye"></i> View Client</a>
                    <?php endif; ?>
                    <button class="btn btn-icon btn-sm" onclick="toggleMessage(<?php echo $l['id']; ?>)" title="View message" style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; padding:0; border:1px solid var(--clr-border); background:var(--clr-surface); cursor:pointer; color:var(--clr-text-muted); border-radius:var(--radius-sm);">
                      <i class="bi bi-chat-text"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <tr id="msg-row-<?php echo $l['id']; ?>" style="display:none;">
                <td colspan="8" style="background:#f9fafb; padding:1rem 1.5rem;">
                  <strong style="font-size:0.78rem;color:var(--clr-text-muted);text-transform:uppercase;">Message</strong>
                  <p style="margin-top:0.25rem;font-size:0.88rem;"><?php echo nl2br(htmlspecialchars($l['message'] ?? 'No message')); ?></p>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="leads-no-matches" style="display: none;">
              <td colspan="8" style="text-align: center; padding: 2.5rem 1rem; color: var(--clr-text-muted);">
                <i class="bi bi-search" style="font-size: 1.75rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
                <p style="margin: 0; font-size: 0.9rem;">No leads match your search criteria</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php foreach ($leads as $l): 
          $prefDateStr = '';
          if ($l['preferred_date']) $prefDateStr .= date('d M Y', strtotime($l['preferred_date']));
          if ($l['preferred_time']) $prefDateStr .= ' at ' . date('h:i A', strtotime($l['preferred_time']));
          $preference = htmlspecialchars($l['preference'] ?? '');
          $isConverted = ($l['status'] ?? '') === 'converted';
        ?>
          <div class="grid-card lead-item" id="lead-card-<?php echo $l['id']; ?>" data-status="<?php echo htmlspecialchars($l['status'] ?? 'new'); ?>" data-name="<?php echo htmlspecialchars(strtolower($l['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($l['email'])); ?>">
            <div style="flex: 1; display: flex; flex-direction: column;">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <i class="bi bi-person-badge" style="color:var(--clr-primary); font-size:1.1rem;"></i>
                  <span><?php echo htmlspecialchars($l['name']); ?></span>
                </div>
                <div style="font-size:0.75rem;color:var(--clr-text-muted);"><?php echo date('d M Y', strtotime($l['created_at'])); ?></div>
              </div>
              <div class="grid-card-body" style="margin-top: 0.5rem;">
                <div class="grid-card-item" title="Email" style="margin-bottom:0.25rem;">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($l['email']); ?>"><?php echo htmlspecialchars($l['email']); ?></a>
                </div>
                <?php if ($l['phone']): ?>
                  <div class="grid-card-item" title="Phone" style="margin-bottom:0.25rem;">
                    <i class="bi bi-telephone"></i>
                    <span><?php echo htmlspecialchars(($l['country_code'] ?? '') . ' ' . $l['phone']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ($prefDateStr): ?>
                  <div class="grid-card-item" title="Preferred Date" style="margin-bottom:0.25rem;">
                    <i class="bi bi-calendar-event"></i>
                    <span>Pref: <?php echo $prefDateStr; ?></span>
                  </div>
                <?php endif; ?>
                <div class="grid-card-item" style="margin-bottom:0.25rem; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
                  <span style="display:inline-flex; align-items:center; gap:0.35rem;"><i class="bi bi-camera-video"></i> Preference: <span class="badge badge-<?php echo $preference; ?>"><?php echo $preference ?: '-'; ?></span></span>
                </div>
                <div class="grid-card-item" style="margin-top: 0.25rem;">
                  <i class="bi bi-flag"></i>
                  <span style="font-size:0.8rem; font-weight:500; color:var(--clr-text-secondary); margin-right: 0.35rem;">Status:</span>
                  <select class="status-select" onchange="updateLeadStatus(<?php echo $l['id']; ?>, this.value)" <?php echo $isConverted ? 'disabled' : ''; ?>>
                    <?php if ($isConverted): ?>
                      <option value="converted" selected>Converted</option>
                    <?php else: ?>
                      <option value="new" <?php echo ($l['status'] ?? 'new') === 'new' ? 'selected' : ''; ?>>New</option>
                      <option value="accepted" <?php echo ($l['status'] ?? '') === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                      <option value="declined" <?php echo ($l['status'] ?? '') === 'declined' ? 'selected' : ''; ?>>Declined</option>
                    <?php endif; ?>
                  </select>
                </div>
                
                <!-- Card Message Collapsible -->
                <div id="grid-msg-<?php echo $l['id']; ?>" style="display:none; background:var(--clr-bg); padding:0.75rem; border-radius:var(--radius-sm); border:1px solid var(--clr-border-light); margin-top:0.75rem;">
                  <strong style="font-size:0.7rem;color:var(--clr-text-muted);text-transform:uppercase;display:block;margin-bottom:0.15rem;">Message</strong>
                  <p style="font-size:0.8rem; margin:0; line-height:1.4; color:var(--clr-text);"><?php echo nl2br(htmlspecialchars($l['message'] ?? 'No message')); ?></p>
                </div>
              </div>
            </div>
            <div class="grid-card-footer" style="margin-top: 1rem;">
              <button class="btn btn-icon btn-sm" onclick="toggleMessage(<?php echo $l['id']; ?>)" title="View message" style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; padding:0; border:1px solid var(--clr-border); background:var(--clr-surface); cursor:pointer; color:var(--clr-text-muted); border-radius:var(--radius-sm);">
                <i class="bi bi-chat-text"></i>
              </button>
              <?php if (!$isConverted): ?>
                <button class="btn btn-success btn-sm" onclick="convertLead(<?php echo $l['id']; ?>, '<?php echo htmlspecialchars(addslashes($l['name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($l['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($l['phone'] ?? ''), ENT_QUOTES); ?>')">
                  <i class="bi bi-person-plus"></i> Convert
                </button>
              <?php else: ?>
                <a href="index.php?page=client-profile&id=<?php echo $l['client_id']; ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:0.25rem;"><i class="bi bi-eye"></i> View Client</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div id="leads-grid-no-matches" style="display: none; grid-column: 1 / -1; text-align: center; padding: 3.5rem 1.5rem; color: var(--clr-text-muted); width: 100%;">
          <i class="bi bi-search" style="font-size: 2.25rem; display: block; margin-bottom: 0.5rem; opacity: 0.4;"></i>
          <p style="margin: 0; font-size: 0.9rem;">No leads match your search criteria</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleMessage(id) {
  var row = document.getElementById('msg-row-' + id);
  if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
  
  var gridMsg = document.getElementById('grid-msg-' + id);
  if (gridMsg) gridMsg.style.display = gridMsg.style.display === 'none' ? 'block' : 'none';
}

function updateLeadStatus(id, status) {
  const row = document.getElementById('lead-row-' + id);
  const card = document.getElementById('lead-card-' + id);
  const item = row || card;
  const oldStatus = item ? (item.getAttribute('data-status') || 'new') : 'new';

  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('id', id);
  fd.append('status', status);
  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Lead status updated');

        // Update data-status attributes
        if (row) row.setAttribute('data-status', status);
        if (card) card.setAttribute('data-status', status);

        // Update counter numbers in toolbar
        const oldCounter = document.getElementById('count-' + oldStatus);
        const newCounter = document.getElementById('count-' + status);
        if (oldCounter) {
          oldCounter.textContent = Math.max(0, parseInt(oldCounter.textContent) - 1);
        }
        if (newCounter) {
          newCounter.textContent = parseInt(newCounter.textContent) + 1;
        }

        // Hide item if it no longer matches the current filter tab
        const currentFilter = '<?php echo htmlspecialchars($statusFilter); ?>';
        if (currentFilter && currentFilter !== 'all' && currentFilter !== status) {
          if (row) {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-15px)';
            setTimeout(function() {
              row.style.display = 'none';
              const msgRow = document.getElementById('msg-row-' + id);
              if (msgRow) msgRow.style.display = 'none';
              checkEmptyState();
            }, 300);
          }
          if (card) {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95)';
            setTimeout(function() {
              card.style.display = 'none';
              checkEmptyState();
            }, 300);
          }
        }
      }
      else showToast(data.error || 'Error', 'error');
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function convertLead(id, name, email, phone) {
  if (!confirm('Convert "' + name + '" to a client?')) return;
  var fd = new FormData();
  fd.append('action', 'convert');
  fd.append('id', id);
  fd.append('name', name);
  fd.append('email', email);
  fd.append('phone', phone);
  
  // Fade out row/card immediately to feel fast
  const row = document.getElementById('lead-row-' + id);
  const card = document.getElementById('lead-card-' + id);
  if (row) {
    row.style.transition = 'opacity 0.3s ease';
    row.style.opacity = '0';
  }
  if (card) {
    card.style.transition = 'opacity 0.3s ease';
    card.style.opacity = '0';
  }

  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Lead converted to client!');
        setTimeout(function() { location.reload(); }, 800);
      } else {
        if (row) row.style.opacity = '1';
        if (card) card.style.opacity = '1';
        showToast(data.error || 'Error', 'error');
      }
    })
    .catch(function() { 
      if (row) row.style.opacity = '1';
      if (card) card.style.opacity = '1';
      showToast('Network error', 'error'); 
    });
}

function checkEmptyState() {
  const visibleRows = Array.from(document.querySelectorAll('tbody .lead-item')).filter(r => r.style.display !== 'none');
  const visibleCards = Array.from(document.querySelectorAll('.grid-view-container .lead-item')).filter(c => c.style.display !== 'none');
  if (visibleRows.length === 0 && visibleCards.length === 0) {
    location.reload();
  }
}

// View Mode Toggle Configuration
(function() {
  const page = 'leads';
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
      const items = document.querySelectorAll('.lead-item');
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
      const listNoMatch = document.getElementById('leads-no-matches');
      const gridNoMatch = document.getElementById('leads-grid-no-matches');
      
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
