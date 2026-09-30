<?php
/* $client, $db available. */
require_once dirname(__DIR__) . '/api/session-lib.php';
$cid  = (int) $client['id'];
$now  = date('Y-m-d H:i:s');
$stmt = $db->prepare("SELECT * FROM `sessions` WHERE `client_id` = :c AND `status` IN ('pending','confirmed','rejected') AND `end_time` >= :n ORDER BY `start_time` ASC LIMIT 1");
$stmt->execute([':c' => $cid, ':n' => $now]);
$next = $stmt->fetch(PDO::FETCH_ASSOC);

$q = $db->prepare("SELECT COUNT(*) FROM `sessions` WHERE `client_id` = :c");
$q->execute([':c' => $cid]);
$sessionsTotal = (int) $q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(`amount`),0) FROM `client_fees` WHERE `client_id` = :c AND `status` NOT IN ('paid','waived')");
$q->execute([':c' => $cid]);
$balance = (float) $q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM `patient-intake` WHERE `client_id` = :c");
$q->execute([':c' => $cid]);
$intakeDone = (int) $q->fetchColumn() > 0;

$q = $db->prepare("SELECT * FROM `sessions` WHERE `client_id` = :c AND `start_time` < :n ORDER BY `start_time` DESC LIMIT 5");
$q->execute([':c' => $cid, ':n' => $now]);
$recent = $q->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="stat-strip">
  <div class="stat-strip-card">
    <div class="stat-strip-icon teal"><i class="bi bi-calendar-event"></i></div>
    <div><div class="stat-strip-num"><?= $next ? e(date('d M', strtotime($next['start_time']))) : '-' ?></div><div class="stat-strip-label">Next session</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon blue"><i class="bi bi-calendar-check"></i></div>
    <div><div class="stat-strip-num"><?= $sessionsTotal ?></div><div class="stat-strip-label">Sessions total</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon amber"><i class="bi bi-currency-rupee"></i></div>
    <div><div class="stat-strip-num">&#8377;<?= number_format($balance, 2) ?></div><div class="stat-strip-label">Balance due</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon green"><i class="bi bi-clipboard2-pulse"></i></div>
    <div><div class="stat-strip-num"><?= $intakeDone ? 'Submitted' : 'Pending' ?></div><div class="stat-strip-label">Intake status</div></div>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Upcoming session</div></div>
  <div class="panel-body">
    <?php if (!$next): ?>
      <div class="empty-state"><i class="bi bi-calendar-x"></i><p>You have no upcoming sessions.</p></div>
      <a class="btn btn-primary btn-sm" href="?page=sessions">Book a session</a>
    <?php else:
      $st = strtotime($next['start_time']); $en = strtotime($next['end_time']);
      $online = $next['session_type'] === 'online';
      $canJoin = $online && $next['video_link'] && $next['status'] === 'confirmed' && time() >= $st - 900 && time() <= $en;
    ?>
      <div class="detail-grid">
        <div><div class="detail-label">When</div><div class="detail-value"><?= e(date('l, d M Y', $st)) ?> &middot; <?= e(date('h:i A', $st)) ?></div></div>
        <div><div class="detail-label">Duration</div><div class="detail-value"><?= (int) round(($en - $st) / 60) ?> min</div></div>
        <div><div class="detail-label">Type</div><div class="detail-value"><?= $online ? 'Online' : 'In person' ?></div></div>
        <div><div class="detail-label">Status</div><div class="detail-value"><span class="badge badge-session-<?= e($next['status']) ?>"><?= e(sessionStatusLabel($next['status'])) ?></span></div></div>
        <div><div class="detail-label">With</div><div class="detail-value"><?= e(getSetting('practice_name')) ?></div></div>
      </div>
      <?php if ($online): ?>
        <?php if ($canJoin): ?>
          <a class="btn btn-primary btn-sm" href="<?= e($next['video_link']) ?>" target="_blank" rel="noopener"><i class="bi bi-camera-video"></i> Join video session</a>
        <?php else: ?>
          <button class="btn btn-primary btn-sm" disabled><i class="bi bi-camera-video"></i> Join video session</button>
          <div class="panel-subtitle"><?= $next['status'] !== 'confirmed' ? 'Available once the session is confirmed.' : 'Opens 15 minutes before the session starts.' ?></div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Recent sessions</div>
    <a class="btn btn-ghost btn-sm" href="?page=sessions">All sessions</a>
  </div>
  <div class="panel-body">
    <?php if (!$recent): ?>
      <div class="empty-state"><i class="bi bi-clock-history"></i><p>No past sessions yet</p></div>
    <?php else: ?>
      <ul class="activity-list">
        <?php foreach ($recent as $r): ?>
          <li class="activity-item">
            <div class="activity-icon teal"><i class="bi bi-calendar-check"></i></div>
            <div>
              <div class="activity-text"><?= e(date('D, d M Y h:i A', strtotime($r['start_time']))) ?> &middot; <span class="badge badge-session-<?= e($r['status']) ?>"><?= e(sessionStatusLabel($r['status'])) ?></span></div>
              <div class="activity-time"><?= $r['session_type'] === 'online' ? 'Online' : 'In person' ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
