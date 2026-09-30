<?php
/* $client, $db available. Clinical notes are private: never selected here. */
require_once dirname(__DIR__) . '/api/session-lib.php';
$stmt = $db->prepare('SELECT `id`,`start_time`,`end_time`,`session_type`,`status`,`rescheduled_count` FROM `sessions` WHERE `client_id` = :c ORDER BY `start_time` ASC');
$stmt->execute([':c' => (int) $client['id']]);
$upcoming = $history = [];
$cnt = ['pending' => 0, 'completed' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    if (in_array($s['status'], ['pending', 'confirmed', 'rejected'], true) && strtotime($s['end_time']) >= time()) {
        $upcoming[] = $s;
        if ($s['status'] === 'pending') { $cnt['pending']++; }
    } else {
        array_unshift($history, $s);
        if ($s['status'] === 'completed') { $cnt['completed']++; }
    }
}
$bookable = clientIsBookable($client['status']);
$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+' . PORTAL_MAX_DAYS . ' days'));
$sessRow = function ($s, $actions) {
    $st = strtotime($s['start_time']); ?>
    <tr>
      <td><?= e(date('D, d M Y', $st)) ?></td>
      <td><?= e(date('h:i A', $st)) ?></td>
      <td><?= (int) round((strtotime($s['end_time']) - $st) / 60) ?> min</td>
      <td><?= $s['session_type'] === 'online' ? 'Online' : 'In person' ?></td>
      <td><span class="badge badge-session-<?= e($s['status']) ?>"><?= e(sessionStatusLabel($s['status'])) ?></span></td>
      <?php if ($actions !== null): ?><td class="td-right"><?= $actions ?></td><?php endif; ?>
    </tr>
<?php };
$thead = function ($withActions) { ?>
    <thead><tr><th>Date</th><th>Time</th><th>Duration</th><th>Type</th><th>Status</th><?php if ($withActions): ?><th class="th-right">Actions</th><?php endif; ?></tr></thead>
<?php };
?>
<div class="stat-strip">
  <div class="stat-strip-card">
    <div class="stat-strip-icon teal"><i class="bi bi-calendar-event"></i></div>
    <div><div class="stat-strip-num"><?= count($upcoming) ?></div><div class="stat-strip-label">Upcoming</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon amber"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="stat-strip-num"><?= $cnt['pending'] ?></div><div class="stat-strip-label">Pending</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon green"><i class="bi bi-calendar-check"></i></div>
    <div><div class="stat-strip-num"><?= $cnt['completed'] ?></div><div class="stat-strip-label">Completed</div></div>
  </div>
</div>

<div class="panel" id="psPicker">
  <div class="panel-header">
    <div>
      <div class="panel-title">Book a session</div>
      <div class="panel-subtitle">Pick a day, then a time. Changes need 24 hours notice.</div>
    </div>
  </div>
  <div class="panel-body">
    <?php if (!$bookable): ?>
      <div class="empty-state"><i class="bi bi-lock"></i><p>Booking is not available on your account yet. Please contact the practice.</p></div>
    <?php else: ?>
      <div class="form-inline">
        <div class="form-group">
          <label class="form-label" for="bkDate">Date</label>
          <input type="date" id="bkDate" class="form-input" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" />
        </div>
        <div class="form-group">
          <label class="form-label" for="bkType">Type</label>
          <select id="bkType" class="form-select"><option value="online">Online</option><option value="inperson">In person</option></select>
        </div>
      </div>
      <div class="form-inline" id="bkSlots"></div>
      <div class="alert" id="bkMsg" hidden></div>
      <button class="btn btn-primary" id="bkGo" disabled>Request session</button>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Upcoming sessions</div></div>
  <?php if (!$upcoming): ?>
    <div class="panel-body"><div class="empty-state"><i class="bi bi-calendar-x"></i><p>No upcoming sessions.</p></div></div>
  <?php else: ?>
  <div class="panel-body-flush"><div class="data-table-wrap"><table class="data-table">
    <?php $thead(true); ?>
    <tbody>
    <?php foreach ($upcoming as $s):
      $act = '';
      if (strtotime($s['start_time']) - time() >= PORTAL_MIN_NOTICE) {
          if ($s['status'] !== 'rejected') {
              $act .= '<button class="btn btn-ghost btn-sm" data-resched="' . (int) $s['id'] . '">Reschedule</button> ';
          }
          $act .= '<button class="btn btn-danger btn-sm" data-cancel="' . (int) $s['id'] . '">Cancel</button>';
      } else {
          $act = '<span class="hint">Changes need 24h notice</span>';
      }
      $sessRow($s, $act);
    endforeach; ?>
    </tbody>
  </table></div></div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">History</div></div>
  <?php if (!$history): ?>
    <div class="panel-body"><div class="empty-state"><i class="bi bi-clock-history"></i><p>No past sessions yet.</p></div></div>
  <?php else: ?>
  <div class="panel-body-flush"><div class="data-table-wrap"><table class="data-table">
    <?php $thead(false); ?>
    <tbody><?php foreach ($history as $s) { $sessRow($s, null); } ?></tbody>
  </table></div></div>
  <?php endif; ?>
</div>

<div class="modal-overlay" id="rsModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Choose a new time</div>
      <button class="modal-close" type="button" data-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label" for="rsDate">New date</label>
        <input type="date" id="rsDate" class="form-input" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" />
      </div>
      <div class="form-inline" id="rsSlots"></div>
      <div class="alert" id="rsMsg" hidden></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-close>Close</button>
      <button type="button" class="btn btn-primary" id="rsGo" disabled>Reschedule session</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="cnModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Cancel this session?</div>
      <button class="modal-close" type="button" data-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p class="prose">The time will be released. Sessions can only be cancelled with 24 hours notice.</p>
      <div class="alert" id="cnMsg" hidden></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" data-close>Keep session</button>
      <button type="button" class="btn btn-danger" id="cnGo">Cancel session</button>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  var $ = function (id) { return document.getElementById(id); };
  function post(url, data) {
    var fd = new FormData(); fd.append('csrf', csrf);
    for (var k in data) fd.append(k, data[k]);
    return fetch('api/' + url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  function msg(el, t, ok) {
    el.textContent = t || ''; el.hidden = !t;
    el.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
  }
  /* One slot picker, used by the booking panel (p='bk') and the reschedule modal (p='rs'). */
  function picker(p, sessionId) {
    var st = { time: '' }, go = $(p + 'Go'), box = $(p + 'Slots');
    function load() {
      st.time = ''; go.disabled = true; box.innerHTML = '';
      if (!$(p + 'Date').value) return;
      post('session-slots.php', { date: $(p + 'Date').value, session_id: sessionId() }).then(function (r) {
        if (!r.slots || !r.slots.length) { msg($(p + 'Msg'), 'No available times on that day.', false); return; }
        msg($(p + 'Msg'), '', true);
        r.slots.forEach(function (s) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-ghost btn-sm'; b.textContent = s.label;
          b.onclick = function () {
            st.time = s.time; go.disabled = false;
            [].forEach.call(box.children, function (c) { c.className = 'btn btn-ghost btn-sm'; });
            b.className = 'btn btn-primary btn-sm';
          };
          box.appendChild(b);
        });
      });
    }
    $(p + 'Date').addEventListener('change', load);
    go.onclick = function () {
      go.disabled = true;
      var date = $(p + 'Date').value;
      var pr = p === 'rs' ? post('session-reschedule.php', { session_id: sessionId(), date: date, time: st.time })
                          : post('session-book.php', { date: date, time: st.time, type: $('bkType').value });
      pr.then(function (r) {
        if (r.success) { msg($(p + 'Msg'), r.message, true); setTimeout(function () { location.reload(); }, 1500); }
        else { msg($(p + 'Msg'), r.error, false); load(); }
      });
    };
    return { load: load };
  }
  var rsId = 0, cnId = 0;
  if ($('bkDate')) picker('bk', function () { return 0; });
  var rs = picker('rs', function () { return rsId; });
  function closeAll() { [].forEach.call(document.querySelectorAll('.modal-overlay'), function (m) { m.classList.remove('open'); }); }
  [].forEach.call(document.querySelectorAll('.modal-overlay'), function (m) {
    m.addEventListener('click', function (e) { if (e.target === m || e.target.hasAttribute('data-close')) closeAll(); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
  [].forEach.call(document.querySelectorAll('[data-resched]'), function (b) {
    b.onclick = function () {
      rsId = +b.dataset.resched; $('rsDate').value = ''; msg($('rsMsg'), '', true); rs.load(); $('rsModal').classList.add('open');
    };
  });
  [].forEach.call(document.querySelectorAll('[data-cancel]'), function (b) {
    b.onclick = function () { cnId = b.dataset.cancel; msg($('cnMsg'), '', true); $('cnGo').disabled = false; $('cnModal').classList.add('open'); };
  });
  $('cnGo').onclick = function () {
    $('cnGo').disabled = true;
    post('session-cancel.php', { session_id: cnId }).then(function (r) {
      if (r.success) location.reload(); else { msg($('cnMsg'), r.error, false); $('cnGo').disabled = false; }
    });
  };
})();
</script>
