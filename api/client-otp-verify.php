<?php
/** Public API: exchange email + 6-digit code for a portal session (POST). */

session_start();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once dirname(__DIR__) . '/includes/client-otp.php';

try {
    $client = verifyClientOtp(getDbConnection(), $_POST['email'] ?? '', $_POST['code'] ?? '');
} catch (Throwable $e) {
    error_log('[client-otp] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
    exit;
}

if (!$client) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'That code is invalid or has expired.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['client_id']        = (int) $client['id'];
$_SESSION['client_last_seen'] = time();
$_SESSION['client_csrf']      = bin2hex(random_bytes(32));
echo json_encode(['success' => true, 'redirect' => 'portal/index.php']);
