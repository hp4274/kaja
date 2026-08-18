/**
 * Intake wizard: paginates the existing form, tracks progress, flips the link
 * to "filled" on first input, and autosaves a draft.
 *
 * The form markup is untouched. Steps are derived from the section headings
 * already in the HTML, so adding a section to the form adds a step here with
 * no change to this file.
 */
(function () {
  var boot = window.INTAKE_BOOT;
  if (!boot) return;

  var form = document.getElementById('patientIntakeForm');
  if (!form) return;

  // ---- Build the steps from the existing section headings -----------------
  var headings = Array.prototype.slice.call(form.querySelectorAll('.ra-form-section-hdr'));
  if (headings.length < 2) return;   // nothing to paginate

  var steps = headings.map(function (h) {
    var step = document.createElement('div');
    step.className = 'intake-step';

    // Everything from this heading up to (not including) the next one.
    var collected = [];
    var node = h;
    while (node) {
      collected.push(node);
      var next = node.nextSibling;
      if (next && next.nodeType === 1 && next.classList.contains('ra-form-section-hdr')) break;
      node = next;
    }

    h.parentNode.insertBefore(step, h);
    collected.forEach(function (n) { step.appendChild(n); });

    return { el: step, title: h.textContent.trim() };
  });

  // ---- Progress bar -------------------------------------------------------
  var bar = document.createElement('div');
  bar.className = 'intake-progress';
  bar.innerHTML =
    '<div class="intake-progress-track"><div class="intake-progress-fill"></div></div>' +
    '<div class="intake-progress-label"></div>';
  form.insertBefore(bar, form.firstChild);

  var fill  = bar.querySelector('.intake-progress-fill');
  var label = bar.querySelector('.intake-progress-label');

  // ---- Navigation ---------------------------------------------------------
  var nav = document.createElement('div');
  nav.className = 'intake-nav';
  nav.innerHTML =
    '<button type="button" class="intake-btn intake-btn-back">Back</button>' +
    '<button type="button" class="intake-btn intake-btn-next">Continue</button>';
  form.appendChild(nav);

  var back    = nav.querySelector('.intake-btn-back');
  var next    = nav.querySelector('.intake-btn-next');
  var submit  = form.querySelector('button[type="submit"], input[type="submit"]');
  var current = 0;

  function show(index) {
    current = Math.max(0, Math.min(index, steps.length - 1));
    steps.forEach(function (s, i) { s.el.hidden = (i !== current); });

    var pct = Math.round(((current + 1) / steps.length) * 100);
    fill.style.width = pct + '%';
    label.textContent = 'Step ' + (current + 1) + ' of ' + steps.length + ' — ' + steps[current].title;

    back.hidden = (current === 0);
    next.hidden = (current === steps.length - 1);

    // The submit button belongs to the last step alone. Leaving it reachable
    // earlier would let someone post a half-answered questionnaire.
    if (submit) submit.hidden = (current !== steps.length - 1);

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /** Only block on fields inside the step being left — later steps are not filled yet. */
  function stepIsValid(index) {
    var invalid = steps[index].el.querySelectorAll(':invalid');
    if (invalid.length) {
      invalid[0].reportValidity();
      return false;
    }
    return true;
  }

  next.addEventListener('click', function () {
    if (!stepIsValid(current)) return;
    saveDraft();
    show(current + 1);
  });
  back.addEventListener('click', function () { show(current - 1); });

  // ---- Draft --------------------------------------------------------------
  function collect() {
    var out = {};
    new FormData(form).forEach(function (value, key) {
      if (key === 'token' || key === 'form_type' || key === 'admin_mode') return;
      if (typeof value === 'string') out[key] = value;
    });
    return out;
  }

  var saving = false;
  function saveDraft() {
    if (saving || !boot.token) return;
    saving = true;

    var body = new FormData();
    body.append('token', boot.token);
    body.append('answers', JSON.stringify(collect()));

    fetch(boot.endpoints.saveDraft, { method: 'POST', body: body })
      .catch(function () { /* a lost draft is not worth interrupting the form for */ })
      .finally(function () { saving = false; });
  }

  var debounce = null;
  function scheduleSave() {
    clearTimeout(debounce);
    debounce = setTimeout(saveDraft, 30000);
  }

  // ---- Filled beacon, fired once ------------------------------------------
  var beaconFired = false;
  form.addEventListener('input', function () {
    scheduleSave();

    if (beaconFired || !boot.token) return;
    beaconFired = true;

    var body = new FormData();
    body.append('token', boot.token);
    fetch(boot.endpoints.markFilled, { method: 'POST', body: body }).catch(function () {});
  });

  // Someone closing the tab mid-answer is exactly who this feature is for, and
  // a normal fetch would be cancelled on unload. sendBeacon survives it.
  window.addEventListener('pagehide', function () {
    if (!boot.token || !navigator.sendBeacon) return;
    var body = new FormData();
    body.append('token', boot.token);
    body.append('answers', JSON.stringify(collect()));
    navigator.sendBeacon(boot.endpoints.saveDraft, body);
  });

  // ---- Restore what we already know ---------------------------------------
  if (boot.draft) {
    Object.keys(boot.draft).forEach(function (name) {
      var fields = form.querySelectorAll('[name="' + name + '"]');
      Array.prototype.forEach.call(fields, function (f) {
        if (f.type === 'radio' || f.type === 'checkbox') {
          if (f.value === boot.draft[name]) f.checked = true;
        } else {
          f.value = boot.draft[name];
        }
      });
    });
  }

  // Clearing the draft is the server's job on submit; the page does not need
  // to know whether the POST succeeded.
  show(0);
})();
