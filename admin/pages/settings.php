<?php
require_once __DIR__ . '/../../includes/settings.php';

$db = getDbConnection();
$currentUserId = $_SESSION['user_id'];

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_action'])) {
    if ($_POST['settings_action'] === 'add_admin') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $errorMsg = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password must be at least 6 characters.';
        } else {
            // Check if username or email already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = :u OR `email` = :e");
            $stmt->execute([':u' => $username, ':e' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $errorMsg = 'Username or email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO `users` (`username`, `email`, `password`) VALUES (:u, :e, :p)");
                $ins->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
                $successMsg = "Admin user '" . htmlspecialchars($username) . "' has been added successfully.";
            }
        }
    }

    if ($_POST['settings_action'] === 'remove_admin') {
        $adminId = intval($_POST['admin_id'] ?? 0);

        if ($adminId === $currentUserId) {
            $errorMsg = 'You cannot remove your own admin account.';
        } else {
            // Check count of remaining admins
            $count = $db->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
            if ($count <= 1) {
                $errorMsg = 'At least one admin account must remain.';
            } else {
                // Get username for success message
                $stmt = $db->prepare("SELECT `username` FROM `users` WHERE `id` = :id");
                $stmt->execute([':id' => $adminId]);
                $usernameToDelete = $stmt->fetchColumn();

                if ($usernameToDelete) {
                    $del = $db->prepare("DELETE FROM `users` WHERE `id` = :id");
                    $del->execute([':id' => $adminId]);
                    $successMsg = "Admin user '" . htmlspecialchars($usernameToDelete) . "' has been removed successfully.";
                } else {
                    $errorMsg = 'Admin user not found.';
                }
            }
        }
    }

    if ($_POST['settings_action'] === 'save_settings') {
        // Whitelisted on purpose: setSetting() writes any key it is handed, and
        // this form is the one place a browser gets to choose one. Looping the
        // raw POST would let a crafted request create settings the app reads.
        // practice_name, practice_email and site_base_url are no longer on this
        // page, so the browser no longer gets to set them. They still exist and
        // are still read -- from the defaults in includes/settings.php, or from
        // whatever row the database already holds.
        $editable = [
            'intake_token_expiry_days', 'admin_reminder_hours', 'intake_form_version',
            'intake_reply_days', 'booking_slots',
        ];
        foreach ($editable as $key) {
            if (array_key_exists($key, $_POST)) {
                setSetting($key, trim($_POST[$key]));
            }
        }
        $successMsg = 'Settings saved.';
    }
}

// Fetch all admins
$admins = $db->query("SELECT * FROM `users` ORDER BY `username` ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
/**
 * The page below is a list of decisions, not a list of inputs.
 *
 * It was one panel holding ten fields in a single column: the practice name, a
 * number of days, the bookable times and a ten-row email template all in the
 * same rhythm, all the same width, with the Save button at the bottom of the
 * scroll. They are grouped here by what a decision affects, each one carrying
 * the consequence of changing it, sized to the answer it expects.
 */

/** One setting: its name, what changing it does, and the control. */
function settingRow($label, $for, $help, $control, $classes = '') {
    echo '<div class="setting ' . $classes . '">';
    // A choice group has no single field to point a label at, so it carries an
    // id the group references instead of a `for` aimed at a hidden input.
    $isChoice = strpos($control, 'data-choice-for="' . $for . '"') !== false;
    echo '<div>';
    echo $isChoice
        ? '<span class="setting-label" id="' . htmlspecialchars($for) . '-label">' . htmlspecialchars($label) . '</span>'
        : '<label class="setting-label" for="' . htmlspecialchars($for) . '">' . htmlspecialchars($label) . '</label>';
    if ($help !== '') {
        echo '<div class="setting-help">' . $help . '</div>';   // markup written here, not user input
    }
    echo '</div>';
    echo $control;
    echo '</div>';
}

function settingText($name, $id, $value, $type = 'text', $extra = '') {
    return '<div class="setting-control"><input class="form-input" type="' . $type . '" id="' . $id
         . '" name="' . $name . '" value="' . htmlspecialchars($value) . '" ' . $extra . ' /></div>';
}

/**
 * A number that means something.
 *
 * The unit sits beside it rather than in the label, the common answers are one
 * click away, and $echo names an element that says what the number resolves to
 * -- because "14" is a value and "a link sent today expires on 8 Sep" is the
 * decision actually being made.
 */
function settingNumber($name, $id, $value, $unit, $presets = [], $min = 1) {
    $out = '<div>';
    $out .= '<div class="setting-control is-short">'
          . '<input class="form-input" type="number" min="' . $min . '" id="' . $id . '" name="' . $name
          . '" value="' . htmlspecialchars($value) . '" />'
          . '<span class="setting-unit">' . htmlspecialchars($unit) . '</span></div>';

    if ($presets) {
        $out .= '<div class="setting-presets">';
        foreach ($presets as $preset) {
            $out .= '<button type="button" class="filter-btn" data-preset-for="' . $id . '"'
                  . ' data-preset="' . (int) $preset . '">' . (int) $preset . '</button>';
        }
        $out .= '</div>';
    }

    $out .= '<div class="setting-echo" data-echo-for="' . $id . '" role="status">'
          . '<i class="bi bi-arrow-return-right" aria-hidden="true"></i><span></span></div>';
    return $out . '</div>';
}
/**
 * A setting whose answers are a short list.
 *
 * No text box: the value lives in a hidden input and the buttons set it. A
 * reply time has four sensible answers and no sensible free-text one, and a
 * number box invites somebody to promise forty-seven working days by holding
 * a key down.
 *
 * A stored value outside the list still gets a button, so a number already in
 * the database can be seen and kept rather than silently reset by the first
 * click anywhere on the page.
 */
function settingChoice($name, $id, $value, $options, $unit) {
    $value = (int) $value;
    if ($value > 0 && !in_array($value, $options, true)) {
        $options[] = $value;
        sort($options);
    }

    $out = '<div>';
    $out .= '<input type="hidden" id="' . $id . '" name="' . $name . '" value="' . $value . '" />';
    $out .= '<div class="choice-row" role="group" aria-labelledby="' . $id . '-label">';
    foreach ($options as $option) {
        $on = ((int) $option === $value);
        $out .= '<button type="button" class="choice-btn' . ($on ? ' active' : '') . '"'
              . ' data-choice-for="' . $id . '" data-choice="' . (int) $option . '"'
              . ' aria-pressed="' . ($on ? 'true' : 'false') . '">' . (int) $option . '</button>';
    }
    $out .= '<span class="choice-unit">' . htmlspecialchars($unit) . '</span>';
    $out .= '</div>';
    $out .= '<div class="setting-echo" data-echo-for="' . $id . '" role="status">'
          . '<i class="bi bi-arrow-return-right" aria-hidden="true"></i><span></span></div>';
    return $out . '</div>';
}
?>

<?php if ($successMsg): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle"></i> <span><?php echo htmlspecialchars($successMsg); ?></span></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
  <div class="alert alert-error"><i class="bi bi-exclamation-circle"></i> <span><?php echo htmlspecialchars($errorMsg); ?></span></div>
<?php endif; ?>

<form method="post" id="settings-form" class="settings-page">
  <input type="hidden" name="settings_action" value="save_settings" />

  <div class="panel">
    <div class="panel-header">
      <div>
        <div class="panel-title">Intake</div>
        <div class="panel-subtitle">How long a questionnaire link lives, and when you get chased about it.</div>
      </div>
    </div>
    <div class="panel-body">
      <?php
      settingRow('Link expiry', 'set-expiry',
        'Applies to links issued from now on. A link already in someone&rsquo;s inbox keeps the deadline it was sent with.',
        settingNumber('intake_token_expiry_days', 'set-expiry', getSetting('intake_token_expiry_days'), 'days', [7, 14, 30]));

      settingRow('Chase me after', 'set-reminder',
        'How long an unopened intake sits before the dashboard lists it as stalled.',
        settingNumber('admin_reminder_hours', 'set-reminder', getSetting('admin_reminder_hours'), 'hours', [24, 48, 72]));

      settingRow('Reply time you promise', 'set-reply-days',
        'Printed on the page someone sees after submitting their questionnaire. Set it to what you can keep to &mdash; '
        . 'it is a promise made on your behalf while you are not there.',
        settingChoice('intake_reply_days', 'set-reply-days', getSetting('intake_reply_days'), [1, 2, 3, 5], 'working days'));
      ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <div>
        <div class="panel-title">Bookable times</div>
        <div class="panel-subtitle">The one list every booking surface offers.</div>
      </div>
    </div>
    <div class="panel-body">
      <div class="setting is-wide is-last">
        <div>
          <span class="setting-label" id="set-slots-label">Times offered</span>
          <div class="setting-help">
            The appointment page, the intake questionnaire and the session calendar all read this list,
            so a time missing here is a time nobody can be booked into. Sessions already booked keep the
            time they were given.
          </div>
        </div>
        <div>
          <!-- The times, as things. This was one text input holding
               "09:00,11:00,13:00": a typo in it dropped a slot from every
               booking surface at once and said nothing, because bookingSlots()
               discards what it cannot parse. Adding goes through a real time
               picker, which has no way to produce an unparseable time.

               The value still leaves here as the same comma-separated string
               the server has always read. Nothing downstream changes. -->
          <div class="time-chips" id="slot-chips" role="list" aria-labelledby="set-slots-label"></div>
          <div class="time-add">
            <input class="form-input" type="time" id="slot-new" step="900" aria-label="Time to add" />
            <button type="button" class="btn btn-ghost btn-sm" id="slot-add">
              <i class="bi bi-plus-lg"></i> Add time
            </button>
          </div>
          <p class="field-error" id="slot-error" role="alert" hidden>
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span></span>
          </p>
          <input type="hidden" id="set-slots" name="booking_slots"
                 value="<?php echo htmlspecialchars(getSetting('booking_slots')); ?>" />
        </div>
      </div>
    </div>
  </div>

  <!-- The email templates used to sit here, as one of the five the
       application sends. They have a page of their own now: Settings > Emails
       in the sidebar. -->
  <p class="prose">
    Wording for the emails this practice sends lives on the
    <a href="index.php?page=emails">Emails</a> page.
  </p>

  <!-- Appears once something has changed. A bar that is always there is a
       button that has stopped meaning anything. -->
  <div class="save-bar" id="save-bar" hidden>
    <span class="save-bar-note">You have unsaved changes.</span>
    <span class="btn-pair">
      <button type="button" class="btn btn-ghost btn-sm" id="settings-discard">Discard</button>
      <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
    </span>
  </div>

  <!-- Without JavaScript that bar never appears, so the form keeps a button. -->
  <noscript>
    <button type="submit" class="btn btn-primary">Save changes</button>
  </noscript>
</form>

<h2 class="settings-section-head settings-page">
  <i class="bi bi-person-gear" aria-hidden="true"></i> Access
</h2>

<div class="panel settings-page">
  <div class="panel-header">
    <div>
      <div class="panel-title">Appearance</div>
      <div class="panel-subtitle">Kept in this browser, not on the account.</div>
    </div>
  </div>
  <div class="panel-body">
    <div class="toggle-row">
      <div>
        <span class="setting-label">Dark mode</span>
        <div class="setting-help">Takes effect immediately &mdash; there is nothing to save.</div>
      </div>
      <label class="theme-switch">
        <input type="checkbox" id="darkModeToggle" />
        <span class="slider"></span>
      </label>
    </div>
  </div>
</div>

<div class="split-grid split-aside settings-page">
  <div class="panel">
    <div class="panel-header">
      <div>
        <div class="panel-title">Admin accounts</div>
        <div class="panel-subtitle">Everyone who can sign in to this panel.</div>
      </div>
    </div>
    <div class="panel-body panel-body-flush">
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Username</th><th>Email</th><th>Added</th><th class="th-right">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($admins as $admin): ?>
              <tr>
                <td class="td-name">
                  <?php echo htmlspecialchars($admin['username']); ?>
                  <?php if ($admin['id'] === $currentUserId): ?>
                    <span class="badge badge-converted">You</span>
                  <?php endif; ?>
                </td>
                <td class="td-email"><?php echo htmlspecialchars($admin['email']); ?></td>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($admin['created_at'])); ?></td>
                <td class="td-actions">
                  <?php if ($admin['id'] === $currentUserId): ?>
                    <span class="hint">Signed in</span>
                  <?php else: ?>
                    <form method="post" action="index.php?page=settings" class="form-inline" onsubmit="return confirm('Remove the admin account for <?php echo htmlspecialchars($admin['username'], ENT_QUOTES); ?>? They will not be able to sign in again.');">
                      <input type="hidden" name="settings_action" value="remove_admin" />
                      <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>" />
                      <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Remove</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <div>
        <div class="panel-title">Add an admin</div>
        <div class="panel-subtitle">They can sign in straight away, with everything you can see.</div>
      </div>
    </div>
    <div class="panel-body">
      <form method="post" action="index.php?page=settings">
        <input type="hidden" name="settings_action" value="add_admin" />
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input type="text" class="form-input" id="username" name="username" required autocomplete="off" />
        </div>
        <div class="form-group">
          <label class="form-label" for="email">Email address</label>
          <input type="email" class="form-input" id="email" name="email" required autocomplete="off" />
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input type="password" class="form-input" id="password" name="password" required minlength="6" autocomplete="new-password" />
          <small class="hint">At least six characters. There is no way for them to change it yet, so pick something you can pass on.</small>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create account</button>
      </form>
    </div>
  </div>
</div>

<style>
/* The switch itself. Everything else on this page comes from the stylesheet;
   this one control is only used here. */
.theme-switch { position: relative; display: inline-block; width: 52px; height: 28px; flex-shrink: 0; }
.theme-switch input {
  position: absolute; opacity: 0; width: 100%; height: 100%;
  top: 0; left: 0; margin: 0; cursor: pointer; z-index: 2;
}
.slider {
  position: absolute; inset: 0;
  background-color: var(--clr-border);
  transition: background-color var(--transition-base);
  border-radius: 28px;
  z-index: 1;
}
.slider:before {
  position: absolute; content: "";
  height: 20px; width: 20px; left: 4px; bottom: 4px;
  background-color: var(--clr-surface);
  transition: transform var(--transition-base);
  border-radius: 50%;
  box-shadow: var(--shadow-sm);
  z-index: 1;
}
.theme-switch input:checked + .slider { background-color: var(--clr-primary); }
.theme-switch input:checked + .slider:before { transform: translateX(24px); }
/* Keyboard users need to see where they are; the input itself is invisible. */
.theme-switch input:focus-visible + .slider { outline: 2px solid var(--clr-primary); outline-offset: 2px; }
</style>

<script>
/**
 * Three small things, all of them about seeing the consequence of a setting
 * before it is saved rather than after someone receives it.
 */
(function () {
  var form = document.getElementById('settings-form');
  if (!form) return;

  // ── The save bar ─────────────────────────────────────────────────────────
  // The Save button used to sit at the bottom of a long scroll, so changing
  // the practice name meant scrolling past the email template to commit it.
  var bar     = document.getElementById('save-bar');
  var discard = document.getElementById('settings-discard');
  var initial = new FormData(form);

  // What each field is called when the save bar has to name it. A key like
  // "notify_lead_confirmed_body" tells the person nothing about what they just
  // edited; "the acceptance email" is the thing they changed.
  var FIELD_NAMES = {
    'intake_token_expiry_days': 'link expiry',
    'admin_reminder_hours': 'when you get chased',
    'intake_reply_days': 'the reply time you promise',
    'booking_slots': 'the bookable times'
  };

  function changedKeys() {
    var now = new FormData(form);
    var changed = [];
    initial.forEach(function (value, key) {
      if (String(now.get(key)) !== String(value) && changed.indexOf(key) === -1) {
        changed.push(key);
      }
    });
    return changed;
  }

  function isDirty() { return changedKeys().length > 0; }

  function refreshBar() {
    var changed = changedKeys();
    bar.hidden = !changed.length;
    if (!changed.length) { return; }

    // Naming them only works while there are few enough to read. Past that the
    // count is the useful fact and the list is noise.
    var named = changed.map(function (k) { return FIELD_NAMES[k] || k; });
    var note  = named.length <= 2
      ? 'Unsaved: ' + named.join(' and ') + '.'
      : 'Unsaved changes to <strong>' + named.length + '</strong> settings.';
    document.querySelector('.save-bar-note').innerHTML = note;
  }

  form.addEventListener('input', refreshBar);
  form.addEventListener('change', refreshBar);

  discard.addEventListener('click', function () {
    // Put every field back to what was on the page when it loaded, rather than
    // reloading: a reload would lose the scroll position of a long page.
    initial.forEach(function (value, key) {
      var field = form.elements[key];
      if (field) { field.value = value; }
    });
    refreshBar();
    renderSlots();
    renderEchoes();
  });

  // Leaving with changes still on screen loses them silently otherwise.
  window.addEventListener('beforeunload', function (e) {
    if (!isDirty()) return;
    e.preventDefault();
    e.returnValue = '';
  });
  form.addEventListener('submit', function () {
    initial = new FormData(form);   // saving is not losing
    bar.hidden = true;
  });

  // ── The times, as a set ──────────────────────────────────────────────────
  // The hidden field still carries "09:00,11:00,13:00" -- the format the server
  // has always parsed. What changed is that nobody types it: a time is added
  // through a time picker, which cannot produce one bookingSlots() would throw
  // away, and removed by the chip it is written on.
  var slotStore = document.getElementById('set-slots');
  var slotChips = document.getElementById('slot-chips');
  var slotInput = document.getElementById('slot-new');
  var slotAdd   = document.getElementById('slot-add');
  var slotError = document.getElementById('slot-error');

  function slotList() {
    return (slotStore.value || '').split(',').map(function (t) {
      var m = /^\s*(\d{1,2}):(\d{2})\s*$/.exec(t);
      if (!m) { return null; }
      var h = parseInt(m[1], 10);
      if (h > 23 || parseInt(m[2], 10) > 59) { return null; }
      return (h < 10 ? '0' + h : String(h)) + ':' + m[2];
    }).filter(Boolean);
  }

  function label(slot) {
    var parts = slot.split(':');
    var h = parseInt(parts[0], 10);
    return (h % 12 === 0 ? 12 : h % 12) + ':' + parts[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
  }

  function saySlotError(message) {
    if (!slotError) { return; }
    slotError.hidden = !message;
    slotError.querySelector('span').textContent = message || '';
  }

  function writeSlots(slots) {
    // Sorted and deduplicated on the way in, so the stored order matches the
    // order every booking form will offer them in.
    slots = slots.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort();
    slotStore.value = slots.join(',');
    renderSlots();
    slotStore.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function renderSlots() {
    var slots = slotList();
    slotChips.innerHTML = '';

    if (!slots.length) {
      // A practice with no times cannot take a booking at all. Worth saying,
      // rather than showing an empty row and letting it be discovered later.
      var empty = document.createElement('span');
      empty.className = 'time-chips-empty';
      empty.textContent = 'No times set — nobody can be booked. The shipped default is used instead.';
      slotChips.appendChild(empty);
      return;
    }

    slots.forEach(function (slot) {
      var chip = document.createElement('span');
      chip.className = 'time-chip';
      chip.setAttribute('role', 'listitem');

      var text = document.createElement('span');
      text.textContent = label(slot);
      chip.appendChild(text);

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'time-chip-remove';
      remove.setAttribute('aria-label', 'Remove ' + label(slot));
      remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
      remove.addEventListener('click', function () {
        writeSlots(slotList().filter(function (s) { return s !== slot; }));
        slotInput.focus();
      });
      chip.appendChild(remove);

      slotChips.appendChild(chip);
    });
  }

  function addSlot() {
    var value = (slotInput.value || '').trim();
    if (!value) {
      saySlotError('Pick a time first.');
      slotInput.focus();
      return;
    }
    var slot = value.slice(0, 5);
    if (slotList().indexOf(slot) !== -1) {
      saySlotError(label(slot) + ' is already offered.');
      return;
    }
    saySlotError('');
    writeSlots(slotList().concat([slot]));
    slotInput.value = '';
    slotInput.focus();
  }

  if (slotAdd) { slotAdd.addEventListener('click', addSlot); }
  if (slotInput) {
    // Enter in a field inside a form submits it. Here it means "add this one".
    slotInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); addSlot(); }
    });
  }

  // ── What a number actually decides ───────────────────────────────────────
  // A duration in a box is an abstraction. These say what it resolves to, in
  // the same words the person on the other end of it will read.
  var DAY = 864e5;

  function onDate(date) {
    return date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }

  var ECHOES = {
    'set-expiry': function (n) {
      if (!n) { return ['is-danger', 'A link would expire the moment it is sent.']; }
      return ['', 'A link sent today would expire on ' + onDate(new Date(Date.now() + n * DAY)) + '.'];
    },
    'set-reminder': function (n) {
      if (!n) { return ['is-danger', 'Every unopened intake would be listed as stalled straight away.']; }
      var when = new Date(Date.now() + n * 36e5);
      var day = n >= 24 ? onDate(when) + ', ' : '';
      return ['', 'An intake sent now would be listed as stalled from ' + day
                + when.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) + '.'];
    },
    'set-reply-days': function (n) {
      if (!n) { return ['is-danger', 'A promise of no working days is not a promise.']; }
      var tone = n > 5 ? 'is-warning' : '';
      var note = n > 5 ? ' That is over a week — it is a promise made on your behalf while you are not there.' : '';
      return [tone, 'They are told to expect a reply within ' + n + ' working day'
                  + (n === 1 ? '' : 's') + '.' + note];
    }
  };

  function renderEcho(id) {
    var field = document.getElementById(id);
    var echo  = document.querySelector('[data-echo-for="' + id + '"]');
    if (!field || !echo || !ECHOES[id]) { return; }

    var result = ECHOES[id](parseInt(field.value, 10) || 0);
    echo.className = 'setting-echo ' + result[0];
    echo.querySelector('span').textContent = result[1];

    // The preset that matches the value in the box is the one that is set.
    document.querySelectorAll('[data-preset-for="' + id + '"]').forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.preset === String(field.value));
    });
    document.querySelectorAll('[data-choice-for="' + id + '"]').forEach(function (btn) {
      var on = btn.dataset.choice === String(field.value);
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function renderEchoes() { Object.keys(ECHOES).forEach(renderEcho); }

  // A setting whose answers are a list: the buttons ARE the field. The value
  // lives in a hidden input, so the form still posts one number.
  document.querySelectorAll('[data-choice-for]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.dataset.choiceFor;
      var field = document.getElementById(id);
      if (!field) { return; }

      field.value = btn.dataset.choice;
      document.querySelectorAll('[data-choice-for="' + id + '"]').forEach(function (other) {
        var on = other === btn;
        other.classList.toggle('active', on);
        other.setAttribute('aria-pressed', on ? 'true' : 'false');
      });

      // A hidden input fires nothing of its own, and the save bar listens on
      // the form, so the change has to be announced.
      field.dispatchEvent(new Event('input', { bubbles: true }));
      renderEcho(id);
    });
  });

  document.querySelectorAll('[data-preset-for]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var field = document.getElementById(btn.dataset.presetFor);
      if (!field) { return; }
      field.value = btn.dataset.preset;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      renderEcho(btn.dataset.presetFor);
    });
  });

  form.addEventListener('input', function (e) {
    if (e.target.id && ECHOES[e.target.id]) { renderEcho(e.target.id); }
  });

  renderSlots();
  renderEchoes();
})();

// ── Dark mode ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('darkModeToggle');
  if (!toggle) return;

  if (localStorage.getItem('admin-theme') === 'dark') { toggle.checked = true; }

  toggle.addEventListener('change', function () {
    if (toggle.checked) {
      document.body.classList.add('admin-dark-theme');
      localStorage.setItem('admin-theme', 'dark');
      if (window.showToast) showToast('Dark mode on');
    } else {
      document.body.classList.remove('admin-dark-theme');
      localStorage.setItem('admin-theme', 'light');
      if (window.showToast) showToast('Dark mode off');
    }
  });
});
</script>
