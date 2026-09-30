<?php
require_once __DIR__ . '/../../includes/intake-data.php';
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/client-notes.php';
require_once __DIR__ . '/../../includes/client-payments.php';
require_once __DIR__ . '/../../includes/client-documents.php';
require_once __DIR__ . '/../../includes/session-repo.php';
require_once __DIR__ . '/../../includes/booking-slots.php';
require_once __DIR__ . '/../../includes/form-builder.php';

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
$documents         = clientDocuments($db, $clientId);
$clientDocBytes    = clientTotalDocumentBytes($db, $clientId, true);
$totalDocBytes     = clientTotalDocumentBytes($db, $clientId, false);
$maxTotalDocBytes  = clientMaxTotalDocumentBytes();
$clientUsedDocMb   = round($clientDocBytes / (1024 * 1024), 1);
$totalUsedDocMb    = round($totalDocBytes / (1024 * 1024), 1);
$maxDocMb          = (int) round($maxTotalDocBytes / (1024 * 1024));
$clientRemDocMb    = max(0, round(($maxTotalDocBytes - $clientDocBytes) / (1024 * 1024), 1));
$usedDocMb         = $clientUsedDocMb;
$remDocMb          = $clientRemDocMb;

// Other non-archived clients, for the merge picker.
$noShowRun = consecutiveNoShows($db, $clientId);

$mergeCandidates = array_values(array_filter(fetchClients($db, []), function ($o) use ($clientId) {
    return (int) $o['id'] !== (int) $clientId;
}));

$fees = $db->prepare("SELECT * FROM `client_fees` WHERE `client_id`=:cid ORDER BY `fee_date` DESC");
$fees->execute([':cid'=>$clientId]);
$fees = $fees->fetchAll(PDO::FETCH_ASSOC);

$totalFees = 0; $paidFees = 0; $pendingFees = 0; $waivedFees = 0;
foreach ($fees as $f) {
    if ($f['status'] === 'waived') {
        $waivedFees += (float) $f['amount'];
        continue;
    }
    $totalFees += (float) $f['amount'];
    if ($f['status'] === 'paid') $paidFees += (float) $f['amount'];
    if ($f['status'] === 'pending') $pendingFees += (float) $f['amount'];
}

// Client payment reports from portal awaiting verification
$cprStmt = $db->prepare("SELECT * FROM `client_payment_reports` WHERE `client_id` = :cid AND `status` = 'pending' ORDER BY `created_at` DESC");
$cprStmt->execute([':cid' => $clientId]);
$pendingPaymentReports = $cprStmt->fetchAll(PDO::FETCH_ASSOC);

// Check matching patient intakes by client_id OR email
$clientEmail = trim($client['email'] ?? '');
if ($clientEmail !== '') {
    $piStmt = $db->prepare("SELECT * FROM `patient-intake` WHERE `client_id` = :cid OR `email` = :email ORDER BY `created_at` ASC");
    $piStmt->execute([':cid' => $clientId, ':email' => $clientEmail]);
} else {
    $piStmt = $db->prepare("SELECT * FROM `patient-intake` WHERE `client_id` = :cid ORDER BY `created_at` ASC");
    $piStmt->execute([':cid' => $clientId]);
}
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

// How many times this client has actually sent a completed questionnaire
// back, across every named form variant they were ever asked to fill.
$submittedFormsStmt = $db->prepare('SELECT COUNT(*) FROM `intake_links` WHERE `client_id` = :id AND `status` = "submitted"');
$submittedFormsStmt->execute([':id' => $clientId]);
$submittedFormsCount = (int) $submittedFormsStmt->fetchColumn();

// Client Portal connection data
$otpStmt = $db->prepare('SELECT `used_at` FROM `client_otps` WHERE `client_id` = :id AND `used_at` IS NOT NULL ORDER BY `used_at` DESC LIMIT 1');
$otpStmt->execute([':id' => $clientId]);
$lastPortalLogin = $otpStmt->fetchColumn();

$pendingReqStmt = $db->prepare('SELECT * FROM `sessions` WHERE `client_id` = :id AND `status` = "pending" AND `start_time` >= NOW() ORDER BY `start_time` ASC LIMIT 1');
$pendingReqStmt->execute([':id' => $clientId]);
$pendingSessionReq = $pendingReqStmt->fetch(PDO::FETCH_ASSOC);

$clientUploadedDocCount = count(array_filter($documents, function($d) { return !empty($d['client_uploaded']); }));

$formTemplatesAvailable = formTemplates($db);
?>

<!-- Back link -->
<a href="index.php?page=clients" class="back-link">
  <i class="bi bi-arrow-left"></i> Back to Clients
</a>

<!-- Profile Header -->
<div class="profile-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
  <div style="display:flex; align-items:center; gap:1.25rem;">
    <div class="profile-avatar"><?php echo $initials; ?></div>
    <div>
      <div class="profile-name"><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></div>
      <div class="profile-meta">
        <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($client['email']); ?></span>
        <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($client['phone'] ?? ''); ?></span>
        <span><span class="badge badge-<?php echo $client['status']; ?>"><?php echo $client['status']; ?></span></span>
      </div>
    </div>
  </div>
  <div class="profile-header-actions" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
    <button type="button" class="btn btn-secondary btn-sm" onclick="launchPortal(<?php echo (int) $client['id']; ?>)">
      <i class="bi bi-box-arrow-up-right"></i> View Client Portal
    </button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="sendPortalInvite(<?php echo (int) $client['id']; ?>)">
      <i class="bi bi-send"></i> Send Portal Invite
    </button>
  </div>
</div>

<?php if ($pendingSessionReq): ?>
  <div class="bulk-bar is-warning" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
    <div>
      <i class="bi bi-calendar-event"></i>
      <strong>Pending Session Request:</strong> Client requested a session on <strong><?php echo date('D, d M Y \a\t h:i A', strtotime($pendingSessionReq['start_time'])); ?></strong> (<?php echo ucfirst($pendingSessionReq['session_type']); ?>).
    </div>
    <div style="display:flex; gap:0.5rem;">
      <button class="btn btn-primary btn-sm" onclick="confirmSession(<?php echo (int) $pendingSessionReq['id']; ?>)">
        <i class="bi bi-check-lg"></i> Confirm Session
      </button>
      <button class="btn btn-ghost btn-sm" onclick="openRescheduleModal(<?php echo (int) $pendingSessionReq['id']; ?>, '<?php echo date('Y-m-d', strtotime($pendingSessionReq['start_time'])); ?>', '<?php echo date('H:i', strtotime($pendingSessionReq['start_time'])); ?>')">
        <i class="bi bi-pencil-square"></i> Reschedule
      </button>
    </div>
  </div>
<?php endif; ?>

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
  <button class="profile-tab active" data-tab="overview" onclick="showProfileTab('overview')">Overview</button>
  <button class="profile-tab" data-tab="sessions" onclick="showProfileTab('sessions')">Sessions (<?php echo count($sessions); ?>)</button>
  <button class="profile-tab" data-tab="notes" onclick="showProfileTab('notes')">Notes (<?php echo count($notes); ?>)</button>
  <button class="profile-tab" data-tab="fees" onclick="showProfileTab('fees')">Fees (₹<?php echo number_format($totalFees,2); ?>)</button>
  <button class="profile-tab" data-tab="intake" onclick="showProfileTab('intake')">Intake Data (<?php echo $submittedFormsCount; ?>)</button>
  <button class="profile-tab" data-tab="profile" onclick="showProfileTab('profile')">Profile</button>
  <button class="profile-tab" data-tab="documents" onclick="showProfileTab('documents')">Documents (<?php echo count($documents); ?>)</button>
</div>

<!-- Overview Tab -->
<style>
.status-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; padding-top:16px; border-top:1px solid var(--border, #e5e7eb); }
.status-btn { color:#fff; border:0; }
.status-btn-inactive { background:#b45309; }
.status-btn-inactive:hover { background:#92400e; }
.status-btn-completed { background:#15803d; }
.status-btn-completed:hover { background:#166534; }
.intake-row-btn { cursor:pointer; }
</style>
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
              <span class="badge badge-<?php echo $client['status']; ?>"><?php echo htmlspecialchars(clientStatusLabel($client['status'])); ?></span>
              <?php if (!clientIsBookable($client['status'])): ?>
                <div class="hint is-block">Not bookable while <?php echo strtolower(clientStatusLabel($client['status'])); ?>.</div>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div class="detail-label">Preferred Session Mode</div>
            <div class="detail-value">
              <?php if (!empty($client['pref_mode'])): ?>
                <span class="badge badge-<?php echo strtolower($client['pref_mode']) === 'online' ? 'online' : 'inperson'; ?>">
                  <i class="bi <?php echo strtolower($client['pref_mode']) === 'online' ? 'bi-camera-video' : 'bi-building'; ?>"></i> <?php echo htmlspecialchars($client['pref_mode']); ?>
                </span>
              <?php else: ?>
                <span class="td-muted">No preference</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div class="detail-label">Preferred Times / Days</div>
            <div class="detail-value">
              <?php if (!empty($client['pref_times'])): ?>
                <span><?php echo htmlspecialchars(str_replace(',', ', ', ucwords($client['pref_times']))); ?></span>
              <?php else: ?>
                <span class="td-muted">Flexible</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="status-actions">
          <?php foreach (['inactive' => 'Inactive', 'completed' => 'Completed'] as $k => $lbl): if ($client['status'] === $k) continue; ?>
            <button type="button" class="btn btn-sm status-btn status-btn-<?php echo $k; ?>" onclick="setClientStatus('<?php echo $k; ?>', '<?php echo $lbl; ?>')">Mark <?php echo $lbl; ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Quick Stats & Assessment Column -->
    <div>
      <?php if ($nextSession): ?>
        <div class="stat-strip-card" style="margin-bottom: 1rem;">
          <div class="stat-strip-icon teal"><i class="bi bi-calendar-event"></i></div>
          <div>
            <div class="detail-label">Next Session</div>
            <div class="detail-value"><?php echo date('d M Y, h:i A', strtotime($nextSession['start_time'])); ?></div>
            <div class="hint"><?php echo ucfirst($nextSession['session_type']); ?> · <?php echo round((strtotime($nextSession['end_time']) - strtotime($nextSession['start_time'])) / 60); ?> min</div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Redesigned Intake Assessment Section -->
      <div class="panel intake-assessment-panel">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
          <div class="panel-title" style="display:flex; align-items:center; gap:0.5rem;">
            <i class="bi bi-clipboard2-pulse" style="color:var(--clr-primary);"></i>
            <span>Intake Assessment</span>
          </div>
          <?php if ($latestIntake): ?>
            <span class="badge badge-completed"><i class="bi bi-check2"></i> Completed</span>
          <?php else: ?>
            <span class="badge badge-pending">Pending</span>
          <?php endif; ?>
        </div>
        <div class="panel-body">
          <?php if ($intakeScore && $latestIntake):
            $ts = $intakeScore['total'];
            $pct = round(($ts / 36) * 100);
            $tcls = $ts >= 24 ? 'high' : ($ts >= 12 ? 'mid' : 'low');
            $tierLabel = $ts >= 24 ? 'High Support Need' : ($ts >= 12 ? 'Moderate Support' : 'Mild / Preventive');
            $tierColor = $ts >= 24 ? 'var(--clr-danger)' : ($ts >= 12 ? 'var(--clr-warning-text)' : 'var(--clr-success)');
            $tierBg = $ts >= 24 ? 'var(--clr-danger-light)' : ($ts >= 12 ? 'var(--clr-warning-light)' : 'var(--clr-success-light)');
          ?>
            <div class="intake-score-hero-card">
              <div class="score-hero-left">
                <div class="score-hero-number-wrap">
                  <span class="score-hero-val" style="color: <?php echo $tierColor; ?>;"><?php echo $ts; ?></span>
                  <span class="score-hero-max">/36</span>
                </div>
                <div class="score-hero-meta">
                  <span class="score-tier-pill" style="background: <?php echo $tierBg; ?>; color: <?php echo $tierColor; ?>;">
                    <?php echo $tierLabel; ?>
                  </span>
                  <span class="hint" style="margin-top: 4px; display: block;">
                    Completed <?php echo date('d M Y', strtotime($latestIntake['created_at'])); ?>
                  </span>
                </div>
              </div>
              <button type="button" class="btn btn-ghost btn-sm" onclick="openIntakeDrawer(<?php echo (int) $latestIntake['id']; ?>)">
                <i class="bi bi-eye"></i> View Form
              </button>
            </div>

            <!-- Score Progress Bar -->
            <div class="intake-meter-bar">
              <div class="intake-meter-fill <?php echo $tcls; ?>" style="width: <?php echo $pct; ?>%;"></div>
            </div>

            <!-- Subscores Breakdown -->
            <div class="intake-subscores-grid">
              <div class="intake-subscore-box">
                <div class="subscore-head">
                  <span class="subscore-title"><i class="bi bi-person-bounding-box"></i> Q1 Self-Awareness</span>
                  <span class="subscore-num"><?php echo $intakeScore['q1']; ?> <small>/ 18</small></span>
                </div>
                <div class="intake-submeter">
                  <div class="intake-submeter-fill" style="width: <?php echo round(($intakeScore['q1'] / 18) * 100); ?>%;"></div>
                </div>
              </div>

              <div class="intake-subscore-box">
                <div class="subscore-head">
                  <span class="subscore-title"><i class="bi bi-heart-pulse"></i> Q2 Well-Being</span>
                  <span class="subscore-num"><?php echo $intakeScore['q2']; ?> <small>/ 18</small></span>
                </div>
                <div class="intake-submeter">
                  <div class="intake-submeter-fill submeter-teal" style="width: <?php echo round(($intakeScore['q2'] / 18) * 100); ?>%;"></div>
                </div>
              </div>
            </div>

          <?php else: ?>
            <div class="empty-state" style="padding: 1.5rem 1rem;">
              <i class="bi bi-clipboard2-x" style="font-size: 2rem; color: var(--clr-text-muted);"></i>
              <p style="margin: 0.5rem 0; font-size: 0.9rem; color: var(--clr-text-secondary);">No assessment submitted yet.</p>
              <?php if (!empty($client['lead_id'])): ?>
                <button class="btn btn-primary btn-sm" onclick="openResendIntakeModal()" style="margin-top: 0.5rem;">
                  <i class="bi bi-send"></i> Send Intake Link
                </button>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($intakes)): ?>
        <div class="panel" style="margin-top: 1rem;">
          <div class="panel-header"><div class="panel-title">Assessment History</div></div>
          <div class="panel-body panel-body-flush">
            <div class="data-table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Form</th>
                    <th>Date</th>
                    <th class="th-right">Score</th>
                    <th class="th-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $attemptNum = 1;
                  foreach (array_reverse($intakes) as $intakeRow):
                    $iq1 = 0; $iq2 = 0;
                    for ($i=1;$i<=18;$i++) { if (strtolower($intakeRow["q1_{$i}"]??'') === 'yes') $iq1++; }
                    for ($i=1;$i<=18;$i++) { if (strtolower($intakeRow["q2_{$i}"]??'') === 'yes') $iq2++; }
                    $itotal = $iq1 + $iq2;
                    $itcls = $itotal >= 24 ? 'high' : ($itotal >= 12 ? 'mid' : 'low');
                  ?>
                    <tr>
                      <td class="td-name">Intake <?php echo !empty($intakeRow['form_version']) ? 'v' . (int)$intakeRow['form_version'] : '#' . $attemptNum++; ?></td>
                      <td class="td-muted"><?php echo date('d M Y', strtotime($intakeRow['created_at'])); ?></td>
                      <td class="td-actions">
                        <span class="score-text <?php echo $itcls; ?>"><?php echo $itotal; ?>/36</span>
                      </td>
                      <td class="td-actions">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="openIntakeDrawer(<?php echo (int) $intakeRow['id']; ?>)">
                          <i class="bi bi-eye"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="panel" style="margin-top: 1rem;">
        <div class="panel-body">
          <div class="split-grid stat-tile">
            <div>
              <div class="detail-label">Total Sessions</div>
              <div class="stat-tile-value"><?php echo count($sessions); ?></div>
            </div>
            <div>
              <div class="detail-label">Balance Due</div>
              <div class="stat-tile-value <?php echo $pendingFees > 0 ? 'is-pending' : 'is-paid'; ?>">₹<?php echo number_format($pendingFees, 2); ?></div>
              <?php if ($paidFees > 0): ?>
                <div class="hint" style="margin-top: 2px;">₹<?php echo number_format($paidFees, 2); ?> paid</div>
              <?php endif; ?>
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
                  <td class="td-nowrap">
                    <span class="badge badge-session-<?php echo htmlspecialchars($s['status']); ?>"><?php echo htmlspecialchars(sessionStatusLabel($s['status'])); ?></span>
                  </td>
                  <td class="td-muted td-clip"><?php echo htmlspecialchars($s['notes'] ?? '-'); ?></td>
                  <td class="td-nowrap td-actions">
                    <div style="display:inline-flex; gap:0.35rem; align-items:center;">
                      <?php if ($s['status'] === 'pending'): ?>
                        <button type="button" class="btn btn-sm btn-success" onclick="updateSessionStatus(<?php echo (int) $s['id']; ?>, 'confirmed')" title="Confirm Session">
                          <i class="bi bi-check-lg"></i> Confirm
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="updateSessionStatus(<?php echo (int) $s['id']; ?>, 'completed')" title="Complete Session">
                          <i class="bi bi-check2-all"></i> Complete
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="updateSessionStatus(<?php echo (int) $s['id']; ?>, 'cancelled')" title="Cancel Session">
                          <i class="bi bi-x-lg"></i> Cancel
                        </button>
                      <?php elseif ($s['status'] === 'confirmed'): ?>
                        <button type="button" class="btn btn-sm btn-primary" onclick="updateSessionStatus(<?php echo (int) $s['id']; ?>, 'completed')" title="Complete Session">
                          <i class="bi bi-check2-all"></i> Complete
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="updateSessionStatus(<?php echo (int) $s['id']; ?>, 'cancelled')" title="Cancel Session">
                          <i class="bi bi-x-lg"></i> Cancel
                        </button>
                      <?php else: ?>
                        <span class="td-muted">—</span>
                      <?php endif; ?>
                    </div>
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
    <h3 class="subsection-title">Fees &amp; Billing</h3>
    <div class="row-actions">
      <?php if ($pendingFees > 0): ?>
        <button class="btn btn-ghost btn-sm" id="fee-remind"
                onclick="sendFeeReminder(<?php echo (int) $client['id']; ?>)">
          <i class="bi bi-envelope"></i> Send payment reminder
        </button>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" onclick="openFeeModal()"><i class="bi bi-plus"></i> Add Fee</button>
    </div>
  </div>

  <?php if (!empty($pendingPaymentReports)): ?>
    <!-- Client Portal Reported Payments Alert -->
    <div class="panel" style="border-left: 4px solid var(--clr-primary); margin-bottom: 1.25rem;">
      <div class="panel-header" style="background: rgba(13, 115, 119, 0.05); padding: 0.85rem 1.25rem;">
        <div class="panel-title" style="display:flex; align-items:center; gap:0.5rem; color: var(--clr-primary); font-size: 0.95rem;">
          <i class="bi bi-bell-fill"></i> Client Reported Payment Awaiting Verification
        </div>
      </div>
      <div class="panel-body" style="padding: 0.85rem 1.25rem;">
        <?php foreach ($pendingPaymentReports as $cpr): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; padding: 0.5rem 0;">
            <div>
              <strong style="font-size:1.05rem;">₹<?php echo number_format($cpr['amount'], 2); ?></strong>
              <span class="badge badge-session-confirmed" style="margin-left: 6px;"><?php echo htmlspecialchars(strtoupper($cpr['method'])); ?></span>
              <span class="hint" style="margin-left: 8px;">
                Paid on <?php echo date('d M Y', strtotime($cpr['paid_on'])); ?>
                <?php if (!empty($cpr['reference'])): ?> · Ref: <code><?php echo htmlspecialchars($cpr['reference']); ?></code><?php endif; ?>
              </span>
            </div>
            <div style="display:flex; gap:0.4rem;">
              <button type="button" class="btn btn-sm btn-success" onclick="verifyPaymentReport(<?php echo (int) $cpr['id']; ?>)">
                <i class="bi bi-check-lg"></i> Approve &amp; Mark Paid
              </button>
              <button type="button" class="btn btn-sm btn-ghost" onclick="rejectPaymentReport(<?php echo (int) $cpr['id']; ?>)">
                <i class="bi bi-x-lg"></i> Dismiss
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Fee Summary -->
  <div class="stat-row">
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Total Billed</div><div class="stat-tile-value">₹<?php echo number_format($totalFees,2); ?></div></div></div>
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Paid</div><div class="stat-tile-value is-paid">₹<?php echo number_format($paidFees,2); ?></div></div></div>
    <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Balance Due</div><div class="stat-tile-value is-pending">₹<?php echo number_format($pendingFees,2); ?></div></div></div>
    <?php if ($waivedFees > 0): ?>
      <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Waived</div><div class="stat-tile-value" style="color:var(--clr-text-muted);">₹<?php echo number_format($waivedFees,2); ?></div></div></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-body-flush">
      <?php if (empty($fees)): ?>
        <div class="empty-state"><i class="bi bi-currency-rupee"></i><p>No fee records</p></div>
      <?php else: ?>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Amount</th>
                <th>Status</th>
                <th class="th-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fees as $f): ?>
                <tr>
                  <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($f['fee_date'])); ?></td>
                  <td><?php echo htmlspecialchars($f['description'] ?? 'Session Fee'); ?></td>
                  <td class="td-muted"><?php echo $f['status'] === 'paid' ? htmlspecialchars(clientPaymentMethodLabel($f['method'] ?? 'other')) : '—'; ?></td>
                  <td class="td-muted"><?php echo htmlspecialchars(($f['reference'] ?? '') !== '' ? $f['reference'] : '—'); ?></td>
                  <td class="td-name">₹<?php echo number_format($f['amount'],2); ?></td>
                  <td>
                    <?php if ($f['status'] === 'paid'): ?>
                      <span class="badge badge-completed"><i class="bi bi-check-circle"></i> Paid</span>
                    <?php elseif ($f['status'] === 'pending'): ?>
                      <span class="badge badge-pending"><i class="bi bi-hourglass-split"></i> Pending</span>
                    <?php else: ?>
                      <span class="badge badge-archived">Waived</span>
                    <?php endif; ?>
                  </td>
                  <td class="td-nowrap td-actions">
                    <div style="display:inline-flex; gap:0.35rem; align-items:center;">
                      <?php if ($f['status'] === 'pending'): ?>
                        <button type="button" class="btn btn-sm btn-success" onclick="openRecordPaymentModal(<?php echo (int) $f['id']; ?>, <?php echo (float) $f['amount']; ?>, '<?php echo htmlspecialchars(addslashes($f['description'] ?? 'Session Fee')); ?>')" title="Record Payment">
                          <i class="bi bi-credit-card-2-front"></i> Mark Paid
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="waiveFee(<?php echo (int) $f['id']; ?>, <?php echo (float) $f['amount']; ?>)" title="Waive this fee">
                          <i class="bi bi-slash-circle"></i> Waive
                        </button>
                        <button type="button" class="btn btn-icon btn-sm" onclick="deleteFee(<?php echo (int) $f['id']; ?>)" title="Delete fee" style="color:var(--clr-danger);">
                          <i class="bi bi-trash3"></i>
                        </button>
                      <?php elseif ($f['status'] === 'paid'): ?>
                        <a class="btn btn-ghost btn-sm" href="../portal/api/receipt.php?id=<?php echo (int) $f['id']; ?>" target="_blank" rel="noopener" title="Print/View Receipt">
                          <i class="bi bi-receipt"></i> Receipt
                        </a>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="revertFeeToPending(<?php echo (int) $f['id']; ?>)" title="Revert to pending">
                          <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="revertFeeToPending(<?php echo (int) $f['id']; ?>)" title="Restore fee">
                          <i class="bi bi-arrow-counterclockwise"></i> Restore
                        </button>
                      <?php endif; ?>
                    </div>
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
  <div class="subsection-head">
    <h3 class="subsection-title">Intake form<?php echo $submittedFormsCount > 0 ? ' (submitted ' . $submittedFormsCount . 'x)' : ''; ?></h3>
    <?php if (!empty($client['lead_id'])): ?>
      <button class="btn btn-primary btn-sm" onclick="openResendIntakeModal()"><i class="bi bi-envelope-arrow-up"></i> Send intake form again</button>
    <?php endif; ?>
  </div>
  <?php if (empty($intakes)): ?>
    <div class="empty-state">
      <i class="bi bi-clipboard-x"></i>
      <p>No intake form on file</p>
      <p>It appears here once the questionnaire is submitted.</p>
    </div>
  <?php else: ?>
    <div class="panel">
      <div class="panel-body panel-body-flush">
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <tr><th>Form</th><th>Date filled</th><th class="th-right">Score</th><th class="th-right">Details</th></tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($intakes) as $row):
                $tq = 0;
                for ($i = 1; $i <= 18; $i++) {
                    if (strtolower($row["q1_{$i}"] ?? '') === 'yes') $tq++;
                    if (strtolower($row["q2_{$i}"] ?? '') === 'yes') $tq++;
                }
                $tcls = $tq >= 24 ? 'high' : ($tq >= 12 ? 'mid' : 'low');
              ?>
                <tr>
                  <td class="td-name">Patient Intake Form<?php echo !empty($row['form_version']) ? ' (v' . (int) $row['form_version'] . ')' : ''; ?></td>
                  <td class="td-muted"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></td>
                  <td class="td-actions"><span class="score-text <?php echo $tcls; ?>"><?php echo $tq; ?>/36</span></td>
                  <td class="td-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="openIntakeDrawer(<?php echo (int) $row['id']; ?>)"><i class="bi bi-eye"></i> Details</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php $allPatientIntakes = $intakes; include __DIR__ . '/../includes/intake-drawer.php'; ?>

<!-- Resend Intake Modal -->
<div class="modal-overlay" id="resendIntakeModal">
  <div class="modal-box is-narrow">
    <div class="modal-header">
      <div class="modal-title">Send which intake form?</div>
      <button class="modal-close" onclick="document.getElementById('resendIntakeModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitResendIntake(event)">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="resendFormVersion">Intake form</label>
          <select class="form-select" id="resendFormVersion" name="form_version">
            <?php foreach ($formTemplatesAvailable as $t): ?>
              <option value="<?php echo $t['version']; ?>" <?php echo $t['is_default'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('resendIntakeModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-send-check"></i> Send</button>
      </div>
    </form>
  </div>
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
          <div class="form-group">
            <label class="form-label" for="p-pref-mode">Preferred Consultation Mode</label>
            <select class="form-select" id="p-pref-mode" name="pref_mode">
              <option value="">No preference / Flexible</option>
              <option value="Online" <?php echo ($client['pref_mode'] ?? '') === 'Online' ? 'selected' : ''; ?>>Online (Video / Call)</option>
              <option value="In-person" <?php echo ($client['pref_mode'] ?? '') === 'In-person' ? 'selected' : ''; ?>>In-person (Clinic)</option>
              <option value="Flexible" <?php echo ($client['pref_mode'] ?? '') === 'Flexible' ? 'selected' : ''; ?>>Flexible / Either</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="p-pref-times">Preferred Times / Availability</label>
            <input class="form-input" id="p-pref-times" name="pref_times" value="<?php echo htmlspecialchars($client['pref_times'] ?? ''); ?>" placeholder="e.g. morning, evening, weekends" />
          </div>
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

  <div class="panel">
    <div class="panel-header"><div class="panel-title">Delete</div></div>
    <div class="panel-body">
      <p class="prose">
        Deleting removes this client and every intake form they submitted for good. Their sessions,
        notes, fees and documents go with it. This cannot be undone — archive instead if you just
        want them out of the lists.
      </p>
      <button class="btn btn-danger" onclick="deleteClientRecord()"><i class="bi bi-trash"></i> Delete this client</button>
    </div>
  </div>
</div>

<!-- Documents Tab -->
<style>
/* ── Documents Tab – Redesigned ── */
.doc-header-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1.25rem;
}
.doc-header-left {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.doc-header-icon {
  width: 42px; height: 42px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0d7377 0%, #14b8a6 100%);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem;
  flex-shrink: 0;
}
.doc-header-text h3 {
  font-size: 1.05rem; font-weight: 700; color: var(--clr-text, #1f2937); margin: 0;
}
.doc-header-text .doc-count-line {
  font-size: 0.8rem; color: var(--clr-text-secondary, #6b7280); margin-top: 2px;
}
/* Storage Meter */
.doc-storage-meter {
  display: flex; align-items: center; gap: 0.75rem;
  background: var(--clr-surface, #fff);
  border: 1px solid var(--clr-border-light, #e5e7eb);
  border-radius: 10px;
  padding: 0.5rem 0.875rem;
}
.doc-storage-meter-info { font-size: 0.78rem; color: var(--clr-text-secondary); white-space: nowrap; }
.doc-storage-meter-info strong { color: var(--clr-text, #1f2937); }
.doc-meter-track {
  width: 100px; height: 6px; border-radius: 99px;
  background: #e5e7eb; overflow: hidden; flex-shrink: 0;
}
.doc-meter-fill {
  height: 100%; border-radius: 99px;
  background: linear-gradient(90deg, #0d7377, #14b8a6);
  transition: width 0.4s ease;
}
.doc-meter-fill.is-warn { background: linear-gradient(90deg, #f59e0b, #ef4444); }

/* Upload Card */
.doc-upload-card {
  background: var(--clr-surface, #fff);
  border: 1px solid var(--clr-border, #e5e7eb);
  border-radius: 14px;
  padding: 1.25rem;
  margin-bottom: 1.25rem;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.doc-drop {
  border: 2px dashed #cbd5e1;
  border-radius: 12px;
  background: #f8fafc;
  padding: 1.5rem 1rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
}
.doc-drop:hover, .doc-drop.is-dragover {
  border-color: #0d7377; background: #f0fdfa; transform: translateY(-1px);
}
.doc-drop-icon {
  width: 44px; height: 44px; border-radius: 12px;
  background: #e8f4f4; color: #0d7377;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 1.35rem; margin-bottom: 0.35rem;
  transition: transform 0.2s ease;
}
.doc-drop:hover .doc-drop-icon { transform: scale(1.08); }
.doc-drop-text { font-size: 0.88rem; font-weight: 600; color: #334155; }
.doc-drop-text a { color: #0d7377; font-weight: 700; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.doc-drop-hint { font-size: 0.78rem; color: #94a3b8; margin-top: 4px; }
/* File preview inside drop area */
.doc-file-preview {
  display: none; align-items: center; justify-content: space-between;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
  padding: 0.65rem 0.875rem; max-width: 420px; margin: 0 auto;
  box-shadow: 0 1px 4px rgba(0,0,0,0.03);
}
.doc-file-preview.is-active { display: flex; }
.doc-fpv-left { display: flex; align-items: center; gap: 0.65rem; overflow: hidden; text-align: left; }
.doc-fpv-icon { width: 34px; height: 34px; border-radius: 8px; background: #e8f4f4; color: #0d7377;
  display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
.doc-fpv-name { font-size: 0.84rem; font-weight: 600; color: #1e293b; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
.doc-fpv-size { font-size: 0.72rem; color: #94a3b8; }
.doc-fpv-clear { background: none; border: none; color: #94a3b8; padding: 4px; border-radius: 6px;
  cursor: pointer; font-size: 0.9rem; display: flex; transition: all 0.15s; }
.doc-fpv-clear:hover { color: #ef4444; background: #fee2e2; }
/* Upload footer */
.doc-upload-footer {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
  gap: 0.75rem; margin-top: 1rem; padding-top: 0.875rem; border-top: 1px solid #f1f5f9;
}
/* Toggle switch */
.doc-toggle-wrap {
  display: flex; align-items: center; gap: 0.6rem; cursor: pointer; user-select: none;
}
.doc-toggle-track {
  width: 36px; height: 20px; border-radius: 99px; background: #cbd5e1;
  position: relative; transition: background 0.2s; flex-shrink: 0;
}
.doc-toggle-track::after {
  content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px;
  border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
  transition: transform 0.2s;
}
.doc-toggle-wrap input { display: none; }
.doc-toggle-wrap input:checked + .doc-toggle-track { background: #0d7377; }
.doc-toggle-wrap input:checked + .doc-toggle-track::after { transform: translateX(16px); }
.doc-toggle-label { font-size: 0.82rem; font-weight: 500; color: #475569; }
.doc-toggle-label i { color: #0d7377; margin-right: 3px; }
.doc-quota-note {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 0.72rem; font-weight: 500; color: #047857; background: #ecfdf5;
  padding: 3px 8px; border-radius: 99px; margin-top: 0.5rem;
}

/* Document list – card rows */
.doc-list-card {
  background: var(--clr-surface, #fff);
  border: 1px solid var(--clr-border, #e5e7eb);
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.doc-list-head {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
  gap: 0.5rem; padding: 0.875rem 1.25rem;
  border-bottom: 1px solid var(--clr-border-light, #f0f1f3);
  background: var(--clr-surface-alt, #fafbfc);
}
.doc-list-head-title {
  font-size: 0.88rem; font-weight: 700; color: var(--clr-text, #1f2937);
  display: flex; align-items: center; gap: 0.4rem;
}
.doc-list-storage-note { font-size: 0.76rem; color: var(--clr-text-secondary); }
.doc-list-storage-note strong { color: var(--clr-text); }
.doc-item {
  display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1.25rem;
  border-bottom: 1px solid #f3f4f6;
  transition: background 0.15s;
}
.doc-item:last-child { border-bottom: none; }
.doc-item:hover { background: #f9fafb; }
.doc-item-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; flex-shrink: 0;
}
.doc-item-body { flex: 1; min-width: 0; }
.doc-item-name { font-size: 0.88rem; font-weight: 600; color: #1e293b; word-break: break-word; }
.doc-item-meta {
  display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 3px;
}
.doc-item-meta span { font-size: 0.74rem; color: #94a3b8; }
.doc-item-meta .doc-pill {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 7px; border-radius: 5px; font-size: 0.7rem; font-weight: 600;
}
.doc-pill-client { background: #eff6ff; color: #1d4ed8; }
.doc-pill-therapist { background: #f0fdf4; color: #15803d; }
.doc-pill-shared { background: #e8f4f4; color: #0d7377; }
.doc-pill-internal { background: #f1f5f9; color: #64748b; }
.doc-item-actions {
  display: flex; align-items: center; gap: 0.25rem; flex-shrink: 0;
}
.doc-item-actions .doc-act-btn {
  background: none; border: none; padding: 0.375rem; border-radius: 8px;
  cursor: pointer; color: #64748b; font-size: 1rem; display: flex;
  transition: all 0.15s;
}
.doc-act-btn:hover { background: #f1f5f9; color: #334155; }
.doc-act-btn.is-danger:hover { background: #fee2e2; color: #ef4444; }
/* Toggle in list */
.doc-item-toggle {
  display: flex; align-items: center; gap: 0.4rem; flex-shrink: 0; margin-right: 0.25rem;
}
.doc-mini-toggle {
  width: 30px; height: 16px; border-radius: 99px; background: #cbd5e1;
  position: relative; transition: background 0.2s; cursor: pointer; flex-shrink: 0;
}
.doc-mini-toggle::after {
  content: ''; position: absolute; top: 2px; left: 2px; width: 12px; height: 12px;
  border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.12);
  transition: transform 0.2s;
}
.doc-mini-toggle.is-on { background: #0d7377; }
.doc-mini-toggle.is-on::after { transform: translateX(14px); }
.doc-toggle-tip { font-size: 0.7rem; color: #94a3b8; white-space: nowrap; }

/* Empty state */
.doc-empty {
  padding: 3rem 1.5rem; text-align: center;
}
.doc-empty-icon {
  width: 56px; height: 56px; border-radius: 14px; background: #e8f4f4; color: #0d7377;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 1.6rem; margin-bottom: 0.75rem;
}
.doc-empty h4 { font-size: 0.95rem; font-weight: 600; color: var(--clr-text); margin: 0 0 0.25rem; }
.doc-empty p { font-size: 0.84rem; color: var(--clr-text-secondary); max-width: 380px; margin: 0 auto; }
</style>

<div class="profile-tab-content" id="tab-documents">

  <!-- Header Bar -->
  <div class="doc-header-bar">
    <div class="doc-header-left">
      <div class="doc-header-icon"><i class="bi bi-folder2-open"></i></div>
      <div class="doc-header-text">
        <h3>Documents</h3>
        <div class="doc-count-line"><?php echo count($documents); ?> file<?php echo count($documents) === 1 ? '' : 's'; ?> · <?php echo $totalUsedDocMb; ?> MB total</div>
      </div>
    </div>
    <div class="doc-storage-meter" title="Only client-uploaded files count toward the 50 MB portal quota. Therapist uploads are exempt.">
      <div class="doc-storage-meter-info"><strong><?php echo $clientUsedDocMb; ?></strong> / <?php echo $maxDocMb; ?> MB</div>
      <div class="doc-meter-track">
        <?php $meterPct = $maxTotalDocBytes > 0 ? min(100, round(($clientDocBytes / $maxTotalDocBytes) * 100)) : 0; ?>
        <div class="doc-meter-fill <?php echo $meterPct > 85 ? 'is-warn' : ''; ?>" style="width:<?php echo $meterPct; ?>%;"></div>
      </div>
      <div class="doc-storage-meter-info" style="color:#94a3b8;"><?php echo $clientRemDocMb; ?> MB free</div>
    </div>
  </div>

  <!-- Upload Card -->
  <div class="doc-upload-card">
    <form id="doc-upload-form" enctype="multipart/form-data">
      <input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>" />

      <div class="doc-drop" id="doc-dropzone" onclick="document.getElementById('doc-file').click()">
        <input type="file" id="doc-file" name="document" style="display:none;" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required />

        <div id="doc-dropzone-prompt">
          <div class="doc-drop-icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
          <div class="doc-drop-text"><a>Browse</a> or drop a file here</div>
          <div class="doc-drop-hint"><?php echo getSettingInt('upload_max_mb', 10); ?> MB max · <?php echo htmlspecialchars(strtoupper(implode(', ', documentAllowedExtensions()))); ?></div>
        </div>

        <div class="doc-file-preview" id="doc-selected-card" onclick="event.stopPropagation();">
          <div class="doc-fpv-left">
            <div class="doc-fpv-icon"><i class="bi bi-file-earmark-check-fill"></i></div>
            <div>
              <div class="doc-fpv-name" id="doc-selected-name">file.pdf</div>
              <div class="doc-fpv-size" id="doc-selected-size">0 KB</div>
            </div>
          </div>
          <button type="button" class="doc-fpv-clear" title="Remove" onclick="clearSelectedDoc();"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>

      <div class="doc-upload-footer">
        <div>
          <label class="doc-toggle-wrap">
            <input type="checkbox" name="shared_with_client" value="1" checked />
            <span class="doc-toggle-track"></span>
            <span class="doc-toggle-label"><i class="bi bi-eye"></i> Share with client</span>
          </label>
          <div class="doc-quota-note"><i class="bi bi-shield-check"></i> Admin uploads don't count toward quota</div>
        </div>
        <button type="submit" class="btn btn-primary" id="doc-upload-btn" style="padding:0.5rem 1.15rem; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
          <i class="bi bi-cloud-arrow-up"></i> Upload
        </button>
      </div>
    </form>
  </div>

  <!-- Document List -->
  <div class="doc-list-card">
    <div class="doc-list-head">
      <span class="doc-list-head-title"><i class="bi bi-files"></i> All Documents</span>
      <?php if (!empty($documents)): ?>
        <span class="doc-list-storage-note">Client uploads: <strong><?php echo $clientUsedDocMb; ?> MB</strong> · Total: <strong><?php echo $totalUsedDocMb; ?> MB</strong></span>
      <?php endif; ?>
    </div>

    <div id="doc-list">
      <?php if (empty($documents)): ?>
        <div class="doc-empty">
          <div class="doc-empty-icon"><i class="bi bi-folder2-open"></i></div>
          <h4>No documents yet</h4>
          <p>Upload treatment plans, worksheets, or care records. Client-uploaded files will also appear here.</p>
        </div>
      <?php else: ?>
        <?php
        function adminDocFileMeta($name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'pdf':  return ['icon' => 'bi-filetype-pdf',        'color' => '#dc2626', 'bg' => '#fee2e2'];
                case 'doc':
                case 'docx': return ['icon' => 'bi-filetype-docx',       'color' => '#2563eb', 'bg' => '#dbeafe'];
                case 'jpg':
                case 'jpeg':
                case 'png':  return ['icon' => 'bi-file-earmark-image',  'color' => '#059669', 'bg' => '#d1fae5'];
                default:     return ['icon' => 'bi-file-earmark-text',   'color' => '#475569', 'bg' => '#f1f5f9'];
            }
        }
        ?>
        <?php foreach ($documents as $d):
          $meta = adminDocFileMeta($d['original_name']);
          $isClient = !empty($d['client_uploaded']);
          $sizeKb = $d['size_bytes'] / 1024;
          $sizeFormatted = $sizeKb >= 1024 ? round($sizeKb / 1024, 1) . ' MB' : round($sizeKb) . ' KB';
          $isShared = !empty($d['shared_with_client']);
        ?>
          <div class="doc-item">
            <div class="doc-item-icon" style="background:<?php echo $meta['bg']; ?>; color:<?php echo $meta['color']; ?>;">
              <i class="bi <?php echo $meta['icon']; ?>"></i>
            </div>
            <div class="doc-item-body">
              <div class="doc-item-name"><?php echo htmlspecialchars($d['original_name']); ?></div>
              <div class="doc-item-meta">
                <span><?php echo $sizeFormatted; ?></span>
                <span>·</span>
                <span><?php echo date('d M Y, h:i A', strtotime($d['uploaded_at'])); ?></span>
                <span class="doc-pill <?php echo $isClient ? 'doc-pill-client' : 'doc-pill-therapist'; ?>">
                  <i class="bi <?php echo $isClient ? 'bi-person-fill' : 'bi-shield-check'; ?>"></i>
                  <?php echo $isClient ? 'Client' : 'Therapist'; ?>
                </span>
                <?php if ($isClient || $isShared): ?>
                  <span class="doc-pill doc-pill-shared"><i class="bi bi-eye-fill"></i> Shared</span>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!$isClient): ?>
              <div class="doc-item-toggle" title="<?php echo $isShared ? 'Visible in client portal – click to hide' : 'Hidden from client – click to share'; ?>">
                <div class="doc-mini-toggle <?php echo $isShared ? 'is-on' : ''; ?>"
                     onclick="toggleShareDoc(<?php echo (int) $d['id']; ?>, this)"
                     id="doc-toggle-<?php echo (int) $d['id']; ?>"></div>
                <span class="doc-toggle-tip" id="share-label-<?php echo (int) $d['id']; ?>"><?php echo $isShared ? 'Portal' : 'Internal'; ?></span>
              </div>
            <?php else: ?>
              <div class="doc-item-toggle" title="Client uploads are always visible in portal">
                <div class="doc-mini-toggle is-on" style="opacity:0.5; cursor:default;"></div>
                <span class="doc-toggle-tip">Portal</span>
              </div>
            <?php endif; ?>

            <div class="doc-item-actions">
              <a class="doc-act-btn" href="document.php?id=<?php echo (int) $d['id']; ?>" title="Download"><i class="bi bi-download"></i></a>
              <button type="button" class="doc-act-btn is-danger" title="Delete" onclick="archiveDocument(<?php echo (int) $d['id']; ?>)"><i class="bi bi-trash3"></i></button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
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

<!-- Record Payment Modal -->
<div class="modal-overlay" id="recordPaymentModal">
  <div class="modal-box is-narrow">
    <div class="modal-header">
      <div class="modal-title"><i class="bi bi-credit-card-2-front"></i> Record Fee Payment</div>
      <button class="modal-close" onclick="document.getElementById('recordPaymentModal').classList.remove('open')">&times;</button>
    </div>
    <form onsubmit="submitRecordPayment(event)">
      <input type="hidden" name="fee_id" id="rec_fee_id" />
      <div class="modal-body">
        <div style="background:var(--clr-surface-alt, #f8fafc); padding:0.85rem 1rem; border-radius:var(--radius-sm, 8px); margin-bottom:1rem; border:1px solid var(--clr-border-light, #eef2f6);">
          <div class="detail-label" id="rec_fee_desc">Session Fee</div>
          <div style="font-size:1.45rem; font-weight:700; color:var(--clr-text); margin-top:2px;" id="rec_fee_amount_display">₹0.00</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="rec_paid_date">Payment Date</label>
            <input type="date" class="form-input" id="rec_paid_date" name="paid_date" value="<?php echo date('Y-m-d'); ?>" required />
          </div>
          <div class="form-group">
            <label class="form-label" for="rec_method">Payment Method</label>
            <select class="form-select" id="rec_method" name="method" required>
              <option value="upi">UPI</option>
              <option value="cash">Cash</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="rec_reference">Reference / Transaction ID</label>
          <input type="text" class="form-input" id="rec_reference" name="reference" placeholder="e.g. UPI Ref, transaction number..." />
        </div>
        <div class="form-group" style="margin-top:0.75rem;">
          <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.88rem; cursor:pointer;">
            <input type="checkbox" name="send_receipt" id="rec_send_receipt" value="1" checked />
            <span>Email payment receipt to client</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('recordPaymentModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-success" id="recSubmitBtn"><i class="bi bi-check2-circle"></i> Confirm Payment</button>
      </div>
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
var clientId = <?php echo $clientId; ?>;

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
      setTimeout(function () { reloadTab('sessions'); }, 600);
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
        setTimeout(function(){ reloadTab('sessions'); }, 600);
      } else {
        showToast(d.error || 'Error', 'error');
      }
    })
    .catch(function(){ showToast('Network error', 'error'); });
}

async function updateSessionStatus(id, status) {
  if (status === 'cancelled') {
    if (!await showConfirm('Cancel this session? The client will be notified and the booking slot freed.', { danger: true, okText: 'Cancel Session' })) {
      return;
    }
  }

  var scope = await seriesScopeFor(id, status === 'cancelled' ? 'cancel' : 'change');
  if (scope === null) return;

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
        setTimeout(function(){ reloadTab('sessions'); }, 600);
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
      setTimeout(function() { reloadTab('overview'); }, 700);
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function showProfileTab(tab) {
  if (!tab) tab = 'overview';
  var target = document.getElementById('tab-' + tab);
  if (!target) return;
  document.querySelectorAll('.profile-tab-content').forEach(function(el) { el.classList.remove('active'); });
  document.querySelectorAll('.profile-tab').forEach(function(el) {
    if (el.getAttribute('data-tab') === tab) {
      el.classList.add('active');
    } else {
      el.classList.remove('active');
    }
  });
  target.classList.add('active');
  try {
    sessionStorage.setItem('client_profile_active_tab_' + clientId, tab);
    if (history.replaceState) {
      history.replaceState(null, null, '#tab=' + tab);
    } else {
      location.hash = 'tab=' + tab;
    }
  } catch (err) {}
}

function reloadTab(tab) {
  if (tab) {
    try {
      sessionStorage.setItem('client_profile_active_tab_' + clientId, tab);
      if (history.replaceState) {
        history.replaceState(null, null, '#tab=' + tab);
      } else {
        location.hash = 'tab=' + tab;
      }
    } catch (err) {}
  }
  location.reload();
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
        setTimeout(function(){ reloadTab('notes'); }, 600);
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
      if (d.success) { showToast('Profile saved'); setTimeout(function(){ reloadTab('profile'); }, 600); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function(){ showToast('Network error','error'); });
}

(function() {
  var form = document.getElementById('doc-upload-form');
  var fileInput = document.getElementById('doc-file');
  var dropzone = document.getElementById('doc-dropzone');
  var promptEl = document.getElementById('doc-dropzone-prompt');
  var selectedCard = document.getElementById('doc-selected-card');
  var nameEl = document.getElementById('doc-selected-name');
  var sizeEl = document.getElementById('doc-selected-size');
  var submitBtn = document.getElementById('doc-upload-btn');

  if (!form || !fileInput || !dropzone) return;

  function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    var k = 1024;
    var sizes = ['Bytes', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  function handleFileSelected(file) {
    if (!file) return;
    if (nameEl) nameEl.textContent = file.name;
    if (sizeEl) sizeEl.textContent = formatBytes(file.size);
    if (promptEl) promptEl.style.display = 'none';
    if (selectedCard) selectedCard.classList.add('is-active');
  }

  window.clearSelectedDoc = function() {
    fileInput.value = '';
    if (promptEl) promptEl.style.display = '';
    if (selectedCard) selectedCard.classList.remove('is-active');
  };

  fileInput.addEventListener('change', function() {
    if (fileInput.files && fileInput.files[0]) {
      handleFileSelected(fileInput.files[0]);
    }
  });

  // Drag and drop listeners
  ['dragenter', 'dragover'].forEach(function(evt) {
    dropzone.addEventListener(evt, function(e) {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.add('is-dragover');
    }, false);
  });

  ['dragleave', 'drop'].forEach(function(evt) {
    dropzone.addEventListener(evt, function(e) {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.remove('is-dragover');
    }, false);
  });

  dropzone.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    if (dt && dt.files && dt.files.length > 0) {
      fileInput.files = dt.files;
      handleFileSelected(dt.files[0]);
    }
  });

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    if (!fileInput.files || fileInput.files.length === 0) {
      showToast('Please select a file to upload.', 'error');
      return;
    }
    var origBtnHtml = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="margin-right:6px;"></span> Uploading...';

    var fd = new FormData(form);
    fd.append('action', 'upload');
    fetch('api/client-documents.php', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.success) { 
          showToast('Document uploaded successfully'); 
          setTimeout(function(){ reloadTab('documents'); }, 500); 
        } else { 
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
          showToast(d.error || 'Upload failed', 'error'); 
        }
      })
      .catch(function(){ 
        submitBtn.disabled = false;
        submitBtn.innerHTML = origBtnHtml;
        showToast('Network error while uploading','error'); 
      });
  });
})();

function toggleShareDoc(id, el) {
  var isOn = el.classList.contains('is-on');
  var newShared = isOn ? 0 : 1;

  // Optimistic UI update
  el.classList.toggle('is-on');
  var labelSpan = document.getElementById('share-label-' + id);
  if (labelSpan) labelSpan.textContent = newShared ? 'Portal' : 'Internal';

  // Also update the "Shared" pill in meta
  var item = el.closest('.doc-item');
  if (item) {
    var pills = item.querySelectorAll('.doc-pill-shared');
    pills.forEach(function(p) { p.style.display = newShared ? '' : 'none'; });
    if (newShared && pills.length === 0) {
      var meta = item.querySelector('.doc-item-meta');
      if (meta) {
        var pill = document.createElement('span');
        pill.className = 'doc-pill doc-pill-shared';
        pill.innerHTML = '<i class="bi bi-eye-fill"></i> Shared';
        meta.appendChild(pill);
      }
    }
  }

  var fd = new FormData();
  fd.append('action', 'share');
  fd.append('document_id', id);
  fd.append('shared', newShared);
  fetch('api/client-documents.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) { showToast(newShared ? 'Shared with client' : 'Removed from portal'); }
      else {
        // Revert
        el.classList.toggle('is-on');
        if (labelSpan) labelSpan.textContent = isOn ? 'Portal' : 'Internal';
        showToast(d.error || 'Error', 'error');
      }
    })
    .catch(function(){
      el.classList.toggle('is-on');
      if (labelSpan) labelSpan.textContent = isOn ? 'Portal' : 'Internal';
      showToast('Network error','error');
    });
}

// Keep legacy shareDocument for backwards compat (unused in new UI)
function shareDocument(id, box) {
  toggleShareDoc(id, document.getElementById('doc-toggle-' + id) || box);
}

async function archiveDocument(id) {
  if (!await showConfirm('Remove this document from the client record?', { danger: true, okText: 'Remove' })) return;
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

async function sendFeeReminder(id) {
  var btn = document.getElementById('fee-remind');
  if (!await showConfirm('Email this client their outstanding balance?')) { return; }

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

async function archiveClientRecord() {
  if (!await showConfirm('Archive this client? The record is kept, but it disappears from every list.', { danger: true, okText: 'Archive' })) return;
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

function openResendIntakeModal() {
  document.getElementById('resendIntakeModal').classList.add('open');
}

function submitResendIntake(e) {
  e.preventDefault();
  var fd = new FormData();
  fd.append('action', 'resend_intake');
  fd.append('client_id', clientId);
  fd.append('form_version', document.getElementById('resendFormVersion').value);
  document.getElementById('resendIntakeModal').classList.remove('open');
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) { showToast('Intake link sent'); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function() { showToast('Network error', 'error'); });
}

async function deleteClientRecord() {
  if (!await showConfirm('Delete this client permanently? Their intake data, sessions, notes, fees and documents are all removed. This cannot be undone.', { danger: true, okText: 'Delete' })) return;
  var fd = new FormData();
  fd.append('action', 'delete');
  fd.append('client_id', clientId);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) { showToast('Client deleted'); setTimeout(function(){ location.href='index.php?page=clients'; }, 700); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function(){ showToast('Network error','error'); });
}

async function submitMerge(e) {
  e.preventDefault();
  var loser = document.getElementById('merge-loser').value;
  if (!loser) return;
  if (!await showConfirm('Merge that record into this one? Their sessions, notes, documents and payments move here, and the other record is archived.')) return;

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
    .then(function(d) {
      if(d.success){
        showToast('Fee recorded successfully');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error||'Error','error');
      }
    })
    .catch(function() { showToast('Network error','error'); });
}

function openRecordPaymentModal(feeId, amount, description) {
  document.getElementById('rec_fee_id').value = feeId;
  document.getElementById('rec_fee_amount_display').textContent = '₹' + parseFloat(amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  document.getElementById('rec_fee_desc').textContent = description || 'Session Fee';
  document.getElementById('rec_paid_date').value = new Date().toISOString().split('T')[0];
  document.getElementById('rec_reference').value = '';
  document.getElementById('rec_send_receipt').checked = true;
  document.getElementById('recordPaymentModal').classList.add('open');
}

function submitRecordPayment(e) {
  e.preventDefault();
  var btn = document.getElementById('recSubmitBtn');
  if (btn) btn.disabled = true;
  var fd = new FormData(e.target);
  fd.append('action', 'update_fee_status');
  fd.append('status', 'paid');
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (btn) btn.disabled = false;
      if (d.success) {
        showToast('Payment confirmed and recorded');
        document.getElementById('recordPaymentModal').classList.remove('open');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to record payment', 'error');
      }
    })
    .catch(function() {
      if (btn) btn.disabled = false;
      showToast('Network error recording payment', 'error');
    });
}

async function waiveFee(feeId, amount) {
  if (!await showConfirm('Waive this fee of ₹' + amount + '? The client will not be billed for it.', { okText: 'Waive Fee' })) return;
  var fd = new FormData();
  fd.append('action', 'update_fee_status');
  fd.append('fee_id', feeId);
  fd.append('status', 'waived');
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Fee waived');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to waive fee', 'error');
      }
    })
    .catch(function() { showToast('Network error waiving fee', 'error'); });
}

async function revertFeeToPending(feeId) {
  if (!await showConfirm('Restore fee status to Pending?')) return;
  var fd = new FormData();
  fd.append('action', 'update_fee_status');
  fd.append('fee_id', feeId);
  fd.append('status', 'pending');
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Fee restored to pending');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to update fee', 'error');
      }
    })
    .catch(function() { showToast('Network error updating fee', 'error'); });
}

async function deleteFee(feeId) {
  if (!await showConfirm('Permanently delete this fee entry? This cannot be undone.', { danger: true, okText: 'Delete Fee' })) return;
  var fd = new FormData();
  fd.append('action', 'delete_fee');
  fd.append('fee_id', feeId);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Fee deleted');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to delete fee', 'error');
      }
    })
    .catch(function() { showToast('Network error deleting fee', 'error'); });
}

async function verifyPaymentReport(reportId) {
  if (!await showConfirm('Approve this client-reported payment? This marks the fee as paid and sends a confirmation receipt.')) return;
  var fd = new FormData();
  fd.append('action', 'verify_payment_report');
  fd.append('report_id', reportId);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Payment verified and recorded');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to verify payment', 'error');
      }
    })
    .catch(function() { showToast('Network error verifying payment', 'error'); });
}

async function rejectPaymentReport(reportId) {
  if (!await showConfirm('Dismiss this payment report?', { danger: true, okText: 'Dismiss' })) return;
  var fd = new FormData();
  fd.append('action', 'reject_payment_report');
  fd.append('report_id', reportId);
  fetch('api/clients.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Payment report dismissed');
        setTimeout(function(){ reloadTab('fees'); }, 600);
      } else {
        showToast(d.error || 'Failed to dismiss payment report', 'error');
      }
    })
    .catch(function() { showToast('Network error dismissing report', 'error'); });
}

async function setClientStatus(status, label) {
  if (!await showConfirm('Mark this client as ' + label.toLowerCase() + '?', { okText: label })) return;
  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('client_id', clientId);
  fd.append('status', status);
  fetch('api/clients.php', { method:'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) { if(d.success){ showToast('Status updated'); setTimeout(function(){ reloadTab('overview'); }, 600); } else showToast(d.error||'Error','error'); })
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
  if (sel && end) {
    sel.addEventListener('change', function() { end.hidden = (sel.value === 'none'); });
  }

  var feeStatusSel = document.getElementById('fee-status');
  var feeMethodGrp = document.getElementById('fee-method-group');
  var feeRefGrp    = document.getElementById('fee-reference-group');
  if (feeStatusSel && feeMethodGrp && feeRefGrp) {
    feeStatusSel.addEventListener('change', function() {
      var isPaid = (feeStatusSel.value === 'paid');
      feeMethodGrp.hidden = !isPaid;
      feeRefGrp.hidden = !isPaid;
    });
  }
})();

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

function sendPortalInvite(cid) {
  showToast('Sending portal invitation...', 'info');
  var fd = new FormData();
  fd.append('client_id', cid);
  fetch('api/client-portal-invite.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast(d.message || 'Invitation sent successfully');
      } else {
        showToast(d.error || 'Failed to send invite', 'error');
      }
    })
    .catch(function() { showToast('Network error sending invite', 'error'); });
}

async function confirmSession(sid) {
  if (!await showConfirm('Confirm this session request and send confirmation email to client?')) return;
  var fd = new FormData();
  fd.append('action', 'update_status');
  fd.append('session_id', sid);
  fd.append('status', 'confirmed');
  fetch('api/sessions.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success) {
        showToast('Session confirmed and confirmation email sent');
        setTimeout(function() { reloadTab('sessions'); }, 600);
      } else {
        showToast(d.error || 'Failed to confirm session', 'error');
      }
    })
    .catch(function() { showToast('Network error confirming session', 'error'); });
}

// Restore active tab on page load
(function() {
  function getTargetTab() {
    var hash = location.hash || '';
    var m = hash.match(/tab=([a-z0-9_-]+)/i);
    if (m && m[1]) return m[1];
    try {
      var saved = sessionStorage.getItem('client_profile_active_tab_' + clientId);
      if (saved) return saved;
    } catch (e) {}
    return null;
  }
  var tab = getTargetTab();
  if (tab && document.getElementById('tab-' + tab)) {
    showProfileTab(tab);
  }
})();
</script>

