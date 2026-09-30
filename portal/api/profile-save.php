<?php
require_once dirname(__DIR__) . '/includes/auth.php';
$client = requireClient(true);
portalRequireCsrf();
header('Content-Type: application/json; charset=utf-8');

function fail($msg) {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
$str = function ($k, $max) {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
};

$first = $str('first_name', 100);
$last  = $str('last_name', 100);
if ($first === '' || $last === '') {
    fail('First and last name are required.');
}
$phone = preg_replace('/[\s\-]/', '', $str('phone', 50));
if (!preg_match('/^\d{10}$/', $phone)) {
    fail('Phone must be exactly 10 digits.');
}

$db  = getDbConnection();
$dob = $str('dob', 10);
if ($dob === '') {
    $dob = null;
} else {
    $p = explode('-', $dob);
    if (count($p) !== 3 || !ctype_digit(implode('', $p)) || !checkdate((int) $p[1], (int) $p[2], (int) $p[0]) || (int) $p[0] < 1900) {
        fail('Enter a valid date of birth.');
    }
    // MySQL's own date: PHP's clock is in a different timezone.
    $future = $db->prepare('SELECT :d > CURDATE()');
    $future->execute([':d' => $dob]);
    if ($future->fetchColumn()) {
        fail('Date of birth cannot be in the future.');
    }
}

$mode = $str('pref_mode', 20);
if (!in_array($mode, ['', 'Online', 'In-person'], true)) {
    fail('Invalid session mode.');
}
$allowed = ['morning', 'afternoon', 'evening', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
$times = array_values(array_intersect($allowed, (array) ($_POST['pref_times'] ?? [])));

$db->prepare('UPDATE `clients` SET `first_name`=:f, `last_name`=:l, `phone`=:p, `dob`=:d,
        `occupation`=:o, `city`=:c, `pref_mode`=:m, `pref_times`=:t WHERE `id`=:id')
   ->execute([
       ':f' => $first, ':l' => $last, ':p' => $phone, ':d' => $dob,
       ':o' => $str('occupation', 100) ?: null, ':c' => $str('city', 100) ?: null,
       ':m' => $mode ?: null, ':t' => $times ? implode(',', $times) : null,
       ':id' => (int) $client['id'],
   ]);
$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
              VALUES ('client_profile_updated', 'Client updated their profile in the portal', 'client', :rid)")
   ->execute([':rid' => (int) $client['id']]);
echo json_encode(['success' => true]);
