<?php
require_once __DIR__ . '/../../includes/intake-data.php';
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/client-notes.php';
require_once __DIR__ . '/../../includes/client-payments.php';
require_once __DIR__ . '/../../includes/client-documents.php';
require_once __DIR__ . '/../../includes/session-repo.php';
require_once __DIR__ . '/../../includes/booking-slots.php';

$db = getDbConnection();
$clientId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$clientId) {
    echo '<div class="empty-state"><i class="bi bi-exclamation-circle"></i><p>Client not found</p></div>';
    return;
}

$stmt = $db->prepare("SELECT * FROM `clients` WHERE `id` = :id");
$stmt->execute([':id' => $clientId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    echo '<div class="empty-state"><i class="bi bi-exclamation-circle"></i><p>Client not found</p></div>';
    return;
}

$pageTitle = $client['first_name'] . ' ' . $client['last_name'];

// Fetch related data
$sessions = $db->prepare("SELECT * FROM `sessions` WHERE `client_id`=:cid ORDER BY `start_time` DESC");
$sessions->execute([':cid'=>$clientId]);
$sessions = $sessions->fetchAll(PDO::FETCH_ASSOC);

$notes         = clientNotes($db, $clientId);
$correctedIds  = correctedNoteIds($db, $clientId);
$documents     = clientDocuments($db, $clientId);

// Other non-archived clients, for the merge picker.
$noShowRun = consecutiveNoShows($db, $clientId);

$mergeCandidates = array_values(array_filter(fetchClients($db, []), function ($o) use ($clientId) {
    return (int) $o['id'] !== (int) $clientId;
}));

$fees = $db->prepare("SELECT * FROM `client_fees` WHERE `client_id`=:cid ORDER BY `fee_date` DESC");
$fees->execute([':cid'=>$clientId]);
$fees = $fees->fetchAll(PDO::FETCH_ASSOC);

$totalFees = 0; $paidFees = 0; $pendingFees = 0;
foreach ($fees as $f) {
    $totalFees += $f['amount'];
    if ($f['status'] === 'paid') $paidFees += $f['amount'];
    if ($f['status'] === 'pending') $pendingFees += $f['amount'];
}

// Check matching patient intakes by email AND phone
$piStmt = $db->prepare("SELECT * FROM `patient-intake` WHERE `email` = :email AND `phone` = :phone ORDER BY `created_at` ASC");
$piStmt->execute([':email' => $client['email'], ':phone' => $client['phone']]);
$intakes = $piStmt->fetchAll(PDO::FETCH_ASSOC);

$latestIntake = !empty($intakes) ? $intakes[count($intakes) - 1] : null;

// The canonical intake: encrypted JSON on the client, rendered against the
// form version it was actually filled under.
$intakeRecord   = readClientIntakeData($db, $clientId);
$intakeSections = $intakeRecord ? renderClientIntake($db, $clientId) : [];

$intakeScore = null;
if ($latestIntake) {
    $q1s = 0; $q2s = 0;
    for ($i=1;$i<=18;$i++) { if (strtolower($latestIntake["q1_{$i}"]??'') === 'yes') $q1s++; }
    for ($i=1;$i<=18;$i++) { if (strtolower($latestIntake["q2_{$i}"]??'') === 'yes') $q2s++; }
    $intakeScore = ['q1'=>$q1s,'q2'=>$q2s,'total'=>$q1s+$q2s];
}

// Fallback values if client profile doesn't have them but matched intake does
$clientCity = $client['city'] ?: ($latestIntake ? $latestIntake['city'] : '');
$clientOccupation = $client['occupation'] ?: ($latestIntake ? $latestIntake['occupation'] : '');
$clientDob = $client['dob'] ?: ($latestIntake ? $latestIntake['dob'] : '');
$clientConcern = $client['concern'] ?: ($latestIntake ? $latestIntake['concern'] : '');

// Next session
$nextSession = $db->prepare("SELECT * FROM `sessions` WHERE `client_id`=:cid AND `start_time` >= NOW() AND `status` IN ('pending','confirmed') ORDER BY `start_time` ASC LIMIT 1");
$nextSession->execute([':cid'=>$clientId]);
$nextSession = $nextSession->fetch(PDO::FETCH_ASSOC);

$initials = strtoupper(substr($client['first_name'],0,1) . substr($client['last_name'],0,1));
?>

<!-- Back link -->
<a href="index.php?page=clients" class="back-link">
  <i class="bi bi-arrow-left"></i> Back to Clients
</a>

<!-- Profile Header -->
<div class="profile-header">
  <div class="profile-avatar"><?php echo $initials; ?></div>
  <div>
    <div class="profile-name"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></div>
    <div class="profile-meta">
      <span><?php echo htmlspecialchars($client['email']); ?></span>
      <span><?php echo htmlspecialchars($client['phone'] ?? ''); ?></span>
      <span><span class="badge badge-<?php echo $client['status']; ?>"><?php echo $client['status']; ?></span></span>
    </div>
  </div>
</div>

<?php if ($noShowRun >= 2): ?>
  <!-- A visibility nudge, never an automatic action. What to do about a run of
       no-shows is a clinical judgement, not something a CRM should decide. -->
  <div class="bulk-bar is-danger">
    <i class="bi bi-person-x"></i>
    <?php echo (int) $noShowRun; ?> sessions in a row marked no-show. Worth a conversation before booking the next one.
  </div>
<?php endif; ?>

<?php if ($client['status'] === 'review'): ?>
  <div class="bulk-bar is-warning">
    <i class="bi bi-clipboard-check"></i>
    Intake submitted and waiting for your review. This client is not bookable yet.
    <button class="btn btn-primary btn-sm push-right" onclick="markReviewed(<?php echo (int) $client['id']; ?>)">Mark reviewed</button>
  </div>
<?php endif; ?>

<!-- Tabs -->
<div class="profile-tabs">
  <button class="profile-tab active" onclick="showProfileTab('overview')">Overview</button>
  <button class="profile-tab" onclick="showProfileTab('sessions')">Sessions (<?php echo count($sessions); ?>)</button>
  <button class="profile-tab" onclick="showProfileTab('notes')">Notes (<?php echo count($notes); ?>)</button>
  <button class="profile-tab" onclick="showProfileTab('fees')">Fees (₹<?php echo number_format($totalFees,2); ?>)</button>
  <button class="profile-tab" onclick="showProfileTab('intake')">Intake Data</button>
  <button class="profile-tab" onclick="showProfileTab('profile')">Profile</button>
  <button class="profile-tab" onclick="showProfileTab('documents')">Documents (<?php echo count($documents); ?>)</button>
</div>

<!-- Overview Tab -->
<div class="profile-tab-content active" id="tab-overview">
  <div class="split-grid">
    <!-- Client Info -->
    <div class="panel">
      <div class="panel-header"><div class="panel-title">Client Information</div></div>
      <div class="panel-body">
        <div class="detail-grid split-grid">
          <div><div class="detail-label">City</div><div class="detail-value"><?php echo htmlspecialchars($clientCity ?: '-'); ?></div></div>
          <div><div class="detail-label">Occupation</div><div class="detail-value"><?php echo htmlspecialchars($clientOccupation ?: '-'); ?></div></div>
          <div><div class="detail-label">Date of Birth</div><div class="detail-value"><?php echo $clientDob ? date('d M Y', strtotime($clientDob)) : '-'; ?></div></div>
          <div><div class="detail-label">Primary Concern</div><div class="detail-value"><?php echo htmlspecialchars($clientConcern ?: '-'); ?></div></div>
          <div><div class="detail-label">Client Since</div><div class="detail-value"><?php echo date('d M Y', strtotime($client['created_at'])); ?></div></div>
          <div>
            <div class="detail-label">Status</div>
            <div class="detail-value">
              <select class="status-select" onchange="updateClientStatus(<?php echo $clientId; ?>, this.value)">
                <?php foreach (clientStatuses() as $cs): ?>
                  <option value="<?php echo $cs; ?>" <?php echo $client['status'] === $cs ? 'selected' : ''; ?>><?php echo clientStatusLabel($cs); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!clientIsBookable($client['status'])): ?>
                <div class="hint is-block">Not bookable while <?php echo strtolower(clientStatusLabel($client['status'])); ?>.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Stats -->
    <div>
      <?php if ($nextSession): ?>
        <div class="stat-strip-card">
          <div class="stat-strip-icon teal"><i class="bi bi-calendar-event"></i></div>
          <div>
            <div class="detail-label">Next Session</div>
            <div class="detail-value"><?php echo date('d M Y, h:i A', strtotime($nextSession['start_time'])); ?></div>
            <div class="hint"><?php echo $nextSession['session_type']; ?> · <?php echo round((strtotime($nextSession['end_time']) - strtotime($nextSession['start_time'])) / 60); ?> min</div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($intakeScore): ?>
        <div class="panel">
          <div class="panel-header"><div class="panel-title">Intake Assessment Score</div></div>
          <div class="panel-body">
            <?php
            $ts = $intakeScore['total'];
            $tcls = $ts >= 24 ? 'high' : ($ts >= 12 ? 'mid' : 'low');
            ?>
            <div class="stat-tile is-banner">
              <div class="score-text is-hero <?php echo $tcls; ?>"><?php echo $ts; ?>/36</div>
              <div class="score-bar"><div class="score-bar-fill <?php echo $tcls; ?>" style="width:<?php echo round(($ts/36)*100); ?>%;"></div></div>
            </div>
            <div class="split-grid stat-tile">
              <div><div class="detail-label">Q1 Self-Awareness</div><div class="score-text <?php echo $intakeScore['q1']>=12?'high':($intakeScore['q1']>=6?'mid':'low'); ?>"><?php echo $intakeScore['q1']; ?>/18</div></div>
              <div><div class="detail-label">Q2 Well-being</div><div class="score-text <?php echo $intakeScore['q2']>=12?'high':($intakeScore['q2']>=6?'mid':'low'); ?>"><?php echo $intakeScore['q2']; ?>/18</div></div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($intakes)): ?>
        <div class="panel">
          <div class="panel-header"><div class="panel-title">Intake History</div></div>
          <div class="panel-body panel-body-flush">
            <div class="data-table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Intake</th>
                    <th>Date</th>
                    <th class="th-right">Score</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $attemptNum = 1;
                  foreach ($intakes as $intakeRow):
                    $iq1 = 0; $iq2 = 0;
                    for ($i=1;$i<=18;$i++) { if (strtolower($intakeRow["q1_{$i}"]??'') === 'yes') $iq1++; }
                    for ($i=1;$i<=18;$i++) { if (strtolower($intakeRow["q2_{$i}"]??'') === 'yes') $iq2++; }
                    $itotal = $iq1 + $iq2;
                    $itcls = $itotal >= 24 ? 'high' : ($itotal >= 12 ? 'mid' : 'low');
                  ?>
                    <tr>
                      <td class="td-name">Session <?php echo $attemptNum++; ?></td>
                      <td class="td-muted"><?php echo date('d M Y', strtotime($intakeRow['created_at'])); ?></td>
                      <td class="td-actions">
                        <span class="score-text <?php echo $itcls; ?>"><?php echo $itotal; ?>/36</span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-body">
          <div class="split-grid stat-tile">
            <div>
              <div class="detail-label">Total Sessions</div>
              <div class="stat-tile-value"><?php echo count($sessions); ?></div>
            </div>
            <div>
              <div class="detail-label">Total Fees</div>
              <div class="stat-tile-value">₹<?php echo number_format($totalFees,2); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Sessions Tab -->
<div class="profile-tab-content" id="tab-sessions">
  <div class="subsection-head">
    <h3 class="subsection-title">Session History</h3>
    <button class="btn btn-primary btn-sm" onclick="openSessionModal()"><i class="bi bi-plus"></i> Add Session</button>
  </div>
  <div class="panel">
    <div class="panel-body-flush">
      <?php if (empty($sessions)): ?>
        <div class="empty-state">
          <i class="bi bi-calendar3"></i>
          <p>No sessions recorded</p>
        </div>
      <?php else: ?>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Time</th><th>Duration</th><th>Type</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr>
                  <td class="td-nowrap"><?php echo date('d M Y', strtotime($s['start_time'])); ?></td>
                  <td class="td-nowrap"><?php echo date('h:i A', strtotime($s['start_time'])); ?></td>
                  <td><?php echo round((strtotime($s['end_time']) - strtotime($s['start_time'])) / 60); ?> min</td>
                  <td><span class="badge badge-<?php echo $s['session_type']; ?>"><?php echo $s['session_type']; ?></span></td>
                  <td>
                    <select class="status-select" aria-label="Session status" onchange="updateSessionStatus(<?php echo $s['id']; ?>, this.value)">
                      <option value="pending" <?php echo $s['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                      <option value="confirmed" <?php echo $s['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                      <option value="completed" <?php echo $s['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                      <option value="cancelled" <?php echo $s['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                  </td>
                  <td class="td-muted td-clip"><?php echo htmlspecialchars($s['notes'] ?? '-'); ?></td>
                  <td class="td-nowrap">
                    <button class="btn btn-icon" onclick="openRescheduleModal(<?php echo $s['id']; ?>, '<?php echo date('Y-m-d', strtotime($s['start_time'])); ?>', '<?php echo date('H:i', strtotime($s['start_time'])); ?>')" title="Reschedule"><i class="bi bi-pencil-square"></i></button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Notes Tab -->
<div class="profile-tab-content" id="tab-notes">
  <div class="subsection-head">
    <h3 class="subsection-title">Client Notes</h3>
    <button class="btn btn-primary btn-sm" onclick="openNoteModal()"><i class="bi bi-plus"></i> Add Note</button>
  </div>
  <?php if (empty($notes)): ?>
    <div class="panel"><div class="empty-state"><i class="bi bi-sticky"></i><p>No notes yet</p></div></div>
  <?php else: ?>
    <?php foreach ($notes as $n): ?>
      <div class="panel">
        <div class="panel-body">
          <div class="subsection-head">
            <span>
              <span class="badge <?php echo $n['note_kind'] === 'session' ? 'badge-scheduled' : 'badge-archived'; ?>"><?php echo $n['note_kind']; ?></span>
              <?php if (in_array((int) $n['id'], $correctedIds, true)): ?>
                <!-- Superseded, not replaced. The original stays readable
                     because it is what was believed at the time. -->
                <span class="badge badge-inactive" title="A later entry corrects this one">Corrected</span>
              <?php endif; ?>
              <?php if ($n['corrects_note_id']): ?>
                <span class="badge badge-intake-filled">Correction of #<?php echo (int) $n['corrects_note_id']; ?></span>
              <?php endif; ?>
            </span>
            <span class="hint">
              <?php echo htmlspecialchars($n['author']); ?> &middot; <?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?>
            </span>
          </div>
          <p class="note-body"><?php echo nl2br(htmlspecialchars($n['content'])); ?></p>
          <div class="form-actions">
            <button class="btn btn-ghost btn-sm" onclick="openNoteModal(<?php echo (int) $n['id']; ?>)">Add a correction</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Fees Tab -->
<div class="profile-tab-content" id="tab-fees">
  <div class="subsection-head">
    <h3 class="subsection-title">Fees</h3>
    <div class="row-actions">
      <!-- Sent by hand, one client at a time. Chasing money on a schedule is a
           decision about a relationship, not a cron job. Offered only when
           something is actually outstanding: the commonest way to lose
           somebody's trust over money is to ask for money they do not owe. -->
      <?php if ($pendingFees > 0): ?>
        <button class="btn btn-ghost btn-sm" id="fee-remind"
                onclick="sendFeeReminder(<?php echo (int) $client['id']; ?>)">
          <i class="bi bi-envelope"></i> Send payment reminder
        </button>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" onclick="openFeeModal()"><i class="bi bi-plus"></i> Add Fee</button>
    </div>
  </div>
  <!-- Fee Summary -->
  <div class="stat-row">
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Total</div><div class="stat-tile-value">₹<?php echo number_format($totalFees,2); ?></div></div></div>
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Paid</div><div class="stat-tile-value is-paid">₹<?php echo number_format($paidFees,2); ?></div></div></div>
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Pending</div><div class="stat-tile-value is-pending">₹<?php echo number_format($pendingFees,2); ?></div><?php if ($pendingFees > 0): ?><div class="hint">What a reminder would ask for</div><?php endif; ?></div></div>
  </div>
  <div class="panel">
    <div class="panel-body-flush">
      <?php if (empty($fees)): ?>
        <div class="empty-state"><i class="bi bi-currency-rupee"></i><p>No fee records</p></div>
      <?php else: ?>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Description</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($fees as $f): ?>
                <tr>
                  <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($f['fee_date'])); ?></td>
                  <td><?php echo htmlspecialchars($f['description'] ?? '-'); ?></td>
                  <td class="td-muted"><?php echo $f['status'] === 'paid' ? htmlspecialchars(clientPaymentMethodLabel($f['method'] ?? 'other')) : '—'; ?></td>
                  <td class="td-muted"><?php echo htmlspecialchars(($f['reference'] ?? '') !== '' ? $f['reference'] : '—'); ?></td>
                  <td class="td-name">₹<?php echo number_format($f['amount'],2); ?></td>
                  <td><span class="badge badge-<?php echo $f['status']; ?>"><?php echo $f['status']; ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Add Session Modal -->
<div class="modal-overlay" id="sessionModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Schedule Session</div>
      <button class="modal-close" onclick="document.getElementById('sessionModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitSession(event)">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="prof-session-date">Date</label><input type="date" class="form-input" id="prof-session-date" name="session_date" required /></div>
          <div class="form-group">
            <label class="form-label" for="prof-session-time">Time</label>
            <!-- Same slots the public forms offer; see includes/booking-slots.php. -->
            <select class="form-select" id="prof-session-time" name="session_time" required>
              <option value="" disabled selected hidden>Select a time</option>
              <?php foreach (bookingSlots() as $slot): ?>
                <option value="<?php echo htmlspecialchars($slot); ?>"><?php echo htmlspecialchars(bookingSlotLabel($slot)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Duration (min)</label><input type="number" class="form-input" name="duration" value="60" min="15" step="15" /></div>
          <div class="form-group"><label class="form-label">Type</label><select class="form-select" name="session_type"><option value="online">Online</option><option value="inperson">In-Person</option></select></div>
          <div class="form-group">
            <label class="form-label">Repeat</label>
            <select class="form-select" name="repeat" id="repeat-select-prof">
              <option value="none">Does not repeat</option>
              <option value="weekly">Weekly</option>
              <option value="fortnightly">Fortnightly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div class="form-group" id="repeat-end-prof" hidden>
            <label class="form-label">Until</label>
            <div class="form-inline">
              <select class="form-select form-grow" name="repeat_end_type">
                <option value="count">After N sessions</option>
                <option value="date">On a date</option>
                <option value="open">Keep going (I will stop it)</option>
              </select>
              <input class="form-input form-grow" name="repeat_end_value" value="4" />
            </div>
            <small class="hint">Occurrences that clash with an existing session are skipped, not booked.</small>
          </div>

        </div>
        <div class="form-group"><label class="form-label">Notes</label><textarea class="form-textarea" name="notes" placeholder="Session notes..."></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="document.getElementById('sessionModal').classList.remove('open')">Cancel</button><button type="submit" class="btn btn-primary">Schedule</button></div>
    </form>
  </div>
</div>

<!-- Intake Data Tab -->
<div class="profile-tab-content" id="tab-intake">
  <?php if (!$intakeRecord): ?>
    <div class="empty-state">
      <i class="bi bi-clipboard-x"></i>
      <p>No intake form on file</p>
      <p>It appears here once the questionnaire is submitted.</p>
    </div>
  <?php else: ?>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          Intake responses
          <?php if ($intakeRecord['version'] < 2): ?>
            <!-- Only older forms are tagged. Labelling the current one would
                 add noise to every record for the sake of the few that differ. -->
            <span class="badge badge-archived" title="Answered under an older version of the form">Form v<?php echo (int) $intakeRecord['version']; ?></span>
          <?php endif; ?>
        </div>
        <span class="hint">
          Submitted <?php echo $intakeRecord['submitted_at'] ? date('d M Y, H:i', strtotime($intakeRecord['submitted_at'])) : 'unknown'; ?>
        </span>
      </div>
      <div class="panel-body">
        <?php foreach ($intakeSections as $section): ?>
          <h3 class="intake-section-title"><?php echo htmlspecialchars($section['title']); ?></h3>
          <dl class="lead-answers">
            <?php foreach ($section['answers'] as $a): ?>
              <dt><?php echo htmlspecialchars($a['label']); ?></dt>
              <dd><?php echo nl2br(htmlspecialchars($a['value'])); ?></dd>
            <?php endforeach; ?>
          </dl>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Profile Tab -->
<div class="profile-tab-content" id="tab-profile">
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Contact details</div></div>
    <div class="panel-body">
      <form onsubmit="submitProfile(event)">
        <div class="split-grid">
          <div class="form-group"><label class="form-label" for="p-first">First name</label>
            <input class="form-input" id="p-first" name="first_name" value="<?php echo htmlspecialchars($client['first_name']); ?>" required /></div>
          <div class="form-group"><label class="form-label" for="p-last">Last name</label>
            <input class="form-input" id="p-last" name="last_name" value="<?php echo htmlspecialchars($client['last_name']); ?>" required /></div>
          <div class="form-group"><label class="form-label" for="p-email">Email</label>
            <input class="form-input" id="p-email" name="email" type="email" value="<?php echo htmlspecialchars($client['email']); ?>" required /></div>
          <div class="form-group"><label class="form-label" for="p-phone">Phone</label>
            <input class="form-input" id="p-phone" name="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" /></div>
          <div class="form-group"><label class="form-label" for="p-city">City</label>
            <input class="form-input" id="p-city" name="city" value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>" /></div>
          <div class="form-group"><label class="form-label" for="p-occ">Occupation</label>
            <input class="form-input" id="p-occ" name="occupation" value="<?php echo htmlspecialchars($client['occupation'] ?? ''); ?>" /></div>
          <div class="form-group"><label class="form-label" for="p-dob">Date of birth</label>
            <input class="form-input" id="p-dob" name="dob" type="date" value="<?php echo htmlspecialchars($client['dob'] ?? ''); ?>" /></div>
          <div class="form-group"><label class="form-label" for="p-concern">Primary concern</label>
            <input class="form-input" id="p-concern" name="concern" value="<?php echo htmlspecialchars($client['concern'] ?? ''); ?>" /></div>
        </div>
        <button type="submit" class="btn btn-primary">Save changes</button>
        <span class="hint">Status is changed on the Overview tab, so every move is logged.</span>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><div class="panel-title">Merge a duplicate into this record</div></div>
    <div class="panel-body">
      <?php if (empty($mergeCandidates)): ?>
        <p class="prose">There is no other client to merge.</p>
      <?php else: ?>
        <form onsubmit="submitMerge(event)" class="form-inline">
          <div class="form-group is-grow">
            <label class="form-label" for="merge-loser">Record to absorb</label>
            <select class="form-select" id="merge-loser">
              <option value="">Choose a client...</option>
              <?php foreach ($mergeCandidates as $mc): ?>
                <option value="<?php echo (int) $mc['id']; ?>">
                  <?php echo htmlspecialchars($mc['first_name'] . ' ' . $mc['last_name'] . ' — ' . $mc['email']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="hint">Their sessions, notes, documents and payments move here. Nothing is deleted: the other record is archived and points back to this one.</small>
          </div>
          <button type="submit" class="btn btn-primary">Merge</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><div class="panel-title">Archive</div></div>
    <div class="panel-body">
      <p class="prose">
        Archiving hides this client from every list and count. The record, its notes and its
        documents all stay in the database — there is no hard delete.
      </p>
      <button class="btn btn-danger" onclick="archiveClientRecord()"><i class="bi bi-archive"></i> Archive this client</button>
    </div>
  </div>
</div>

<!-- Documents Tab -->
<div class="profile-tab-content" id="tab-documents">
  <div class="panel">
    <div class="panel-body">
      <form id="doc-upload-form" enctype="multipart/form-data" class="form-inline">
        <input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>" />
        <div class="form-group is-grow">
          <label class="form-label" for="doc-file">Add a document</label>
          <input class="form-input" type="file" id="doc-file" name="document" required />
          <small class="hint">
            Up to <?php echo getSettingInt('upload_max_mb', 10); ?> MB.
            Accepted: <?php echo htmlspecialchars(implode(', ', documentAllowedExtensions())); ?>.
          </small>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel-body-flush">
      <div id="doc-list">
        <?php if (empty($documents)): ?>
          <div class="empty-state"><i class="bi bi-folder2-open"></i><p>No documents yet</p></div>
        <?php else: ?>
          <div class="data-table-wrap">
            <table class="data-table">
              <thead><tr><th>File</th><th>Uploaded</th><th>By</th><th>Size</th><th class="th-right">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($documents as $d): ?>
                  <tr>
                    <td class="td-name"><?php echo htmlspecialchars($d['original_name']); ?></td>
                    <td class="td-nowrap td-muted"><?php echo date('d M Y, H:i', strtotime($d['uploaded_at'])); ?></td>
                    <td class="td-muted"><?php echo htmlspecialchars($d['uploader']); ?></td>
                    <td class="td-nowrap td-muted"><?php echo number_format($d['size_bytes'] / 1024, 0); ?> KB</td>
                    <td class="td-actions">
                      <!-- Never a static URL: document.php checks the session
                           and logs the download before sending a byte. -->
                      <a class="btn btn-ghost btn-sm" href="document.php?id=<?php echo (int) $d['id']; ?>"><i class="bi bi-download"></i> Download</a>
                      <button class="btn btn-danger btn-sm" onclick="archiveDocument(<?php echo (int) $d['id']; ?>)"><i class="bi bi-x-lg"></i></button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal-overlay" id="noteModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Add Note</div>
      <button class="modal-close" onclick="document.getElementById('noteModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitNote(event)">
      <div class="modal-body">
        <input type="hidden" name="corrects_note_id" id="note-corrects" value="" />
        <p id="note-correcting-hint" class="hint" hidden>
          This is saved as a new entry correcting the earlier one. The original stays on file.
        </p>
        <div class="form-group">
          <label class="form-label">Kind</label>
          <select class="form-select" name="note_kind" id="note-kind">
            <option value="session">Session note</option>
            <option value="administrative">Administrative</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Content</label><textarea class="form-textarea" name="content" required placeholder="Write your note..." rows="5"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="document.getElementById('noteModal').classList.remove('open')">Cancel</button><button type="submit" class="btn btn-primary">Save Note</button></div>
    </form>
  </div>
</div>

<!-- Add Fee Modal -->
<div class="modal-overlay" id="feeModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Add Fee</div>
      <button class="modal-close" onclick="document.getElementById('feeModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitFee(event)">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" class="form-input" name="amount" step="0.01" min="0" required /></div>
          <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-input" name="fee_date" required /></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><input type="text" class="form-input" name="description" placeholder="e.g., Session fee, Assessment fee..." /></div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="fee-status">Status</label>
            <select class="form-select" id="fee-status" name="status">
              <option value="pending">Pending</option>
              <option value="paid">Paid</option>
              <option value="waived">Waived</option>
            </select>
          </div>
          <!-- How it was paid, and the reference for it. These lived on a
               second tab over the same table; a fee and a payment were never
               two things. Shown only once the fee is marked paid, because an
               unpaid fee has no method yet. -->
          <div class="form-group" id="fee-method-group" hidden>
            <label class="form-label" for="fee-method">Method</label>
            <select class="form-select" id="fee-method" name="method">
              <?php foreach (clientPaymentMethods() as $m): ?>
                <option value="<?php echo $m; ?>"><?php echo clientPaymentMethodLabel($m); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group" id="fee-reference-group" hidden>
          <label class="form-label" for="fee-reference">Reference</label>
          <input type="text" class="form-input" id="fee-reference" name="reference" placeholder="UPI ref, cheque no..." />
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="document.getElementById('feeModal').classList.remove('open')">Cancel</button><button type="submit" class="btn btn-primary">Save Fee</button></div>
    </form>
  </div>
</div>

<!-- Reschedule Session Modal -->
<?php
// session id -> series id for this client's sessions, so the tab knows which
// ones need the scope question.
$seriesMap = [];
$smStmt = $db->prepare('SELECT `id`, `recurring_series_id` FROM `sessions`
                        WHERE `client_id` = :c AND `recurring_series_id` IS NOT NULL');
$smStmt->execute([':c' => $clientId]);
foreach ($smStmt as $srow) {
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
var clientId = <?php echo $clientId; ?>;

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

/**
 * Book a session for this client.
 *
 * The form called this by name and nothing defined it, so every submit threw a
 * ReferenceError -- which stops preventDefault() ever running. The form then
 * did what a form with no action does: posted itself to the current URL and
 * navigated away. That is the redirect to the dashboard, and it is why nothing
 * was ever saved: the booking never reached the API at all.
 */
function submitSession(e) {
  e.preventDefault();

  var form = e.target;
  var btn  = form.querySelector('button[type="submit"]');
  var label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Scheduling...'; }

  var fd = new FormData(form);
  fd.append('action', 'add_session');
  // The client is the page, not a field on it -- there is no picker here.
  fd.append('client_id', clientId);

  fetch('api/sessions.php', { method: 'POST', body: fd })
    .then(function (r) { return r.text(); })
    .then(function (text) {
      var d;
      try {
        d = JSON.parse(text);
      } catch (err) {
        // A PHP warning printed ahead of the JSON turns a booking that worked
        // into a silent failure. Show what came back instead of "Error".
        throw new Error('Unexpected server response: ' + text.slice(0, 160));
      }
      if (!d.success) { throw new Error(d.error || 'The session could not be booked.'); }

      var msg = d.booked > 1 ? (d.booked + ' sessions scheduled') : 'Session scheduled';
      if (d.skipped && d.skipped.length) {
        msg += ' — ' + d.skipped.length + ' skipped (already booked)';
      }
      showToast(msg);
      setTimeout(function () { location.reload(); }, 600);
    })
    .catch(function (err) {
      showToast(err.message || 'Network error', 'error');
      if (btn) { btn.disabled = false; btn.textContent = label; }
    });
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

function markReviewed(id) {
  var fd = new FormData();
  fd.append('action', 'mark_reviewed');
  fd.append('client_id', id);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Client marked reviewed and is now active');
      setTimeout(function() { location.reload(); }, 700);
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function showProfileTab(tab) {
  document.querySelectorAll('.profile-tab-content').forEach(function(el) { el.classList.remove('active'); });
  document.querySelectorAll('.profile-tab').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('tab-' + tab).classList.add('active');
  event.target.classList.add('active');
}

function openSessionModal() {
  // Default the date to today, so the commonest booking needs one less field.
  var date = document.getElementById('prof-session-date');
  if (date && !date.value) { date.value = new Date().toISOString().slice(0, 10); }
  document.getElementById('sessionModal').classList.add('open');
}

/**
 * Add Fee was broken the same way Schedule Session was: the button named a
 * function that did not exist, so the click threw and the modal never opened.
 * Found while fixing the other one -- same page, same defect.
 */
function openFeeModal() {
  var date = document.querySelector('#feeModal input[name="fee_date"]');
  if (date && !date.value) { date.value = new Date().toISOString().slice(0, 10); }
  document.getElementById('feeModal').classList.add('open');
}
function openNoteModal(correctsId) {
  var field = document.getElementById('note-corrects');
  var hint  = document.getElementById('note-correcting-hint');
  var kind  = document.getElementById('note-kind');

  field.value = correctsId ? correctsId : '';
  hint.hidden = !correctsId;
  // A correction is administrative by default: it is a record about a record.
  if (correctsId) { kind.value = 'administrative'; }

  document.getElementById('noteModal').classList.add('open');
}

function submitNote(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'add_note');
  fd.append('client_id', clientId);
  var isCorrection = !!fd.get('corrects_note_id');
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast(isCorrection ? 'Correction added' : 'Note added');
        setTimeout(function(){ location.reload(); }, 600);
      } else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function() { showToast('Network error','error'); });
}

function submitProfile(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'update_profile');
  fd.append('client_id', clientId);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) { showToast('Profile saved'); setTimeout(function(){ location.reload(); }, 600); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function(){ showToast('Network error','error'); });
}

(function() {
  var form = document.getElementById('doc-upload-form');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(form);
    fd.append('action', 'upload');
    fetch('api/client-documents.php', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.success) { showToast('Document uploaded'); setTimeout(function(){ location.reload(); }, 600); }
        else { showToast(d.error || 'Error', 'error'); }
      })
      .catch(function(){ showToast('Network error','error'); });
  });
})();

function archiveDocument(id) {
  if (!confirm('Remove this document from the client record?')) return;
  var fd = new FormData();
  fd.append('action', 'archive');
  fd.append('document_id', id);
  fetch('api/client-documents.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) { showToast('Document removed'); setTimeout(function(){ location.reload(); }, 600); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function(){ showToast('Network error','error'); });
}

// Method and reference only mean something once a fee is paid, so the modal
// asks for them only then. An unpaid fee has no method yet.
(function () {
  var status = document.getElementById('fee-status');
  if (!status) { return; }
  function sync() {
    var paid = status.value === 'paid';
    document.getElementById('fee-method-group').hidden = !paid;
    document.getElementById('fee-reference-group').hidden = !paid;
  }
  status.addEventListener('change', sync);
  sync();
})();

function sendFeeReminder(id) {
  var btn = document.getElementById('fee-remind');
  if (!confirm('Email this client their outstanding balance?')) { return; }

  // Disabled while it is in flight: a second click is a second email, and the
  // recipient cannot tell it was an accident.
  if (btn) { btn.disabled = true; }

  var fd = new FormData();
  fd.append('action', 'send_fee_reminder');
  fd.append('client_id', id);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success) { showToast('Reminder sent for ₹' + d.amount); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function () { showToast('Network error', 'error'); })
    .then(function () { if (btn) { btn.disabled = false; } });
}

function archiveClientRecord() {
  if (!confirm('Archive this client? The record is kept, but it disappears from every list.')) return;
  var fd = new FormData();
  fd.append('action', 'archive');
  fd.append('client_id', clientId);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) { showToast('Client archived'); setTimeout(function(){ location.href='index.php?page=clients'; }, 700); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function(){ showToast('Network error','error'); });
}

function submitMerge(e) {
  e.preventDefault();
  var loser = document.getElementById('merge-loser').value;
  if (!loser) return;
  if (!confirm('Merge that record into this one? Their sessions, notes, documents and payments move here, and the other record is archived.')) return;

  var fd = new FormData();
  fd.append('action', 'merge');
  fd.append('survivor_id', clientId);
  fd.append('loser_id', loser);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Merged: ' + d.moved.sessions + ' session(s), ' + d.moved.notes + ' note(s), '
                + d.moved.payments + ' payment(s), ' + d.moved.documents + ' document(s)');
      setTimeout(function(){ location.reload(); }, 900);
    })
    .catch(function(){ showToast('Network error','error'); });
}

function submitFee(e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('action', 'add_fee');
  fd.append('client_id', clientId);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) { if(d.success){showToast('Fee recorded');setTimeout(function(){location.reload();},600);}else{showToast(d.error||'Error','error');} })
    .catch(function() { showToast('Network error','error'); });
}

function updateClientStatus(id, status) {
  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('client_id', id);
  fd.append('status', status);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) { if(d.success) showToast('Status updated'); else showToast(d.error||'Error','error'); })
    .catch(function() { showToast('Network error','error'); });
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(m) {
  m.addEventListener('click', function(e) {
    if (e.target === m) m.classList.remove('open');
  });
});

(function() {
  var sel = document.getElementById('repeat-select-prof');
  var end = document.getElementById('repeat-end-prof');
  if (!sel || !end) return;
  sel.addEventListener('change', function() { end.hidden = (sel.value === 'none'); });
})();
</script>
