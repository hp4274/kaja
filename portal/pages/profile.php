<?php
/* $client, $db in scope. */
$times = $client['pref_times'] ? explode(',', $client['pref_times']) : [];
$chk = function ($v) use ($times) { return in_array($v, $times, true); };
$initials = strtoupper(substr($client['first_name'] ?: 'C', 0, 1) . substr($client['last_name'] ?: '', 0, 1));
$currentMode = $client['pref_mode'] ?? '';
?>

<!-- Profile Hero Identity Card -->
<div class="profile-hero-card">
  <div class="profile-hero-avatar-wrap">
    <div class="profile-hero-avatar"><?= e($initials) ?></div>
    <span class="profile-avatar-status-dot" title="Active Account"></span>
  </div>
  <div class="profile-hero-meta">
    <h1 class="profile-hero-name"><?= e($client['first_name'] . ' ' . $client['last_name']) ?></h1>
    <div class="profile-badges-row">
      <span class="profile-status-badge">
        <i class="bi bi-shield-check"></i> <?= e(ucfirst($client['status'] ?? 'Active')) ?> Client
      </span>
      <span class="profile-email-badge">
        <i class="bi bi-envelope-at"></i> <?= e($client['email']) ?>
      </span>
      <?php if (!empty($client['phone'])): ?>
        <span class="profile-email-badge">
          <i class="bi bi-telephone"></i> <?= e($client['phone']) ?>
        </span>
      <?php endif; ?>
      <?php if (!empty($client['created_at'])): ?>
        <span class="profile-email-badge">
          <i class="bi bi-calendar3"></i> Since <?= e(date('M Y', strtotime($client['created_at']))) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<form id="pfForm" novalidate>
  <!-- Section 1: Personal Details -->
  <div class="doc-section-card">
    <div class="doc-section-header">
      <div class="doc-section-title-wrap">
        <div class="doc-section-icon accent-emerald">
          <i class="bi bi-person-lines-fill"></i>
        </div>
        <div>
          <h2 class="doc-section-title">Personal Information</h2>
          <p class="doc-section-desc">Keep your contact details up to date for appointment notifications and records.</p>
        </div>
      </div>
      <span class="doc-badge-tag from-therapist">
        <i class="bi bi-check-circle-fill"></i> Identity Verified
      </span>
    </div>

    <div class="panel-body" style="padding: 1.75rem;">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="pfFirst">First Name <span class="text-danger">*</span></label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-person input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfFirst" name="first_name" maxlength="100" required value="<?= e($client['first_name']) ?>" placeholder="First name" />
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="pfLast">Last Name <span class="text-danger">*</span></label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-person-fill input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfLast" name="last_name" maxlength="100" required value="<?= e($client['last_name']) ?>" placeholder="Last name" />
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
            <label class="form-label" for="pfEmail" style="margin-bottom: 0;">Sign-In Email</label>
            <span class="badge" style="background: rgba(13, 115, 119, 0.1); color: var(--clr-primary); font-size: 0.7rem; font-weight: 600;">
              <i class="bi bi-lock-fill"></i> Primary ID
            </span>
          </div>
          <div class="input-with-icon-wrap">
            <i class="bi bi-envelope input-icon-lead"></i>
            <input class="form-input has-lead-icon is-readonly" id="pfEmail" type="email" value="<?= e($client['email']) ?>" readonly disabled />
          </div>
          <small class="hint is-block" style="margin-top: 0.35rem;">Used for OTP sign-ins and reminders. Contact the clinic to update.</small>
        </div>

        <div class="form-group">
          <label class="form-label" for="pfPhone">Phone (10 Digits) <span class="text-danger">*</span></label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-telephone input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfPhone" name="phone" inputmode="numeric" maxlength="14" required value="<?= e($client['phone']) ?>" placeholder="e.g. 9876543210" />
          </div>
          <small class="hint is-block" style="margin-top: 0.35rem;">Used for session reminders and emergency contact.</small>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="pfDob">Date of Birth</label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-calendar-event input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfDob" name="dob" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($client['dob']) ?>" />
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="pfOcc">Occupation</label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-briefcase input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfOcc" name="occupation" maxlength="100" value="<?= e($client['occupation']) ?>" placeholder="e.g. Software Engineer, Designer, Student" />
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="pfCity">City / Location</label>
          <div class="input-with-icon-wrap">
            <i class="bi bi-geo-alt input-icon-lead"></i>
            <input class="form-input has-lead-icon" id="pfCity" name="city" maxlength="100" value="<?= e($client['city']) ?>" placeholder="e.g. Mumbai, Bangalore, Pune" />
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Section 2: Consultation & Care Preferences -->
  <div class="doc-section-card">
    <div class="doc-section-header">
      <div class="doc-section-title-wrap">
        <div class="doc-section-icon accent-blue">
          <i class="bi bi-sliders2-vertical"></i>
        </div>
        <div>
          <h2 class="doc-section-title">Consultation &amp; Care Preferences</h2>
          <p class="doc-section-desc">Tailor how and when you prefer your therapy sessions to be scheduled.</p>
        </div>
      </div>
      <span class="doc-badge-tag client-owned">
        <i class="bi bi-clock-history"></i> Scheduling Settings
      </span>
    </div>

    <div class="panel-body" style="padding: 1.75rem;">
      <!-- Hidden input for session mode -->
      <input type="hidden" id="pfMode" name="pref_mode" value="<?= e($currentMode) ?>" />

      <div class="form-group" style="margin-bottom: 1.75rem;">
        <label class="form-label" style="font-weight: 600; font-size: 0.95rem;">Preferred Session Format</label>
        <div class="pref-mode-grid">
          <div class="pref-mode-card <?= $currentMode === 'Online' ? 'is-active' : '' ?>" data-mode="Online">
            <div class="pref-mode-card-header">
              <div class="pref-mode-card-icon"><i class="bi bi-camera-video-fill"></i></div>
              <div class="pref-mode-radio-visual"></div>
            </div>
            <div class="pref-mode-title">Online Video</div>
            <p class="pref-mode-desc">Remote video consultation via Google Meet from your preferred private space.</p>
          </div>

          <div class="pref-mode-card <?= $currentMode === 'In-person' ? 'is-active' : '' ?>" data-mode="In-person">
            <div class="pref-mode-card-header">
              <div class="pref-mode-card-icon"><i class="bi bi-building"></i></div>
              <div class="pref-mode-radio-visual"></div>
            </div>
            <div class="pref-mode-title">In-Person Clinic</div>
            <p class="pref-mode-desc">In-clinic therapy consultation at our practice office space.</p>
          </div>

          <div class="pref-mode-card <?= $currentMode === '' ? 'is-active' : '' ?>" data-mode="">
            <div class="pref-mode-card-header">
              <div class="pref-mode-card-icon"><i class="bi bi-shuffle"></i></div>
              <div class="pref-mode-radio-visual"></div>
            </div>
            <div class="pref-mode-title">No Preference</div>
            <p class="pref-mode-desc">Flexible with either format based on current practitioner availability.</p>
          </div>
        </div>
      </div>

      <!-- Preferred Time of Day -->
      <div class="form-group" style="margin-bottom: 1.75rem;">
        <label class="form-label" style="font-weight: 600; font-size: 0.95rem;">Preferred Time of Day</label>
        <div class="pref-time-pills">
          <label class="pref-time-pill <?= $chk('morning') ? 'is-active' : '' ?>">
            <input type="checkbox" name="pref_times[]" value="morning" <?= $chk('morning') ? 'checked' : '' ?> />
            <i class="bi bi-sunrise-fill pref-time-icon"></i>
            <div>
              <div class="pref-time-label">Morning</div>
              <span class="pref-time-sub">9:00 AM – 12:00 PM</span>
            </div>
          </label>

          <label class="pref-time-pill <?= $chk('afternoon') ? 'is-active' : '' ?>">
            <input type="checkbox" name="pref_times[]" value="afternoon" <?= $chk('afternoon') ? 'checked' : '' ?> />
            <i class="bi bi-sun-fill pref-time-icon"></i>
            <div>
              <div class="pref-time-label">Afternoon</div>
              <span class="pref-time-sub">12:00 PM – 5:00 PM</span>
            </div>
          </label>

          <label class="pref-time-pill <?= $chk('evening') ? 'is-active' : '' ?>">
            <input type="checkbox" name="pref_times[]" value="evening" <?= $chk('evening') ? 'checked' : '' ?> />
            <i class="bi bi-moon-stars-fill pref-time-icon"></i>
            <div>
              <div class="pref-time-label">Evening</div>
              <span class="pref-time-sub">5:00 PM – 8:00 PM</span>
            </div>
          </label>
        </div>
      </div>

      <!-- Preferred Weekdays -->
      <div class="form-group" style="margin-bottom: 0.5rem;">
        <label class="form-label" style="font-weight: 600; font-size: 0.95rem;">Preferred Weekdays</label>
        <p class="hint" style="margin-bottom: 0.6rem;">Select the days you are typically free for therapy sessions:</p>
        <div class="pref-days-strip">
          <?php
          $days = [
              'mon' => 'Mon',
              'tue' => 'Tue',
              'wed' => 'Wed',
              'thu' => 'Thu',
              'fri' => 'Fri',
              'sat' => 'Sat',
              'sun' => 'Sun',
          ];
          foreach ($days as $k => $l):
            $active = $chk($k);
          ?>
            <label class="pref-day-chip <?= $active ? 'is-active' : '' ?>" title="<?= $l ?>">
              <input type="checkbox" name="pref_times[]" value="<?= $k ?>" <?= $active ? 'checked' : '' ?> />
              <?= $l ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Save Actions -->
      <div class="form-sticky-actions">
        <div>
          <div id="pfMsg" class="save-status-indicator" role="status"></div>
        </div>
        <button class="btn btn-primary" type="submit" id="pfSaveBtn" style="padding: 0.65rem 1.5rem; font-weight: 600;">
          <i class="bi bi-check2-circle"></i> Save Profile Changes
        </button>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  var msg = document.getElementById('pfMsg');
  var saveBtn = document.getElementById('pfSaveBtn');

  function say(t, ok) {
    msg.style.color = ok ? 'var(--clr-success)' : 'var(--clr-danger)';
    msg.innerHTML = ok
      ? '<i class="bi bi-check-circle-fill"></i> ' + t
      : '<i class="bi bi-exclamation-circle-fill"></i> ' + t;
  }

  function post(url, fd) {
    fd.append('csrf', csrf);
    return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // Interactive Session Mode cards
  var modeInput = document.getElementById('pfMode');
  var modeCards = document.querySelectorAll('.pref-mode-card');
  modeCards.forEach(function (card) {
    card.addEventListener('click', function () {
      modeCards.forEach(function (c) { c.classList.remove('is-active'); });
      card.classList.add('is-active');
      modeInput.value = card.dataset.mode;
    });
  });

  // Interactive Time pills
  var timePills = document.querySelectorAll('.pref-time-pill');
  timePills.forEach(function (pill) {
    var cb = pill.querySelector('input[type=checkbox]');
    cb.addEventListener('change', function () {
      pill.classList.toggle('is-active', cb.checked);
    });
  });

  // Interactive Day chips
  var dayChips = document.querySelectorAll('.pref-day-chip');
  dayChips.forEach(function (chip) {
    var cb = chip.querySelector('input[type=checkbox]');
    cb.addEventListener('change', function () {
      chip.classList.toggle('is-active', cb.checked);
    });
  });

  // Profile Form Submission
  document.getElementById('pfForm').addEventListener('submit', function (ev) {
    ev.preventDefault();

    // Client-side phone verification
    var phoneInput = document.getElementById('pfPhone');
    var rawPhone = phoneInput.value.replace(/[\s\-]/g, '');
    if (!/^\d{10}$/.test(rawPhone)) {
      say('Phone number must be exactly 10 digits.', false);
      phoneInput.focus();
      return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving changes...';
    msg.innerHTML = '';

    post('api/profile-save.php', new FormData(this)).then(function (d) {
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save Profile Changes';
      if (d.success) {
        say('Profile updated successfully.', true);
        setTimeout(function () {
          msg.textContent = '';
        }, 4000);
      } else {
        say(d.error || 'Could not save profile changes.', false);
      }
    }).catch(function () {
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Save Profile Changes';
      say('Network error occurred. Please try again.', false);
    });
  });
})();
</script>
