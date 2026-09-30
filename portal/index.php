<?php
/**
 * Client portal shell. Section pages live in portal/pages/<name>.php and are
 * included here with $client (clients row), $db (PDO) and $page in scope.
 */
require_once __DIR__ . '/includes/auth.php';
$client = requireClient();
$db = getDbConnection();

// Whitelist: add a key here when adding a page.
$nav = [
    'dashboard' => ['Dashboard', 'bi-house'],
    'sessions'  => ['Sessions', 'bi-calendar-check'],
    'intake'    => ['Intake & Assessments', 'bi-clipboard2-pulse'],
    'billing'   => ['Billing', 'bi-receipt'],
    'documents' => ['Documents', 'bi-folder2-open'],
    'profile'   => ['Profile', 'bi-person'],
];
$page = $_GET['page'] ?? 'dashboard';
if (!is_string($page) || !isset($nav[$page])) {
    $page = 'dashboard';
}
$file = __DIR__ . '/pages/' . $page . '.php';
$practice = getSetting('practice_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?= e($nav[$page][0]) ?> | <?= e($practice) ?> Portal</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="../admin/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../admin/css/dashboard.css') ?>" />
  <link rel="stylesheet" href="css/portal.css?v=<?= filemtime(__DIR__ . '/css/portal.css') ?>" />
  <link rel="icon" type="image/png" href="../images/logo3.png" />
  <meta name="csrf-token" content="<?= e(portalCsrfToken()) ?>" />
</head>
<body class="admin-body">
  <script>
    (function() {
      try { if (localStorage.getItem('admin-theme') === 'dark') document.body.classList.add('admin-dark-theme'); } catch (e) {}
    })();
  </script>
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
      <img src="../images/logo3.png" alt="Logo" />
      <div>
        <div class="sidebar-brand-text"><?= e($practice) ?></div>
        <div class="sidebar-brand-sub">Client Portal</div>
      </div>
    </div>
    <ul class="sidebar-nav">
      <li class="sidebar-section-label">Menu</li>
      <?php foreach ($nav as $key => $item): ?>
        <li><a href="?page=<?= e($key) ?>" class="sidebar-link <?= $key === $page ? 'active' : '' ?>"><i class="bi <?= e($item[1]) ?>"></i> <?= e($item[0]) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= e(strtoupper(substr($client['first_name'], 0, 1))) ?></div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= e($client['first_name'] . ' ' . $client['last_name']) ?></div>
          <div class="sidebar-user-role">Client</div>
        </div>
      </div>
    </div>
  </aside>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <div class="admin-main">
    <?php if (!empty($_SESSION['admin_impersonating'])): ?>
      <div class="admin-preview-banner">
        <div class="preview-banner-content">
          <span class="preview-badge"><i class="bi bi-shield-lock-fill"></i> Admin Preview</span>
          <span class="preview-text">
            Viewing portal as <strong><?= e($client['first_name'] . ' ' . $client['last_name']) ?></strong> (<?= e($client['email']) ?>)
          </span>
        </div>
        <div class="preview-banner-actions">
          <a href="../admin/index.php?page=client-profile&id=<?= (int) $client['id'] ?>" class="btn-preview-link">
            <i class="bi bi-arrow-left"></i> Client Profile
          </a>
          <a href="api/exit-preview.php" class="btn-preview-exit">
            <i class="bi bi-box-arrow-right"></i> Exit Preview
          </a>
        </div>
      </div>
    <?php endif; ?>
    <header class="admin-header">
      <div class="admin-header-left">
        <button class="btn-mobile-toggle" id="mobileMenuToggle" type="button" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
        <h1 class="admin-header-title"><?= e($nav[$page][0]) ?></h1>
      </div>
      <div class="admin-header-right">
        <a href="#" id="ptLogout" class="btn-header-action"><i class="bi bi-box-arrow-right"></i> Logout</a>
      </div>
    </header>
    <main class="admin-content">
      <?php if (is_file($file)) { include $file; } else { ?>
        <div class="panel"><div class="panel-body"><div class="empty-state"><i class="bi bi-hourglass-split"></i><p>This section is coming soon.</p></div></div></div>
      <?php } ?>
    </main>
  </div>
  <script>
    var sb = document.getElementById('adminSidebar'), ov = document.getElementById('sidebarOverlay');
    document.getElementById('mobileMenuToggle').addEventListener('click', function () { sb.classList.toggle('open'); ov.classList.toggle('open'); });
    ov.addEventListener('click', function () { sb.classList.remove('open'); ov.classList.remove('open'); });
    document.getElementById('ptLogout').addEventListener('click', function (ev) {
      ev.preventDefault();
      var fd = new FormData();
      fd.append('csrf', document.querySelector('meta[name=csrf-token]').content);
      fetch('api/logout.php', { method: 'POST', body: fd }).finally(function () {
        location.href = '../customer-login.html';
      });
    });
  </script>
</body>
</html>
