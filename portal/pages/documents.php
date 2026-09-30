<?php
/* $client, $db in scope. */
require_once dirname(__DIR__, 2) . '/includes/client-documents.php';

$stmt = $db->prepare('SELECT `id`,`original_name`,`size_bytes`,`uploaded_at`,`client_uploaded`
    FROM `client_documents`
    WHERE `client_id` = :c AND `archived_at` IS NULL AND (`shared_with_client` = 1 OR `client_uploaded` = 1)
    ORDER BY `uploaded_at` DESC');
$stmt->execute([':c' => (int) $client['id']]);
$docs     = $stmt->fetchAll(PDO::FETCH_ASSOC);
$shared   = array_values(array_filter($docs, function ($d) { return !$d['client_uploaded']; }));
$mine     = array_values(array_filter($docs, function ($d) { return $d['client_uploaded']; }));

$totalDocBytes = clientTotalDocumentBytes($db, (int) $client['id']);
$maxDocBytes   = clientMaxTotalDocumentBytes();
$usedDocMb     = round($totalDocBytes / (1024 * 1024), 1);
$maxDocMb      = (int) round($maxDocBytes / (1024 * 1024));
$remDocMb      = max(0, round(($maxDocBytes - $totalDocBytes) / (1024 * 1024), 1));
$remDocBytes   = max(0, $maxDocBytes - $totalDocBytes);

// Helper to determine file type icon and class
function getDocTypeMeta($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':
            return ['icon' => 'bi-filetype-pdf', 'cls' => 'pdf', 'label' => 'PDF'];
        case 'doc':
        case 'docx':
            return ['icon' => 'bi-filetype-docx', 'cls' => 'docx', 'label' => 'Word'];
        case 'png':
        case 'jpg':
        case 'jpeg':
            return ['icon' => 'bi-file-earmark-image', 'cls' => 'img', 'label' => 'Image'];
        default:
            return ['icon' => 'bi-file-earmark-text', 'cls' => 'default', 'label' => strtoupper($ext ?: 'FILE')];
    }
}
?>

<!-- Document Vault Hero Banner -->
<div class="portal-hero-banner">
  <div class="portal-hero-content">
    <div class="portal-hero-kicker">
      <span class="kicker-dot"></span> Secure Client Vault
    </div>
    <h1 class="portal-hero-title">Documents &amp; Care Records</h1>
    <p class="portal-hero-subtitle">
      Access therapist-shared worksheets and securely upload your prior health records.
    </p>
  </div>
  <div class="portal-hero-stats">
    <div class="portal-stat-pill">
      <i class="bi bi-folder-symlink"></i>
      <span>Shared</span>
      <span class="pill-val"><?= count($shared) ?></span>
    </div>
    <div class="portal-stat-pill">
      <i class="bi bi-cloud-arrow-up"></i>
      <span>Your Uploads</span>
      <span class="pill-val"><?= count($mine) ?></span>
    </div>
    <div class="portal-stat-pill">
      <i class="bi bi-pie-chart"></i>
      <span>Storage</span>
      <span class="pill-val"><?= $usedDocMb ?> / <?= $maxDocMb ?> MB</span>
    </div>
  </div>
</div>

<!-- Section 1: Shared with you (Therapist Resources) -->
<div class="doc-section-card">
  <div class="doc-section-header">
    <div class="doc-section-title-wrap">
      <div class="doc-section-icon accent-emerald">
        <i class="bi bi-journal-bookmark-fill"></i>
      </div>
      <div>
        <h2 class="doc-section-title">Shared by Your Therapist</h2>
        <p class="doc-section-desc">Personalized reflection exercises, session notes, and take-home resources.</p>
      </div>
    </div>
    <span class="doc-badge-tag from-therapist">
      <i class="bi bi-patch-check-fill"></i> Practitioner Verified
    </span>
  </div>

  <div class="doc-list-container">
    <?php if (empty($shared)): ?>
      <div class="empty-state" style="padding: 3rem 1.5rem;">
        <i class="bi bi-folder2-open" style="font-size: 2.5rem; color: var(--clr-text-muted);"></i>
        <p style="margin-top: 0.5rem; font-weight: 500;">No resources shared yet.</p>
        <span class="hint">When your therapist shares reflection sheets or audio guides, they will be safely stored here.</span>
      </div>
    <?php else: ?>
      <div class="doc-batch-toolbar" id="pdSharedBatchBar">
        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; margin: 0;">
          <input type="checkbox" id="pdSelectAllShared" class="doc-row-check" />
          <span>Select All</span>
        </label>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <span id="pdSharedSelectedCount" style="font-size: 0.8125rem; color: var(--clr-text-secondary); font-weight: 500;">
            0 selected
          </span>
          <button type="button" class="btn btn-secondary btn-sm" id="pdSharedBatchDownload" disabled style="display: flex; align-items: center; gap: 0.35rem;">
            <i class="bi bi-download"></i> Download Selected
          </button>
        </div>
      </div>
      <?php foreach ($shared as $d):
        $meta = getDocTypeMeta($d['original_name']);
        $sizeKb = (int) ceil($d['size_bytes'] / 1024);
        $sizeStr = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
      ?>
        <div class="doc-item-row" data-id="<?= (int) $d['id'] ?>">
          <div class="doc-item-main">
            <div class="doc-item-check-wrap">
              <input type="checkbox" class="doc-row-check doc-check-shared" data-id="<?= (int) $d['id'] ?>" data-name="<?= e($d['original_name']) ?>" aria-label="Select <?= e($d['original_name']) ?>" />
            </div>
            <div class="doc-type-icon <?= $meta['cls'] ?>">
              <i class="bi <?= $meta['icon'] ?>"></i>
            </div>
            <div class="doc-details">
              <div class="doc-title" title="<?= e($d['original_name']) ?>">
                <?= e($d['original_name']) ?>
              </div>
              <div class="doc-meta">
                <span class="doc-meta-item">
                  <i class="bi bi-calendar3"></i> <?= e(date('d M Y', strtotime($d['uploaded_at']))) ?>
                </span>
                <span class="doc-meta-item">
                  <i class="bi bi-hdd"></i> <?= $sizeStr ?>
                </span>
                <span class="doc-badge-tag from-therapist">Therapy Resource</span>
              </div>
            </div>
          </div>
          <div class="doc-actions">
            <a class="btn-doc-download" href="api/document-download.php?id=<?= (int) $d['id'] ?>">
              <i class="bi bi-download"></i> Download
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Section 2: Upload Prior Health Records & Documents -->
<div class="doc-section-card">
  <div class="doc-section-header">
    <div class="doc-section-title-wrap">
      <div class="doc-section-icon accent-blue">
        <i class="bi bi-cloud-arrow-up-fill"></i>
      </div>
      <div>
        <h2 class="doc-section-title">Your Health Records &amp; Uploads</h2>
        <p class="doc-section-desc">Upload relevant medical history, doctor notes, or intake documents prior to consultations.</p>
      </div>
    </div>
    <span class="doc-badge-tag client-owned">
      <i class="bi bi-lock-fill"></i> Private &amp; Encrypted
    </span>
  </div>

  <div class="dropzone-container">
    <form id="pdUpload" enctype="multipart/form-data">
      <div class="doc-dropzone" id="pdDropzone">
        <input class="dropzone-file-input" type="file" id="pdFile" name="documents[]" accept=".pdf,.png,.jpg,.jpeg,.docx" multiple required />
        <div class="dropzone-icon-circle">
          <i class="bi bi-cloud-arrow-up"></i>
        </div>
        <div class="dropzone-text-primary">
          <span>Click to browse</span> or drag and drop your documents here
        </div>
        <div class="dropzone-text-secondary">
          Supports PDF, DOCX, PNG, or JPG (Up to 10 MB per file &bull; Multiple files supported &bull; <?= $usedDocMb ?> MB of <?= $maxDocMb ?> MB storage used)
        </div>
      </div>

      <!-- Preview bar once file(s) selected -->
      <div id="pdSelectedWrap" class="dropzone-selected-wrap" style="display: none; margin-top: 1rem;">
        <div class="dropzone-selected-preview">
          <div class="dropzone-selected-info">
            <i class="bi bi-file-earmark-arrow-up-fill" id="pdFilePreviewIcon"></i>
            <div>
              <div class="dropzone-filename" id="pdFileName">filename.pdf</div>
              <div class="dropzone-filesize" id="pdFileSize">0 KB</div>
            </div>
          </div>
          <div class="dropzone-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="pdFileCancel" title="Clear selection">
              <i class="bi bi-x-lg"></i> Cancel
            </button>
            <button type="submit" class="btn btn-primary btn-sm" id="pdSubmitBtn">
              <i class="bi bi-upload"></i> Upload Now
            </button>
          </div>
        </div>
        <div id="pdFilesList" style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.35rem; max-height: 180px; overflow-y: auto;"></div>
      </div>

      <div id="pdMsg" class="hint is-block" style="margin-top: 0.75rem; font-weight: 500;" role="status"></div>
    </form>
  </div>

  <!-- Client's existing uploads list -->
  <div class="doc-list-container" style="border-top: 1px solid var(--clr-border-light);">
    <?php if (empty($mine)): ?>
      <div class="empty-state" style="padding: 2.25rem 1.5rem;">
        <i class="bi bi-file-earmark-arrow-up" style="font-size: 2rem; color: var(--clr-text-muted);"></i>
        <p style="margin-top: 0.35rem; font-weight: 500;">No personal uploads yet.</p>
        <span class="hint">Use the upload box above to share prior assessments or prescriptions.</span>
      </div>
    <?php else: ?>
      <div class="doc-batch-toolbar" id="pdMineBatchBar">
        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; margin: 0;">
          <input type="checkbox" id="pdSelectAllMine" class="doc-row-check" />
          <span>Select All</span>
        </label>
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
          <span id="pdMineSelectedCount" style="font-size: 0.8125rem; color: var(--clr-text-secondary); font-weight: 500; margin-right: 0.25rem;">
            0 selected
          </span>
          <button type="button" class="btn btn-secondary btn-sm" id="pdMineBatchDownload" disabled style="display: flex; align-items: center; gap: 0.35rem;">
            <i class="bi bi-download"></i> Download Selected
          </button>
          <button type="button" class="btn btn-danger btn-sm" id="pdMineBatchDelete" disabled style="display: flex; align-items: center; gap: 0.35rem;">
            <i class="bi bi-trash3"></i> Delete Selected
          </button>
        </div>
      </div>
      <?php foreach ($mine as $d):
        $meta = getDocTypeMeta($d['original_name']);
        $sizeKb = (int) ceil($d['size_bytes'] / 1024);
        $sizeStr = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
      ?>
        <div class="doc-item-row" data-id="<?= (int) $d['id'] ?>">
          <div class="doc-item-main">
            <div class="doc-item-check-wrap">
              <input type="checkbox" class="doc-row-check doc-check-mine" data-id="<?= (int) $d['id'] ?>" data-name="<?= e($d['original_name']) ?>" aria-label="Select <?= e($d['original_name']) ?>" />
            </div>
            <div class="doc-type-icon <?= $meta['cls'] ?>">
              <i class="bi <?= $meta['icon'] ?>"></i>
            </div>
            <div class="doc-details">
              <div class="doc-title" title="<?= e($d['original_name']) ?>">
                <?= e($d['original_name']) ?>
              </div>
              <div class="doc-meta">
                <span class="doc-meta-item">
                  <i class="bi bi-calendar3"></i> <?= e(date('d M Y, H:i', strtotime($d['uploaded_at']))) ?>
                </span>
                <span class="doc-meta-item">
                  <i class="bi bi-hdd"></i> <?= $sizeStr ?>
                </span>
                <span class="doc-badge-tag client-owned">Your Upload</span>
              </div>
            </div>
          </div>
          <div class="doc-actions">
            <a class="btn-doc-download" href="api/document-download.php?id=<?= (int) $d['id'] ?>">
              <i class="bi bi-download"></i> Download
            </a>
            <button class="btn-doc-delete pd-del" type="button" data-id="<?= (int) $d['id'] ?>" data-name="<?= e($d['original_name']) ?>" title="Delete document" aria-label="Delete">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>



<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="pdConfirm">
  <div class="modal-box is-narrow">
    <div class="modal-header">
      <div class="modal-title" style="display: flex; align-items: center; gap: 0.5rem; color: var(--clr-danger);">
        <i class="bi bi-exclamation-triangle-fill"></i> Delete Document
      </div>
      <button class="modal-close" type="button" id="pdConfirmX" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size: 0.95rem; line-height: 1.5; margin: 0 0 0.5rem 0;">
        Are you sure you want to permanently remove <strong id="pdDelDocName">this file</strong> from your records?
      </p>
      <div class="hint" style="color: var(--clr-text-secondary);">
        This action cannot be undone. Your therapist will no longer have access to this upload.
      </div>
      <div id="pdConfirmMsg" class="hint" role="alert" style="margin-top: 0.75rem;"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" id="pdConfirmNo">Cancel</button>
      <button type="button" class="btn btn-danger" id="pdConfirmYes">
        <i class="bi bi-trash3"></i> Delete Document
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  function post(url, fd) {
    fd.append('csrf', csrf);
    return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  // Upload UI Elements
  var fileInput = document.getElementById('pdFile');
  var dropzone = document.getElementById('pdDropzone');
  var previewWrap = document.getElementById('pdSelectedWrap');
  var fileNameEl = document.getElementById('pdFileName');
  var fileSizeEl = document.getElementById('pdFileSize');
  var filesListEl = document.getElementById('pdFilesList');
  var cancelBtn = document.getElementById('pdFileCancel');
  var submitBtn = document.getElementById('pdSubmitBtn');
  var msg = document.getElementById('pdMsg');

  function setMsg(t, isError) {
    msg.style.color = isError ? 'var(--clr-danger)' : 'var(--clr-success)';
    msg.textContent = t;
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function(s) {
      return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[s];
    });
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    var k = 1024;
    var sizes = ['Bytes', 'KB', 'MB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  var maxSingleBytes = 10 * 1024 * 1024;
  var maxTotalBytes  = <?= (int) $maxDocBytes ?>;
  var usedTotalBytes = <?= (int) $totalDocBytes ?>;
  var remainingBytes = <?= (int) $remDocBytes ?>;
  var allowedExts    = ['pdf', 'png', 'jpg', 'jpeg', 'docx'];

  function handleFilesSelected(fileList) {
    if (!fileList || !fileList.length) return;

    var totalBatchSize = 0;
    var validFiles = [];
    var invalidFiles = [];

    for (var i = 0; i < fileList.length; i++) {
      var f = fileList[i];
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (allowedExts.indexOf(ext) === -1) {
        invalidFiles.push(f.name + ' (unsupported type)');
        continue;
      }
      if (f.size > maxSingleBytes) {
        invalidFiles.push(f.name + ' (over 10 MB)');
        continue;
      }
      totalBatchSize += f.size;
      validFiles.push(f);
    }

    if (invalidFiles.length > 0) {
      setMsg('Cannot select: ' + invalidFiles.join(', '), true);
      fileInput.value = '';
      previewWrap.style.display = 'none';
      if (filesListEl) filesListEl.innerHTML = '';
      return;
    }

    if (totalBatchSize > remainingBytes) {
      setMsg('Selected files total ' + formatBytes(totalBatchSize) + ', exceeding remaining storage (' + formatBytes(remainingBytes) + ' remaining of <?= $maxDocMb ?> MB).', true);
      fileInput.value = '';
      previewWrap.style.display = 'none';
      if (filesListEl) filesListEl.innerHTML = '';
      return;
    }

    if (validFiles.length === 1) {
      fileNameEl.textContent = validFiles[0].name;
      fileSizeEl.textContent = formatBytes(validFiles[0].size);
    } else {
      fileNameEl.textContent = validFiles.length + ' documents selected';
      fileSizeEl.textContent = formatBytes(totalBatchSize) + ' total';
    }

    if (filesListEl) {
      filesListEl.innerHTML = '';
      if (validFiles.length > 1) {
        validFiles.forEach(function (f) {
          var row = document.createElement('div');
          row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; padding:0.4rem 0.75rem; background:var(--clr-surface); border:1px solid var(--clr-border); border-radius:var(--radius-sm); font-size:0.8125rem;';
          row.innerHTML = '<div style="display:flex; align-items:center; gap:0.5rem; overflow:hidden;">'
            + '<i class="bi bi-file-earmark-check" style="color:var(--clr-primary);"></i>'
            + '<span style="font-weight:500; text-overflow:ellipsis; overflow:hidden; white-space:nowrap; max-width:280px;">' + escapeHtml(f.name) + '</span>'
            + '</div>'
            + '<span style="color:var(--clr-text-secondary); font-size:0.75rem; flex-shrink:0;">' + formatBytes(f.size) + '</span>';
          filesListEl.appendChild(row);
        });
      }
    }

    previewWrap.style.display = 'block';
    msg.textContent = '';
  }

  fileInput.addEventListener('change', function () {
    if (this.files && this.files.length) {
      handleFilesSelected(this.files);
    }
  });

  cancelBtn.addEventListener('click', function () {
    fileInput.value = '';
    previewWrap.style.display = 'none';
    if (filesListEl) filesListEl.innerHTML = '';
    msg.textContent = '';
  });

  // Drag & drop visual triggers
  ['dragenter', 'dragover'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.add('is-dragover');
    });
  });

  ['dragleave', 'drop'].forEach(function (evt) {
    dropzone.addEventListener(evt, function (e) {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.remove('is-dragover');
    });
  });

  dropzone.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      fileInput.files = e.dataTransfer.files;
      handleFilesSelected(e.dataTransfer.files);
    }
  });

  // Form submission
  document.getElementById('pdUpload').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var files = fileInput.files;
    if (!files || !files.length) {
      setMsg('Please select at least one document to upload.', true);
      return;
    }

    var totalBatchSize = 0;
    for (var i = 0; i < files.length; i++) {
      if (files[i].size > maxSingleBytes) {
        setMsg('"' + files[i].name + '" is larger than 10 MB.', true);
        return;
      }
      totalBatchSize += files[i].size;
    }

    if (totalBatchSize > remainingBytes) {
      setMsg('These files total ' + formatBytes(totalBatchSize) + ', which exceeds your available storage (' + formatBytes(remainingBytes) + ' remaining).', true);
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Uploading ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '...';

    var fd = new FormData();
    for (var j = 0; j < files.length; j++) {
      fd.append('documents[]', files[j]);
    }

    post('api/document-upload.php', fd).then(function (d) {
      if (d.success) {
        var count = d.count || files.length;
        setMsg(count + ' document' + (count === 1 ? '' : 's') + ' uploaded successfully! Refreshing...', false);
        setTimeout(function () { location.reload(); }, 700);
        return;
      }
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="bi bi-upload"></i> Upload Now';
      setMsg(d.error || 'Upload failed.', true);
    }).catch(function () {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="bi bi-upload"></i> Upload Now';
      setMsg('Network error while uploading.', true);
    });
  });

  // ── Document Selection & Batch Actions ─────────────────────────
  function triggerBatchDownload(ids) {
    if (!ids || !ids.length) return;
    ids.forEach(function (id, idx) {
      setTimeout(function () {
        var a = document.createElement('a');
        a.href = 'api/document-download.php?id=' + encodeURIComponent(id);
        a.download = '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
      }, idx * 350);
    });
  }

  // Section 1: Shared selection
  var selectAllShared = document.getElementById('pdSelectAllShared');
  var sharedCheckboxes = document.querySelectorAll('.doc-check-shared');
  var sharedCountEl = document.getElementById('pdSharedSelectedCount');
  var batchDownloadSharedBtn = document.getElementById('pdSharedBatchDownload');

  function updateSharedSelection() {
    var checked = document.querySelectorAll('.doc-check-shared:checked');
    var count = checked.length;
    var total = sharedCheckboxes.length;
    if (sharedCountEl) {
      sharedCountEl.textContent = count + ' selected';
    }
    if (batchDownloadSharedBtn) {
      batchDownloadSharedBtn.disabled = (count === 0);
    }
    if (selectAllShared) {
      selectAllShared.checked = (total > 0 && count === total);
      selectAllShared.indeterminate = (count > 0 && count < total);
    }
    sharedCheckboxes.forEach(function (cb) {
      var row = cb.closest('.doc-item-row');
      if (row) row.classList.toggle('is-selected', cb.checked);
    });
  }

  if (selectAllShared) {
    selectAllShared.addEventListener('change', function () {
      var state = this.checked;
      sharedCheckboxes.forEach(function (cb) { cb.checked = state; });
      updateSharedSelection();
    });
  }
  sharedCheckboxes.forEach(function (cb) {
    cb.addEventListener('change', updateSharedSelection);
  });
  if (batchDownloadSharedBtn) {
    batchDownloadSharedBtn.addEventListener('click', function () {
      var ids = Array.from(document.querySelectorAll('.doc-check-shared:checked')).map(function (c) {
        return c.dataset.id;
      });
      triggerBatchDownload(ids);
    });
  }

  // Section 2: Mine selection
  var selectAllMine = document.getElementById('pdSelectAllMine');
  var mineCheckboxes = document.querySelectorAll('.doc-check-mine');
  var mineCountEl = document.getElementById('pdMineSelectedCount');
  var batchDownloadMineBtn = document.getElementById('pdMineBatchDownload');
  var batchDelBtn = document.getElementById('pdMineBatchDelete');

  function updateMineSelection() {
    var checked = document.querySelectorAll('.doc-check-mine:checked');
    var count = checked.length;
    var total = mineCheckboxes.length;
    if (mineCountEl) {
      mineCountEl.textContent = count + ' selected';
    }
    if (batchDownloadMineBtn) {
      batchDownloadMineBtn.disabled = (count === 0);
    }
    if (batchDelBtn) {
      batchDelBtn.disabled = (count === 0);
    }
    if (selectAllMine) {
      selectAllMine.checked = (total > 0 && count === total);
      selectAllMine.indeterminate = (count > 0 && count < total);
    }
    mineCheckboxes.forEach(function (cb) {
      var row = cb.closest('.doc-item-row');
      if (row) row.classList.toggle('is-selected', cb.checked);
    });
  }

  if (selectAllMine) {
    selectAllMine.addEventListener('change', function () {
      var state = this.checked;
      mineCheckboxes.forEach(function (cb) { cb.checked = state; });
      updateMineSelection();
    });
  }
  mineCheckboxes.forEach(function (cb) {
    cb.addEventListener('change', updateMineSelection);
  });
  if (batchDownloadMineBtn) {
    batchDownloadMineBtn.addEventListener('click', function () {
      var ids = Array.from(document.querySelectorAll('.doc-check-mine:checked')).map(function (c) {
        return c.dataset.id;
      });
      triggerBatchDownload(ids);
    });
  }

  // ── Delete modal logic (Handles both Individual and Batch) ──────
  var modal = document.getElementById('pdConfirm');
  var cmsg = document.getElementById('pdConfirmMsg');
  var docNameEl = document.getElementById('pdDelDocName');
  var confirmYesBtn = document.getElementById('pdConfirmYes');
  var delIds = [];

  function closeModal() {
    modal.classList.remove('open');
    delIds = [];
  }

  // Individual delete button click (Preserved!)
  document.querySelectorAll('.pd-del').forEach(function (b) {
    b.addEventListener('click', function () {
      delIds = [b.dataset.id];
      docNameEl.textContent = '"' + (b.dataset.name || 'this document') + '"';
      cmsg.textContent = '';
      confirmYesBtn.disabled = false;
      confirmYesBtn.innerHTML = '<i class="bi bi-trash3"></i> Delete Document';
      modal.classList.add('open');
    });
  });

  // Batch delete button click
  if (batchDelBtn) {
    batchDelBtn.addEventListener('click', function () {
      var checked = Array.from(document.querySelectorAll('.doc-check-mine:checked')).map(function (c) {
        return c.dataset.id;
      });
      if (!checked.length) return;
      delIds = checked;
      docNameEl.textContent = checked.length + ' selected document' + (checked.length > 1 ? 's' : '');
      cmsg.textContent = '';
      confirmYesBtn.disabled = false;
      confirmYesBtn.innerHTML = '<i class="bi bi-trash3"></i> Delete ' + checked.length + ' Document' + (checked.length > 1 ? 's' : '');
      modal.classList.add('open');
    });
  }

  document.getElementById('pdConfirmNo').addEventListener('click', closeModal);
  document.getElementById('pdConfirmX').addEventListener('click', closeModal);

  confirmYesBtn.addEventListener('click', function () {
    if (!delIds || !delIds.length) return;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Deleting...';

    var fd = new FormData();
    delIds.forEach(function (id) {
      fd.append('ids[]', id);
    });

    post('api/document-delete.php', fd).then(function (d) {
      if (d.success) {
        location.reload();
      } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash3"></i> Delete';
        cmsg.style.color = 'var(--clr-danger)';
        cmsg.textContent = d.error || 'Could not delete document(s).';
      }
    }).catch(function () {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-trash3"></i> Delete';
      cmsg.style.color = 'var(--clr-danger)';
      cmsg.textContent = 'Network error.';
    });
  });
})();
</script>
