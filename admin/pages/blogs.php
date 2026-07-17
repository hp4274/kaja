<?php
$db = getDbConnection();

// Counts
$totalCount     = $db->query("SELECT COUNT(*) FROM `blogs`")->fetchColumn();
$publishedCount = $db->query("SELECT COUNT(*) FROM `blogs` WHERE `status`='published'")->fetchColumn();
$draftCount     = $db->query("SELECT COUNT(*) FROM `blogs` WHERE `status`='draft'")->fetchColumn();

// Status filter
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "SELECT * FROM `blogs`";
$params = [];
$where = [];

if ($statusFilter === 'published') {
    $where[] = "`status` = 'published'";
} elseif ($statusFilter === 'draft') {
    $where[] = "`status` = 'draft'";
}

if ($search) {
    $where[] = "(`title` LIKE :q OR `category` LIKE :q2)";
    $params[':q'] = "%{$search}%";
    $params[':q2'] = "%{$search}%";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY `created_at` DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Editing existing post?
$editBlog = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editStmt = $db->prepare("SELECT * FROM `blogs` WHERE `id`=:id");
    $editStmt->execute([':id'=>$editId]);
    $editBlog = $editStmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!-- Quill CSS -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet" />

<style>
/* ── Blog Manager Styles ── */
.blog-stats { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.blog-stat-card {
  flex:1; min-width:140px; padding:1rem 1.25rem;
  background:var(--clr-surface); border:1px solid var(--clr-border);
  border-radius:var(--radius-md); display:flex; align-items:center; gap:0.85rem;
}
.blog-stat-icon {
  width:42px; height:42px; border-radius:var(--radius-sm);
  display:flex; align-items:center; justify-content:center; font-size:1.15rem;
}
.blog-stat-icon.total   { background:var(--clr-info-light); color:var(--clr-info); }
.blog-stat-icon.pub     { background:var(--clr-success-light); color:var(--clr-success); }
.blog-stat-icon.draft   { background:var(--clr-warning-light); color:var(--clr-warning); }
.blog-stat-num  { font-size:1.35rem; font-weight:700; color:var(--clr-text); line-height:1; }
.blog-stat-label{ font-size:0.72rem; color:var(--clr-text-secondary); margin-top:2px; }

/* Editor panel */
.blog-editor-panel {
  background:var(--clr-surface); border:1px solid var(--clr-border);
  border-radius:var(--radius-md); padding:1.5rem; margin-bottom:1.5rem;
}
.blog-editor-panel h3 { font-size:1rem; font-weight:600; margin-bottom:1.25rem; color:var(--clr-text); }
.blog-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
.blog-form-grid .full { grid-column:1/-1; }
.blog-form-group label {
  display:block; font-size:0.78rem; font-weight:500; color:var(--clr-text-secondary); margin-bottom:0.3rem;
}
.blog-form-group input,
.blog-form-group select,
.blog-form-group textarea {
  width:100%; padding:0.55rem 0.75rem; border:1px solid var(--clr-border);
  border-radius:var(--radius-sm); font-size:0.85rem; font-family:var(--font-family);
  background:var(--clr-bg); color:var(--clr-text); transition:border var(--transition-fast);
}
.blog-form-group input:focus,
.blog-form-group select:focus,
.blog-form-group textarea:focus {
  outline:none; border-color:var(--clr-primary);
}
.blog-form-group textarea { resize:vertical; min-height:70px; }

/* Quill editor container */
#blogEditorContainer { height:280px; margin-bottom:0.5rem; border-radius:var(--radius-sm); }
#blogEditorContainer .ql-toolbar { border-radius:var(--radius-sm) var(--radius-sm) 0 0; border-color:var(--clr-border); }
#blogEditorContainer .ql-container { border-radius:0 0 var(--radius-sm) var(--radius-sm); border-color:var(--clr-border); font-size:0.9rem; }

.blog-form-actions { display:flex; gap:0.65rem; margin-top:1rem; }
.blog-form-actions .btn {
  padding:0.55rem 1.25rem; border:none; border-radius:var(--radius-sm);
  font-size:0.82rem; font-weight:500; cursor:pointer; transition:all var(--transition-fast);
  display:inline-flex; align-items:center; gap:0.4rem;
}
.btn-publish  { background:var(--clr-primary); color:#fff; }
.btn-publish:hover { background:var(--clr-primary-hover); }
.btn-draft    { background:var(--clr-bg); color:var(--clr-text); border:1px solid var(--clr-border) !important; }
.btn-draft:hover { background:var(--clr-border-light); }
.btn-cancel   { background:transparent; color:var(--clr-text-secondary); }
.btn-cancel:hover { color:var(--clr-danger); }

/* Table */
.blog-table { width:100%; border-collapse:collapse; }
.blog-table th {
  text-align:left; padding:0.7rem 1rem; font-size:0.72rem; font-weight:600;
  text-transform:uppercase; letter-spacing:0.03em; color:var(--clr-text-muted);
  border-bottom:1px solid var(--clr-border);
}
.blog-table td {
  padding:0.75rem 1rem; font-size:0.82rem; color:var(--clr-text);
  border-bottom:1px solid var(--clr-border-light); vertical-align:middle;
}
.blog-table tr:hover td { background:var(--clr-bg); }
.blog-title-cell { font-weight:500; max-width:280px; }
.blog-title-cell small { display:block; color:var(--clr-text-muted); font-size:0.72rem; font-weight:400; margin-top:2px; }

.status-badge {
  display:inline-block; padding:0.2rem 0.6rem; border-radius:var(--radius-full);
  font-size:0.7rem; font-weight:600; text-transform:capitalize;
}
.status-badge.published { background:var(--clr-success-light); color:var(--clr-success); }
.status-badge.draft     { background:var(--clr-warning-light); color:var(--clr-warning); }

.blog-cat-badge {
  display:inline-block; padding:0.18rem 0.55rem; border-radius:var(--radius-full);
  font-size:0.7rem; font-weight:500; background:var(--clr-primary-light); color:var(--clr-primary);
}

.blog-actions { display:flex; gap:0.35rem; }
.blog-actions button {
  background:none; border:1px solid var(--clr-border); border-radius:var(--radius-sm);
  padding:0.3rem 0.5rem; cursor:pointer; font-size:0.78rem; color:var(--clr-text-secondary);
  transition:all var(--transition-fast);
}
.blog-actions button:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
.blog-actions button.del:hover { border-color:var(--clr-danger); color:var(--clr-danger); }

@media (max-width:768px) {
  .blog-form-grid { grid-template-columns:1fr; }
  .blog-table th:nth-child(4), .blog-table td:nth-child(4),
  .blog-table th:nth-child(5), .blog-table td:nth-child(5) { display:none; }
}
</style>

<!-- Stats -->
<div class="blog-stats">
  <div class="blog-stat-card">
    <div class="blog-stat-icon total"><i class="bi bi-file-earmark-text"></i></div>
    <div><div class="blog-stat-num" id="stat-total"><?php echo $totalCount; ?></div><div class="blog-stat-label">Total Posts</div></div>
  </div>
  <div class="blog-stat-card">
    <div class="blog-stat-icon pub"><i class="bi bi-check-circle"></i></div>
    <div><div class="blog-stat-num" id="stat-published"><?php echo $publishedCount; ?></div><div class="blog-stat-label">Published</div></div>
  </div>
  <div class="blog-stat-card">
    <div class="blog-stat-icon draft"><i class="bi bi-pencil"></i></div>
    <div><div class="blog-stat-num" id="stat-draft"><?php echo $draftCount; ?></div><div class="blog-stat-label">Drafts</div></div>
  </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
  <div class="toolbar-left">
    <a href="index.php?page=blogs&status=all" class="filter-btn <?php echo $statusFilter==='all' ? 'active' : ''; ?>">All (<?php echo $totalCount; ?>)</a>
    <a href="index.php?page=blogs&status=published" class="filter-btn <?php echo $statusFilter==='published' ? 'active' : ''; ?>">Published (<?php echo $publishedCount; ?>)</a>
    <a href="index.php?page=blogs&status=draft" class="filter-btn <?php echo $statusFilter==='draft' ? 'active' : ''; ?>">Drafts (<?php echo $draftCount; ?>)</a>
  </div>
  <div class="toolbar-right">
    <form method="get" action="index.php" class="search-bar">
      <input type="hidden" name="page" value="blogs" />
      <i class="bi bi-search"></i>
      <input type="text" name="q" placeholder="Search posts..." value="<?php echo htmlspecialchars($search); ?>" />
    </form>
    <button type="button" class="btn-publish" style="margin-left:0.5rem; padding:0.45rem 1rem; border:none; border-radius:var(--radius-sm); font-size:0.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.35rem;" onclick="toggleEditor(true)">
      <i class="bi bi-plus-lg"></i> New Post
    </button>
  </div>
</div>

<!-- Editor Panel (hidden by default) -->
<div class="blog-editor-panel" id="blogEditorPanel" style="display:none;">
  <h3 id="editorTitle"><i class="bi bi-pencil-square"></i> New Blog Post</h3>
  <form id="blogForm" onsubmit="return false;">
    <input type="hidden" id="blogId" value="" />
    <div class="blog-form-grid">
      <div class="blog-form-group">
        <label for="blogTitleInput">Title *</label>
        <input type="text" id="blogTitleInput" placeholder="e.g. Understanding anxiety loops" required />
      </div>
      <div class="blog-form-group">
        <label for="blogSlug">URL Slug *</label>
        <input type="text" id="blogSlug" placeholder="auto-generated-from-title" />
      </div>
      <div class="blog-form-group">
        <label for="blogCategory">Category *</label>
        <select id="blogCategory">
          <option value="Anxiety">Anxiety</option>
          <option value="Relationships">Relationships</option>
          <option value="Self-Growth">Self-Growth</option>
          <option value="Trauma">Trauma</option>
          <option value="Mindfulness">Mindfulness</option>
        </select>
      </div>
      <div class="blog-form-group">
        <label for="blogReadTime">Read Time (minutes)</label>
        <input type="number" id="blogReadTime" value="5" min="1" max="60" />
      </div>
      <div class="blog-form-group">
        <label for="blogCoverImage">Cover Image URL</label>
        <input type="text" id="blogCoverImage" placeholder="https://images.unsplash.com/..." />
      </div>
      <div class="blog-form-group">
        <label for="blogImageFile">Or Upload Cover Image (Resized to 1200x800px)</label>
        <input type="file" id="blogImageFile" accept="image/*" />
      </div>
      <div class="blog-form-group full">
        <label for="blogExcerpt">Excerpt *</label>
        <textarea id="blogExcerpt" rows="2" placeholder="A short summary shown on the blog listing page..."></textarea>
      </div>
      <div class="blog-form-group full">
        <label>Content *</label>
        <div id="blogEditorContainer"></div>
      </div>
    </div>
    <div class="blog-form-actions">
      <button type="button" class="btn btn-publish" onclick="saveBlog('published')"><i class="bi bi-send"></i> Publish</button>
      <button type="button" class="btn btn-draft" onclick="saveBlog('draft')"><i class="bi bi-file-earmark"></i> Save as Draft</button>
      <button type="button" class="btn btn-cancel" onclick="toggleEditor(false)"><i class="bi bi-x-lg"></i> Cancel</button>
    </div>
  </form>
</div>

<!-- Blog List -->
<div class="panel">
  <div class="panel-body-flush">
    <?php if (empty($blogs)): ?>
      <div class="empty-state">
        <i class="bi bi-pencil-square"></i>
        <p>No blog posts found</p>
        <p style="font-size:0.78rem;">Click "New Post" to create your first blog article.</p>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="blog-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Category</th>
              <th>Status</th>
              <th>Read Time</th>
              <th>Date</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($blogs as $b): ?>
              <tr>
                <td class="blog-title-cell">
                  <?php echo htmlspecialchars($b['title']); ?>
                  <small>/<?php echo htmlspecialchars($b['slug']); ?></small>
                </td>
                <td><span class="blog-cat-badge"><?php echo htmlspecialchars($b['category']); ?></span></td>
                <td><span class="status-badge <?php echo $b['status']; ?>"><?php echo $b['status']; ?></span></td>
                <td><?php echo $b['read_time']; ?> min</td>
                <td><?php echo date('M j, Y', strtotime($b['created_at'])); ?></td>
                <td>
                  <div class="blog-actions" style="justify-content:flex-end;">
                    <button onclick="editBlog(<?php echo $b['id']; ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button onclick="toggleBlogStatus(<?php echo $b['id']; ?>)" title="Toggle Status">
                      <i class="bi bi-<?php echo $b['status']==='published' ? 'eye-slash' : 'eye'; ?>"></i>
                    </button>
                    <button class="del" onclick="deleteBlog(<?php echo $b['id']; ?>, '<?php echo addslashes(htmlspecialchars($b['title'])); ?>')" title="Delete"><i class="bi bi-trash3"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Quill JS -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function() {
  'use strict';

  var quill = null;
  var editorReady = false;

  function initQuill() {
    if (editorReady) return;
    quill = new Quill('#blogEditorContainer', {
      theme: 'snow',
      placeholder: 'Write your blog content here...',
      modules: {
        toolbar: [
          [{ 'header': [2, 3, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ 'list': 'ordered' }, { 'list': 'bullet' }],
          ['blockquote'],
          ['link', 'image'],
          ['clean']
        ]
      }
    });
    editorReady = true;
  }

  // Auto-generate slug from title
  var titleInput = document.getElementById('blogTitleInput');
  var slugInput  = document.getElementById('blogSlug');

  titleInput.addEventListener('input', function() {
    if (!document.getElementById('blogId').value) {
      slugInput.value = titleInput.value
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    }
  });

  // Toggle editor panel
  window.toggleEditor = function(show) {
    var panel = document.getElementById('blogEditorPanel');
    if (show) {
      panel.style.display = 'block';
      initQuill();
      if (!document.getElementById('blogId').value) {
        resetForm();
        document.getElementById('editorTitle').innerHTML = '<i class="bi bi-pencil-square"></i> New Blog Post';
      }
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      panel.style.display = 'none';
      resetForm();
    }
  };

  function resetForm() {
    document.getElementById('blogId').value = '';
    document.getElementById('blogTitleInput').value = '';
    document.getElementById('blogSlug').value = '';
    document.getElementById('blogCategory').value = 'Anxiety';
    document.getElementById('blogReadTime').value = '5';
    document.getElementById('blogCoverImage').value = '';
    document.getElementById('blogImageFile').value = '';
    document.getElementById('blogExcerpt').value = '';
    if (quill) quill.root.innerHTML = '';
  }

  // Save blog (create or update)
  window.saveBlog = function(status) {
    var id       = document.getElementById('blogId').value;
    var title    = document.getElementById('blogTitleInput').value.trim();
    var slug     = document.getElementById('blogSlug').value.trim();
    var category = document.getElementById('blogCategory').value;
    var readTime = document.getElementById('blogReadTime').value;
    var coverImg = document.getElementById('blogCoverImage').value.trim();
    var excerpt  = document.getElementById('blogExcerpt').value.trim();
    var content  = quill ? quill.root.innerHTML : '';

    if (!title || !excerpt || !content || content === '<p><br></p>') {
      showToast('Please fill in Title, Excerpt, and Content.', 'error');
      return;
    }

    if (!slug) {
      slug = title.toLowerCase().replace(/[^a-z0-9\s-]/g,'').replace(/\s+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
    }

    var formData = new FormData();
    formData.append('action', id ? 'update' : 'create');
    if (id) formData.append('id', id);
    formData.append('title', title);
    formData.append('slug', slug);
    formData.append('category', category);
    formData.append('read_time', readTime);
    formData.append('cover_image', coverImg);
    
    var fileInput = document.getElementById('blogImageFile');
    if (fileInput.files && fileInput.files[0]) {
      formData.append('cover_image_file', fileInput.files[0]);
    }
    
    formData.append('excerpt', excerpt);
    formData.append('content', content);
    formData.append('status', status);

    fetch('api/blogs.php', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          showToast(id ? 'Blog post updated!' : 'Blog post created!', 'success');
          setTimeout(function() { location.reload(); }, 600);
        } else {
          showToast(data.error || 'Failed to save', 'error');
        }
      })
      .catch(function() { showToast('Network error', 'error'); });
  };

  // Edit blog
  window.editBlog = function(blogId) {
    var formData = new FormData();
    formData.append('action', 'get');
    formData.append('id', blogId);

    fetch('api/blogs.php', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success && data.blog) {
          var b = data.blog;
          document.getElementById('blogId').value = b.id;
          document.getElementById('blogTitleInput').value = b.title;
          document.getElementById('blogSlug').value = b.slug;
          document.getElementById('blogCategory').value = b.category;
          document.getElementById('blogReadTime').value = b.read_time;
          document.getElementById('blogCoverImage').value = b.cover_image || '';
          document.getElementById('blogExcerpt').value = b.excerpt;

          toggleEditor(true);
          document.getElementById('editorTitle').innerHTML = '<i class="bi bi-pencil-square"></i> Edit Blog Post';
          if (quill) quill.root.innerHTML = b.content;
        } else {
          showToast('Could not load blog post', 'error');
        }
      })
      .catch(function() { showToast('Network error', 'error'); });
  };

  // Toggle status
  window.toggleBlogStatus = function(blogId) {
    var formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', blogId);

    fetch('api/blogs.php', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          showToast('Status changed to ' + data.new_status, 'success');
          setTimeout(function() { location.reload(); }, 600);
        } else {
          showToast(data.error || 'Failed', 'error');
        }
      })
      .catch(function() { showToast('Network error', 'error'); });
  };

  // Delete blog
  window.deleteBlog = function(blogId, title) {
    if (!confirm('Delete "' + title + '"? This cannot be undone.')) return;

    var formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', blogId);

    fetch('api/blogs.php', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          showToast('Blog post deleted', 'success');
          setTimeout(function() { location.reload(); }, 600);
        } else {
          showToast(data.error || 'Failed', 'error');
        }
      })
      .catch(function() { showToast('Network error', 'error'); });
  };

  // If editing on page load (via URL ?edit=ID)
  <?php if ($editBlog): ?>
  document.addEventListener('DOMContentLoaded', function() {
    editBlog(<?php echo $editBlog['id']; ?>);
  });
  <?php endif; ?>

})();
</script>
