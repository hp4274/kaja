<?php
require_once __DIR__ . '/includes/auth.php';

$page = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';
$allowedPages = ['dashboard','leads','intake','patient-intake','clients','client-profile','sessions','reports','settings','emails','blogs','forms'];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

$currentPage = $page;

// Map pages to titles
$pageTitles = [
    'dashboard'      => 'Dashboard',
    'leads'          => 'Leads',
    'intake'         => 'New Intake',
    'patient-intake' => 'Patient Intake',
    'clients'        => 'Clients',
    'client-profile' => 'Client Profile',
    'sessions'       => 'Sessions & Calendar',
    'reports'        => 'Reports & Analytics',
    'forms'          => 'Intake Form Builder',
    'emails'         => 'Emails',
    'settings'       => 'Settings',
    'blogs'          => 'Blog Manager',
];

$pageTitle = $pageTitles[$page] ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Admin Dashboard — Rewire With Kajal Mental Health Consultancy" />
  <title><?php echo htmlspecialchars($pageTitle); ?> | Rewire Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/css/dashboard.css'); ?>" />
  <link rel="icon" type="image/png" href="../images/logo3.png" />
</head>
<body class="admin-body">
  <script>
    (function() {
      if (localStorage.getItem('admin-theme') === 'dark') {
        document.body.classList.add('admin-dark-theme');
      }
    })();
  </script>

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="admin-main">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <div class="admin-content">
      <?php include __DIR__ . '/pages/' . $page . '.php'; ?>
    </div>
  </div>

  <!-- Toast notification -->
  <div class="alert-toast" id="alertToast"></div>

  <!-- One dialog, reused everywhere a page used to call the browser's own
       confirm()/prompt(). Those blocked with the site's own chrome hidden
       behind them and could not be styled or reasoned about like everything
       else here, so every page now awaits showConfirm()/showPrompt() instead. -->
  <div class="modal-overlay" id="dialogModal">
    <div class="modal-box is-narrow">
      <div class="modal-header">
        <div class="modal-title" id="dialogTitle">Confirm</div>
        <button class="modal-close" type="button" id="dialogClose">&times;</button>
      </div>
      <div class="modal-body">
        <p class="prose" id="dialogMessage"></p>
        <input type="text" class="form-input" id="dialogInput" hidden />
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="dialogCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="dialogOk">OK</button>
      </div>
    </div>
  </div>

  <script>
    // Sidebar mobile toggle
    var sidebar = document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggleBtn = document.getElementById('mobileMenuToggle');

    if (toggleBtn) {
      toggleBtn.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
      });
    }
    if (overlay) {
      overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
      });
    }

    // Toast notification helper
    function showToast(message, type) {
      type = type || 'success';
      var toast = document.getElementById('alertToast');
      toast.textContent = message;
      toast.className = 'alert-toast ' + type + ' show';
      setTimeout(function() {
        toast.classList.remove('show');
      }, 3000);
    }

    // escapeHtml helper
    function escapeHtml(text) {
      if (!text) return '';
      var el = document.createElement('span');
      el.textContent = text;
      return el.innerHTML;
    }

    // One popup standing in for both window.confirm() and window.prompt(),
    // everywhere across the admin. showConfirm() resolves a boolean;
    // showPrompt() resolves the typed string, or null on Cancel -- same
    // contract the two browser dialogs had, so every call site just adds
    // "await" instead of changing its own logic.
    var dialogResolve = null;

    function openDialog(message, opts) {
      opts = opts || {};
      document.getElementById('dialogTitle').textContent = opts.title || (opts.isPrompt ? 'Enter a value' : 'Confirm');
      document.getElementById('dialogMessage').textContent = message;

      var input = document.getElementById('dialogInput');
      input.hidden = !opts.isPrompt;
      input.value = opts.defaultValue || '';

      var okBtn = document.getElementById('dialogOk');
      okBtn.textContent = opts.okText || 'OK';
      okBtn.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');

      document.getElementById('dialogModal').classList.add('open');
      if (opts.isPrompt) { setTimeout(function () { input.focus(); }, 50); }

      return new Promise(function (resolve) { dialogResolve = resolve; });
    }

    function closeDialog(result) {
      document.getElementById('dialogModal').classList.remove('open');
      if (dialogResolve) { dialogResolve(result); dialogResolve = null; }
    }

    function showConfirm(message, opts) {
      return openDialog(message, opts).then(function (r) { return r === true; });
    }

    function showPrompt(message, defaultValue) {
      return openDialog(message, { isPrompt: true, defaultValue: defaultValue })
        .then(function (r) { return r === false ? null : r; });
    }

    (function () {
      var modal = document.getElementById('dialogModal');
      var input = document.getElementById('dialogInput');

      document.getElementById('dialogCancel').addEventListener('click', function () { closeDialog(false); });
      document.getElementById('dialogClose').addEventListener('click', function () { closeDialog(false); });
      document.getElementById('dialogOk').addEventListener('click', function () {
        closeDialog(input.hidden ? true : input.value);
      });
      modal.addEventListener('click', function (e) { if (e.target === modal) closeDialog(false); });
      document.addEventListener('keydown', function (e) {
        if (!modal.classList.contains('open')) return;
        if (e.key === 'Escape') { closeDialog(false); }
        if (e.key === 'Enter' && (input.hidden || document.activeElement === input)) {
          closeDialog(input.hidden ? true : input.value);
        }
      });
    })();
  </script>
</body>
</html>
