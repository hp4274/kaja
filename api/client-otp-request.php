<?php
/** Public API: request a client portal login code (POST email). Generic reply always. */

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once dirname(__DIR__) . '/includes/client-otp.php';

$email = trim((string) ($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$devCode = null;
try {
    $devCode = requestClientOtp(getDbConnection(), $email, $_SERVER['REMOTE_ADDR'] ?? '');
} catch (Throwable $e) {
    error_log('[client-otp] ' . get_class($e) . ': ' . $e->getMessage());
}
$out = ['success' => true, 'message' => 'If that email is registered, a code has been sent.'];
if (defined('APP_DEBUG') && APP_DEBUG && $devCode !== null) {
    $out['dev_code'] = $devCode; // development only
}
echo json_encode($out);
