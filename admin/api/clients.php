<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
$db = getDbConnection();

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
            $noteType = trim($_POST['note_type'] ?? 'general');
            $content = trim($_POST['content'] ?? '');

            if (!$clientId || empty($content) || !in_array($noteType, ['session', 'general', 'clinical'])) {
                echo json_encode(['success' => false, 'error' => 'Missing or invalid fields']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO `client_notes` (`client_id`, `note_type`, `content`) VALUES (:cid, :nt, :c)");
            $stmt->execute([
                ':cid' => $clientId,
                ':nt' => $noteType,
                ':c' => $content
            ]);
            $noteId = $db->lastInsertId();

            // Get client name for logging
            $cStmt = $db->prepare("SELECT `first_name`, `last_name` FROM `clients` WHERE `id` = :id");
            $cStmt->execute([':id' => $clientId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC);
            $name = $client ? "{$client['first_name']} {$client['last_name']}" : "Client #{$clientId}";

            // Log activity
            $desc = "Added a new {$noteType} note for client {$name}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('note_added', :d, 'client', :rid)")
               ->execute([':d' => $desc, ':rid' => $clientId]);

            echo json_encode(['success' => true, 'note_id' => $noteId]);
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
