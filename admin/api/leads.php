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
require_once __DIR__ . '/../../includes/lead-repo.php';
require_once __DIR__ . '/../../includes/lead-notes.php';
require_once __DIR__ . '/../../includes/lead-confirm.php';
require_once __DIR__ . '/../../includes/lead-form-map.php';
require_once __DIR__ . '/../../includes/lead-timeline.php';
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

        case 'detail':
            $id   = intval($_POST['id'] ?? 0);
            $lead = $id ? fetchLead($db, $id) : null;

            if ($lead === null) {
                echo json_encode(['success'=>false,'error'=>'Lead not found']);
                exit;
            }

            // Opening the drawer IS the review, so the stamp lands here rather
            // than behind a "mark as reviewed" button nobody would click.
            markLeadViewed($db, $id);

            $duplicate = null;
            if (!empty($lead['possible_duplicate_of'])) {
                $dup = fetchLead($db, (int) $lead['possible_duplicate_of']);
                if ($dup !== null) {
                    $duplicate = [
                        'id'         => (int) $dup['id'],
                        'name'       => $dup['name'],
                        'email'      => $dup['email'],
                        'created_at' => $dup['created_at'],
                        'is_client'  => (int) $lead['is_existing_client'],
                    ];
                }
            }

            // Only offer moves the server would actually accept, plus the
            // current status so the select has something selected.
            $allowed = [];
            foreach (leadStatuses() as $optS) {
                if ($optS === $lead['status'] || leadCanTransition($lead['status'], $optS)) {
                    $allowed[] = ['value' => $optS, 'label' => leadStatusLabel($optS)];
                }
            }

            echo json_encode([
                'success'          => true,
                'lead'             => $lead,
                'answers'          => leadAnswers($lead),
                'timeline'         => leadTimeline($db, $id),
                'notes'            => leadNotes($db, $id),
                'duplicate'        => $duplicate,
                'allowed_statuses' => $allowed,
            ]);
            break;

        case 'add_note':
            $id      = intval($_POST['id'] ?? 0);
            $content = trim($_POST['content'] ?? '');

            if (!$id || $content === '') {
                echo json_encode(['success'=>false,'error'=>'A note cannot be empty']);
                exit;
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $noteId = addLeadNote($db, $id, $userId, $content);

            echo json_encode(['success'=>true,'note_id'=>$noteId,'notes'=>leadNotes($db, $id)]);
            break;

        case 'bulk_status':
            $ids    = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
            $status = trim($_POST['status'] ?? '');

            if (!isValidLeadStatus($status)) {
                echo json_encode(['success'=>false,'error'=>'Invalid status']);
                exit;
            }

            $result = bulkUpdateLeadStatus($db, $ids, $status);

            $desc = $result['updated'] . ' lead(s) moved to ' . leadStatusLabel($status);
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('status_changed',:d,'lead',NULL)")
               ->execute([':d'=>$desc]);

            echo json_encode([
                'success' => true,
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ]);
            break;

        case 'confirm':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
                exit;
            }

            $lead = fetchLead($db, $id);
            if ($lead === null) {
                echo json_encode(['success'=>false,'error'=>'Lead not found']);
                exit;
            }

            try {
                $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $result = confirmLead($db, $id, $userId);
            } catch (Throwable $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
                exit;
            }

            // After the commit, never inside it. A dead SMTP server must not
            // undo a correct confirm; the URL comes back either way so the
            // therapist can pass it on by hand.
            $mailed = null;
            if (!$result['already'] && $lead['email'] !== '') {
                $mailed = sendIntakeLinkEmail(
                    $lead['email'], $lead['name'], $result['intake_url'], $result['expires_at']
                );
            }

            echo json_encode([
                'success'          => true,
                'client_id'        => $result['client_id'],
                'already'          => $result['already'],
                'intake_link_sent' => $mailed,
                'intake_url'       => $result['intake_url']
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
