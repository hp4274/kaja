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

?>

<?php if ($successMsg): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($successMsg); ?>
  </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
  <div class="alert alert-error">
    <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($errorMsg); ?>
  </div>
<?php endif; ?>

<?php
// The token is deliberately absent from this table. It is the credential for
// someone's private form; a glance over a shoulder should not be enough to
// open it. Status, timing and expiry are what the therapist actually needs.
$linkStatus = isset($_GET['link_status']) ? trim($_GET['link_status']) : '';
$links      = intakeLinksList($db, $linkStatus);
?>

<div class="toolbar">
  <div class="toolbar-left">
    <a href="index.php?page=intake&link_status=all" class="filter-btn <?php echo ($linkStatus === 'all' || $linkStatus === '') ? 'active' : ''; ?>">All</a>
    <?php foreach (intakeStatuses() as $st): ?>
      <a href="index.php?page=intake&link_status=<?php echo $st; ?>" class="filter-btn <?php echo $linkStatus === $st ? 'active' : ''; ?>"><?php echo intakeStatusLabel($st); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="toolbar-right">
    <!-- Sending a link by hand used to be reachable only from a row of the
         short-intake table. That table is gone; the action is not, because
         someone who phones the practice still needs a form. -->
    <form method="post" action="index.php?page=intake" class="send-link-form"
          onsubmit="return confirm('Send an intake link to ' + this.email.value + '?');">
      <input type="hidden" name="action" value="send_intake_link" />
      <label class="visually-hidden" for="send-link-email">Email address</label>
      <input class="form-input" type="email" id="send-link-email" name="email"
             placeholder="Send a link to an email address" required />
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-envelope-plus"></i> Send</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Intake links</div></div>
  <div class="panel-body-flush">
    <?php if (empty($links)): ?>
      <div class="empty-state">
        <i class="bi bi-link-45deg"></i>
        <p>No intake links yet</p>
        <p>Links appear here when a lead is accepted, or when you send one from the box above.</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Sent</th><th>Name</th><th>Email</th>
              <th>Status</th><th>Opened</th><th>Submitted</th><th>Expires</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($links as $il): ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($il['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($il['name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($il['email']); ?>"><?php echo htmlspecialchars($il['email']); ?></a></td>
                <td><span class="badge <?php echo intakeStatusBadgeClass($il['status']); ?>"><?php echo intakeStatusLabel($il['status']); ?></span></td>
                <!-- Same format as Submitted beside it: two timestamps in one
                     row written two different ways is a comparison the reader
                     has to do twice. -->
                <td class="td-nowrap td-muted"><?php echo $il['opened_at'] ? date('d M Y, h:i A', strtotime($il['opened_at'])) : '—'; ?></td>
                <!-- When the questionnaire came back, not when they first
                     touched it. "Started" said someone had typed a character;
                     the date worth chasing, or not chasing, is this one. -->
                <td class="td-nowrap td-muted"><?php echo $il['submitted_at'] ? date('d M Y, h:i A', strtotime($il['submitted_at'])) : '—'; ?></td>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($il['expires_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
