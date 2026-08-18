<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/intake-token.php';
require_once __DIR__ . '/../../includes/lead-status.php';
$db = getDbConnection();

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

try {
    switch ($action) {
        case 'update_status':
            $id     = intval($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');

            if (!$id || !isValidLeadStatus($status)) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            $cur = $db->prepare("SELECT `status` FROM `leads` WHERE `id`=:id");
            $cur->execute([':id'=>$id]);
            $from = $cur->fetchColumn();

            if ($from === false) {
                echo json_encode(['success'=>false,'error'=>'Lead not found']);
                exit;
            }
            // The pipeline is enforced here, not in the browser. A stale page
            // offering a button that is no longer legal must not be able to
            // push the lead into a state the rest of the module cannot explain.
            if (!leadCanTransition($from, $status)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Cannot move a ' . leadStatusLabel($from) . ' lead to ' . leadStatusLabel($status)
                ]);
                exit;
            }

            $stmt = $db->prepare("UPDATE `leads` SET `status`=:s WHERE `id`=:id");
            $stmt->execute([':s'=>$status, ':id'=>$id]);

            $desc = "Lead #{$id} status changed from " . leadStatusLabel($from) . ' to ' . leadStatusLabel($status);
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('status_changed',:d,'lead',:rid)")
               ->execute([':d'=>$desc, ':rid'=>$id]);

            echo json_encode(['success'=>true,'status'=>$status]);
            break;

        case 'convert':
            $id    = intval($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (!$id || !$name) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            // A stale page or a double click must not mint a second client for
            // the same lead. Already converted -> hand back the client we have.
            $leadStmt = $db->prepare("SELECT `status`,`client_id` FROM `leads` WHERE `id`=:id");
            $leadStmt->execute([':id'=>$id]);
            $leadRow = $leadStmt->fetch(PDO::FETCH_ASSOC);

            if (!$leadRow) {
                echo json_encode(['success'=>false,'error'=>'Lead not found']);
                exit;
            }
            if (!empty($leadRow['client_id'])) {
                // Repairs rows the older convert flow left short of 'converted'.
                if ($leadRow['status'] !== 'converted') {
                    $db->prepare("UPDATE `leads` SET `status`='converted' WHERE `id`=:id")
                       ->execute([':id'=>$id]);
                }
                echo json_encode([
                    'success'   => true,
                    'client_id' => (int) $leadRow['client_id'],
                    'already'   => true
                ]);
                exit;
            }

            // Has this person already completed the questionnaire? If so there is
            // nothing to send: promote them straight to an active client using the
            // richer intake data, exactly as before.
            $piStmt = $db->prepare("SELECT * FROM `patient-intake` WHERE `email`=:e AND `phone`=:p ORDER BY `created_at` DESC LIMIT 1");
            $piStmt->execute([':e'=>$email, ':p'=>$phone]);
            $piRow = $piStmt->fetch(PDO::FETCH_ASSOC);

            // Seed first/last from the lead name so the NOT NULL columns hold
            // even when the client row is created before any intake data exists.
            $parts     = explode(' ', $name, 2);
            $firstName = $parts[0];
            $lastName  = isset($parts[1]) ? $parts[1] : '';

            $issued = null;

            // One transaction: client + token + lead status move together.
            // A token issued against a lead that failed to update would leave the
            // dashboard permanently wrong.
            $db->beginTransaction();
            try {
                if ($piRow) {
                    $stmt = $db->prepare("
                        INSERT INTO `clients` (`lead_id`,`patient_intake_id`,`first_name`,`last_name`,`email`,`phone`,`city`,`occupation`,`dob`,`concern`,`status`)
                        VALUES (:lid,:piid,:fn,:ln,:em,:ph,:ci,:oc,:dob,:co,'active')
                    ");
                    $stmt->execute([
                        ':lid'=>$id, ':piid'=>$piRow['id'],
                        ':fn'=>$piRow['first_name'], ':ln'=>$piRow['last_name'],
                        ':em'=>$piRow['email'],      ':ph'=>$piRow['phone'],
                        ':ci'=>$piRow['city'],       ':oc'=>$piRow['occupation'],
                        ':dob'=>$piRow['dob'],       ':co'=>$piRow['concern']
                    ]);
                    $clientId  = (int) $db->lastInsertId();
                    $firstName = $piRow['first_name'];
                    $lastName  = $piRow['last_name'];

                    $db->prepare("UPDATE `leads` SET `status`='converted', `client_id`=:cid WHERE `id`=:id")
                       ->execute([':cid'=>$clientId, ':id'=>$id]);

                    $logAction = 'client_converted';
                    $logDesc   = "{$firstName} {$lastName} converted from lead to client";
                } else {
                    // No questionnaire yet. Create the client as 'pending' and
                    // issue the intake link; submit_intake.php fills the rest in
                    // and flips the client to 'active' when it comes back.
                    $stmt = $db->prepare("
                        INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`phone`,`status`)
                        VALUES (:lid,:fn,:ln,:em,:ph,'pending')
                    ");
                    $stmt->execute([
                        ':lid'=>$id, ':fn'=>$firstName, ':ln'=>$lastName,
                        ':em'=>$email, ':ph'=>$phone
                    ]);
                    $clientId = (int) $db->lastInsertId();

                    $issued = issueIntakeToken($db, $id, $clientId);

                    // The lead is done the moment a client exists for it — the
                    // outstanding questionnaire is tracked by the client being
                    // 'pending', not by holding the lead back.
                    $db->prepare("UPDATE `leads` SET `status`='converted', `client_id`=:cid WHERE `id`=:id")
                       ->execute([':cid'=>$clientId, ':id'=>$id]);

                    $logAction = 'intake_link_sent';
                    $logDesc   = "Intake link issued to {$name} (expires " . date('d M Y', strtotime($issued['expires_at'])) . ")";
                }

                $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES (:a,:d,'client',:rid)")
                   ->execute([':a'=>$logAction, ':d'=>$logDesc, ':rid'=>$clientId]);

                $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            // Mail is sent AFTER the commit. An SMTP hiccup must not roll back
            // correct database state; the URL is returned either way so the
            // therapist can pass it on by hand.
            $mailed = null;
            if ($issued && $email !== '') {
                $mailed = sendIntakeLinkEmail($email, $name, $issued['url'], $issued['expires_at']);
            }

            echo json_encode([
                'success'          => true,
                'client_id'        => $clientId,
                'intake_link_sent' => $mailed,
                'intake_url'       => $issued ? $issued['url'] : null
            ]);
            break;

        case 'update_intake_status':
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = trim($_POST['status'] ?? '');

            if (empty($email) || !isValidLeadStatus($status)) {
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
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source`, `status`)
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
                    INSERT INTO `leads` (`name`, `email`, `phone`, `source`, `status`)
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
