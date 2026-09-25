<?php
require_once __DIR__ . '/../../includes/lead-repo.php';
require_once __DIR__ . '/../../includes/lead-status.php';

$db = getDbConnection();

$counts  = leadStatusCounts($db);
$sources = leadSources($db);

$filters = [
    'q'         => isset($_GET['q']) ? trim($_GET['q']) : '',
    'status'    => isset($_GET['status']) ? trim($_GET['status']) : '',
    'source'    => isset($_GET['source']) ? trim($_GET['source']) : '',
    'date_from' => isset($_GET['date_from']) ? trim($_GET['date_from']) : '',
    'date_to'   => isset($_GET['date_to']) ? trim($_GET['date_to']) : '',
    'sort'      => (isset($_GET['sort']) && $_GET['sort'] === 'status') ? 'status' : 'date',
    'dir'       => (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc',
];

// Land on New when there is anything new and the admin has not chosen a tab.
if (!isset($_GET['status']) && !isset($_GET['q']) && $counts['new'] > 0) {
    $filters['status'] = 'new';
}

$statusFilter = $filters['status'];
$search       = $filters['q'];
$leads        = fetchLeads($db, $filters);

/** Rebuild the current query string with one key changed — used by the tabs and sort links. */
function leadsUrl(array $filters, array $overrides = []) {
    $params = array_merge(['page' => 'leads'], $filters, $overrides);
    $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
    return 'index.php?' . http_build_query($params);
}
?>

<!-- Toolbar -->
<div class="toolbar">
  <div class="toolbar-left">
    <a href="<?php echo leadsUrl($filters, ['status' => 'all']); ?>" class="filter-btn <?php echo ($statusFilter === 'all' || $statusFilter === '') ? 'active' : ''; ?>">All (<span id="count-all"><?php echo $counts['all']; ?></span>)</a>
    <?php foreach (leadStatuses() as $s): ?>
      <a href="<?php echo leadsUrl($filters, ['status' => $s]); ?>" class="filter-btn <?php echo $statusFilter === $s ? 'active' : ''; ?>"><?php echo leadStatusLabel($s); ?> (<span id="count-<?php echo $s; ?>"><?php echo $counts[$s]; ?></span>)</a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <form method="get" action="index.php" class="search-bar">
      <input type="hidden" name="page" value="leads" />
      <i class="bi bi-search"></i>
      <input type="text" name="q" placeholder="Search name, email or phone..." value="<?php echo htmlspecialchars($search); ?>" />
    </form>
    <button class="btn btn-ghost btn-sm" id="toggle-lead-filters" type="button">
      <i class="bi bi-funnel"></i> Filters
    </button>
    <div class="view-toggle" data-page="leads">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
</div>

<!-- Filter bar — open by default when a filter is already applied, so a
     reloaded page never hides the reason it is showing fewer rows. -->
<form method="get" action="index.php" class="lead-filter-bar" id="lead-filter-bar" <?php echo ($filters['source'] === '' && $filters['date_from'] === '' && $filters['date_to'] === '') ? 'hidden' : ''; ?>>
  <input type="hidden" name="page" value="leads" />
  <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>" />
  <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>" />
  <label class="lead-filter-field">
    <span>Source</span>
    <select name="source" class="form-select-sm">
      <option value="">All sources</option>
      <?php foreach ($sources as $src): ?>
        <option value="<?php echo htmlspecialchars($src); ?>" <?php echo $filters['source'] === $src ? 'selected' : ''; ?>><?php echo htmlspecialchars($src); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="lead-filter-field">
    <span>From</span>
    <input type="date" name="date_from" class="form-input-sm" value="<?php echo htmlspecialchars($filters['date_from']); ?>" />
  </label>
  <label class="lead-filter-field">
    <span>To</span>
    <input type="date" name="date_to" class="form-input-sm" value="<?php echo htmlspecialchars($filters['date_to']); ?>" />
  </label>
  <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  <a href="<?php echo leadsUrl(['status' => $statusFilter]); ?>" class="btn btn-ghost btn-sm">Clear</a>
</form>

<!-- Bulk action bar — only visible while something is selected -->
<div class="bulk-bar" id="lead-bulk-bar" hidden>
  <span id="lead-bulk-count">0 selected</span>
  <select class="status-select" id="lead-bulk-status">
    <option value="">Change status to...</option>
    <?php foreach (leadStatuses() as $s): ?>
      <option value="<?php echo $s; ?>"><?php echo leadStatusLabel($s); ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary btn-sm" id="lead-bulk-apply" type="button">Apply</button>
  <button class="btn btn-ghost btn-sm" id="lead-bulk-export" type="button"><i class="bi bi-download"></i> Export CSV</button>
  <button class="btn btn-ghost btn-sm" id="lead-bulk-clear" type="button">Clear</button>
</div>

<!-- Leads Table -->
<div class="panel">
  <div class="panel-body-flush">
    <?php if (empty($leads)): ?>
      <div class="empty-state">
        <i class="bi bi-funnel"></i>
        <p>No leads found</p>
        <p>Leads will appear here when visitors submit the appointment or booking form.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="th-check"><input type="checkbox" id="lead-select-all" title="Select all" /></th>
              <?php
              // The header has to say which column is sorted and which way.
              // Both used to print the same neutral glyph whatever the state,
              // so the table was sorted and the header never admitted it.
              $sortIcon = function ($column) use ($filters) {
                  if ($filters['sort'] !== $column) { return 'bi-arrow-down-up'; }
                  return $filters['dir'] === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down';
              };
              $ariaSort = function ($column) use ($filters) {
                  if ($filters['sort'] !== $column) { return 'none'; }
                  return $filters['dir'] === 'asc' ? 'ascending' : 'descending';
              };
              ?>
              <th aria-sort="<?php echo $ariaSort('date'); ?>" data-sort-col="date">
                <a href="<?php echo leadsUrl($filters, ['sort' => 'date', 'dir' => ($filters['sort'] === 'date' && $filters['dir'] === 'desc') ? 'asc' : 'desc']); ?>"
                   class="th-sort <?php echo $filters['sort'] === 'date' ? 'is-sorted' : ''; ?>"
                   data-sort="date" data-dir="<?php echo ($filters['sort'] === 'date' && $filters['dir'] === 'desc') ? 'asc' : 'desc'; ?>">
                  Date <i class="bi <?php echo $sortIcon('date'); ?>"></i>
                </a>
              </th>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Pref. Date & Time</th>
              <th>Preference</th>
              <th aria-sort="<?php echo $ariaSort('status'); ?>" data-sort-col="status">
                <a href="<?php echo leadsUrl($filters, ['sort' => 'status', 'dir' => ($filters['sort'] === 'status' && $filters['dir'] === 'asc') ? 'desc' : 'asc']); ?>"
                   class="th-sort <?php echo $filters['sort'] === 'status' ? 'is-sorted' : ''; ?>"
                   data-sort="status" data-dir="<?php echo ($filters['sort'] === 'status' && $filters['dir'] === 'asc') ? 'desc' : 'asc'; ?>">
                  Status <i class="bi <?php echo $sortIcon('status'); ?>"></i>
                </a>
              </th>
              <th class="th-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            foreach ($leads as $l):
              $ls = ($l['status'] ?? '') ?: 'new';
              // A confirmed lead already has a client — pending, awaiting its
              // questionnaire — so client_id is no longer a proxy for 'done'.
              $hasClient = !empty($l['client_id']);
              $isClosed  = (leadStatusIsTerminal($ls) || $ls === 'converted');
            ?>
              <tr id="lead-row-<?php echo $l['id']; ?>" class="lead-item" data-created="<?php echo htmlspecialchars($l['created_at']); ?>" data-status="<?php echo htmlspecialchars($ls); ?>" data-name="<?php echo htmlspecialchars(strtolower($l['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($l['email'])); ?>">
                <td><input type="checkbox" class="lead-select" value="<?php echo $l['id']; ?>" /></td>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($l['created_at'])); ?></td>
                <td class="td-name"><a href="#" onclick="openLeadDrawer(<?php echo $l['id']; ?>); return false;"><?php echo htmlspecialchars($l['name']); ?></a></td>
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
                  <span class="badge <?php echo leadStatusBadgeClass($ls); ?>" data-lead-badge="<?php echo $l['id']; ?>"><?php echo leadStatusLabel($ls); ?></span>
                  <?php if (leadIsAging($l)): ?>
                    <span class="badge badge-aging" title="Untouched for more than <?php echo LEAD_AGING_HOURS; ?> hours"><i class="bi bi-exclamation-triangle-fill"></i> Aging</span>
                  <?php endif; ?>
                </td>
                <td class="td-actions">
                  <div class="row-actions">
                    <?php if ($hasClient): ?>
                      <a href="index.php?page=client-profile&id=<?php echo $l['client_id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View Client</a>
                    <?php endif; ?>
                    <?php if (!$isClosed): ?>
                      <button class="btn btn-success btn-sm" data-lead-contact="<?php echo $l['id']; ?>" onclick="updateLeadStatus(<?php echo $l['id']; ?>, 'contacted')" title="Mark as contacted" <?php echo $ls === 'new' ? '' : 'hidden'; ?>>
                        <i class="bi bi-telephone"></i> Contact
                      </button>
                      <button class="btn btn-primary btn-sm" data-lead-convert="<?php echo $l['id']; ?>" onclick="confirmLeadAction(<?php echo $l['id']; ?>, '<?php echo htmlspecialchars(addslashes($l['name']), ENT_QUOTES); ?>')" <?php echo ($ls === 'new' || $ls === 'contacted') ? '' : 'hidden'; ?>>
                        <i class="bi bi-send-check"></i> Accept
                      </button>
                      <button class="btn btn-danger btn-sm" data-lead-reject="<?php echo $l['id']; ?>" onclick="updateLeadStatus(<?php echo $l['id']; ?>, 'rejected')" title="Reject lead" <?php echo (leadStatusIsTerminal($ls) || $ls === 'converted') ? 'hidden' : ''; ?>>
                        <i class="bi bi-x-lg"></i> Reject
                      </button>
                    <?php endif; ?>
                    <button class="btn btn-icon" onclick="toggleMessage(<?php echo $l['id']; ?>)" title="View message">
                      <i class="bi bi-chat-text"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <tr id="msg-row-<?php echo $l['id']; ?>" style="display:none;">
                <!-- A token, not a fixed grey: a hardcoded near-white here is
                     a white band across the page in the dark theme. -->
                <td colspan="9" class="msg-cell">
                  <strong class="msg-label">Message</strong>
                  <p class="msg-body"><?php echo nl2br(htmlspecialchars($l['message'] ?? 'No message')); ?></p>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="leads-no-matches" class="no-matches" style="display:none;">
              <td colspan="9">
                <i class="bi bi-search"></i>
                <p>No leads match your search criteria</p>
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
          $ls = ($l['status'] ?? '') ?: 'new';
          $hasClient = !empty($l['client_id']);
          $isClosed  = (leadStatusIsTerminal($ls) || $ls === 'converted');
        ?>
          <div class="grid-card lead-item" id="lead-card-<?php echo $l['id']; ?>" data-created="<?php echo htmlspecialchars($l['created_at']); ?>" data-status="<?php echo htmlspecialchars($ls); ?>" data-name="<?php echo htmlspecialchars(strtolower($l['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($l['email'])); ?>">
            <div class="grid-card-inner">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <i class="bi bi-person-badge"></i>
                  <span><a href="#" onclick="openLeadDrawer(<?php echo $l['id']; ?>); return false;"><?php echo htmlspecialchars($l['name']); ?></a></span>
                </div>
                <div class="grid-card-date"><?php echo date('d M Y', strtotime($l['created_at'])); ?></div>
              </div>
              <div class="grid-card-body">
                <div class="grid-card-item" title="Email">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($l['email']); ?>"><?php echo htmlspecialchars($l['email']); ?></a>
                </div>
                <?php if ($l['phone']): ?>
                  <div class="grid-card-item" title="Phone">
                    <i class="bi bi-telephone"></i>
                    <span><?php echo htmlspecialchars(($l['country_code'] ?? '') . ' ' . $l['phone']); ?></span>
                  </div>
                <?php endif; ?>
                <?php if ($prefDateStr): ?>
                  <div class="grid-card-item" title="Preferred Date">
                    <i class="bi bi-calendar-event"></i>
                    <span>Pref: <?php echo $prefDateStr; ?></span>
                  </div>
                <?php endif; ?>
                <div class="grid-card-item">
                  <span class="grid-card-label"><i class="bi bi-camera-video"></i> Preference: <span class="badge badge-<?php echo $preference; ?>"><?php echo $preference ?: '-'; ?></span></span>
                </div>
                <div class="grid-card-item">
                  <i class="bi bi-flag"></i>
                  <span class="grid-card-label">Status:</span>
                  <span class="badge <?php echo leadStatusBadgeClass($ls); ?>" data-lead-badge="<?php echo $l['id']; ?>"><?php echo leadStatusLabel($ls); ?></span>
                  <?php if (leadIsAging($l)): ?>
                    <span class="badge badge-aging" title="Untouched for more than <?php echo LEAD_AGING_HOURS; ?> hours"><i class="bi bi-exclamation-triangle-fill"></i> Aging</span>
                  <?php endif; ?>
                </div>
                
                <!-- Card Message Collapsible -->
                <div id="grid-msg-<?php echo $l['id']; ?>" class="msg-panel" style="display:none;">
                  <strong class="msg-label">Message</strong>
                  <p class="msg-body"><?php echo nl2br(htmlspecialchars($l['message'] ?? 'No message')); ?></p>
                </div>
              </div>
            </div>
            <div class="grid-card-footer">
              <button class="btn btn-icon" onclick="toggleMessage(<?php echo $l['id']; ?>)" title="View message">
                <i class="bi bi-chat-text"></i>
              </button>
              <?php if ($hasClient): ?>
                <a href="index.php?page=client-profile&id=<?php echo $l['client_id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View Client</a>
              <?php endif; ?>
              <?php if (!$isClosed): ?>
                <div class="row-actions">
                  <button class="btn btn-success btn-sm" data-lead-contact="<?php echo $l['id']; ?>" onclick="updateLeadStatus(<?php echo $l['id']; ?>, 'contacted')" title="Mark as contacted" <?php echo $ls === 'new' ? '' : 'hidden'; ?>>
                    <i class="bi bi-telephone"></i> Contact
                  </button>
                  <button class="btn btn-primary btn-sm" data-lead-convert="<?php echo $l['id']; ?>" onclick="confirmLeadAction(<?php echo $l['id']; ?>, '<?php echo htmlspecialchars(addslashes($l['name']), ENT_QUOTES); ?>')" <?php echo ($ls === 'new' || $ls === 'contacted') ? '' : 'hidden'; ?>>
                    <i class="bi bi-send-check"></i> Accept
                  </button>
                  <button class="btn btn-danger btn-sm" data-lead-reject="<?php echo $l['id']; ?>" onclick="updateLeadStatus(<?php echo $l['id']; ?>, 'rejected')" title="Reject lead" <?php echo (leadStatusIsTerminal($ls) || $ls === 'converted') ? 'hidden' : ''; ?>>
                    <i class="bi bi-x-lg"></i> Reject
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div id="leads-grid-no-matches" class="no-matches-card" style="display:none;">
          <i class="bi bi-search"></i>
          <p>No leads match your search criteria</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="lead-drawer-backdrop" id="lead-drawer-backdrop" hidden></div>
<aside class="lead-drawer" id="lead-drawer" hidden aria-label="Lead detail">
  <header class="lead-drawer-header">
    <div>
      <h2 id="drawer-name">Lead</h2>
      <div class="lead-drawer-sub" id="drawer-meta"></div>
    </div>
    <button class="btn btn-icon btn-sm" id="drawer-close" title="Close"><i class="bi bi-x-lg"></i></button>
  </header>

  <div class="lead-drawer-body">
    <div class="lead-dup-banner" id="drawer-duplicate" hidden></div>

    <section class="lead-drawer-section">
      <h3>Actions</h3>
      <div class="lead-drawer-actions">
        <select class="status-select" id="drawer-status"></select>
        <button class="btn btn-primary btn-sm" id="drawer-confirm"><i class="bi bi-send-check"></i> Accept</button>
        <button class="btn btn-danger btn-sm" id="drawer-reject"><i class="bi bi-x-lg"></i> Reject</button>
      </div>
    </section>

    <section class="lead-drawer-section">
      <h3>Submitted answers</h3>
      <dl class="lead-answers" id="drawer-answers"></dl>
    </section>

    <section class="lead-drawer-section">
      <h3>Notes</h3>
      <form id="drawer-note-form">
        <textarea id="drawer-note-input" rows="2" placeholder="Add a note..." class="form-textarea"></textarea>
        <button type="submit" class="btn btn-primary btn-sm">Add note</button>
      </form>
      <ul class="lead-notes" id="drawer-notes"></ul>
    </section>

    <section class="lead-drawer-section">
      <h3>Activity</h3>
      <ul class="activity-list" id="drawer-timeline"></ul>
    </section>
  </div>
</aside>

<script>
function toggleMessage(id) {
  var row = document.getElementById('msg-row-' + id);
  if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
  
  var gridMsg = document.getElementById('grid-msg-' + id);
  if (gridMsg) gridMsg.style.display = gridMsg.style.display === 'none' ? 'block' : 'none';
}

var LEAD_STATUSES = ['new', 'contacted', 'confirmed', 'converted', 'rejected'];

// The labels PHP prints on first render, so a badge updated in the browser
// does not suddenly disagree with the one beside it.
var LEAD_STATUS_LABELS = <?php
    echo json_encode(array_combine(leadStatuses(), array_map('leadStatusLabel', leadStatuses())));
?>;

// Status is display-only; the action buttons are the sole way to change it.
// A lead has a row AND a card live at the same time, so update both.
//   new       -> Contact + Accept + Reject
//   contacted -> Accept + Reject
//   converted, rejected -> no status actions left
// Accept is offered on a new lead too: being contacted first is a thing that
// may or may not have happened, and clicking a step that did not happen is how
// a status stops describing anything real.
function syncLeadStatusUI(id, status) {
  document.querySelectorAll('[data-lead-badge="' + id + '"]').forEach(function(badge) {
    LEAD_STATUSES.forEach(function(s) { badge.classList.remove('badge-' + s); });
    badge.classList.add('badge-' + status);
    badge.textContent = LEAD_STATUS_LABELS[status] || status;
  });
  var closed = (status === 'rejected' || status === 'converted');

  document.querySelectorAll('[data-lead-contact="' + id + '"]').forEach(function(btn) {
    btn.hidden = (status !== 'new');
  });
  document.querySelectorAll('[data-lead-reject="' + id + '"]').forEach(function(btn) {
    btn.hidden = closed;
  });
  document.querySelectorAll('[data-lead-convert="' + id + '"]').forEach(function(btn) {
    btn.hidden = (status !== 'new' && status !== 'contacted');
  });
}

function updateLeadStatus(id, status) {
  const row = document.getElementById('lead-row-' + id);
  const card = document.getElementById('lead-card-' + id);
  const item = row || card;
  const oldStatus = item ? (item.getAttribute('data-status') || 'new') : 'new';

  if (oldStatus === status) return;

  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('id', id);
  fd.append('status', status);
  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast(status === 'contacted' ? 'Lead marked as contacted' : (status === 'rejected' ? 'Lead rejected' : 'Lead status updated'));

        // Update data-status attributes
        if (row) row.setAttribute('data-status', status);
        if (card) card.setAttribute('data-status', status);

        syncLeadStatusUI(id, status);

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
      else {
        // Roll the badge and buttons back — the server rejected the change.
        syncLeadStatusUI(id, oldStatus);
        showToast(data.error || 'Error', 'error');
      }
    })
    .catch(function() {
      syncLeadStatusUI(id, oldStatus);
      showToast('Network error', 'error');
    });
}

// Named confirmLeadAction, not confirmLead: window.confirm is what the dialog
// below calls, and shadowing it would break every other confirm on the page.
function confirmLeadAction(id, name) {
  // No dialog. The button says Accept, the toast says what happened, and the
  // lead's own row shows the new state a moment later -- a prompt in between
  // is a second click for a decision that was already made by the first.
  var fd = new FormData();
  fd.append('action', 'confirm');
  fd.append('id', id);
  
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
        if (data.already) {
          showToast('This lead was already accepted');
        } else if (data.intake_sending) {
          // The client, the token and the lead are all written by the time this
          // arrives; the email is still going out behind it. A send that fails
          // lands in the mail queue, which the dashboard counts.
          showToast('Accepted — sending the intake link');
        } else {
          showToast('Accepted');
        }
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

// ─── Detail drawer ───────────────────────────────────────────────────────
var DRAWER_LEAD_ID = null;

function openLeadDrawer(id) {
  var fd = new FormData();
  fd.append('action', 'detail');
  fd.append('id', id);

  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) { showToast(data.error || 'Error', 'error'); return; }
      DRAWER_LEAD_ID = id;
      renderLeadDrawer(data);
      document.getElementById('lead-drawer').hidden = false;
      document.getElementById('lead-drawer-backdrop').hidden = false;
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function closeLeadDrawer() {
  document.getElementById('lead-drawer').hidden = true;
  document.getElementById('lead-drawer-backdrop').hidden = true;
  DRAWER_LEAD_ID = null;
}

function renderLeadDrawer(data) {
  var lead = data.lead;
  document.getElementById('drawer-name').textContent = lead.name;
  document.getElementById('drawer-meta').textContent =
    lead.email + ' · ' + (lead.phone || 'no phone') + ' · via ' + lead.source;

  var dup = document.getElementById('drawer-duplicate');
  if (data.duplicate) {
    // textContent throughout: a duplicate's name is visitor-supplied, and the
    // banner is the one place two people's data meet on screen.
    dup.textContent = '';
    var icon = document.createElement('i');
    icon.className = 'bi bi-exclamation-triangle-fill';
    dup.appendChild(icon);
    dup.appendChild(document.createTextNode(
      ' Possible duplicate of ' + data.duplicate.name + ' (' + data.duplicate.email + ')' +
      (data.duplicate.is_client ? ' — already an existing client.' : '.')
    ));
    dup.hidden = false;
  } else {
    dup.hidden = true;
  }

  var sel = document.getElementById('drawer-status');
  sel.innerHTML = '';
  data.allowed_statuses.forEach(function(opt) {
    var o = document.createElement('option');
    o.value = opt.value;
    o.textContent = opt.label;
    if (opt.value === lead.status) o.selected = true;
    sel.appendChild(o);
  });

  var closed = (lead.status === 'rejected' || lead.status === 'converted');
  document.getElementById('drawer-confirm').hidden = (lead.status !== 'new' && lead.status !== 'contacted');
  document.getElementById('drawer-reject').hidden  = closed;

  var dl = document.getElementById('drawer-answers');
  dl.innerHTML = '';
  data.answers.forEach(function(a) {
    var dt = document.createElement('dt'); dt.textContent = a.label;
    var dd = document.createElement('dd'); dd.textContent = a.value;
    dl.appendChild(dt); dl.appendChild(dd);
  });

  renderDrawerNotes(data.notes);

  var tl = document.getElementById('drawer-timeline');
  tl.innerHTML = '';
  data.timeline.forEach(function(e) {
    var li = document.createElement('li');
    li.className = 'activity-item';
    li.innerHTML =
      '<div class="activity-icon ' + e.tone + '"><i class="bi ' + e.icon + '"></i></div>' +
      '<div><div class="activity-text"></div><div class="activity-time"></div></div>';
    li.querySelector('.activity-text').textContent = e.text + (e.author ? ' — ' + e.author : '');
    li.querySelector('.activity-time').textContent = e.at;
    tl.appendChild(li);
  });
}

function renderDrawerNotes(notes) {
  var ul = document.getElementById('drawer-notes');
  ul.innerHTML = '';
  notes.forEach(function(n) {
    var li = document.createElement('li');
    li.innerHTML = '<p class="lead-note-body"></p><span class="lead-note-meta"></span>';
    li.querySelector('.lead-note-body').textContent = n.content;
    li.querySelector('.lead-note-meta').textContent = n.author + ' · ' + n.created_at;
    ul.appendChild(li);
  });
}

(function() {
  document.getElementById('drawer-close').addEventListener('click', closeLeadDrawer);
  document.getElementById('lead-drawer-backdrop').addEventListener('click', closeLeadDrawer);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && DRAWER_LEAD_ID !== null) closeLeadDrawer();
  });

  document.getElementById('drawer-status').addEventListener('change', function() {
    updateLeadStatus(DRAWER_LEAD_ID, this.value);
  });
  document.getElementById('drawer-reject').addEventListener('click', function() {
    updateLeadStatus(DRAWER_LEAD_ID, 'rejected');
  });
  document.getElementById('drawer-confirm').addEventListener('click', function() {
    confirmLeadAction(DRAWER_LEAD_ID, document.getElementById('drawer-name').textContent);
  });

  document.getElementById('drawer-note-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var input = document.getElementById('drawer-note-input');
    if (!input.value.trim()) return;

    var fd = new FormData();
    fd.append('action', 'add_note');
    fd.append('id', DRAWER_LEAD_ID);
    fd.append('content', input.value);

    fetch('api/leads.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) { showToast(data.error || 'Error', 'error'); return; }
        input.value = '';
        renderDrawerNotes(data.notes);
        showToast('Note added');
      })
      .catch(function() { showToast('Network error', 'error'); });
  });
})();

// Bulk selection
(function() {
  var bar       = document.getElementById('lead-bulk-bar');
  var countEl   = document.getElementById('lead-bulk-count');
  var selectAll = document.getElementById('lead-select-all');
  if (!bar) return;

  function selected() {
    return Array.from(document.querySelectorAll('.lead-select:checked')).map(function(cb) { return cb.value; });
  }

  /** The rows a click on the header would actually sweep in: visible ones. */
  function selectable() {
    return Array.prototype.slice.call(document.querySelectorAll('.lead-select'))
      .filter(function (cb) {
        var row = cb.closest('tr');
        return !row || row.style.display !== 'none';
      });
  }

  function refresh() {
    var n = selected().length;
    countEl.textContent = n + ' selected';
    bar.hidden = (n === 0);

    if (!selectAll) return;

    // Three states, not two. A ticked header box while only four of twenty
    // rows are selected says "everything", and the next bulk action is taken
    // on that belief. The dash is the browser's own way of saying "some".
    var rows  = selectable();
    var picked = rows.filter(function (cb) { return cb.checked; }).length;

    selectAll.checked       = (rows.length > 0 && picked === rows.length);
    selectAll.indeterminate = (picked > 0 && picked < rows.length);
    selectAll.title = selectAll.checked ? 'Clear the selection'
                    : (selectAll.indeterminate ? picked + ' of ' + rows.length + ' selected'
                                               : 'Select all');
  }

  document.addEventListener('change', function(e) {
    if (e.target.classList && e.target.classList.contains('lead-select')) refresh();
  });

  // The search hides rows, and "all" means all the rows you can see -- so the
  // header has to be recomputed when the visible set changes, not only when a
  // box is ticked.
  window.refreshLeadSelection = refresh;

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      // Clicking the dash clears rather than selects: a partial selection is
      // one the admin built by hand, and the click that follows is far more
      // often "start again" than "take the other sixteen too".
      var want = selectAll.indeterminate ? false : selectAll.checked;

      // Only rows still visible after a search are swept in -- a hidden row is
      // not something the admin can see they are about to change.
      selectable().forEach(function (cb) { cb.checked = want; });
      refresh();
    });
  }

  document.getElementById('lead-bulk-clear').addEventListener('click', function() {
    document.querySelectorAll('.lead-select').forEach(function(cb) { cb.checked = false; });
    refresh();
  });

  document.getElementById('lead-bulk-apply').addEventListener('click', function() {
    var status = document.getElementById('lead-bulk-status').value;
    var ids    = selected();
    if (!status || !ids.length) return;
    if (!confirm('Move ' + ids.length + ' lead(s) to ' + status + '?')) return;

    var fd = new FormData();
    fd.append('action', 'bulk_status');
    fd.append('status', status);
    ids.forEach(function(id) { fd.append('ids[]', id); });

    fetch('api/leads.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) { showToast(data.error || 'Error', 'error'); return; }
        var msg = data.updated + ' updated';
        if (data.skipped.length) msg += ', ' + data.skipped.length + ' skipped';
        showToast(msg);
        setTimeout(function() { location.reload(); }, 700);
      })
      .catch(function() { showToast('Network error', 'error'); });
  });

  document.getElementById('lead-bulk-export').addEventListener('click', function() {
    window.location = 'api/leads-export.php?ids=' + encodeURIComponent(selected().join(','));
  });
})();

// Filter bar toggle
(function() {
  var toggle = document.getElementById('toggle-lead-filters');
  var bar    = document.getElementById('lead-filter-bar');
  if (toggle && bar) {
    toggle.addEventListener('click', function() { bar.hidden = !bar.hidden; });
  }
})();

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

      if (window.refreshLeadSelection) window.refreshLeadSelection();
    });

    if (searchInput.value) {
      searchInput.dispatchEvent(new Event('input'));
    }
  }
})();
</script>

<script>
/**
 * Sorting, done in the page.
 *
 * The column headers were plain links: every click threw away the whole page
 * and asked the server for the same rows in a different order, which lost the
 * scroll position, closed the open drawer and cleared any selection. Nothing
 * about the rows changes when they are sorted, so nothing needs to be fetched.
 *
 * The links keep their href, so this is an enhancement rather than a
 * replacement: without JavaScript the server still sorts, and the URL is kept
 * in step with history.replaceState so a refresh or a shared link lands on the
 * same order.
 */
(function () {
  var table = document.querySelector('.data-table');
  var tbody = table ? table.querySelector('tbody') : null;
  if (!tbody) return;

  // The same order the SQL uses. Alphabetical would read confirmed,
  // contacted, converted, new -- which describes nothing.
  var PIPELINE = ['new', 'contacted', 'confirmed', 'converted', 'rejected'];

  function keyOf(el, column) {
    if (column === 'status') {
      var at = PIPELINE.indexOf(el.dataset.status || '');
      return at === -1 ? PIPELINE.length : at;
    }
    return el.dataset.created || '';   // "YYYY-MM-DD HH:MM:SS" sorts as text
  }

  function compare(a, b, column, dir) {
    var ka = keyOf(a, column), kb = keyOf(b, column);
    if (ka === kb) {
      // A stable second key, so two leads with the same status keep a
      // predictable order instead of shuffling on every click.
      ka = a.dataset.created; kb = b.dataset.created;
      return ka < kb ? 1 : (ka > kb ? -1 : 0);
    }
    var out = ka < kb ? -1 : 1;
    return dir === 'asc' ? out : -out;
  }

  function sortBy(column, dir) {
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.lead-item'));
    rows.sort(function (a, b) { return compare(a, b, column, dir); });
    rows.forEach(function (row) {
      tbody.appendChild(row);
      // Each lead has a hidden message row directly after it. Moving the lead
      // without it would leave the message attached to whichever lead landed
      // in that slot -- the wrong message under the wrong name.
      var msg = document.getElementById('msg-row-' + row.id.replace('lead-row-', ''));
      if (msg) tbody.appendChild(msg);
    });

    var cards = document.querySelector('.grid-view-container');
    if (cards) {
      Array.prototype.slice.call(cards.querySelectorAll('.lead-item'))
        .sort(function (a, b) { return compare(a, b, column, dir); })
        .forEach(function (card) { cards.appendChild(card); });
    }

    markHeaders(column, dir);
    rememberInUrl(column, dir);
  }

  /** The header says what it did: which column, which way, next click's way. */
  function markHeaders(column, dir) {
    table.querySelectorAll('th[data-sort-col]').forEach(function (th) {
      var link   = th.querySelector('.th-sort');
      var isThis = th.dataset.sortCol === column;
      var icon   = link.querySelector('i');

      th.setAttribute('aria-sort', isThis ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
      link.classList.toggle('is-sorted', isThis);
      icon.className = 'bi ' + (isThis ? (dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down')
                                       : 'bi-arrow-down-up');
      // The next click on this header flips it; on the other one it starts
      // from the direction that column defaults to.
      link.dataset.dir = isThis ? (dir === 'asc' ? 'desc' : 'asc')
                                : (th.dataset.sortCol === 'date' ? 'desc' : 'asc');
    });
  }

  function rememberInUrl(column, dir) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', column);
    url.searchParams.set('dir', dir);
    history.replaceState(null, '', url.toString());
  }

  table.querySelectorAll('.th-sort').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      sortBy(link.dataset.sort, link.dataset.dir);
    });
  });
})();
</script>
