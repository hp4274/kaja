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
require_once __DIR__ . '/../../includes/client-documents.php';
require_once __DIR__ . '/../../includes/client-merge.php';

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
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
            foreach (['first_name','last_name','email','phone','city','occupation','dob','concern'] as $f) {
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
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            echo json_encode(['success' => true, 'moved' => $moved]);
            break;

        case 'add_payment':
            $clientId  = intval($_POST['client_id'] ?? 0);
            $amount    = trim($_POST['amount'] ?? '');
            $method    = trim($_POST['method'] ?? 'cash');
            $date      = trim($_POST['fee_date'] ?? '');
            $reference = trim($_POST['reference'] ?? '');

            if (!$clientId) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }

            try {
                addClientPayment($db, $clientId, $amount, $method, $date, $reference);
            } catch (InvalidArgumentException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            echo json_encode([
                'success'  => true,
                'payments' => clientPayments($db, $clientId),
                'total'    => clientPaymentTotal($db, $clientId),
            ]);
            break;

        case 'add_fee':
            $clientId = intval($_POST['client_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $feeDate = trim($_POST['fee_date'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = trim($_POST['status'] ?? 'pending');

            if (!$clientId || $amount <= 0 || empty($feeDate) || !in_array($status, ['paid', 'pending', 'waived'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid or missing fields']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO `client_fees` (`client_id`, `amount`, `fee_date`, `description`, `status`) VALUES (:cid, :a, :fd, :d, :s)");
            $stmt->execute([
                ':cid' => $clientId,
                ':a' => $amount,
                ':fd' => $feeDate,
                ':d' => $description,
                ':s' => $status
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

            echo json_encode(['success' => true, 'fee_id' => $feeId]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
