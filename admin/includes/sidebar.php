<?php
$currentPage = isset($currentPage) ? $currentPage : 'dashboard';
$db = getDbConnection();
$newLeadCount = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='new'")->fetchColumn();
?>
<aside class="admin-sidebar" id="adminSidebar">
  <div class="sidebar-brand">
    <img src="../images/logo3.png" alt="Logo" />
    <div>
      <div class="sidebar-brand-text">Rewire</div>
      <div class="sidebar-brand-sub">Therapist Portal</div>
    </div>
  </div>

  <ul class="sidebar-nav">
    <li class="sidebar-section-label">Main</li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard">
        <i class="bi bi-grid-1x2"></i> Dashboard
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'leads' ? 'active' : ''; ?>" href="index.php?page=leads">
        <i class="bi bi-funnel"></i> Leads
        <?php if ($newLeadCount > 0): ?><span class="badge-count"><?php echo $newLeadCount; ?></span><?php endif; ?>
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'intake' ? 'active' : ''; ?>" href="index.php?page=intake">
        <i class="bi bi-envelope-paper"></i> New Intake
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'patient-intake' ? 'active' : ''; ?>" href="index.php?page=patient-intake">
        <i class="bi bi-clipboard2-pulse"></i> Patient Intake
      </a>
    </li>

    <li class="sidebar-section-label">Management</li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'clients' ? 'active' : ''; ?>" href="index.php?page=clients">
        <i class="bi bi-people"></i> Clients
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'sessions' ? 'active' : ''; ?>" href="index.php?page=sessions">
        <i class="bi bi-calendar3"></i> Sessions
      </a>
    </li>

    <li class="sidebar-section-label">Insights</li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'blogs' ? 'active' : ''; ?>" href="index.php?page=blogs">
        <i class="bi bi-pencil-square"></i> Blog Manager
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'reports' ? 'active' : ''; ?>" href="index.php?page=reports">
        <i class="bi bi-graph-up-arrow"></i> Reports
      </a>
    </li>
    <li>
      <a class="sidebar-link <?php echo $currentPage === 'settings' ? 'active' : ''; ?>" href="index.php?page=settings">
        <i class="bi bi-gear"></i> Settings
      </a>
    </li>
  </ul>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-avatar"><?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
        <div class="sidebar-user-role">Therapist</div>
      </div>
    </div>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
