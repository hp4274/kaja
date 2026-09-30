<?php
require_once __DIR__ . '/session-lib.php';
$client = requireClient(true);
portalRequireCsrf();
$db = getDbConnection();
if (!clientIsBookable($client['status'])) {
    portalJson(false, ['error' => 'Booking is not available on your account yet. Please contact the practice.']);
}
$date = (string) ($_POST['date'] ?? '');
$time = (string) ($_POST['time'] ?? '');
$type = ($_POST['type'] ?? '') === 'inperson' ? 'inperson' : 'online';
$dur  = getSettingInt('default_session_duration', 60);
if (!in_array($time, portalFreeSlots($db, $date, $dur), true)) {
    portalJson(false, ['error' => 'That time is no longer available. Please pick another.']);
}
try {
    $id = createSession($db, (int) $client['id'], $date . ' ' . $time . ':00', $dur, $type, null, null, 'pending');
    // Clients never confirm themselves, whatever auto_confirm_sessions says.
    $db->prepare("UPDATE `sessions` SET `status` = 'pending' WHERE `id` = :id")->execute([':id' => $id]);
} catch (Throwable $e) {
    portalJson(false, ['error' => portalSessionErr($e, 'Could not book. Please try again.')]);
}
$s = fetchSession($db, $id);
portalActivity($db, 'session_client_booked', trim($client['first_name'] . ' ' . $client['last_name']) . ' requested a session on ' . portalFmt($s['start_time']), $client['id']);
portalNotifyAdmin($db, $client, 'New session request', 'Requested ' . portalFmt($s['start_time']) . ' (' . ($type === 'online' ? 'Online' : 'In person') . ").\nIt is Pending: confirm it from the Sessions page.");
portalJson(true, ['message' => 'Session requested for ' . portalFmt($s['start_time']) . '. The practice will confirm it shortly.']);
