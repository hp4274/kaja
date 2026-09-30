<?php
/**
 * Admin API: Launch / Impersonate Client Portal.
 *
 * Allows an authorized therapist / admin to view the client portal exactly
 * as a given client sees it, without needing the client's OTP code.
 * Safe and audited: logs an activity entry and sets admin_impersonating flag.
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

$db = getDbConnection();
$clientId = (int) ($_POST['client_id'] ?? $_GET['client_id'] ?? 0);

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

// Establish authenticated client portal session alongside admin session
$_SESSION['client_id']        = (int) $client['id'];
$_SESSION['client_last_seen'] = time();
$_SESSION['client_csrf']      = bin2hex(random_bytes(32));
$_SESSION['client_sv']        = (int) ($client['session_version'] ?? 0);
$_SESSION['admin_impersonating'] = [
    'admin_user_id' => $_SESSION['user_id'] ?? null,
    'admin_user'    => $_SESSION['username'] ?? 'Admin',
    'client_id'     => (int) $client['id'],
    'client_name'   => trim($client['first_name'] . ' ' . $client['last_name']),
    'started_at'    => time(),
];

// Audit trail
$clientName = trim($client['first_name'] . ' ' . $client['last_name']);
$adminUser  = $_SESSION['username'] ?? 'Admin';
$db->prepare("
    INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`)
    VALUES ('admin_portal_preview', :d, 'client', :rid)
")->execute([
    ':d'   => "{$adminUser} opened client portal preview for {$clientName}",
    ':rid' => $clientId,
]);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ../../portal/index.php');
    exit;
}

echo json_encode([
    'success'  => true,
    'redirect' => '../portal/index.php',
    'client'   => [
        'id'    => $client['id'],
        'name'  => $clientName,
        'email' => $client['email'],
    ],
]);
