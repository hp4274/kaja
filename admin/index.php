<?php
require_once __DIR__ . '/includes/auth.php';

$page = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';
$allowedPages = ['dashboard','leads','intake','patient-intake','clients','client-profile','sessions','reports','settings','blogs'];

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
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>" />
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
  </script>
</body>
</html>
