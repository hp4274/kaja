<?php
/**
 * Admin API: Send Client Portal Invitation & Access Code.
 *
 * Dispatches an access code & sign-in email to the client, logging
 * an audit entry in the practice activity log.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
require_once __DIR__ . '/../../includes/client-repo.php';
require_once __DIR__ . '/../../includes/client-otp.php';

$db = getDbConnection();
$clientId = (int) ($_POST['client_id'] ?? 0);

if ($clientId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

$client = fetchClient($db, $clientId);
if (!$client) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Client not found or archived']);
    exit;
}

$email = trim((string) $client['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Client does not have a valid email address']);
    exit;
}

$devCode = null;
try {
    $devCode = requestClientOtp($db, $email, $_SERVER['REMOTE_ADDR'] ?? '');
} catch (Throwable $e) {
    error_log('[portal-invite] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to generate access code']);
    exit;
}

$clientName = trim($client['first_name'] . ' ' . $client['last_name']);
$adminUser  = $_SESSION['username'] ?? 'Admin';
$db->prepare("
    INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`)
    VALUES ('portal_invite_sent', :d, 'client', :rid)
")->execute([
    ':d'   => "{$adminUser} dispatched portal invitation and login code to {$clientName} ({$email})",
    ':rid' => $clientId,
]);

$response = [
    'success' => true,
    'message' => "Portal sign-in code sent to {$email}.",
];

if (defined('APP_DEBUG') && APP_DEBUG && $devCode !== null) {
    $response['dev_code'] = $devCode;
    $response['message'] .= " (Dev code: {$devCode})";
}

echo json_encode($response);
