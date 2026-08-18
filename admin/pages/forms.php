<?php
require_once __DIR__ . '/../../includes/form-builder.php';
require_once __DIR__ . '/../../includes/settings.php';

$db = getDbConnection();

// Bring the shipped versions into the database the first time this page is
// opened, so what the module edits is exactly what the form asked.
foreach (intakeSchemaVersions() as $v) {
    seedFormVersion($db, $v);
}

$versions = formVersions($db);
$liveVersion = getSettingInt('intake_form_version', 1);

$selected = isset($_GET['v']) ? (int) $_GET['v'] : $liveVersion;
if (!in_array($selected, $versions, true)) {
    $selected = $versions ? $versions[0] : $liveVersion;
}

$questions = formQuestions($db, $selected);
$locked    = formVersionIsInUse($db, $selected);

// Group for display, preserving the order the questions come back in.
$grouped = [];
foreach ($questions as $q) {
    $grouped[$q['section']][] = $q;
}
?>

<div class="toolbar">
  <div class="toolbar-left">
    <?php foreach ($versions as $v): ?>
      <a href="index.php?page=forms&v=<?php echo $v; ?>" class="filter-btn <?php echo $selected === $v ? 'active' : ''; ?>">
        Version <?php echo $v; ?><?php echo $v === $liveVersion ? ' (live)' : ''; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <button class="btn btn-primary btn-sm" onclick="publishVersion(<?php echo $selected; ?>)">
      <i class="bi bi-files"></i> Publish a new version from this
    </button>
  </div>
</div>

<?php if ($locked): ?>
  <div class="bulk-bar" style="background:var(--clr-warning-light); border-color:var(--clr-warning); color:#b45309;">
    <i class="bi bi-lock"></i>
    Version <?php echo $selected; ?> is locked: someone has answered it, or a link using it is still out.
    Publish a new version to make changes — editing this one would change what past clients are recorded as having been asked.
  </div>
<?php endif; ?>

<?php if ($selected !== $liveVersion): ?>
  <div class="bulk-bar">
    <i class="bi bi-info-circle"></i>
    Version <?php echo $liveVersion; ?> is the one new links are issued with.
    <button class="btn btn-primary btn-sm" style="margin-left:auto;" onclick="makeLive(<?php echo $selected; ?>)">
      Make version <?php echo $selected; ?> live
    </button>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Questions in version <?php echo $selected; ?></div>
    <span class="td-muted" style="font-size:0.8rem;"><?php echo count($questions); ?> question<?php echo count($questions) === 1 ? '' : 's'; ?></span>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($questions)): ?>
      <div class="empty-state"><i class="bi bi-ui-checks"></i><p>No questions in this version</p></div>
    <?php else: ?>
      <?php foreach ($grouped as $section => $rows): ?>
        <div style="padding:1rem 1.25rem 0;">
          <h3 class="intake-section-title"><?php echo htmlspecialchars($section); ?></h3>
        </div>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <tr><th style="width:70px;">Order</th><th>Question</th><th style="width:130px;">Type</th>
                  <th style="width:90px;">Required</th><th style="width:150px;">Field id</th><th style="width:90px;"></th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $q): ?>
                <tr>
                  <td><input class="form-input" style="width:60px;padding:0.25rem 0.4rem;" type="number"
                             id="order-<?php echo $q['id']; ?>" value="<?php echo (int) $q['sort_order']; ?>" <?php echo $locked ? 'disabled' : ''; ?> /></td>
                  <td>
                    <input class="form-input" id="label-<?php echo $q['id']; ?>"
                           value="<?php echo htmlspecialchars($q['label']); ?>" <?php echo $locked ? 'disabled' : ''; ?> />
                    <?php if ($q['reveal_field']): ?>
                      <small style="color:var(--clr-text-muted);">
                        Only shown when <code><?php echo htmlspecialchars($q['reveal_field']); ?></code>
                        is <code><?php echo htmlspecialchars($q['reveal_value']); ?></code>
                      </small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <select class="status-select" id="type-<?php echo $q['id']; ?>" <?php echo $locked ? 'disabled' : ''; ?>>
                      <?php foreach (formFieldTypes() as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $q['field_type'] === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td style="text-align:center;">
                    <input type="checkbox" id="req-<?php echo $q['id']; ?>"
                           <?php echo $q['is_required'] ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?> />
                  </td>
                  <td class="td-muted"><code><?php echo htmlspecialchars($q['field_id']); ?></code></td>
                  <td style="text-align:right;">
                    <?php if (!$locked): ?>
                      <button class="btn btn-ghost btn-sm" onclick="saveQuestion(<?php echo $q['id']; ?>)">Save</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-top:1.25rem;">
  <div class="panel-header"><div class="panel-title">How this works</div></div>
  <div class="panel-body">
    <p class="td-muted" style="font-size:0.85rem;line-height:1.7;">
      A version is locked as soon as anyone has answered it or a link using it is still outstanding.
      That is deliberate: those answers are recorded against these exact questions, and rewording one
      afterwards would change what a past client is shown as having been asked.
      Publishing copies the version to a new number you can edit freely; links already sent keep the
      version they were issued with.
    </p>
    <p class="td-muted" style="font-size:0.85rem;line-height:1.7;">
      Editing here changes validation and how answers are labelled when you read them back.
      A question also needs a matching input in <code>patient-intake-form.html</code> to be asked at all —
      the automated tests check the two agree.
    </p>
  </div>
</div>

<script>
function saveQuestion(id) {
  var fd = new FormData();
  fd.append('action', 'update_question');
  fd.append('question_id', id);
  fd.append('label', document.getElementById('label-' + id).value);
  fd.append('field_type', document.getElementById('type-' + id).value);
  fd.append('is_required', document.getElementById('req-' + id).checked ? '1' : '0');
  fd.append('sort_order', document.getElementById('order-' + id).value);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success) { showToast('Question saved'); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function () { showToast('Network error', 'error'); });
}

function publishVersion(from) {
  if (!confirm('Copy version ' + from + ' into a new editable version?')) return;
  var fd = new FormData();
  fd.append('action', 'publish_version');
  fd.append('from_version', from);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Published version ' + d.version);
      setTimeout(function () { location.href = 'index.php?page=forms&v=' + d.version; }, 700);
    })
    .catch(function () { showToast('Network error', 'error'); });
}

function makeLive(v) {
  if (!confirm('Issue all new intake links against version ' + v + '?\n\nLinks already sent keep the version they were issued with.')) return;
  var fd = new FormData();
  fd.append('action', 'set_live_version');
  fd.append('version', v);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Version ' + v + ' is now live');
      setTimeout(function () { location.reload(); }, 700);
    })
    .catch(function () { showToast('Network error', 'error'); });
}
</script>
