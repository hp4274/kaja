<?php
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/email-templates.php';

$templates = emailTemplates();
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['emails_action'] ?? '') === 'save_templates') {
    // Whitelisted from the catalogue itself, so a key the browser posts that
    // no email actually reads is ignored rather than written into settings.
    $written = 0;
    foreach (emailTemplateKeys() as $key) {
        if (array_key_exists($key, $_POST)) {
            setSetting($key, trim($_POST[$key]));
            $written++;
        }
    }

    // An email whose only job is to carry a link, sent without the link, is
    // worse than not sending it: the recipient is told to act and given no
    // way to. Refused here as well as in the browser, because a form check
    // is a convenience and this is the actual rule.
    $broken = [];
    foreach ($templates as $template) {
        foreach ($template['required'] as $placeholder) {
            $body = (string) getSetting($template['body_key']);
            if (strpos($body, '{{' . $placeholder . '}}') === false) {
                $broken[] = $template['label'] . ' is missing {{' . $placeholder . '}}';
            }
        }
    }

    $successMsg = $written > 0 ? 'Email templates saved.' : '';
    $errorMsg   = $broken ? implode('. ', $broken) . '.' : '';

    // Reload so the editors show what is actually stored.
    $templates = emailTemplates();
}

$first = array_key_first($templates);
?>

<?php if ($successMsg): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle"></i> <span><?php echo htmlspecialchars($successMsg); ?></span></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
  <div class="alert alert-error"><i class="bi bi-exclamation-circle"></i> <span><?php echo htmlspecialchars($errorMsg); ?></span></div>
<?php endif; ?>

<!-- Every template's fields stay in this one form, so a wording change made
     on one email and another made on a second are written by the same Save. -->
<form method="post" id="emails-form">
  <input type="hidden" name="emails_action" value="save_templates" />

  <div class="mail-layout">
    <!-- The list is the navigation. Five twelve-row textareas stacked down a
         page is a scroll, not a screen; one at a time is the whole reason
         this page exists separately from Settings. -->
    <nav class="mail-list" role="tablist" aria-label="Emails">
      <?php foreach ($templates as $id => $t): ?>
        <button type="button"
                class="mail-list-item<?php echo $id === $first ? ' active' : ''; ?>"
                role="tab"
                id="mailtab-<?php echo htmlspecialchars($id); ?>"
                aria-controls="mailpane-<?php echo htmlspecialchars($id); ?>"
                aria-selected="<?php echo $id === $first ? 'true' : 'false'; ?>"
                tabindex="<?php echo $id === $first ? '0' : '-1'; ?>"
                data-mail="<?php echo htmlspecialchars($id); ?>">
          <i class="bi <?php echo htmlspecialchars($t['icon']); ?>" aria-hidden="true"></i>
          <span class="mail-list-text">
            <span class="mail-list-label"><?php echo htmlspecialchars($t['label']); ?></span>
            <span class="mail-list-when"><?php echo htmlspecialchars($t['trigger']); ?></span>
          </span>
          <span class="mail-list-flag" data-flag-for="<?php echo htmlspecialchars($id); ?>" hidden
                title="This email is missing something it cannot work without">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
          </span>
        </button>
      <?php endforeach; ?>
    </nav>

    <div class="mail-panes">
      <?php foreach ($templates as $id => $t): ?>
        <section class="mail-pane"
                 id="mailpane-<?php echo htmlspecialchars($id); ?>"
                 role="tabpanel"
                 aria-labelledby="mailtab-<?php echo htmlspecialchars($id); ?>"
                 data-mailpane="<?php echo htmlspecialchars($id); ?>"
                 <?php echo $id === $first ? '' : 'hidden'; ?>>

          <div class="panel">
            <div class="panel-header">
              <div>
                <div class="panel-title"><?php echo htmlspecialchars($t['label']); ?></div>
                <div class="panel-subtitle"><?php echo htmlspecialchars($t['blurb']); ?></div>
              </div>
            </div>

            <div class="panel-body">
              <div class="split-grid">
                <div>
                  <div class="mail-field">
                    <label class="setting-label" for="subject-<?php echo htmlspecialchars($id); ?>">Subject</label>
                    <input class="form-input"
                           id="subject-<?php echo htmlspecialchars($id); ?>"
                           name="<?php echo htmlspecialchars($t['subject_key']); ?>"
                           data-mail-subject="<?php echo htmlspecialchars($id); ?>"
                           value="<?php echo htmlspecialchars(getSetting($t['subject_key'])); ?>" />
                  </div>

                  <div class="mail-field">
                    <label class="setting-label" for="body-<?php echo htmlspecialchars($id); ?>">Message</label>
                    <div class="setting-help">Click a placeholder to drop it in at the cursor.</div>
                    <div class="placeholder-chips">
                      <?php foreach (array_keys($t['vars']) as $var):
                        $isRequired = in_array($var, $t['required'], true); ?>
                        <button type="button"
                                class="placeholder-chip<?php echo $isRequired ? ' is-required' : ''; ?>"
                                data-placeholder="{{<?php echo htmlspecialchars($var); ?>}}"
                                data-target="body-<?php echo htmlspecialchars($id); ?>"
                                title="<?php echo $isRequired
                                    ? 'Required - this email does not work without it'
                                    : 'Click to insert at the cursor'; ?>">{{<?php echo htmlspecialchars($var); ?>}}</button>
                      <?php endforeach; ?>
                    </div>
                    <textarea class="form-textarea"
                              id="body-<?php echo htmlspecialchars($id); ?>"
                              name="<?php echo htmlspecialchars($t['body_key']); ?>"
                              data-mail-body="<?php echo htmlspecialchars($id); ?>"
                              rows="14"><?php echo htmlspecialchars(getSetting($t['body_key'])); ?></textarea>
                    <?php if ($t['required']): ?>
                      <p class="field-error" data-error-for="<?php echo htmlspecialchars($id); ?>" role="alert" hidden>
                        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                        <span>Add <code>{{<?php echo htmlspecialchars($t['required'][0]); ?>}}</code>
                          - without it this email arrives with nothing to act on.</span>
                      </p>
                    <?php endif; ?>
                  </div>

                  <!-- Not part of the form: an image is stored the moment it is
                       chosen, like the dark-mode switch, so there is nothing
                       to save and nothing for Discard to undo. It has no name
                       attribute for the same reason -- it must not show up in
                       the save bar's idea of what changed. -->
                  <div class="mail-field">
                    <span class="setting-label" id="bg-label-<?php echo htmlspecialchars($id); ?>">Background image</span>
                    <div class="setting-help">
                      Sits behind the message. It is stored on the site and linked from the email,
                      never attached. The text stays on a white card, so it reads on any picture.
                    </div>
                    <div class="bg-picker" role="group" aria-labelledby="bg-label-<?php echo htmlspecialchars($id); ?>">
                      <div class="bg-thumb" data-bg-thumb="<?php echo htmlspecialchars($id); ?>" aria-hidden="true">
                        <i class="bi bi-image"></i>
                      </div>
                      <div class="bg-picker-actions">
                        <input type="file" hidden
                               accept="image/jpeg,image/png,image/webp,image/gif"
                               data-bg-input="<?php echo htmlspecialchars($id); ?>"
                               data-max="<?php echo emailBackgroundMaxBytes(); ?>" />
                        <button type="button" class="btn btn-ghost btn-sm" data-bg-choose="<?php echo htmlspecialchars($id); ?>">
                          <i class="bi bi-upload" aria-hidden="true"></i> <span>Choose image</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm" data-bg-remove="<?php echo htmlspecialchars($id); ?>" hidden>
                          <i class="bi bi-trash3" aria-hidden="true"></i> Remove
                        </button>
                      </div>
                    </div>
                    <p class="field-error" data-bg-error="<?php echo htmlspecialchars($id); ?>" role="alert" hidden>
                      <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span></span>
                    </p>
                    <!-- A background the recipient's mail app cannot fetch is a
                         background that never appears, and nothing else says so. -->
                    <div class="setting-echo is-warning" data-bg-warn="<?php echo htmlspecialchars($id); ?>" role="status" hidden>
                      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                      <span>Recipients&rsquo; mail apps fetch this image from an address that only works on this
                        computer, so for now they will see a plain grey background. It works once the site is
                        live, or once <code>site_base_url</code> is set to its public address.</span>
                    </div>
                  </div>
                </div>

                <div class="mail-field">
                  <span class="setting-label">What lands in their inbox</span>
                  <div class="mailbox">
                    <div class="mailbox-chrome">
                      <i class="bi bi-inbox" aria-hidden="true"></i> Inbox
                    </div>
                    <div class="mailbox-subject" data-preview-subject="<?php echo htmlspecialchars($id); ?>"></div>
                    <div class="mailbox-from">
                      <div class="mailbox-avatar" aria-hidden="true"><?php
                        echo htmlspecialchars(strtoupper(substr(getSetting('practice_name') ?: 'P', 0, 1)));
                      ?></div>
                      <div class="mailbox-who">
                        <div class="mailbox-sender">
                          <?php echo htmlspecialchars(getSetting('practice_name') ?: 'The practice'); ?>
                          <span>&lt;<?php echo htmlspecialchars(getSetting('practice_email') ?: 'no-reply@example.com'); ?>&gt;</span>
                        </div>
                        <div class="mailbox-to">to <?php echo htmlspecialchars($t['to']); ?></div>
                      </div>
                      <div class="mailbox-time"><?php echo date('d M, H:i'); ?></div>
                    </div>
                    <div class="mailbox-stage" data-preview-stage="<?php echo htmlspecialchars($id); ?>">
                      <div class="mailbox-body" data-preview-body="<?php echo htmlspecialchars($id); ?>"></div>
                    </div>
                    <div class="mailbox-foot">
                      Sample values. The real email uses their own details.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="save-bar" id="emails-save-bar" hidden>
    <span class="save-bar-note">You have unsaved changes.</span>
    <span class="btn-pair">
      <button type="button" class="btn btn-ghost btn-sm" id="emails-discard">Discard</button>
      <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
    </span>
  </div>

  <noscript>
    <button type="submit" class="btn btn-primary">Save changes</button>
  </noscript>
</form>

<!-- The catalogue's sample values, so the preview fills the same placeholders
     the sender will fill, from the same list. -->
<script type="application/json" id="mail-samples"><?php
  $samples = [];
  foreach ($templates as $id => $t) {
      $publicUrl = emailBackgroundUrl($id);
      $samples[$id] = [
          'vars'     => $t['vars'],
          'required' => $t['required'],
          'bg'       => emailBackgroundPreviewUrl($id),
          'private'  => $publicUrl !== '' && emailUrlIsPrivate($publicUrl),
      ];
  }
  echo json_encode($samples, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
?></script>

<script>
(function () {
  var form = document.getElementById('emails-form');
  if (!form) { return; }

  var SAMPLES = JSON.parse(document.getElementById('mail-samples').textContent);

  function escapeHtml(text) {
    var el = document.createElement('span');
    el.textContent = String(text == null ? '' : text);
    return el.innerHTML;
  }

  function fill(text, vars) {
    return String(text || '').replace(/\{\{(\w+)\}\}/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(vars, key) ? vars[key] : match;
    });
  }

  /**
   * The body as the recipient's mail client draws it.
   *
   * Any placeholder holding a URL becomes a real anchor: a preview that shows
   * the one thing somebody is going to click as flat grey text is not showing
   * what lands in their inbox. Escaping happens BEFORE the anchors are spliced
   * in, so a template cannot inject markup into this page.
   */
  function fillAsHtml(text, vars) {
    var marks = {};
    var n = 0;
    var marked = String(text || '').replace(/\{\{(\w+)\}\}/g, function (match, key) {
      if (!Object.prototype.hasOwnProperty.call(vars, key)) { return match; }
      if (!/^https?:\/\//i.test(String(vars[key]))) { return vars[key]; }
      var mark = '\u0000L' + (n++) + '\u0000';
      marks[mark] = vars[key];
      return mark;
    });

    var safe = escapeHtml(marked);
    Object.keys(marks).forEach(function (mark) {
      var href = escapeHtml(marks[mark]);
      safe = safe.split(mark).join('<a href="#" onclick="return false;">' + href + '</a>');
    });
    return safe;
  }

  function checkOne(id) {
    var required = (SAMPLES[id] || {}).required || [];
    var body = (document.querySelector('[data-mail-body="' + id + '"]') || {}).value || '';
    var missing = required.filter(function (key) {
      return body.indexOf('{{' + key + '}}') === -1;
    });

    var error = document.querySelector('[data-error-for="' + id + '"]');
    if (error) { error.hidden = missing.length === 0; }

    // Flagged in the list as well as in the pane: the broken one is probably
    // not the one on screen.
    var flag = document.querySelector('[data-flag-for="' + id + '"]');
    if (flag) { flag.hidden = missing.length === 0; }

    return missing.length === 0;
  }

  function render(id) {
    var vars = (SAMPLES[id] || {}).vars || {};
    var subject = (document.querySelector('[data-mail-subject="' + id + '"]') || {}).value || '';
    var body    = (document.querySelector('[data-mail-body="' + id + '"]') || {}).value || '';

    document.querySelector('[data-preview-subject="' + id + '"]').textContent = fill(subject, vars);
    document.querySelector('[data-preview-body="' + id + '"]').innerHTML = fillAsHtml(body, vars);
    checkOne(id);
  }

  function renderAll() { Object.keys(SAMPLES).forEach(function (id) { render(id); paintBg(id); }); }

  // ── Background image ─────────────────────────────────────────────────────
  // Applied the moment it is chosen: it is stored on the server straight away,
  // so it is not part of what Save or Discard covers.
  function q(attr, id) { return document.querySelector('[' + attr + '="' + id + '"]'); }

  function paintBg(id) {
    var s = SAMPLES[id];
    var url = s.bg ? 'url("' + s.bg + '")' : '';

    var stage = q('data-preview-stage', id);
    stage.classList.toggle('has-bg', !!s.bg);
    stage.style.backgroundImage = url;

    var thumb = q('data-bg-thumb', id);
    thumb.classList.toggle('has-image', !!s.bg);
    thumb.style.backgroundImage = url;

    q('data-bg-choose', id).querySelector('span').textContent = s.bg ? 'Replace image' : 'Choose image';
    q('data-bg-remove', id).hidden = !s.bg;
    q('data-bg-warn', id).hidden = !(s.bg && s.private);
  }

  function bgError(id, message) {
    var box = q('data-bg-error', id);
    box.hidden = !message;
    box.querySelector('span').textContent = message || '';
  }

  function sendBg(id, fd, done) {
    var choose = q('data-bg-choose', id), remove = q('data-bg-remove', id);
    // Disabled while in flight: a second click is a second upload racing the
    // first, and the loser's file is the one that ends up deleted.
    choose.disabled = remove.disabled = true;
    bgError(id, '');

    fd.append('template', id);
    fetch('api/email-bg.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { bgError(id, d.error || 'That did not work.'); return; }
        SAMPLES[id].bg = d.url;
        SAMPLES[id].private = !!d.private;
        paintBg(id);
        done();
      })
      .catch(function () { bgError(id, 'Network error. Try again.'); })
      .then(function () { choose.disabled = remove.disabled = false; });
  }

  document.querySelectorAll('[data-bg-choose]').forEach(function (btn) {
    var id = btn.dataset.bgChoose, input = q('data-bg-input', id);

    btn.addEventListener('click', function () { input.click(); });

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) { return; }

      // The server is the authority; this only spares a round trip for the
      // commonest mistake, a photograph straight off a phone.
      var max = parseInt(input.dataset.max, 10);
      if (file.size > max) {
        bgError(id, 'That image is ' + Math.round(file.size / 1024) + ' KB. The limit is '
          + Math.round(max / 1024) + ' KB, so the email stays quick to open on a phone.');
        input.value = '';
        return;
      }

      var fd = new FormData();
      fd.append('action', 'upload');
      fd.append('image', file);
      sendBg(id, fd, function () { showToast('Background saved'); });
      input.value = '';   // choosing the same file twice must still fire change
    });
  });

  document.querySelectorAll('[data-bg-remove]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.dataset.bgRemove;
      var fd = new FormData();
      fd.append('action', 'remove');
      sendBg(id, fd, function () { showToast('Background removed'); });
    });
  });

  // ── Which email is open ──────────────────────────────────────────────────
  var items = Array.prototype.slice.call(document.querySelectorAll('[data-mail]'));

  function open(id, focus) {
    if (!SAMPLES[id]) { return false; }
    items.forEach(function (item) {
      var on = item.dataset.mail === id;
      item.classList.toggle('active', on);
      item.setAttribute('aria-selected', on ? 'true' : 'false');
      item.tabIndex = on ? 0 : -1;
      if (on && focus) { item.focus(); }
    });
    document.querySelectorAll('[data-mailpane]').forEach(function (pane) {
      pane.hidden = pane.dataset.mailpane !== id;
    });
    return true;
  }

  items.forEach(function (item, i) {
    item.addEventListener('click', function () {
      open(item.dataset.mail);
      history.replaceState(null, '', '#' + item.dataset.mail);
    });
    item.addEventListener('keydown', function (e) {
      var step = (e.key === 'ArrowDown') ? 1 : (e.key === 'ArrowUp' ? -1 : 0);
      if (!step) { return; }
      e.preventDefault();
      var next = items[(i + step + items.length) % items.length];
      open(next.dataset.mail, true);
      history.replaceState(null, '', '#' + next.dataset.mail);
    });
  });

  // ── Placeholders you can click ───────────────────────────────────────────
  document.querySelectorAll('.placeholder-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var field = document.getElementById(chip.dataset.target);
      if (!field) { return; }
      var at = field.selectionStart, to = field.selectionEnd;
      field.value = field.value.slice(0, at) + chip.dataset.placeholder + field.value.slice(to);
      field.focus();
      field.selectionStart = field.selectionEnd = at + chip.dataset.placeholder.length;
      field.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // ── The save bar ─────────────────────────────────────────────────────────
  var bar     = document.getElementById('emails-save-bar');
  var discard = document.getElementById('emails-discard');
  var initial = new FormData(form);

  function changedLabels() {
    var now = new FormData(form);
    var changed = {};
    initial.forEach(function (value, key) {
      if (String(now.get(key)) === String(value)) { return; }
      var field = form.elements[key];
      var id = field && (field.dataset.mailSubject || field.dataset.mailBody);
      if (id) {
        var tab = document.querySelector('[data-mail="' + id + '"] .mail-list-label');
        changed[tab ? tab.textContent : id] = true;
      }
    });
    return Object.keys(changed);
  }

  function refreshBar() {
    var names = changedLabels();
    bar.hidden = names.length === 0;
    if (!names.length) { return; }
    document.querySelector('#emails-save-bar .save-bar-note').innerHTML =
      names.length <= 2
        ? 'Unsaved: ' + names.join(' and ') + '.'
        : 'Unsaved changes to <strong>' + names.length + '</strong> emails.';
  }

  form.addEventListener('input', function (e) {
    var id = e.target.dataset && (e.target.dataset.mailSubject || e.target.dataset.mailBody);
    if (id) { render(id); }
    refreshBar();
  });

  discard.addEventListener('click', function () {
    initial.forEach(function (value, key) {
      var field = form.elements[key];
      if (field) { field.value = value; }
    });
    renderAll();
    refreshBar();
  });

  window.addEventListener('beforeunload', function (e) {
    if (!changedLabels().length) { return; }
    e.preventDefault();
    e.returnValue = '';
  });

  // An email that cannot do its job is not worth saving. Refused here, and
  // again on the server, where the actual rule lives.
  form.addEventListener('submit', function (e) {
    var broken = Object.keys(SAMPLES).filter(function (id) { return !checkOne(id); });
    if (!broken.length) {
      initial = new FormData(form);
      bar.hidden = true;
      return;
    }
    e.preventDefault();
    open(broken[0]);
    // focus() scrolls it into view by itself; a scrollIntoView as well is a
    // second animation racing the first.
    document.querySelector('[data-mail-body="' + broken[0] + '"]').focus();
  });

  open((window.location.hash || '').replace('#', '')) || open(<?php echo json_encode($first); ?>);
  renderAll();
  refreshBar();
})();
</script>
