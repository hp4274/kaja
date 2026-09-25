<?php $pageTitle = isset($pageTitle) ? $pageTitle : 'Dashboard'; ?>
<header class="admin-header">
  <div class="admin-header-left">
    <button class="btn-mobile-toggle" id="mobileMenuToggle" aria-label="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>
    <h1 class="admin-header-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
  </div>
  <div class="admin-header-right">
    <a href="../api/logout.php" class="btn-header-action">
      <i class="bi bi-box-arrow-right"></i> Logout
    </a>
  </div>
</header>
