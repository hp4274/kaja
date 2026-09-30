<?php
/** Client reports a payment they made. Stored pending; admin verifies. Never touches client_fees. */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
$client = requireClient(true);
portalRequireCsrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}
require_once dirname(__DIR__, 2) . '/includes/client-payments.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';

function reportFail($msg) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$db     = getDbConnection();
$cid    = (int) $client['id'];
$method = (string) ($_POST['method'] ?? '');
$amount = trim((string) ($_POST['amount'] ?? ''));
$ref    = trim((string) ($_POST['reference'] ?? ''));
$date   = (string) ($_POST['paid_on'] ?? '');

if (!in_array($method, ['upi', 'bank_transfer', 'cash'], true)) reportFail('Choose a payment method.');
if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) reportFail('Enter a valid amount.');
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) reportFail('Enter the date you paid.');
if (mb_strlen($ref) > 100) reportFail('Reference is too long.');

// Light flood guard: at most 5 pending reports per client.
$n = $db->prepare("SELECT COUNT(*) FROM `client_payment_reports` WHERE `client_id` = :c AND `status` = 'pending'");
$n->execute([':c' => $cid]);
if ((int) $n->fetchColumn() >= 5) reportFail('You have several reports awaiting verification. Please wait for us to confirm them.');

$db->prepare('INSERT INTO `client_payment_reports` (`client_id`,`method`,`amount`,`reference`,`paid_on`) VALUES (:c,:m,:a,:r,:d)')
   ->execute([':c' => $cid, ':m' => $method, ':a' => $amount, ':r' => $ref, ':d' => $date]);
$id   = (int) $db->lastInsertId();
$name = trim($client['first_name'] . ' ' . $client['last_name']);
$desc = "{$name} reported a payment of INR {$amount} via " . clientPaymentMethodLabel($method) . ($ref !== '' ? " (ref {$ref})" : '') . ' - awaiting verification';

$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('payment_reported',:d,'client',:rid)")
   ->execute([':d' => $desc, ':rid' => $cid]);

$practiceEmail = getSetting('practice_email');
if ($practiceEmail) {
    sendMail($practiceEmail, 'Payment reported by ' . $name, $desc . "\nPaid on: {$date}\nReport #{$id}\n\nVerify it and record the payment in the client's Fees tab.");
}
echo json_encode(['success' => true]);
