<?php
require_once __DIR__ . '/session-lib.php';
$client = requireClient(true);
portalRequireCsrf();
$db = getDbConnection();
$s = portalOwnSession($db, $client, $_POST['session_id'] ?? 0);
if (!$s) {
    portalJson(false, ['error' => 'Session not found.']);
}
if ($err = portalChangeBlock($s, ['pending', 'confirmed', 'rejected'])) {
    portalJson(false, ['error' => $err]);
}
try {
    cancelSession($db, (int) $s['id'], 'Cancelled by client from the portal.');
} catch (Throwable $e) {
    portalJson(false, ['error' => portalSessionErr($e, 'Could not cancel. Please try again.')]);
}
portalActivity($db, 'session_client_cancelled', trim($client['first_name'] . ' ' . $client['last_name']) . ' cancelled the session on ' . portalFmt($s['start_time']), $client['id']);
sendSessionMail($db, (int) $s['id'], 'cancellation');
portalNotifyAdmin($db, $client, 'Session cancelled by client', 'Cancelled the session on ' . portalFmt($s['start_time']) . '.');
portalJson(true, ['message' => 'Session cancelled.']);
