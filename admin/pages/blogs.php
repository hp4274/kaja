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

<!-- The blog manager used to carry its own stylesheet: its own table, its own
     form fields, its own badges and its own buttons, at padding values that
     matched nothing else in the panel. Everything it needs is a shared
     component now; only the Quill container, which is a third-party widget,
     still needs styling of its own, and that lives in dashboard.css. -->

<!-- Stats -->
<div class="stat-strip">
  <div class="stat-strip-card">
    <div class="stat-strip-icon blue"><i class="bi bi-file-earmark-text"></i></div>
    <div><div class="stat-strip-num" id="stat-total"><?php echo $totalCount; ?></div><div class="stat-strip-label">Total Posts</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon green"><i class="bi bi-check-circle"></i></div>
    <div><div class="stat-strip-num" id="stat-published"><?php echo $publishedCount; ?></div><div class="stat-strip-label">Published</div></div>
  </div>
  <div class="stat-strip-card">
    <div class="stat-strip-icon amber"><i class="bi bi-pencil"></i></div>
    <div><div class="stat-strip-num" id="stat-draft"><?php echo $draftCount; ?></div><div class="stat-strip-label">Drafts</div></div>
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
    <button type="button" class="btn btn-primary btn-sm" onclick="toggleEditor(true)">
      <i class="bi bi-plus-lg"></i> New Post
    </button>
  </div>
</div>

<!-- Editor Panel (hidden by default) -->
<div class="panel" id="blogEditorPanel" hidden>
  <div class="panel-body">
  <h3 class="subsection-title" id="editorTitle"><i class="bi bi-pencil-square"></i> New Blog Post</h3>
  <form id="blogForm" onsubmit="return false;">
    <input class="form-input" type="hidden" id="blogId" value="" />
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="blogTitleInput">Title *</label>
        <input class="form-input" type="text" id="blogTitleInput" placeholder="e.g. Understanding anxiety loops" required />
      </div>
      <div class="form-group">
        <label class="form-label" for="blogSlug">URL Slug *</label>
        <input class="form-input" type="text" id="blogSlug" placeholder="auto-generated-from-title" />
      </div>
      <div class="form-group">
        <label class="form-label" for="blogCategory">Category *</label>
        <select class="form-select" id="blogCategory">
          <option value="Anxiety">Anxiety</option>
          <option value="Relationships">Relationships</option>
          <option value="Self-Growth">Self-Growth</option>
          <option value="Trauma">Trauma</option>
          <option value="Mindfulness">Mindfulness</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="blogReadTime">Read Time (minutes)</label>
        <input class="form-input" type="number" id="blogReadTime" value="5" min="1" max="60" />
      </div>
      <div class="form-group">
        <label class="form-label" for="blogCoverImage">Cover Image URL</label>
        <input class="form-input" type="text" id="blogCoverImage" placeholder="https://images.unsplash.com/..." />
      </div>
      <div class="form-group">
        <label class="form-label" for="blogImageFile">Or Upload Cover Image (Resized to 1200x800px)</label>
        <input class="form-input" type="file" id="blogImageFile" accept="image/*" />
      </div>
      <div class="form-group is-full">
        <label class="form-label" for="blogExcerpt">Excerpt *</label>
        <textarea class="form-textarea" id="blogExcerpt" rows="2" placeholder="A short summary shown on the blog listing page..."></textarea>
      </div>
      <div class="form-group is-full">
        <label class="form-label">Content *</label>
        <div id="blogEditorContainer"></div>
      </div>
    </div>
    <div class="form-actions">
      <button type="button" class="btn btn-primary" onclick="saveBlog('published')"><i class="bi bi-send"></i> Publish</button>
      <button type="button" class="btn btn-ghost" onclick="saveBlog('draft')"><i class="bi bi-file-earmark"></i> Save as Draft</button>
      <button type="button" class="btn btn-ghost" onclick="toggleEditor(false)"><i class="bi bi-x-lg"></i> Cancel</button>
    </div>
  </form>
  </div>
</div>

<!-- Blog List -->
<div class="panel">
  <div class="panel-body-flush">
    <?php if (empty($blogs)): ?>
      <div class="empty-state">
        <i class="bi bi-pencil-square"></i>
        <p>No blog posts found</p>
        <p>Click "New Post" to create your first blog article.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Category</th>
              <th>Status</th>
              <th>Read Time</th>
              <th>Date</th>
              <th class="th-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($blogs as $b): ?>
              <tr>
                <td class="td-name td-title">
                  <?php echo htmlspecialchars($b['title']); ?>
                  <small>/<?php echo htmlspecialchars($b['slug']); ?></small>
                </td>
                <td><span class="badge badge-category"><?php echo htmlspecialchars($b['category']); ?></span></td>
                <td><span class="badge badge-<?php echo $b['status']; ?>"><?php echo $b['status']; ?></span></td>
                <td><?php echo $b['read_time']; ?> min</td>
                <td><?php echo date('M j, Y', strtotime($b['created_at'])); ?></td>
                <td class="td-actions">
                  <div class="row-actions">
                    <button class="btn btn-icon" onclick="editBlog(<?php echo $b['id']; ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-icon" onclick="toggleBlogStatus(<?php echo $b['id']; ?>)" title="<?php echo $b['status']==='published' ? 'Unpublish' : 'Publish'; ?>">
                      <i class="bi bi-<?php echo $b['status']==='published' ? 'eye-slash' : 'eye'; ?>"></i>
                    </button>
                    <button class="btn btn-icon is-danger" onclick="deleteBlog(<?php echo $b['id']; ?>, '<?php echo addslashes(htmlspecialchars($b['title'])); ?>')" title="Delete"><i class="bi bi-trash3"></i></button>
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
      panel.hidden = false;
      initQuill();
      if (!document.getElementById('blogId').value) {
        resetForm();
        document.getElementById('editorTitle').innerHTML = '<i class="bi bi-pencil-square"></i> New Blog Post';
      }
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      panel.hidden = true;
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
