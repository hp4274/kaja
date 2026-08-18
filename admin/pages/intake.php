<?php
require_once __DIR__ . "/../../includes/settings.php";
require_once __DIR__ . "/../../includes/intake-token.php";
require_once __DIR__ . "/../../includes/intake-repo.php";
require_once __DIR__ . "/../../includes/intake-status.php";

$db = getDbConnection();
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_intake_link') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address.';
    } else {
        // Find or create the lead this link belongs to, then mint a token.
        // The link that goes out is personal to this address: intake.php refuses
        // to render without one, so a forwarded or guessed URL gets nowhere.
        $checkLead = $db->prepare("SELECT `id`, `name` FROM `leads` WHERE `email` = :email ORDER BY `id` ASC LIMIT 1");
        $checkLead->execute([':email' => $email]);
        $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);

        try {
            $db->beginTransaction();

            if ($existingLead) {
                $leadId   = (int) $existingLead['id'];
                $leadName = $existingLead['name'];
            } else {
                $ins = $db->prepare("
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source`, `status`)
                    VALUES ('Valued Client', :email, '', 'Short Intake Contact', 'new')
                ");
                $ins->execute([':email' => $email]);
                $leadId   = (int) $db->lastInsertId();
                $leadName = '';
            }

            // No client row yet: we know only an email address. submit_intake.php
            // creates the client when the questionnaire actually arrives.
            $issued = issueIntakeToken($db, $leadId, null);

            $desc = "Intake link issued to {$email} (expires " . date('d M Y', strtotime($issued['expires_at'])) . ")";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('intake_link_sent',:d,'lead',:rid)")
               ->execute([':d' => $desc, ':rid' => $leadId]);

            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $issued = null;
            $errorMsg = 'Could not create the intake link. Please try again.';
            error_log('[admin/intake] ' . $e->getMessage());
        }

        // Mail only after the commit: a mail failure must not undo a valid token.
        if ($issued) {
            if (sendIntakeLinkEmail($email, $leadName, $issued['url'], $issued['expires_at'])) {
                $successMsg = "Personal intake link sent to " . htmlspecialchars($email) . " successfully!";
            } else {
                // XAMPP has no real MTA; the link is live regardless, so surface
                // it rather than pretending it was delivered.
                $successMsg = "Intake link created for " . htmlspecialchars($email)
                    . " (local mail not delivered). Share this link directly: " . htmlspecialchars($issued['url']);
            }
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

<?php
// The token is deliberately absent from this table. It is the credential for
// someone's private form; a glance over a shoulder should not be enough to
// open it. Status, timing and expiry are what the therapist actually needs.
$linkStatus = isset($_GET['link_status']) ? trim($_GET['link_status']) : '';
$links      = intakeLinksList($db, $linkStatus);
?>

<div class="toolbar" style="margin-top:1.5rem;">
  <div class="toolbar-left">
    <a href="index.php?page=intake&link_status=all" class="filter-btn <?php echo ($linkStatus === 'all' || $linkStatus === '') ? 'active' : ''; ?>">All</a>
    <?php foreach (intakeStatuses() as $st): ?>
      <a href="index.php?page=intake&link_status=<?php echo $st; ?>" class="filter-btn <?php echo $linkStatus === $st ? 'active' : ''; ?>"><?php echo intakeStatusLabel($st); ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Intake links</div></div>
  <div class="panel-body-flush">
    <?php if (empty($links)): ?>
      <div class="empty-state">
        <i class="bi bi-link-45deg"></i>
        <p>No intake links yet</p>
        <p style="font-size:0.78rem;">Links appear here when a lead is confirmed, or when you send one above.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Sent</th><th>Name</th><th>Email</th>
              <th>Status</th><th>Opened</th><th>Started</th><th>Expires</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($links as $il): ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($il['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($il['name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($il['email']); ?>"><?php echo htmlspecialchars($il['email']); ?></a></td>
                <td><span class="badge <?php echo intakeStatusBadgeClass($il['status']); ?>"><?php echo intakeStatusLabel($il['status']); ?></span></td>
                <td class="td-nowrap td-muted"><?php echo $il['opened_at'] ? date('d M, H:i', strtotime($il['opened_at'])) : '—'; ?></td>
                <td class="td-nowrap td-muted"><?php echo $il['filled_at'] ? date('d M, H:i', strtotime($il['filled_at'])) : '—'; ?></td>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($il['expires_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
