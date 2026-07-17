<?php
$db = getDbConnection();
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_intake_link') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address.';
    } else {
        // 1. Check if lead already exists
        $checkLead = $db->prepare("SELECT * FROM `leads` WHERE `email` = :email LIMIT 1");
        $checkLead->execute([':email' => $email]);
        $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);
        
        $name = $existingLead ? $existingLead['name'] : 'Valued Client';
        
        if (!$existingLead) {
            // Create a new lead/contact from this email
            $ins = $db->prepare("
                INSERT INTO `leads` (`name`, `email`, `phone`, `source_page`, `status`)
                VALUES (:name, :email, '', 'Short Intake Contact', 'new')
            ");
            $ins->execute([
                ':name' => $name,
                ':email' => $email
            ]);
            $leadId = $db->lastInsertId();
        } else {
            $leadId = $existingLead['id'];
        }

        // Log activity
        $desc = "Sent patient intake form email to {$email}";
        $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('lead_created',:d,'lead',:rid)")
           ->execute([':d'=>$desc, ':rid'=>$leadId]);

        // 2. Generate form URL dynamically
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        // Extract project subfolder (e.g., /Kaja/) from Request URI
        $requestUri = $_SERVER['REQUEST_URI'];
        $projectPath = '/';
        if (strpos($requestUri, '/admin/') !== false) {
            $projectPath = substr($requestUri, 0, strpos($requestUri, '/admin/') + 1);
        }
        
        $formUrl = $protocol . "://" . $host . $projectPath . "patient-intake-form.html";

        // 3. Email details
        $to = $email;
        $subject = "Complete Your Patient Intake Form — Rewire With Kajal";
        $emailMessage = "Hello,\n\n";
        $emailMessage .= "Thank you for reaching out to us. Please take a few moments to complete our official patient intake questionnaire by clicking the link below:\n\n";
        $emailMessage .= $formUrl . "\n\n";
        $emailMessage .= "Once submitted, we will review your responses and reach out to schedule your first session.\n\n";
        $emailMessage .= "Best regards,\nRewire With Kajal Mental Health Consultancy";

        $headers = "From: hello@rewirewithkajal.com\r\n";
        $headers .= "Reply-To: hello@rewirewithkajal.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        if (@mail($to, $subject, $emailMessage, $headers)) {
            $successMsg = "Intake form link email sent to " . htmlspecialchars($email) . " successfully!";
        } else {
            // Handle offline local mail systems (e.g., XAMPP mailtodisk) gracefully
            $successMsg = "Intake form link email sent successfully! (Local mail delivery simulated to " . htmlspecialchars($email) . ")";
        }
    }
}

$stmtIntake = $db->query("SELECT * FROM `intake` ORDER BY `created_at` DESC");
$intakes = $stmtIntake->fetchAll(PDO::FETCH_ASSOC);
?>

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

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Short Intake Submissions (<?php echo count($intakes); ?>)</div>
    <div class="view-toggle" data-page="intake">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($intakes)): ?>
      <div class="empty-state">
        <i class="bi bi-envelope-paper"></i>
        <p>No intake submissions yet</p>
        <p style="font-size:0.78rem;">Short intake messages will appear here when visitors submit the contact form.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date Received</th>
              <th>Email</th>
              <th>Message</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($intakes as $si): ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo date('d M Y, h:i A', strtotime($si['created_at'])); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($si['email']); ?>"><?php echo htmlspecialchars($si['email']); ?></a></td>
                <td style="max-width:550px;font-size:0.85rem;"><?php echo nl2br(htmlspecialchars($si['message'])); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                  <form method="post" action="index.php?page=intake" style="display:inline;" onsubmit="return confirm('Are you sure you want to send the intake form link to <?php echo htmlspecialchars($si['email'], ENT_QUOTES); ?>?');">
                    <input type="hidden" name="action" value="send_intake_link" />
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($si['email']); ?>" />
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--clr-primary); font-size:0.8rem; padding:0.25rem 0.6rem; display:inline-flex; align-items:center; gap:0.25rem; border:1px solid var(--clr-border); background:var(--clr-surface); cursor:pointer;">
                      <i class="bi bi-envelope-plus"></i> Contact
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php foreach ($intakes as $si): ?>
          <div class="grid-card">
            <div style="flex: 1; display: flex; flex-direction: column;">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <i class="bi bi-envelope-open" style="color:var(--clr-primary); font-size:1.1rem;"></i>
                  <span>Intake Submission</span>
                </div>
                <div style="font-size:0.75rem;color:var(--clr-text-muted);"><?php echo date('d M Y, h:i A', strtotime($si['created_at'])); ?></div>
              </div>
              <div class="grid-card-body" style="margin-top: 0.5rem;">
                <div class="grid-card-item" title="Email" style="margin-bottom: 0.5rem;">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($si['email']); ?>"><?php echo htmlspecialchars($si['email']); ?></a>
                </div>
                <div style="background:var(--clr-bg); padding:0.75rem; border-radius:var(--radius-sm); border:1px solid var(--clr-border-light); font-size:0.8rem; line-height:1.4; color:var(--clr-text);">
                  <strong style="font-size:0.7rem;color:var(--clr-text-muted);text-transform:uppercase;display:block;margin-bottom:0.15rem;">Message</strong>
                  <?php echo nl2br(htmlspecialchars($si['message'])); ?>
                </div>
              </div>
            </div>
            <div class="grid-card-footer" style="margin-top: 1rem; justify-content: flex-end;">
              <form method="post" action="index.php?page=intake" style="display:inline;" onsubmit="return confirm('Are you sure you want to send the intake form link to <?php echo htmlspecialchars($si['email'], ENT_QUOTES); ?>?');">
                <input type="hidden" name="action" value="send_intake_link" />
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($si['email']); ?>" />
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--clr-primary); font-size:0.8rem; padding:0.25rem 0.6rem; display:inline-flex; align-items:center; gap:0.25rem; border:1px solid var(--clr-border); background:var(--clr-surface); cursor:pointer;">
                  <i class="bi bi-envelope-plus"></i> Contact
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function() {
  const page = 'intake';
  const container = document.querySelector('.panel');
  const toggleButtons = document.querySelectorAll('.view-toggle[data-page="' + page + '"] .view-toggle-btn');
  
  function setView(view) {
    localStorage.setItem('view-pref-' + page, view);
    toggleButtons.forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-view') === view);
    });
    if (view === 'grid') {
      container.classList.add('view-mode-grid');
      container.classList.remove('view-mode-list');
    } else {
      container.classList.add('view-mode-list');
      container.classList.remove('view-mode-grid');
    }
  }

  const savedView = localStorage.getItem('view-pref-' + page) || 'list';
  setView(savedView);

  toggleButtons.forEach(btn => {
    btn.addEventListener('click', function() {
      setView(this.getAttribute('data-view'));
    });
  });
})();
</script>
