<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-status.php';
require_once __DIR__ . '/../../includes/client-notes.php';
require_once __DIR__ . '/../../includes/client-payments.php';
require_once __DIR__ . '/../../includes/fee-mail.php';
require_once __DIR__ . '/../../includes/client-documents.php';
require_once __DIR__ . '/../../includes/client-merge.php';
require_once __DIR__ . '/../../includes/intake-token.php';
require_once __DIR__ . '/../../includes/mail-queue.php';
require_once __DIR__ . '/../../includes/form-builder.php';

$db     = getDbConnection();
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

/** Everything below logs against a name, not a bare id. */
function clientLabel(PDO $db, $clientId) {
    $stmt = $db->prepare('SELECT `first_name`,`last_name` FROM `clients` WHERE `id` = :id');
    $stmt->execute([':id' => (int) $clientId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    return $c ? trim($c['first_name'] . ' ' . $c['last_name']) : 'Client #' . (int) $clientId;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

try {
    switch ($action) {
        case 'mark_reviewed':
            $clientId = intval($_POST['client_id'] ?? 0);
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            // Only a client actually awaiting review moves. The precondition is
            // in the WHERE clause so a second click on a stale page cannot log
            // a review that never happened.
            $stmt = $db->prepare("UPDATE `clients` SET `status`='active' WHERE `id`=:id AND `status`='review'");
            $stmt->execute([':id' => $clientId]);

            if ($stmt->rowCount() > 0) {
                $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('intake_reviewed',:d,'client',:rid)")
                   ->execute([':d' => "Intake reviewed; client #{$clientId} is now active", ':rid' => $clientId]);
            }

            echo json_encode(['success' => true, 'moved' => $stmt->rowCount() > 0]);
            break;

        case 'update_status':
            $clientId = intval($_POST['client_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$clientId || !in_array($status, ['review', 'active', 'inactive', 'completed'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            $stmt = $db->prepare("UPDATE `clients` SET `status` = :s WHERE `id` = :id");
            $stmt->execute([':s' => $status, ':id' => $clientId]);

            // Get client name for logging
            $cStmt = $db->prepare("SELECT `first_name`, `last_name` FROM `clients` WHERE `id` = :id");
            $cStmt->execute([':id' => $clientId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC);
            $name = $client ? "{$client['first_name']} {$client['last_name']}" : "Client #{$clientId}";

            // Log activity
            $desc = "Status of client {$name} updated to {$status}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('status_changed', :d, 'client', :rid)")
               ->execute([':d' => $desc, ':rid' => $clientId]);

            echo json_encode(['success' => true]);
            break;

        case 'add_note':
            $clientId = intval($_POST['client_id'] ?? 0);
            $content  = trim($_POST['content'] ?? '');
            $kind     = trim($_POST['note_kind'] ?? 'session');
            $corrects = isset($_POST['corrects_note_id']) && $_POST['corrects_note_id'] !== ''
                      ? (int) $_POST['corrects_note_id'] : null;

            if (!$clientId || $content === '') {
                echo json_encode(['success' => false, 'error' => 'A note cannot be empty']);
                exit;
            }

            try {
                $noteId = addClientNote($db, $clientId, $userId, $content, $kind, $corrects);
            } catch (InvalidArgumentException $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            $what = $corrects === null ? 'note' : 'correction';
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('note_added',:d,'client',:rid)")
               ->execute([':d' => 'Added a ' . $what . ' for ' . clientLabel($db, $clientId), ':rid' => $clientId]);

            echo json_encode([
                'success' => true,
                'note_id' => $noteId,
                'notes'   => clientNotes($db, $clientId),
                'corrected' => correctedNoteIds($db, $clientId),
            ]);
            break;

        case 'update_profile':
            $clientId = intval($_POST['client_id'] ?? 0);
            if (!$clientId || fetchClient($db, $clientId) === null) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }

            $fields = [];
            foreach (['first_name','last_name','email','phone','city','occupation','dob','concern','pref_mode','pref_times'] as $f) {
                if (array_key_exists($f, $_POST)) {
                    $fields[$f] = trim($_POST[$f]);
                }
            }
            if (isset($fields['email']) && $fields['email'] !== ''
                && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Please provide a valid email address']);
                exit;
            }

            // updateClientProfile whitelists columns, so status and archived_at
            // cannot be moved through this form even if they are posted.
            updateClientProfile($db, $clientId, $fields);

            echo json_encode(['success' => true]);
            break;

        case 'archive':
            $clientId = intval($_POST['client_id'] ?? 0);
            if (!$clientId || fetchClient($db, $clientId) === null) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }

            $name = clientLabel($db, $clientId);
            archiveClient($db, $clientId);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('client_archived',:d,'client',:rid)")
               ->execute([':d' => $name . ' was archived', ':rid' => $clientId]);

            echo json_encode(['success' => true]);
            break;

        case 'merge':
            $survivorId = intval($_POST['survivor_id'] ?? 0);
            $loserId    = intval($_POST['loser_id'] ?? 0);

            try {
                $moved = mergeClients($db, $survivorId, $loserId, $userId);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => publicError($e)]);
                exit;
            }

            echo json_encode(['success' => true, 'moved' => $moved]);
            break;

        case 'send_fee_reminder':
            // Sent by hand, one client at a time. Chasing money on a schedule
            // is a decision about a relationship, not a cron job.
            $clientId = intval($_POST['client_id'] ?? 0);
            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }

            $result = sendFeeReminder($db, $clientId);
            if (!$result['sent']) {
                echo json_encode(['success' => false, 'error' => $result['error']]);
                exit;
            }

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('fee_reminder_sent',:d,'client',:rid)")
               ->execute([':d' => 'Payment reminder sent for INR ' . $result['amount'], ':rid' => $clientId]);

            echo json_encode(['success' => true, 'amount' => $result['amount']]);
            break;

        case 'add_fee':
            $clientId = intval($_POST['client_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $feeDate = trim($_POST['fee_date'] ?? '');
            // Column is VARCHAR(255); unlike `reference` below this one was
            // never capped, so a longer note threw a PDOException and the
            // whole request came back as a masked 500.
            $description = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            $status = trim($_POST['status'] ?? 'pending');
            // The Payments tab was the only way to record these; it was the
            // same table shown twice, so they belong on the fee itself.
            $method = trim($_POST['method'] ?? 'cash');
            $reference = trim($_POST['reference'] ?? '');

            if (!$clientId || $amount <= 0 || empty($feeDate) || !in_array($status, ['paid', 'pending', 'waived'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid or missing fields']);
                exit;
            }
            if (!in_array($method, clientPaymentMethods(), true)) {
                // MySQL would coerce an unknown enum value to '' and the
                // ledger would go on to claim it was cash.
                echo json_encode(['success' => false, 'error' => 'Unknown payment method']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO `client_fees` (`client_id`, `amount`, `fee_date`, `description`, `status`, `method`, `reference`) VALUES (:cid, :a, :fd, :d, :s, :m, :r)");
            $stmt->execute([
                ':cid' => $clientId,
                ':a' => $amount,
                ':fd' => $feeDate,
                ':d' => $description,
                ':s' => $status,
                ':m' => $method,
                ':r' => mb_substr($reference, 0, 255)
            ]);
            $feeId = $db->lastInsertId();

            // Get client name for logging
            $cStmt = $db->prepare("SELECT `first_name`, `last_name` FROM `clients` WHERE `id` = :id");
            $cStmt->execute([':id' => $clientId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC);
            $name = $client ? "{$client['first_name']} {$client['last_name']}" : "Client #{$clientId}";

            // Log activity
            $desc = "Recorded a fee of ₹{$amount} ({$status}) for client {$name}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_added', :d, 'client', :rid)")
               ->execute([':d' => $desc, ':rid' => $clientId]);

            // Payment receipt, after the write. Failure is queued, never shown.
            if ($status === 'paid') {
                sendFeeReceivedEmail($db, $clientId, $amount, $feeDate);
            }

            echo json_encode(['success' => true, 'fee_id' => $feeId]);
            break;

        case 'update_fee_status':
            $feeId  = intval($_POST['fee_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$feeId || !in_array($status, ['paid', 'pending', 'waived'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid fee ID or status']);
                exit;
            }

            $fStmt = $db->prepare("SELECT * FROM `client_fees` WHERE `id` = :id");
            $fStmt->execute([':id' => $feeId]);
            $fee = $fStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee) {
                echo json_encode(['success' => false, 'error' => 'Fee record not found']);
                exit;
            }
            $clientId = (int) $fee['client_id'];
            $name = clientLabel($db, $clientId);

            if ($status === 'paid') {
                $method    = trim($_POST['method'] ?? 'cash');
                $reference = trim($_POST['reference'] ?? '');
                $paidDate  = trim($_POST['paid_date'] ?? date('Y-m-d'));
                if (!in_array($method, clientPaymentMethods(), true)) {
                    $method = 'cash';
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) {
                    $paidDate = date('Y-m-d');
                }

                $stmt = $db->prepare("UPDATE `client_fees` SET `status` = 'paid', `method` = :m, `reference` = :r, `fee_date` = :fd WHERE `id` = :id");
                $stmt->execute([
                    ':m'  => $method,
                    ':r'  => mb_substr($reference, 0, 255),
                    ':fd' => $paidDate,
                    ':id' => $feeId,
                ]);

                $desc = "Marked fee #{$feeId} (₹{$fee['amount']}) as paid via " . clientPaymentMethodLabel($method) . " for {$name}";
                $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_paid', :d, 'client', :rid)")
                   ->execute([':d' => $desc, ':rid' => $clientId]);

                if (!empty($_POST['send_receipt'])) {
                    sendFeeReceivedEmail($db, $clientId, $fee['amount'], $paidDate);
                }
            } elseif ($status === 'waived') {
                $stmt = $db->prepare("UPDATE `client_fees` SET `status` = 'waived' WHERE `id` = :id");
                $stmt->execute([':id' => $feeId]);

                $desc = "Waived fee #{$feeId} (₹{$fee['amount']}) for {$name}";
                $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_waived', :d, 'client', :rid)")
                   ->execute([':d' => $desc, ':rid' => $clientId]);
            } else { // pending
                $stmt = $db->prepare("UPDATE `client_fees` SET `status` = 'pending' WHERE `id` = :id");
                $stmt->execute([':id' => $feeId]);

                $desc = "Reverted fee #{$feeId} (₹{$fee['amount']}) to pending for {$name}";
                $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_reverted', :d, 'client', :rid)")
                   ->execute([':d' => $desc, ':rid' => $clientId]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'delete_fee':
            $feeId = intval($_POST['fee_id'] ?? 0);
            if (!$feeId) {
                echo json_encode(['success' => false, 'error' => 'Invalid fee ID']);
                exit;
            }
            $fStmt = $db->prepare("SELECT * FROM `client_fees` WHERE `id` = :id");
            $fStmt->execute([':id' => $feeId]);
            $fee = $fStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee) {
                echo json_encode(['success' => false, 'error' => 'Fee record not found']);
                exit;
            }
            $clientId = (int) $fee['client_id'];
            $name = clientLabel($db, $clientId);

            $db->prepare("DELETE FROM `client_fees` WHERE `id` = :id")->execute([':id' => $feeId]);
            $desc = "Deleted fee record #{$feeId} (₹{$fee['amount']}) for {$name}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_deleted', :d, 'client', :rid)")
               ->execute([':d' => $desc, ':rid' => $clientId]);

            echo json_encode(['success' => true]);
            break;

        case 'verify_payment_report':
            $reportId = intval($_POST['report_id'] ?? 0);
            if (!$reportId) {
                echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
                exit;
            }
            $rStmt = $db->prepare("SELECT * FROM `client_payment_reports` WHERE `id` = :id");
            $rStmt->execute([':id' => $reportId]);
            $rep = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rep) {
                echo json_encode(['success' => false, 'error' => 'Payment report not found']);
                exit;
            }
            $clientId = (int) $rep['client_id'];
            $name = clientLabel($db, $clientId);

            // Mark report as verified
            $db->prepare("UPDATE `client_payment_reports` SET `status` = 'verified' WHERE `id` = :id")->execute([':id' => $reportId]);

            // Try to match an open pending fee for this client with the same amount
            $mFee = $db->prepare("SELECT `id` FROM `client_fees` WHERE `client_id` = :cid AND `status` = 'pending' AND `amount` = :a ORDER BY `fee_date` ASC LIMIT 1");
            $mFee->execute([':cid' => $clientId, ':a' => $rep['amount']]);
            $matchedFeeId = $mFee->fetchColumn();

            if ($matchedFeeId) {
                $db->prepare("UPDATE `client_fees` SET `status` = 'paid', `method` = :m, `reference` = :r, `fee_date` = :fd WHERE `id` = :id")
                   ->execute([
                       ':m'  => in_array($rep['method'], clientPaymentMethods(), true) ? $rep['method'] : 'other',
                       ':r'  => mb_substr((string)$rep['reference'], 0, 255),
                       ':fd' => $rep['paid_on'] ?: date('Y-m-d'),
                       ':id' => $matchedFeeId,
                   ]);
                $finalFeeId = $matchedFeeId;
            } else {
                // If no exact match, create a paid fee record
                $db->prepare("INSERT INTO `client_fees` (`client_id`, `amount`, `fee_date`, `description`, `status`, `method`, `reference`) VALUES (:cid, :a, :fd, :d, 'paid', :m, :r)")
                   ->execute([
                       ':cid' => $clientId,
                       ':a'   => $rep['amount'],
                       ':fd'  => $rep['paid_on'] ?: date('Y-m-d'),
                       ':d'   => 'Verified payment report #' . $reportId,
                       ':m'   => in_array($rep['method'], clientPaymentMethods(), true) ? $rep['method'] : 'other',
                       ':r'   => mb_substr((string)$rep['reference'], 0, 255),
                   ]);
                $finalFeeId = $db->lastInsertId();
            }

            // Send receipt
            sendFeeReceivedEmail($db, $clientId, $rep['amount'], $rep['paid_on'] ?: date('Y-m-d'));

            $desc = "Verified payment report #{$reportId} (₹{$rep['amount']}) for {$name}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('fee_verified', :d, 'client', :rid)")
               ->execute([':d' => $desc, ':rid' => $clientId]);

            echo json_encode(['success' => true, 'fee_id' => $finalFeeId]);
            break;

        case 'reject_payment_report':
            $reportId = intval($_POST['report_id'] ?? 0);
            if (!$reportId) {
                echo json_encode(['success' => false, 'error' => 'Invalid report ID']);
                exit;
            }
            $db->prepare("UPDATE `client_payment_reports` SET `status` = 'rejected' WHERE `id` = :id")->execute([':id' => $reportId]);
            echo json_encode(['success' => true]);
            break;

        case 'resend_intake':
            $clientId = intval($_POST['client_id'] ?? 0);
            $client   = $clientId ? fetchClient($db, $clientId) : null;
            if (!$client) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }
            if (empty($client['lead_id'])) {
                echo json_encode(['success' => false, 'error' => 'This client has no originating lead to send a link against']);
                exit;
            }

            $requestedVersion = isset($_POST['form_version']) ? trim($_POST['form_version']) : '';
            $formVersion = null;
            if ($requestedVersion !== '') {
                $candidate = (int) $requestedVersion;
                $known = array_unique(array_merge(intakeSchemaVersions(), formVersions($db)));
                if (in_array($candidate, $known, true)) {
                    $formVersion = $candidate;
                }
            }

            $issued = issueIntakeToken($db, (int) $client['lead_id'], $clientId, $formVersion);
            $name   = trim($client['first_name'] . ' ' . $client['last_name']);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('intake_link_sent',:d,'client',:rid)")
               ->execute([':d' => "Intake link resent to {$name}", ':rid' => $clientId]);

            if (!sendIntakeLinkEmail($client['email'], $name, $issued['url'], $issued['expires_at'])) {
                queueFailedMail([
                    'to'      => $client['email'],
                    'name'    => $name,
                    'url'     => $issued['url'],
                    'expires' => $issued['expires_at'],
                    'kind'    => 'intake_link',
                ], 'SMTP send failed');
            }

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $clientId = intval($_POST['client_id'] ?? 0);
            $client   = $clientId ? fetchClient($db, $clientId) : null;
            if (!$client) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }
            $name = clientLabel($db, $clientId);

            // -- 1. Delete document files from disk first, then DB rows --
            $docs = $db->prepare("SELECT `stored_name` FROM `client_documents` WHERE `client_id` = :id");
            $docs->execute([':id' => $clientId]);
            $storageDir = documentStorageDir();
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $fname) {
                $path = $storageDir . DIRECTORY_SEPARATOR . $fname;
                if (is_file($path)) { @unlink($path); }
            }
            $db->prepare("DELETE FROM `client_documents` WHERE `client_id` = :id")->execute([':id' => $clientId]);

            // -- 2. Sessions, notes, fees --
            $db->prepare("DELETE FROM `sessions`     WHERE `client_id` = :id")->execute([':id' => $clientId]);
            $db->prepare("DELETE FROM `client_notes`  WHERE `client_id` = :id")->execute([':id' => $clientId]);
            $db->prepare("DELETE FROM `client_fees`   WHERE `client_id` = :id")->execute([':id' => $clientId]);

            // -- 3. Intake links & patient-intake --
            $db->prepare("DELETE FROM `intake_links`   WHERE `client_id` = :id")->execute([':id' => $clientId]);
            $db->prepare("DELETE FROM `patient-intake` WHERE `client_id` = :id")->execute([':id' => $clientId]);

            // -- 4. Leads & their notes --
            $leadIds = $db->prepare("SELECT `id` FROM `leads` WHERE `client_id` = :id");
            $leadIds->execute([':id' => $clientId]);
            $lids = $leadIds->fetchAll(PDO::FETCH_COLUMN);
            if ($lids) {
                $ph = implode(',', array_fill(0, count($lids), '?'));
                $db->prepare("DELETE FROM `lead_notes` WHERE `lead_id` IN ($ph)")->execute($lids);
                $db->prepare("DELETE FROM `leads`      WHERE `id` IN ($ph)")->execute($lids);
            }

            // -- 5. Payment reports (table may not exist on older schemas) --
            try {
                $db->prepare("DELETE FROM `client_payment_reports` WHERE `client_id` = :id")->execute([':id' => $clientId]);
            } catch (PDOException $ignore) {}

            // -- 6. Delete the client record itself --
            $db->prepare("DELETE FROM `clients` WHERE `id` = :id")->execute([':id' => $clientId]);

            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('client_deleted',:d,'client',NULL)")
               ->execute([':d' => $name . ' deleted, along with all related data (leads, intake, sessions, notes, fees, documents)']);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => publicError($e)]);
}
