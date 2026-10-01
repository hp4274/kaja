<?php
require_once __DIR__ . '/../../includes/form-builder.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/intake-repo.php';

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
$templates = formTemplates($db);
$currentName = formTemplateName($db, $selected);

// Group for display, preserving the order the questions come back in.
//
// Personal information and the emergency contact are left out on purpose:
// they are not questions anyone edits. See intakeFixedSections() -- the first
// IS the client record, the second is the one block whose absence is a safety
// problem. They are still asked and still rendered; they are simply not on
// offer here, and the write path refuses them too.
$grouped = [];
$fixed   = [];
foreach ($questions as $q) {
    if (intakeSectionIsFixed($q['section'])) {
        $fixed[$q['section']][] = $q;
        continue;
    }
    $grouped[$q['section']][] = $q;
}
$editableCount = count($questions) - array_sum(array_map('count', $fixed));
?>

<!-- Every variant, at a glance. There is no single "live" form any more --
     each of these is independently usable; Default only marks which one a
     send path that does not ask (the short-intake form, or a lead accepted
     without a version chosen) falls back to. This is also how many forms
     exist becomes a visible number instead of something you'd have to click
     through a dropdown to count. -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">All intake forms (<?php echo count($templates); ?>)</div>
    <button class="btn btn-primary btn-sm" onclick="newTemplate(<?php echo $selected; ?>)">
      <i class="bi bi-plus-lg"></i> New form
    </button>
  </div>
  <div class="panel-body-flush">
    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Name</th><th>Questions</th><th>Status</th><th class="th-right">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($templates as $t): ?>
            <tr <?php echo $t['version'] === $selected ? 'class="is-selected"' : ''; ?>>
              <td class="td-name">
                <?php echo htmlspecialchars($t['name']); ?>
                <small>Version <?php echo $t['version']; ?></small>
              </td>
              <td><?php echo $t['questions']; ?></td>
              <td>
                <?php if ($t['is_default']): ?>
                  <span class="badge badge-published" title="The form used when a send path does not ask which one"><i class="bi bi-star-fill"></i> Default</span>
                <?php endif; ?>
                <?php if ($t['locked']):
                  $pendingNames = intakeLinksPendingNames($db, $t['version']);
                  $pendingTitle = $pendingNames
                    ? 'Still filling: ' . implode(', ', $pendingNames)
                    : 'Answered already -- no one is currently filling it';
                ?>
                  <span class="badge badge-pending" title="<?php echo htmlspecialchars($pendingTitle); ?>"><i class="bi bi-lock"></i> In use</span>
                <?php endif; ?>
              </td>
              <td class="td-actions">
                <div class="row-actions">
                  <a href="index.php?page=forms&v=<?php echo $t['version']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i> Edit</a>
                  <button class="btn btn-ghost btn-sm" onclick="newTemplate(<?php echo $t['version']; ?>)"><i class="bi bi-files"></i> Duplicate</button>
                  <?php if (!$t['locked'] && !$t['is_default'] && !in_array($t['version'], intakeSchemaVersions(), true)): ?>
                    <button class="btn btn-danger btn-sm" onclick="deleteTemplate(<?php echo $t['version']; ?>, '<?php echo htmlspecialchars(addslashes($t['name']), ENT_QUOTES); ?>')"><i class="bi bi-trash"></i> Delete</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="toolbar">
  <div class="toolbar-left">
    <span class="filter-btn active" aria-current="page">
      Editing: <?php echo htmlspecialchars($currentName); ?>
    </span>
    <button class="btn btn-icon btn-sm" onclick="renameTemplate(<?php echo $selected; ?>, '<?php echo htmlspecialchars(addslashes($currentName), ENT_QUOTES); ?>')" title="Rename this form">
      <i class="bi bi-pencil"></i>
    </button>
  </div>
  <div class="toolbar-right">
    <?php if (!$locked): ?>
      <button class="btn btn-ghost btn-sm" onclick="restoreOrder(<?php echo $selected; ?>)"
              title="Put the sections back in the order the form ships with">
        <i class="bi bi-arrow-counterclockwise"></i> Restore section order
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($locked): ?>
  <div class="bulk-bar is-warning">
    <i class="bi bi-lock"></i>
    "<?php echo htmlspecialchars($currentName); ?>" is locked: someone has answered it, or a link using it is still out.
    Duplicate it to make changes — editing this one would change what past clients are recorded as having been asked.
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <div class="panel-title"><?php echo htmlspecialchars($currentName); ?></div>
    <span class="hint"><?php echo $editableCount; ?> editable question<?php echo $editableCount === 1 ? '' : 's'; ?></span>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($questions)): ?>
      <div class="empty-state"><i class="bi bi-ui-checks"></i><p>No questions in this version</p></div>
    <?php else: ?>
      <?php foreach ($grouped as $section => $rows): ?>
        <div class="panel-body-tight">
          <h3 class="intake-section-title"><?php echo htmlspecialchars($section); ?></h3>
        </div>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <!-- Type, Required and Field id are gone on purpose: every
                   question here is a required yes/no, and the field id is
                   generated. Three columns that never vary are three columns
                   of noise between the admin and the only thing they edit. -->
              <tr><th class="th-handle"><span class="visually-hidden">Reorder</span></th><th>Question</th><th class="th-rowbtn"></th></tr>
            </thead>
            <tbody class="question-list" data-section="<?php echo htmlspecialchars($section); ?>">
              <?php foreach ($rows as $q): ?>
                <tr data-question="<?php echo (int) $q['id']; ?>" <?php echo $locked ? '' : 'draggable="true"'; ?>>
                  <td>
                    <?php if (!$locked): ?>
                      <!-- The order is derived from where the rows sit, so there
                           is nothing here to type. -->
                      <span class="drag-handle" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                      <button type="button" class="visually-hidden-focusable btn-move" data-move="up">Move up</button>
                      <button type="button" class="visually-hidden-focusable btn-move" data-move="down">Move down</button>
                    <?php endif; ?>
                  </td>
                  <td>
                    <input class="form-input" id="label-<?php echo $q['id']; ?>"
                           value="<?php echo htmlspecialchars($q['label']); ?>" <?php echo $locked ? 'disabled' : ''; ?> />
                    <?php if ($q['reveal_field']): ?>
                      <small class="hint">
                        Only shown when <code><?php echo htmlspecialchars($q['reveal_field']); ?></code>
                        is <code><?php echo htmlspecialchars($q['reveal_value']); ?></code>
                      </small>
                    <?php endif; ?>
                  </td>
                  <td class="td-actions">
                    <?php if (!$locked): ?>
                      <button class="btn btn-ghost btn-sm" onclick="saveQuestion(<?php echo $q['id']; ?>)">Save</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!$locked): ?>
          <!-- Wording only. Every added question is a required yes/no, which
               is the one shape this form asks and the reason the Type and
               Required columns are not here. -->
          <form class="add-question" onsubmit="addQuestion(event, '<?php echo htmlspecialchars($section, ENT_QUOTES); ?>')">
            <input class="form-input" name="label" placeholder="Add a yes / no question to <?php echo htmlspecialchars($section, ENT_QUOTES); ?>" required />
            <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($fixed): ?>
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Asked, but not editable</div></div>
    <div class="panel-body">
      <p class="prose">
        <?php echo htmlspecialchars(implode(' and ', array_keys($fixed))); ?>
        <?php echo count($fixed) === 1 ? 'is' : 'are'; ?> still asked on every intake form and
        <?php echo count($fixed) === 1 ? 'is' : 'are'; ?> not listed above.
        Personal information is the client record itself &mdash; name, email, date of birth and the
        rest are read by name across the admin &mdash; and the emergency contact is the one block
        whose absence is a safety problem rather than a gap in a questionnaire.
      </p>
      <p class="prose">
        <?php
        $fixedCounts = [];
        foreach ($fixed as $title => $rows) {
            $fixedCounts[] = htmlspecialchars($title) . ' (' . count($rows) . ')';
        }
        echo implode(', ', $fixedCounts);
        ?>
      </p>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header"><div class="panel-title">How this works</div></div>
  <div class="panel-body">
    <p class="prose">
      A version is locked as soon as anyone has answered it or a link using it is still outstanding.
      That is deliberate: those answers are recorded against these exact questions, and rewording one
      afterwards would change what a past client is shown as having been asked.
      Publishing copies the version to a new number you can edit freely; links already sent keep the
      version they were issued with.
    </p>
    <p class="prose">
      Drag a question by its handle to move it; the order is saved as soon as you drop it, and the
      numbering is worked out from where the rows end up. Wording, type and whether an answer is
      required are saved per question with its own Save button.
    </p>
    <p class="prose">
      Editing here changes validation and how answers are labelled when you read them back.
      A question also needs a matching input in <code>patient-intake-form.html</code> to be asked at all —
      the automated tests check the two agree.
    </p>
  </div>
</div>

<script>
function saveQuestion(id) {
  // Only the wording is editable. Type, required and the field id are not sent
  // because they are not offered -- what is not shown must not be written.
  var fd = new FormData();
  fd.append('action', 'update_question');
  fd.append('question_id', id);
  fd.append('label', document.getElementById('label-' + id).value);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success) { showToast('Question saved'); }
      else { showToast(d.error || 'Error', 'error'); }
    })
    .catch(function () { showToast('Network error', 'error'); });
}

function addQuestion(e, section) {
  e.preventDefault();
  var form  = e.target;
  var input = form.querySelector('[name=label]');
  var label = input.value.trim();
  if (!label) return;

  var fd = new FormData();
  fd.append('action', 'add_question');
  fd.append('version', <?php echo (int) $selected; ?>);
  fd.append('section', section);
  fd.append('label', label);

  var btn = form.querySelector('button');
  btn.disabled = true;

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); btn.disabled = false; return; }
      showToast('Question added');
      setTimeout(function () { location.reload(); }, 600);
    })
    .catch(function () { showToast('Network error', 'error'); btn.disabled = false; });
}

async function restoreOrder(v) {
  if (!await showConfirm('Put the sections back in the order the form ships with? The order of questions inside each section is kept.')) return;
  var fd = new FormData();
  fd.append('action', 'normalise_order');
  fd.append('version', v);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Section order restored');
      setTimeout(function () { location.reload(); }, 700);
    })
    .catch(function () { showToast('Network error', 'error'); });
}

// Duplicates `from` into a brand-new, independently-named variant -- this is
// how "Depression intake" or "Anxiety intake" gets created: start from
// whichever form is closest, rename it, then edit its questions freely.
async function newTemplate(from) {
  var name = await showPrompt('Name for the new form (e.g. "Depression intake"):');
  if (name === null) return;   // cancelled
  name = name.trim();
  if (!name) { showToast('A name is required', 'error'); return; }

  var fd = new FormData();
  fd.append('action', 'publish_version');
  fd.append('from_version', from);
  fd.append('name', name);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Created "' + d.name + '"');
      setTimeout(function () { location.href = 'index.php?page=forms&v=' + d.version; }, 700);
    })
    .catch(function () { showToast('Network error', 'error'); });
}

async function renameTemplate(v, currentName) {
  var name = await showPrompt('Rename this form:', currentName);
  if (name === null) return;
  name = name.trim();
  if (!name || name === currentName) return;

  var fd = new FormData();
  fd.append('action', 'rename_template');
  fd.append('version', v);
  fd.append('name', name);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('Renamed to "' + name + '"');
      setTimeout(function () { location.reload(); }, 500);
    })
    .catch(function () { showToast('Network error', 'error'); });
}

async function deleteTemplate(v, name) {
  if (!await showConfirm('Delete "' + name + '" permanently? Its questions go with it.', { danger: true, okText: 'Delete' })) return;

  var fd = new FormData();
  fd.append('action', 'delete_template');
  fd.append('version', v);

  fetch('api/forms.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.success) { showToast(d.error || 'Error', 'error'); return; }
      showToast('"' + name + '" deleted');
      setTimeout(function () { location.href = 'index.php?page=forms'; }, 500);
    })
    .catch(function () { showToast('Network error', 'error'); });
}

</script>

<script>
/**
 * Reordering by dragging.
 *
 * The order used to be a number typed into every row and saved one row at a
 * time: two rows could hold the same number, a gap could be closed by accident,
 * and the result was only visible after a reload. Now the order IS the order of
 * the rows, and the numbers are worked out on the server from what it is given.
 *
 * Dragging is not the only way in. The same move is on two focusable buttons
 * per row, because a reorder that needs a mouse is a reorder some people cannot
 * make at all.
 */
(function () {
  var lists = document.querySelectorAll('.question-list');
  if (!lists.length) return;

  var VERSION = <?php echo (int) $selected; ?>;
  var dragged = null;

  function rowsOf(list) {
    return Array.prototype.slice.call(list.querySelectorAll('tr[data-question]'));
  }

  function save(list) {
    var ids = rowsOf(list).map(function (r) { return r.dataset.question; });

    var fd = new FormData();
    fd.append('action', 'reorder_questions');
    fd.append('version', VERSION);
    fd.append('section', list.dataset.section);
    fd.append('ids', ids.join(','));

    list.classList.add('is-saving');
    fetch('api/forms.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) { showToast('Order saved'); }
        else {
          // The rows on screen would otherwise show an order the database
          // never accepted, which is worse than no reorder at all.
          showToast(d.error || 'The new order could not be saved', 'error');
          setTimeout(function () { location.reload(); }, 900);
        }
      })
      .catch(function () {
        showToast('Network error — reloading to show the saved order', 'error');
        setTimeout(function () { location.reload(); }, 900);
      })
      .then(function () { list.classList.remove('is-saving'); });
  }

  lists.forEach(function (list) {
    list.addEventListener('dragstart', function (e) {
      var row = e.target.closest ? e.target.closest('tr[data-question]') : null;
      if (!row) return;
      dragged = row;
      row.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Firefox will not start a drag at all without something set here.
      e.dataTransfer.setData('text/plain', row.dataset.question);
    });

    list.addEventListener('dragover', function (e) {
      if (!dragged || dragged.parentNode !== list) return;   // not this section
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      var over = e.target.closest ? e.target.closest('tr[data-question]') : null;
      if (!over || over === dragged) return;

      // Insert before or after depending on which half of the row is under the
      // pointer, so a row can be dropped either side of its neighbour.
      var box   = over.getBoundingClientRect();
      var after = (e.clientY - box.top) > (box.height / 2);
      list.insertBefore(dragged, after ? over.nextSibling : over);
    });

    list.addEventListener('drop', function (e) { e.preventDefault(); });

    list.addEventListener('dragend', function () {
      if (!dragged) return;
      dragged.classList.remove('is-dragging');
      dragged = null;
      save(list);
    });

    // Keyboard: the same move, one step at a time.
    list.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.btn-move') : null;
      if (!btn) return;
      var row = btn.closest('tr[data-question]');
      var sibling = btn.dataset.move === 'up'
        ? row.previousElementSibling
        : row.nextElementSibling;
      if (!sibling) return;

      if (btn.dataset.move === 'up') { list.insertBefore(row, sibling); }
      else { list.insertBefore(sibling, row); }
      btn.focus();
      save(list);
    });
  });
})();
</script>
