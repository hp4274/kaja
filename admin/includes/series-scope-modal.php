<?php
/** Series scope picker, shared by sessions.php and client-profile.php. Defines seriesScopeFor(id, verb). */
?>
<!-- Series scope: which sessions of a repeating series a status change applies to -->
<div class="modal-overlay" id="seriesScopeModal">
  <div class="modal-box is-narrow">
    <div class="modal-header">
      <div class="modal-title">This session repeats</div>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label" for="seriesScopeSelect">Which sessions should <span id="seriesScopeVerb">change</span>?</label>
        <select class="form-select" id="seriesScopeSelect">
          <option value="" disabled selected>Choose…</option>
          <option value="one">No further sessions</option>
          <?php foreach ([1, 2, 3, 4, 6, 8, 12] as $w): ?>
          <option value="weeks:<?php echo $w; ?>">Repeat for the next <?php echo $w; ?> week<?php echo $w > 1 ? 's' : ''; ?></option>
          <?php endforeach; ?>
          <option value="future">All later sessions</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" id="seriesScopeCancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="seriesScopeOk">Apply</button>
    </div>
  </div>
</div>

<script>
// ─── Series scope ────────────────────────────────────────────────────────
//
// Whenever a session belongs to a recurring series, the admin is asked whether
// an action applies to this occurrence or to this and every later one.
// Guessing is the defining bug of recurring appointments, and both wrong
// answers stay invisible until somebody turns up to an appointment that was
// cancelled without them.
//
// SESSION_SERIES maps session id -> series id, emitted by the page.
async function seriesScopeFor(id, verb) {
  if (!window.SESSION_SERIES || !SESSION_SERIES[id]) {
    return 'one';   // not part of a series: nothing to ask
  }
  document.getElementById('seriesScopeVerb').textContent = verb;
  var sel = document.getElementById('seriesScopeSelect');
  sel.value = '';   // nothing preselected on purpose, same as the reschedule modal
  var modal = document.getElementById('seriesScopeModal');
  modal.classList.add('open');
  return new Promise(function (resolve) {
    function done(v) {
      modal.classList.remove('open');
      document.getElementById('seriesScopeOk').onclick = null;
      document.getElementById('seriesScopeCancel').onclick = null;
      modal.onclick = null;
      resolve(v);
    }
    document.getElementById('seriesScopeOk').onclick = function () {
      if (!sel.value) { showToast('Choose which sessions this applies to', 'error'); return; }
      done(sel.value);
    };
    document.getElementById('seriesScopeCancel').onclick = function () { done(null); };
    modal.onclick = function (e) { if (e.target === modal) done(null); };
  });
}
</script>
