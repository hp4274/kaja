<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
$db = getDbConnection();

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

try {
    switch ($action) {
        case 'update_status':
            $id = intval($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['new','accepted','converted','declined'])) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }
            $stmt = $db->prepare("UPDATE `leads` SET `status`=:s WHERE `id`=:id");
            $stmt->execute([':s'=>$status, ':id'=>$id]);

            // Log activity
            $desc = "Lead #{$id} status changed to {$status}";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('status_changed',:d,'lead',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$id]);

            echo json_encode(['success'=>true]);
            break;

        case 'convert':
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (!$id || !$name) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            // Split name
            $parts = explode(' ', $name, 2);
            $firstName = $parts[0];
            $lastName = isset($parts[1]) ? $parts[1] : '';

            // Check if lead has a matching patient intake (by email and phone)
            $piStmt = $db->prepare("SELECT `id` FROM `patient-intake` WHERE `email`=:e AND `phone`=:p ORDER BY `created_at` DESC LIMIT 1");
            $piStmt->execute([':e'=>$email, ':p'=>$phone]);
            $piRow = $piStmt->fetch(PDO::FETCH_ASSOC);
            $patientIntakeId = $piRow ? $piRow['id'] : null;

            // If patient intake exists, grab richer data from it
            $city = null; $occupation = null; $dob = null; $concern = null;
            if ($patientIntakeId) {
                $piData = $db->prepare("SELECT * FROM `patient-intake` WHERE `id`=:id");
                $piData->execute([':id'=>$patientIntakeId]);
                $piData = $piData->fetch(PDO::FETCH_ASSOC);
                if ($piData) {
                    $firstName = $piData['first_name'];
                    $lastName = $piData['last_name'];
                    $phone = $piData['phone'];
                    $city = $piData['city'];
                    $occupation = $piData['occupation'];
                    $dob = $piData['dob'];
                    $concern = $piData['concern'];
                }
            }

            // Create client
            $stmt = $db->prepare("
                INSERT INTO `clients` (`lead_id`,`patient_intake_id`,`first_name`,`last_name`,`email`,`phone`,`city`,`occupation`,`dob`,`concern`,`status`)
                VALUES (:lid,:piid,:fn,:ln,:em,:ph,:ci,:oc,:dob,:co,'active')
            ");
            $stmt->execute([
                ':lid'=>$id, ':piid'=>$patientIntakeId,
                ':fn'=>$firstName, ':ln'=>$lastName,
                ':em'=>$email, ':ph'=>$phone,
                ':ci'=>$city, ':oc'=>$occupation,
                ':dob'=>$dob, ':co'=>$concern
            ]);
            $clientId = $db->lastInsertId();

            // Update lead
            $db->prepare("UPDATE `leads` SET `status`='converted', `client_id`=:cid WHERE `id`=:id")
               ->execute([':cid'=>$clientId, ':id'=>$id]);

            // Log activity
            $desc = "{$firstName} {$lastName} converted from lead to client";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('client_converted',:d,'client',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$clientId]);

            echo json_encode(['success'=>true,'client_id'=>$clientId]);
            break;

        case 'update_intake_status':
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = trim($_POST['status'] ?? '');

            if (empty($email) || !in_array($status, ['new','accepted','converted','declined'])) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            // Find matching lead
            $checkLead = $db->prepare("SELECT * FROM `leads` WHERE `email` = :email AND `phone` = :phone LIMIT 1");
            $checkLead->execute([':email' => $email, ':phone' => $phone]);
            $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);

            if ($existingLead) {
                $leadId = $existingLead['id'];
                $db->prepare("UPDATE `leads` SET `status`=:s WHERE `id`=:id")
                   ->execute([':s'=>$status, ':id'=>$leadId]);
            } else {
                // Fetch name from patient-intake
                $piStmt = $db->prepare("SELECT * FROM `patient-intake` WHERE `email`=:e AND `phone`=:p LIMIT 1");
                $piStmt->execute([':e'=>$email, ':p'=>$phone]);
                $piRow = $piStmt->fetch(PDO::FETCH_ASSOC);
                $name = $piRow ? ($piRow['first_name'] . ' ' . $piRow['last_name']) : 'Valued Client';

                // Create a new lead/contact from this email/phone
                $ins = $db->prepare("
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source_page`, `status`)
                    VALUES (:name, :email, :phone, 'Patient Intake Form', :status)
                ");
                $ins->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':status' => $status
                ]);
                $leadId = $db->lastInsertId();
            }

            // Log activity
            $desc = "Lead status updated to {$status}";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('status_changed',:d,'lead',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$leadId]);

            echo json_encode(['success'=>true]);
            break;

        case 'convert_intake':
            $intakeId = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (!$intakeId || !$name || !$email) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            // Find matching lead
            $checkLead = $db->prepare("SELECT * FROM `leads` WHERE `email` = :email AND `phone` = :phone LIMIT 1");
            $checkLead->execute([':email' => $email, ':phone' => $phone]);
            $existingLead = $checkLead->fetch(PDO::FETCH_ASSOC);

            if ($existingLead) {
                $leadId = $existingLead['id'];
            } else {
                // Create a new lead first
                $ins = $db->prepare("
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source_page`, `status`)
                    VALUES (:name, :email, :phone, 'Patient Intake Form', 'new')
                ");
                $ins->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone
                ]);
                $leadId = $db->lastInsertId();
            }

            // Split name
            $parts = explode(' ', $name, 2);
            $firstName = $parts[0];
            $lastName = isset($parts[1]) ? $parts[1] : '';

            // Use the specific patient intake form to grab richer data
            $piData = $db->prepare("SELECT * FROM `patient-intake` WHERE `id`=:id");
            $piData->execute([':id'=>$intakeId]);
            $piData = $piData->fetch(PDO::FETCH_ASSOC);
            
            $city = null; $occupation = null; $dob = null; $concern = null;
            if ($piData) {
                $firstName = $piData['first_name'];
                $lastName = $piData['last_name'];
                $phone = $piData['phone'];
                $city = $piData['city'];
                $occupation = $piData['occupation'];
                $dob = $piData['dob'];
                $concern = $piData['concern'];
            }

            // Create client
            $stmt = $db->prepare("
                INSERT INTO `clients` (`lead_id`,`patient_intake_id`,`first_name`,`last_name`,`email`,`phone`,`city`,`occupation`,`dob`,`concern`,`status`)
                VALUES (:lid,:piid,:fn,:ln,:em,:ph,:ci,:oc,:dob,:co,'active')
            ");
            $stmt->execute([
                ':lid'=>$leadId, ':piid'=>$intakeId,
                ':fn'=>$firstName, ':ln'=>$lastName,
                ':em'=>$email, ':ph'=>$phone,
                ':ci'=>$city, ':oc'=>$occupation,
                ':dob'=>$dob, ':co'=>$concern
            ]);
            $clientId = $db->lastInsertId();

            // Update lead to converted
            $db->prepare("UPDATE `leads` SET `status`='converted', `client_id`=:cid WHERE `id`=:id")
               ->execute([':cid'=>$clientId, ':id'=>$leadId]);

            // Log activity
            $desc = "{$firstName} {$lastName} converted from lead to client";
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('client_converted',:d,'client',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$clientId]);

            echo json_encode(['success'=>true,'client_id'=>$clientId]);
            break;

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
}
