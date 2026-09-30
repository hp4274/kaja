<?php
require_once __DIR__ . '/session-lib.php';
$client = requireClient(true);
portalRequireCsrf();
$db = getDbConnection();
$dur = getSettingInt('default_session_duration', 60);
$excl = 0;
if (!empty($_POST['session_id']) && ($own = portalOwnSession($db, $client, $_POST['session_id']))) {
    $excl = (int) $own['id'];
    $dur  = (int) round((strtotime($own['end_time']) - strtotime($own['start_time'])) / 60);
}
$slots = [];
foreach (portalFreeSlots($db, (string) ($_POST['date'] ?? ''), $dur, $excl) as $t) {
    $slots[] = ['time' => $t, 'label' => bookingSlotLabel($t)];
}
portalJson(true, ['slots' => $slots]);
