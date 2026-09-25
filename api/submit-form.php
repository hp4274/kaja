<?php
/**
 * Public API: Unified Form Submission Handler
 * Handles lead submissions (booking / appointment) and short intake inquiries.
 */

header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Only POST submissions are accepted.']);
    exit;
}

require_once dirname(__DIR__) . '/db-config.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/lead-queue.php';
require_once dirname(__DIR__) . '/includes/intake-token.php';

/**
 * Look for this person in BOTH leads and clients before inserting.
 *
 * Deliberately non-blocking: a returning visitor with a second enquiry is a
 * normal thing, not an error. We record what we found so the admin drawer can
 * show a banner, and let the submission through either way.
 *
 * Phone is only compared when non-empty, otherwise every blank-phone lead
 * would match every other blank-phone lead.
 */
function findPossibleDuplicate(PDO $db, $email, $phone) {
    $result = ['lead_id' => null, 'is_client' => 0];
    $matchPhone = ($phone !== '' && $phone !== null);

    $sql = "SELECT `id` FROM `leads` WHERE `email` = :email";
    $args = [':email' => $email];
    if ($matchPhone) {
        $sql .= " OR `phone` = :phone";
        $args[':phone'] = $phone;
    }
    $sql .= " ORDER BY `id` ASC LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $leadId = $stmt->fetchColumn();
    if ($leadId !== false) {
        $result['lead_id'] = (int) $leadId;
    }

    $sql = "SELECT `id` FROM `clients` WHERE `email` = :email";
    $args = [':email' => $email];
    if ($matchPhone) {
        $sql .= " OR `phone` = :phone";
        $args[':phone'] = $phone;
    }
    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    if ($stmt->fetchColumn() !== false) {
        $result['is_client'] = 1;
    }

    return $result;
}

// Helper function to send response and exit
function sendResponse($success, $messageOrError, $statusCode = 200) {
    http_response_code($statusCode);
    if ($success) {
        echo json_encode(['success' => true, 'message' => $messageOrError]);
    } else {
        echo json_encode(['success' => false, 'error' => $messageOrError]);
    }
    exit;
}

// Check form type
$formType = isset($_POST['form_type']) ? trim($_POST['form_type']) : '';

if (empty($formType)) {
    sendResponse(false, 'Missing form type specification.', 400);
}

$db = getDbConnection();

try {
    switch ($formType) {
        case 'booking':
        case 'appointment':
            // Validation
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $prefDate = isset($_POST['date']) ? trim($_POST['date']) : '';
            $prefTime = isset($_POST['time']) ? trim($_POST['time']) : '';
            $preference = isset($_POST['preference']) ? trim($_POST['preference']) : '';
            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            
            if (empty($name) || empty($email) || empty($phone) || empty($prefDate) || empty($prefTime) || empty($preference) || empty($message)) {
                sendResponse(false, 'All fields are required.', 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, 'Please provide a valid email address.', 400);
            }
            
            $countryCode = isset($_POST['country_code']) ? trim($_POST['country_code']) : '+91';
            $sourcePage = ($formType === 'booking') ? 'home' : 'appointment';

            // leads.preferred_time is a TIME column but the form posts a display
            // value such as "02:00 PM". Without this, MySQL parses it as 02:00:00
            // and every afternoon slot is silently stored as a morning one.
            $prefTimeTs = strtotime($prefTime);
            if ($prefTimeTs === false) {
                sendResponse(false, 'Please select a valid preferred time.', 400);
            }
            $prefTime = date('H:i:s', $prefTimeTs);

            // preferred_date is a DATE column; reject anything not YYYY-MM-DD.
            $dateParts = explode('-', $prefDate);
            if (count($dateParts) !== 3 || !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
                sendResponse(false, 'Please select a valid preferred date.', 400);
            }
            
            // Flag-only duplicate scan across leads AND clients. Never blocks.
            $duplicate = findPossibleDuplicate($db, $email, $phone);

            $leadParams = [
                ':name' => $name,
                ':email' => $email,
                ':country_code' => $countryCode,
                ':phone' => $phone,
                ':preferred_date' => $prefDate,
                ':preferred_time' => $prefTime,
                ':preference' => $preference,
                ':message' => $message,
                ':source' => $sourcePage,
                // Pinned at submit time. The admin drawer renders these answers
                // against this version's field map, never against the live form.
                ':form_version_id' => getSettingInt('intake_form_version', 1),
                ':possible_duplicate_of' => $duplicate['lead_id'],
                ':is_existing_client' => $duplicate['is_client']
            ];

            try {
                $stmt = $db->prepare("
                    INSERT INTO leads (name, email, country_code, phone, preferred_date, preferred_time, preference, message, source, form_version_id, possible_duplicate_of, is_existing_client)
                    VALUES (:name, :email, :country_code, :phone, :preferred_date, :preferred_time, :preference, :message, :source, :form_version_id, :possible_duplicate_of, :is_existing_client)
                ");
                $stmt->execute($leadParams);
            } catch (PDOException $e) {
                // A lead is the one thing we cannot ask the visitor to retype.
                // On a DB blip, spool it to disk and still acknowledge, rather
                // than returning a 500 and losing the enquiry for good.
                error_log('[submit-form] lead insert failed: ' . $e->getMessage());

                if (queueFailedLead($leadParams, $e->getMessage())) {
                    sendResponse(true, 'Your appointment request has been received. We will be in touch shortly.');
                }
                sendResponse(false, 'We could not record your request just now. Please try again, or email us directly.', 500);
            }

            sendResponse(true, 'Your appointment request has been submitted successfully.');
            break;
            
        case 'short_intake':
            // Validation
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            
            if (empty($email) || empty($message)) {
                sendResponse(false, 'Email and message are required fields.', 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, 'Please provide a valid email address.', 400);
            }
            
            // Insert Statement
            $stmt = $db->prepare("
                INSERT INTO intake (email, message)
                VALUES (:email, :message)
            ");
            
            $stmt->execute([
                ':email' => $email,
                ':message' => $message
            ]);
            
            // The emailed link is now a personal token, not a public page.
            // We know only an email address here, so the token carries no
            // client_id -- the client row is created when the form comes back.
            $leadStmt = $db->prepare("SELECT `id`, `name` FROM `leads` WHERE `email` = :email ORDER BY `id` ASC LIMIT 1");
            $leadStmt->execute([':email' => $email]);
            $existingLead = $leadStmt->fetch(PDO::FETCH_ASSOC);

            $db->beginTransaction();
            try {
                if ($existingLead) {
                    $leadId = (int) $existingLead['id'];
                    $leadName = $existingLead['name'];
                } else {
                    // Every submission gets checked, this path included — a
                    // returning visitor who uses the short form is just as
                    // likely to already be on file as one who books.
                    $dup = findPossibleDuplicate($db, $email, '');
                    $db->prepare("
                        INSERT INTO `leads` (`name`, `email`, `phone`, `message`, `source`, `status`, `form_version_id`, `possible_duplicate_of`, `is_existing_client`)
                        VALUES ('Valued Client', :email, '', :message, 'short_intake', 'new', :form_version_id, :dup, :is_client)
                    ")->execute([
                        ':email'           => $email,
                        ':message'         => $message,
                        ':form_version_id' => getSettingInt('intake_form_version', 1),
                        ':dup'             => $dup['lead_id'],
                        ':is_client'       => $dup['is_client'],
                    ]);
                    $leadId = (int) $db->lastInsertId();
                    $leadName = '';
                }

                $issued = issueIntakeToken($db, $leadId, null);
                $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            // Mail goes out only after the commit: an SMTP hiccup must not roll
            // back a correct database state.
            $delivered = sendIntakeLinkEmail($email, $leadName, $issued['url'], $issued['expires_at']);

            if ($delivered) {
                sendResponse(true, "Thank you! We have sent your personal intake form link to {$email}.");
            }
            sendResponse(true, "Thank you! We have sent your personal intake form link to your email (simulated local delivery to {$email}).");
            break;
            
        case 'patient_intake':
            // The intake questionnaire is token-gated. Rendering happens in
            // intake.php and writing in submit_intake.php, which re-checks the
            // token under a row lock, pins the form version, and commits the
            // client + link + lead writes atomically.
            //
            // This branch stays only so the old public path gets a clear answer.
            // Without it, POSTing form_type=patient_intake straight to this file
            // would walk right around the gate.
            sendResponse(false, "This form must be opened from your personal intake link. Please use the link we emailed you, or contact us and we will send a new one.", 403);
            break;
            
        default:
            sendResponse(false, 'Unsupported form type.', 400);
    }
} catch (PDOException $e) {
    sendResponse(false, 'Database transaction failed: ' . $e->getMessage(), 500);
}
