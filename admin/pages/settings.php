<?php
$db = getDbConnection();
$currentUserId = $_SESSION['user_id'];

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_action'])) {
    if ($_POST['settings_action'] === 'add_admin') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $errorMsg = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password must be at least 6 characters.';
        } else {
            // Check if username or email already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = :u OR `email` = :e");
            $stmt->execute([':u' => $username, ':e' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $errorMsg = 'Username or email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO `users` (`username`, `email`, `password`) VALUES (:u, :e, :p)");
                $ins->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
                $successMsg = "Admin user '" . htmlspecialchars($username) . "' has been added successfully.";
            }
        }
    }

    if ($_POST['settings_action'] === 'remove_admin') {
        $adminId = intval($_POST['admin_id'] ?? 0);

        if ($adminId === $currentUserId) {
            $errorMsg = 'You cannot remove your own admin account.';
        } else {
            // Check count of remaining admins
            $count = $db->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
            if ($count <= 1) {
                $errorMsg = 'At least one admin account must remain.';
            } else {
                // Get username for success message
                $stmt = $db->prepare("SELECT `username` FROM `users` WHERE `id` = :id");
                $stmt->execute([':id' => $adminId]);
                $usernameToDelete = $stmt->fetchColumn();

                if ($usernameToDelete) {
                    $del = $db->prepare("DELETE FROM `users` WHERE `id` = :id");
                    $del->execute([':id' => $adminId]);
                    $successMsg = "Admin user '" . htmlspecialchars($usernameToDelete) . "' has been removed successfully.";
                } else {
                    $errorMsg = 'Admin user not found.';
                }
            }
        }
    }
}

// Fetch all admins
$admins = $db->query("SELECT * FROM `users` ORDER BY `username` ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.theme-switch {
  position: relative;
  display: inline-block;
  width: 52px;
  height: 28px;
}
.theme-switch input {
  position: absolute;
  opacity: 0;
  width: 100%;
  height: 100%;
  top: 0;
  left: 0;
  margin: 0;
  cursor: pointer;
  z-index: 2;
}
.slider {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: var(--clr-border);
  transition: .3s;
  border-radius: 28px;
  z-index: 1;
}
.slider:before {
  position: absolute;
  content: "";
  height: 20px;
  width: 20px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .3s;
  border-radius: 50%;
  box-shadow: var(--shadow-sm);
  z-index: 1;
}
.theme-switch input:checked + .slider {
  background-color: var(--clr-primary);
}
.theme-switch input:checked + .slider:before {
  transform: translateX(24px);
}
@media (max-width: 768px) {
  .settings-grid {
    grid-template-columns: 1fr !important;
  }
}
</style>

<?php if ($successMsg): ?>
  <div style="background:var(--clr-success-light);color:var(--clr-success);padding:0.75rem 1.25rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:0.88rem;font-weight:500;">
    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?>
  </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
  <div style="background:var(--clr-danger-light);color:var(--clr-danger);padding:0.75rem 1.25rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:0.88rem;font-weight:500;">
    <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($errorMsg); ?>
  </div>
<?php endif; ?>

<!-- Theme Settings Panel -->
<div class="panel" style="margin-bottom:1.5rem;max-width:900px;">
  <div class="panel-header"><div class="panel-title">Appearance Settings</div></div>
  <div class="panel-body">
    <div style="display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-weight:600;font-size:0.95rem;color:var(--clr-text);">Dark Mode</div>
        <div style="font-size:0.85rem;color:var(--clr-text-secondary);margin-top:0.15rem;">Switch between light and dark themes for the therapist portal.</div>
      </div>
      <div>
        <label class="theme-switch">
          <input type="checkbox" id="darkModeToggle" />
          <span class="slider"></span>
        </label>
      </div>
    </div>
  </div>
</div>

<!-- Admin Management Grid -->
<div class="settings-grid" style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem;max-width:900px;align-items:start;">
  <!-- Admin Users List -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Admin Accounts</div>
    </div>
    <div class="panel-body panel-body-flush">
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Username</th>
              <th>Email</th>
              <th>Created At</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($admins as $admin): ?>
              <tr>
                <td class="td-name">
                  <?php echo htmlspecialchars($admin['username']); ?>
                  <?php if ($admin['id'] === $currentUserId): ?>
                    <span style="background:var(--clr-primary-light);color:var(--clr-primary);font-size:0.7rem;font-weight:600;padding:0.15rem 0.4rem;border-radius:var(--radius-sm);margin-left:0.25rem;">You</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($admin['email']); ?></td>
                <td><?php echo date('M d, Y', strtotime($admin['created_at'])); ?></td>
                <td style="text-align:right;">
                  <?php if ($admin['id'] === $currentUserId): ?>
                    <span style="color:var(--clr-text-muted);font-size:0.8rem;font-style:italic;">Active Session</span>
                  <?php else: ?>
                    <form method="post" action="index.php?page=settings" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove the admin account for \'<?php echo htmlspecialchars($admin['username'], ENT_QUOTES); ?>\'?');">
                      <input type="hidden" name="settings_action" value="remove_admin" />
                      <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>" />
                      <button type="submit" style="color:var(--clr-danger);background:none;border:none;padding:0.25rem;cursor:pointer;font-size:0.88rem;display:inline-flex;align-items:center;gap:0.25rem;">
                        <i class="bi bi-trash"></i> Remove
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Add Admin Form -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">Add New Admin</div>
    </div>
    <div class="panel-body">
      <form method="post" action="index.php?page=settings">
        <input type="hidden" name="settings_action" value="add_admin" />
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input type="text" class="form-input" id="username" name="username" required autocomplete="off" />
        </div>
        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input type="email" class="form-input" id="email" name="email" required autocomplete="off" />
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input type="password" class="form-input" id="password" name="password" required minlength="6" autocomplete="new-password" />
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:0.5rem;width:100%;">Create Admin</button>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var toggle = document.getElementById('darkModeToggle');
  console.log('[Theme Debug] Initial check. localStorage theme:', localStorage.getItem('admin-theme'));
  console.log('[Theme Debug] Initial checkbox checked status:', toggle.checked);
  
  // Set toggle state based on localStorage
  if (localStorage.getItem('admin-theme') === 'dark') {
    toggle.checked = true;
    console.log('[Theme Debug] Checkbox checked set to true because localStorage is dark');
  }
  
  toggle.addEventListener('change', function() {
    console.log('[Theme Debug] Toggle changed. checked:', toggle.checked);
    if (toggle.checked) {
      document.body.classList.add('admin-dark-theme');
      localStorage.setItem('admin-theme', 'dark');
      console.log('[Theme Debug] Added admin-dark-theme. Body class list:', document.body.className);
      if (window.showToast) {
        showToast('Dark mode enabled', 'success');
      }
    } else {
      document.body.classList.remove('admin-dark-theme');
      localStorage.setItem('admin-theme', 'light');
      console.log('[Theme Debug] Removed admin-dark-theme. Body class list:', document.body.className);
      if (window.showToast) {
        showToast('Light mode enabled', 'success');
      }
    }
  });
});
</script>
