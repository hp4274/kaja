<?php
require_once __DIR__ . "/../../includes/settings.php";
require_once __DIR__ . "/../../includes/intake-token.php";
require_once __DIR__ . "/../../includes/intake-repo.php";
require_once __DIR__ . "/../../includes/intake-status.php";

$db = getDbConnection();
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_intake_link') {
    $clientRow = null;
    $pickedId  = (int) ($_POST['client_id'] ?? 0);
    if ($pickedId) {
        $q = $db->prepare("SELECT `id`, `first_name`, `last_name`, `email`, `lead_id` FROM `clients` WHERE `id` = :id");
        $q->execute([':id' => $pickedId]);
        $clientRow = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $email = $clientRow ? trim($clientRow['email']) : '';

    if (!$clientRow || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please pick a client with a valid email address.';
    } else {
        // The link goes to a known client, so the greeting uses their real name.
        // Reuse the lead the client came from; otherwise find/create one by email.
        $clientName = trim($clientRow['first_name'] . ' ' . $clientRow['last_name']);
        $existingLead = false;
        if (!empty($clientRow['lead_id'])) {
            $checkLead = $db->prepare("SELECT `id` FROM `leads` WHERE `id` = :id");
            $checkLead->execute([':id' => (int) $clientRow['lead_id']]);
            $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);
        }
        if (!$existingLead) {
            $checkLead = $db->prepare("SELECT `id` FROM `leads` WHERE `email` = :email ORDER BY `id` ASC LIMIT 1");
            $checkLead->execute([':email' => $email]);
            $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);
        }
        $leadName = $clientName;

        try {
            $db->beginTransaction();

            if ($existingLead) {
                $leadId = (int) $existingLead['id'];
            } else {
                $ins = $db->prepare("
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source`, `status`)
                    VALUES (:name, :email, '', 'Short Intake Contact', 'new')
                ");
                $ins->execute([':name' => $clientName, ':email' => $email]);
                $leadId = (int) $db->lastInsertId();
            }

            $issued = issueIntakeToken($db, $leadId, (int) $clientRow['id']);

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

// Everyone a link is realistically sent to by hand, newest first -- picking a
// name here is what typing their email used to stand in for.
$sendCandidates = $db->query("
    SELECT `id`, `first_name`, `last_name`, `email` FROM `clients`
    WHERE `archived_at` IS NULL AND `email` != ''
    ORDER BY `first_name` ASC, `last_name` ASC
")->fetchAll(PDO::FETCH_ASSOC);
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
          onsubmit="return confirmSendLink(event);">
      <input type="hidden" name="action" value="send_intake_link" />
      <label class="visually-hidden" for="send-link-email">Send to</label>
      <select class="form-select-sm" id="send-link-email" name="client_id" required>
        <option value="" disabled selected hidden>Send a link to...</option>
        <?php foreach ($sendCandidates as $sc): ?>
          <option value="<?php echo (int) $sc['id']; ?>">
            <?php echo htmlspecialchars(trim($sc['first_name'] . ' ' . $sc['last_name']) . ' — ' . $sc['email']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-envelope-plus"></i> Send</button>
    </form>
  </div>
</div>

<script>
async function confirmSendLink(e) {
  e.preventDefault();
  var form = e.target;
  if (await showConfirm('Send an intake link to ' + form.client_id.selectedOptions[0].text.trim() + '?')) { form.submit(); }
  return false;
}
</script>

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
              <th class="th-num">#</th>
              <th>Sent</th><th>Name</th><th>Email</th>
              <th>Status</th><th>Opened</th><th>Submitted</th><th>Expires</th>
              <th class="th-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($links as $il): ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo $il['row_num']; ?></td>
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
                <td class="td-actions">
                  <?php if (!empty($il['client_id'])): ?>
                    <a href="index.php?page=client-profile&id=<?php echo (int) $il['client_id']; ?>" class="btn btn-ghost btn-sm"><i class="bi bi-person-lines-fill"></i> View Profile</a>
                  <?php else: ?>
                    <span class="hint">No client yet</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
