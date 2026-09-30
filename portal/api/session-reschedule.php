<?php
require_once __DIR__ . '/session-lib.php';
$client = requireClient(true);
portalRequireCsrf();
$db = getDbConnection();
$s = portalOwnSession($db, $client, $_POST['session_id'] ?? 0);
if (!$s) {
    portalJson(false, ['error' => 'Session not found.']);
}
if ($err = portalChangeBlock($s, ['pending', 'confirmed'])) {
    portalJson(false, ['error' => $err]);
}
$date = (string) ($_POST['date'] ?? '');
$time = (string) ($_POST['time'] ?? '');
$dur  = (int) round((strtotime($s['end_time']) - strtotime($s['start_time'])) / 60);
if (!in_array($time, portalFreeSlots($db, $date, $dur, (int) $s['id']), true)) {
    portalJson(false, ['error' => 'That time is no longer available. Please pick another.']);
}
try {
    rescheduleSession($db, (int) $s['id'], $date . ' ' . $time . ':00', $dur);
    // ponytail: direct write; the repo has no confirmed->pending transition. A client move always needs re-confirmation.
    $db->prepare("UPDATE `sessions` SET `status` = 'pending' WHERE `id` = :id")->execute([':id' => (int) $s['id']]);
} catch (Throwable $e) {
    portalJson(false, ['error' => portalSessionErr($e, 'Could not reschedule. Please try again.')]);
}
$new = fetchSession($db, (int) $s['id']);
portalActivity($db, 'session_client_rescheduled', trim($client['first_name'] . ' ' . $client['last_name']) . ' moved a session from ' . portalFmt($s['start_time']) . ' to ' . portalFmt($new['start_time']), $client['id']);
notifySessionRescheduled($db, (int) $s['id'], $s['start_time'], 'Rescheduled by you from the client portal.');
portalNotifyAdmin($db, $client, 'Session rescheduled by client', 'Moved from ' . portalFmt($s['start_time']) . ' to ' . portalFmt($new['start_time']) . ".\nIt is Pending again: please re-confirm it.");
portalJson(true, ['message' => 'Session moved to ' . portalFmt($new['start_time']) . '. It is pending confirmation again.']);
