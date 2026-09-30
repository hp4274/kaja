<?php
/* Intake & Assessments. $client, $db, e() in scope. */
require_once dirname(__DIR__, 2) . '/includes/intake-token.php';
require_once dirname(__DIR__, 2) . '/includes/intake-repo.php';
require_once dirname(__DIR__, 2) . '/includes/intake-schema.php';

$cid = (int) $client['id'];
$fmt = function ($d) { return $d ? date('d M Y, H:i', strtotime($d)) : '-'; };

$stmt = $db->prepare('SELECT * FROM `patient-intake` WHERE `client_id` = :c ORDER BY `created_at` DESC, `id` DESC');
$stmt->execute([':c' => $cid]);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$score = function ($row, $set) {
    $n = 0;
    for ($i = 1; $i <= 18; $i++) { if (strtolower((string) ($row["q{$set}_{$i}"] ?? '')) === 'yes') $n++; }
    return $n;
};
$cls = function ($n, $hi, $mid) { return $n >= $hi ? 'high' : ($n >= $mid ? 'mid' : 'low'); };

// Active live intake link for this client
$linkStmt = $db->prepare('
    SELECT il.* FROM `intake_links` il
    LEFT JOIN `leads` l ON l.`id` = il.`lead_id`
    WHERE (il.`client_id` = :c OR l.`client_id` = :c2)
      AND il.`status` IN ("sent","opened","filled") AND il.`expires_at` > NOW()
    ORDER BY il.`created_at` DESC, il.`id` DESC LIMIT 1');
$linkStmt->execute([':c' => $cid, ':c2' => $cid]);
$link = $linkStmt->fetch(PDO::FETCH_ASSOC);

// Latest form data if exists
$latestForm = !empty($forms) ? $forms[0] : null;
$latestA = $latestForm ? $score($latestForm, 1) : 0;
$latestB = $latestForm ? $score($latestForm, 2) : 0;
$latestTotal = $latestA + $latestB;
$latestTierLabel = $latestTotal >= 24 ? 'High Symptoms / Attention Advised' : ($latestTotal >= 12 ? 'Moderate Symptoms Reported' : 'Normal Range / Mild Symptoms');
$latestTierCls = $latestTotal >= 24 ? 'high' : ($latestTotal >= 12 ? 'mid' : 'low');
?>

<!-- 1. Intake Form Status Card -->
<div class="portal-intake-card">
  <div class="portal-intake-header">
    <div class="portal-intake-title">
      <i class="bi bi-clipboard2-pulse"></i>
      <span>Clinical Intake Questionnaire</span>
    </div>
    <?php if ($link): ?>
      <?php
        $draft = readIntakeDraft($db, $link['id']);
        $req   = intakeRequiredFields($link['form_version'], $draft);
        $done  = 0;
        foreach ($req as $id) { if (isset($draft[$id]) && trim((string) $draft[$id]) !== '') $done++; }
        $pct = $req ? (int) round($done / count($req) * 100) : 0;
      ?>
      <span class="badge badge-pending"><i class="bi bi-hourglass-split"></i> <?= $draft ? 'Draft in Progress' : 'Action Required' ?></span>
    <?php elseif ($latestForm): ?>
      <span class="badge badge-completed"><i class="bi bi-check2-circle"></i> Completed &amp; On File</span>
    <?php endif; ?>
  </div>

  <?php if ($link): ?>
    <div class="portal-progress-wrap">
      <div class="portal-progress-meta">
        <span>Draft Progress: <strong><?= $done ?> of <?= count($req) ?> required answers</strong></span>
        <span><strong><?= $pct ?>%</strong></span>
      </div>
      <div class="portal-progress-bar">
        <div class="portal-progress-fill <?= $pct < 50 ? 'mid' : '' ?>" style="width: <?= max(4, $pct) ?>%;"></div>
      </div>
    </div>
    <div class="portal-intake-expiry">
      <i class="bi bi-clock-history"></i>
      <span>This form link expires on <strong><?= e($fmt($link['expires_at'])) ?></strong>.</span>
    </div>
    <div>
      <a class="btn btn-primary" href="<?= e(intakeFormUrl($link['token'])) ?>" style="display:inline-flex; align-items:center; gap:0.5rem;">
        <i class="bi bi-pencil-square"></i>
        <span><?= $draft ? 'Resume Your Intake Form' : 'Start Your Intake Form' ?></span>
        <i class="bi bi-arrow-right"></i>
      </a>
    </div>
  <?php elseif (!$forms && empty($client['intake_submitted_at'])): ?>
    <div class="empty-state">
      <i class="bi bi-envelope-paper"></i>
      <p>You have no active intake link. You can request a fresh questionnaire link below.</p>
    </div>
    <button class="btn btn-primary btn-sm" id="reqLink" type="button"><i class="bi bi-envelope"></i> Request Intake Form Link</button>
    <div class="panel-subtitle" id="reqMsg" role="status" style="margin-top:0.5rem;"></div>
    <script>
      document.getElementById('reqLink').addEventListener('click', function () {
        var b = this, m = document.getElementById('reqMsg'), fd = new FormData();
        fd.append('csrf', document.querySelector('meta[name=csrf-token]').content);
        b.disabled = true;
        fetch('api/intake-request.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
          .then(function (j) { m.textContent = j.success ? 'A new link has been emailed to you.' : j.error; if (!j.success) b.disabled = false; })
          .catch(function () { m.textContent = 'Network error.'; b.disabled = false; });
      });
    </script>
  <?php else: ?>
    <div style="display:flex; align-items:center; gap:0.75rem; color:var(--clr-text-secondary); font-size:0.95rem;">
      <i class="bi bi-check-circle-fill" style="color:var(--clr-success, #16a34a); font-size:1.35rem;"></i>
      <span>Your intake questionnaire was successfully completed and reviewed by your therapist. No further action is required at this time.</span>
    </div>
  <?php endif; ?>
</div>

<!-- 2. Latest Clinical Assessment Overview (if forms submitted) -->
<?php if ($latestForm): ?>
  <div class="portal-intake-card">
    <div class="portal-intake-header">
      <div class="portal-intake-title">
        <i class="bi bi-activity"></i>
        <span>Latest Assessment Results</span>
      </div>
      <span class="hint">Completed on <?= e($fmt($latestForm['created_at'])) ?> (v<?= (int) $latestForm['form_version'] ?>)</span>
    </div>

    <div class="assessment-hero-container">
      <div class="assessment-hero-score-box">
        <div class="detail-label" style="text-transform:uppercase; letter-spacing:0.04em;">Clinical Baseline Score</div>
        <div class="assessment-score-num">
          <?= $latestTotal ?>
          <span class="assessment-score-denom">/ 36</span>
        </div>
        <div class="assessment-tier-badge <?= $latestTierCls ?>">
          <i class="bi bi-circle-fill" style="font-size:0.5rem;"></i>
          <?= $latestTierLabel ?>
        </div>
      </div>

      <div style="display:flex; flex-direction:column; justify-content:space-between;">
        <div class="assessment-subscores-grid">
          <div class="assessment-subscore-card">
            <div>
              <div class="assessment-subscore-label">Part 1: Self-Awareness &amp; Somatic</div>
              <div class="assessment-subscore-value">
                <span class="score-text <?= $cls($latestA, 12, 6) ?>"><?= $latestA ?></span>
                <small>/ 18 flagged</small>
              </div>
            </div>
            <div class="assessment-subscore-desc">Physical tension, bodily awareness, stress physiology, and somatic reflexes.</div>
          </div>

          <div class="assessment-subscore-card">
            <div>
              <div class="assessment-subscore-label">Part 2: Emotional &amp; Cognitive</div>
              <div class="assessment-subscore-value">
                <span class="score-text <?= $cls($latestB, 12, 6) ?>"><?= $latestB ?></span>
                <small>/ 18 flagged</small>
              </div>
            </div>
            <div class="assessment-subscore-desc">Emotional resilience, focus stability, energy depletion, and cognitive patterns.</div>
          </div>
        </div>

        <div style="margin-top:1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
          <p class="panel-subtitle" style="margin:0; font-size:0.83rem;">
            <i class="bi bi-shield-check" style="color:var(--clr-primary);"></i>
            Baseline metrics guide your therapist's clinical approach during sessions.
          </p>
          <button class="btn btn-ghost btn-sm" type="button" onclick="openIntakeDrawer(<?= (int) $latestForm['id'] ?>)">
            <i class="bi bi-eye"></i> View Full Questionnaire Responses
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Assessment History -->
  <div class="portal-intake-card">
    <div class="portal-intake-header">
      <div class="portal-intake-title">
        <i class="bi bi-clock-history"></i>
        <span>Assessment History</span>
      </div>
      <span class="hint"><?= count($forms) ?> record(s) on file</span>
    </div>

    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Form Version</th>
            <th>Part 1 (Somatic)</th>
            <th>Part 2 (Emotional)</th>
            <th>Overall Score</th>
            <th class="th-right">Responses</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($forms as $f):
            $a = $score($f, 1);
            $b = $score($f, 2);
            $t = $a + $b;
          ?>
            <tr>
              <td class="td-nowrap td-muted"><?= e($fmt($f['created_at'])) ?></td>
              <td><span class="badge" style="background:var(--clr-surface-alt); color:var(--clr-text);">v<?= (int) $f['form_version'] ?></span></td>
              <td><span class="score-text <?= $cls($a, 12, 6) ?>"><?= $a ?> / 18</span></td>
              <td><span class="score-text <?= $cls($b, 12, 6) ?>"><?= $b ?> / 18</span></td>
              <td>
                <strong class="score-text <?= $cls($t, 24, 12) ?>" style="font-size:0.95rem;"><?= $t ?> / 36</strong>
              </td>
              <td class="td-actions">
                <button class="btn btn-ghost btn-sm" type="button" onclick="openIntakeDrawer(<?= (int) $f['id'] ?>)">
                  <i class="bi bi-eye"></i> Details
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php if ($forms):
  // Drawer data: this client's own submissions only
  $text = intakeQuestionnaireText();
  $drawerData = [];
  foreach ($forms as $f) {
      $ans = [1 => [], 2 => []];
      foreach ([1, 2] as $set) {
          for ($i = 1; $i <= 18; $i++) { $ans[$set][] = (string) ($f["q{$set}_{$i}"] ?? ''); }
      }
      $drawerData[] = ['id' => (int) $f['id'], 'when' => $fmt($f['created_at']), 'ver' => (int) $f['form_version'], 'a' => $ans];
  }
?>
<div class="drawer-backdrop" id="piDrawerBackdrop" hidden onclick="closeIntakeDrawer()"></div>
<aside class="drawer drawer-wide" id="piDrawer" hidden aria-labelledby="pi-drawer-name" role="dialog" aria-modal="true">
  <div class="drawer-header">
    <div>
      <h2 id="pi-drawer-name">Assessment Details</h2>
      <div class="drawer-sub" id="pi-drawer-sub"></div>
    </div>
    <button class="btn btn-icon" type="button" onclick="closeIntakeDrawer()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="drawer-body" id="piDrawerContent"></div>
</aside>
<script>
var piData = <?= json_encode($drawerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var piText = <?= json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function escapeHtml(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
function piCls(n, hi, mid) { return n >= hi ? 'high' : (n >= mid ? 'mid' : 'low'); }

function openIntakeDrawer(id) {
  var pi = null;
  for (var i = 0; i < piData.length; i++) { if (piData[i].id === id) { pi = piData[i]; break; } }
  if (!pi) return;
  var s = {1: 0, 2: 0};
  [1, 2].forEach(function (set) { pi.a[set].forEach(function (v) { if (String(v).toLowerCase() === 'yes') s[set]++; }); });
  var total = s[1] + s[2], totalCls = piCls(total, 24, 12);
  var html = '<div class="section-title">Score Summary</div><div class="detail-grid is-thirds">' +
    '<div><div class="detail-label">Part 1 (Somatic)</div><div class="score-text ' + piCls(s[1], 12, 6) + ' is-lg">' + s[1] + ' / 18</div></div>' +
    '<div><div class="detail-label">Part 2 (Emotional)</div><div class="score-text ' + piCls(s[2], 12, 6) + ' is-lg">' + s[2] + ' / 18</div></div>' +
    '<div><div class="detail-label">Total Score</div><div class="score-text ' + totalCls + ' is-xl">' + total + ' / 36</div>' +
    '<div class="score-bar"><div class="score-bar-fill ' + totalCls + '" style="width:' + Math.round(total / 36 * 100) + '%;"></div></div></div></div>';
  [1, 2].forEach(function (set) {
    var title = set === 1 ? 'Part 1: Self-Awareness & Somatic Well-Being' : 'Part 2: Emotional & Cognitive Balance';
    html += '<div class="section-title" style="margin-top:1.5rem;">' + title + '</div>';
    (piText[set] || []).forEach(function (q, k) {
      var ans = pi.a[set][k] || 'N/A';
      html += '<div class="qa-row"><div class="qa-question">' + escapeHtml(q) + '</div><div class="qa-answer ' + (ans.toLowerCase() === 'yes' ? 'yes' : 'no') + '">' + escapeHtml(ans.charAt(0).toUpperCase() + ans.slice(1)) + '</div></div>';
    });
  });
  document.getElementById('piDrawerContent').innerHTML = html;
  document.getElementById('pi-drawer-name').textContent = 'Assessment Submitted: ' + pi.when;
  document.getElementById('pi-drawer-sub').textContent = 'Questionnaire Version ' + pi.ver;
  document.getElementById('piDrawer').hidden = false;
  document.getElementById('piDrawerBackdrop').hidden = false;
  document.getElementById('piDrawer').querySelector('.btn-icon').focus();
}
function closeIntakeDrawer() {
  document.getElementById('piDrawer').hidden = true;
  document.getElementById('piDrawerBackdrop').hidden = true;
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && !document.getElementById('piDrawer').hidden) closeIntakeDrawer();
});
</script>
<?php endif; ?>
