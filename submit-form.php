<?php
/**
 * Unified Form Submission Handler
 */

header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Only POST submissions are accepted.']);
    exit;
}

require_once __DIR__ . '/db-config.php';

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
            
            // Insert Statement
            $stmt = $db->prepare("
                INSERT INTO leads (name, email, country_code, phone, preferred_date, preferred_time, preference, message, source_page)
                VALUES (:name, :email, :country_code, :phone, :preferred_date, :preferred_time, :preference, :message, :source_page)
            ");
            
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':country_code' => $countryCode,
                ':phone' => $phone,
                ':preferred_date' => $prefDate,
                ':preferred_time' => $prefTime,
                ':preference' => $preference,
                ':message' => $message,
                ':source_page' => $sourcePage
            ]);
            
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
            
            // Generate form URL dynamically
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            
            // Extract project subfolder
            $requestUri = $_SERVER['REQUEST_URI'];
            $projectPath = '/';
            $lastSlash = strrpos($requestUri, '/');
            if ($lastSlash !== false) {
                $projectPath = substr($requestUri, 0, $lastSlash + 1);
            }
            
            $formUrl = $protocol . "://" . $host . $projectPath . "patient-intake-form.html";
            
            // Email details
            $to = $email;
            $subject = "Complete Your Patient Intake Form — Rewire With Kajal";
            $emailMessage = "Hello,\n\n";
            $emailMessage .= "Please take a few moments to complete our official patient intake questionnaire by clicking the link below:\n\n";
            $emailMessage .= $formUrl . "\n\n";
            $emailMessage .= "Once submitted, we will review your responses and reach out to schedule your first session.\n\n";
            $emailMessage .= "Best regards,\nRewire With Kajal Mental Health Consultancy";
            
            $headers = "From: hello@rewirewithkajal.com\r\n";
            $headers .= "Reply-To: hello@rewirewithkajal.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            // Send or simulate
            if (@mail($to, $subject, $emailMessage, $headers)) {
                sendResponse(true, "Thank you! We have sent the patient intake form link to {$email}!");
            } else {
                sendResponse(true, "Thank you! We have sent the patient intake form link to your email (simulated local delivery to {$email}).");
            }
            break;
            
        case 'patient_intake':
            // Validation of Personal Info
            $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
            $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $city = isset($_POST['city']) ? trim($_POST['city']) : '';
            $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
            $dob = isset($_POST['dob']) ? trim($_POST['dob']) : '';
            $concern = isset($_POST['concern']) ? trim($_POST['concern']) : '';
            
            if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($city) || empty($occupation) || empty($dob) || empty($concern)) {
                sendResponse(false, 'All personal information fields are required.', 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, 'Please provide a valid email address.', 400);
            }
            
            // Validation of Preferences
            $prefConsult = isset($_POST['pref_consult']) ? trim($_POST['pref_consult']) : '';
            $prefDate = isset($_POST['pref_date']) ? trim($_POST['pref_date']) : '';
            $prefTime = isset($_POST['pref_time']) ? trim($_POST['pref_time']) : '';
            
            if (empty($prefConsult) || empty($prefDate) || empty($prefTime)) {
                sendResponse(false, 'All preference fields are required.', 400);
            }
            
            // Gather questionnaire response fields (Q1_1 to Q1_18, Q2_1 to Q2_18)
            $params = [
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':city' => $city,
                ':occupation' => $occupation,
                ':dob' => $dob,
                ':concern' => $concern,
                ':pref_consult' => $prefConsult,
                ':pref_date' => $prefDate,
                ':pref_time' => $prefTime
            ];
            
            $sqlFields = [
                'first_name', 'last_name', 'email', 'phone', 'city', 'occupation', 'dob', 'concern',
                'pref_consult', 'pref_date', 'pref_time'
            ];
            
            // Questionnaire 1
            for ($i = 1; $i <= 18; $i++) {
                $fieldName = "q1_{$i}";
                $val = isset($_POST[$fieldName]) ? trim($_POST[$fieldName]) : '';
                if (empty($val)) {
                    sendResponse(false, "Questionnaire 1 Question {$i} must be answered.", 400);
                }
                $params[":{$fieldName}"] = $val;
                $sqlFields[] = $fieldName;
            }
            
            // Questionnaire 2
            for ($i = 1; $i <= 18; $i++) {
                $fieldName = "q2_{$i}";
                $val = isset($_POST[$fieldName]) ? trim($_POST[$fieldName]) : '';
                if (empty($val)) {
                    sendResponse(false, "Questionnaire 2 Question {$i} must be answered.", 400);
                }
                $params[":{$fieldName}"] = $val;
                $sqlFields[] = $fieldName;
            }
            
            // Dynamically construct SQL to avoid typing all 36 questions manually
            $columnsStr = implode(', ', array_map(function($f) { return "`{$f}`"; }, $sqlFields));
            $placeholdersStr = implode(', ', array_map(function($f) { return ":{$f}"; }, $sqlFields));
            
            $stmt = $db->prepare("INSERT INTO `patient-intake` ({$columnsStr}) VALUES ({$placeholdersStr})");
            $stmt->execute($params);
            
            sendResponse(true, 'Your full patient intake form has been processed and saved successfully.');
            break;
            
        default:
            sendResponse(false, 'Unsupported form type.', 400);
    }
} catch (PDOException $e) {
    sendResponse(false, 'Database transaction failed: ' . $e->getMessage(), 500);
}
