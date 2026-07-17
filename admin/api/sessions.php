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
        case 'add_session':
            $clientId = intval($_POST['client_id'] ?? 0);
            $sessionDate = trim($_POST['session_date'] ?? '');
            $sessionTime = trim($_POST['session_time'] ?? '');
            $duration = intval($_POST['duration'] ?? 60);
            $sessionType = trim($_POST['session_type'] ?? 'online');
            $notes = trim($_POST['notes'] ?? '');

            if (!$clientId || empty($sessionDate) || empty($sessionTime) || !in_array($sessionType, ['online', 'inperson'])) {
                echo json_encode(['success' => false, 'error' => 'Missing or invalid fields']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO `sessions` (`client_id`, `session_date`, `session_time`, `duration_minutes`, `session_type`, `status`, `notes`) VALUES (:cid, :sd, :st, :dur, :stype, 'scheduled', :n)");
            $stmt->execute([
                ':cid' => $clientId,
                ':sd' => $sessionDate,
                ':st' => $sessionTime,
                ':dur' => $duration,
                ':stype' => $sessionType,
                ':n' => $notes
            ]);
            $sessionId = $db->lastInsertId();

            // Get client name for logging
            $cStmt = $db->prepare("SELECT `first_name`, `last_name` FROM `clients` WHERE `id` = :id");
            $cStmt->execute([':id' => $clientId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC);
            $name = $client ? "{$client['first_name']} {$client['last_name']}" : "Client #{$clientId}";

            // Log activity
            $formattedDateTime = date('d M Y \a\t h:i A', strtotime("$sessionDate $sessionTime"));
            $desc = "Scheduled a new {$sessionType} session with {$name} on {$formattedDateTime}";
            $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('session_scheduled', :d, 'session', :rid)")
               ->execute([':d' => $desc, ':rid' => $sessionId]);

            // Auto-invoice/fee record: let's create a pending fee for the scheduled session as well!
            // Let's assume a default assessment/session fee of ₹150 or similar, but since we decided manual entry is preferred, we don't auto-create fees. Let's keep it purely session scheduling.

            echo json_encode(['success' => true, 'session_id' => $sessionId]);
            break;

        case 'update_status':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');

            if (!$sessionId || !in_array($status, ['scheduled', 'completed', 'cancelled', 'no-show'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            $stmt = $db->prepare("UPDATE `sessions` SET `status` = :s WHERE `id` = :id");
            $stmt->execute([':s' => $status, ':id' => $sessionId]);

            // Fetch session client name for log
            $sStmt = $db->prepare("
                SELECT s.session_date, c.first_name, c.last_name, c.id as client_id
                FROM `sessions` s
                JOIN `clients` c ON s.client_id = c.id
                WHERE s.id = :id
            ");
            $sStmt->execute([':id' => $sessionId]);
            $sData = $sStmt->fetch(PDO::FETCH_ASSOC);

            if ($sData) {
                $name = "{$sData['first_name']} {$sData['last_name']}";
                $desc = "Session with {$name} on {$sData['session_date']} status marked as {$status}";
                $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('status_changed', :d, 'session', :rid)")
                   ->execute([':d' => $desc, ':rid' => $sessionId]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'reschedule_session':
            $sessionId = intval($_POST['session_id'] ?? 0);
            $sessionDate = trim($_POST['session_date'] ?? '');
            $sessionTime = trim($_POST['session_time'] ?? '');

            if (!$sessionId || empty($sessionDate) || empty($sessionTime)) {
                echo json_encode(['success' => false, 'error' => 'Missing or invalid fields']);
                exit;
            }

            // Update session date and time
            $stmt = $db->prepare("UPDATE `sessions` SET `session_date` = :sd, `session_time` = :st WHERE `id` = :id");
            $stmt->execute([
                ':sd' => $sessionDate,
                ':st' => $sessionTime,
                ':id' => $sessionId
            ]);

            // Fetch session client name for log
            $sStmt = $db->prepare("
                SELECT s.session_date, c.first_name, c.last_name, c.id as client_id
                FROM `sessions` s
                JOIN `clients` c ON s.client_id = c.id
                WHERE s.id = :id
            ");
            $sStmt->execute([':id' => $sessionId]);
            $sData = $sStmt->fetch(PDO::FETCH_ASSOC);

            if ($sData) {
                $name = "{$sData['first_name']} {$sData['last_name']}";
                $formattedDateTime = date('d M Y \a\t h:i A', strtotime("$sessionDate $sessionTime"));
                $desc = "Rescheduled session with {$name} to {$formattedDateTime}";
                $db->prepare("INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`) VALUES ('session_rescheduled', :d, 'session', :rid)")
                   ->execute([':d' => $desc, ':rid' => $sessionId]);
            }

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
