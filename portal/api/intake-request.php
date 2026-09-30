<?php
/** Client asks for a fresh intake link. Rate limit: 1 per 24h (activity_log). */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
$client = requireClient(true);
portalRequireCsrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}
require_once dirname(__DIR__, 2) . '/includes/intake-token.php';
require_once dirname(__DIR__, 2) . '/includes/mail-queue.php';

$db  = getDbConnection();
$cid = (int) $client['id'];

if (empty($client['lead_id']) || !empty($client['intake_submitted_at'])) {
    echo json_encode(['success' => false, 'error' => 'No intake link is needed for your account. Please contact us.']);
    exit;
}
$recent = $db->prepare("SELECT COUNT(*) FROM `activity_log` WHERE `action` = 'portal_intake_link_requested'
    AND `reference_type` = 'client' AND `reference_id` = :c AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 DAY)");
$recent->execute([':c' => $cid]);
if ((int) $recent->fetchColumn() > 0) {
    echo json_encode(['success' => false, 'error' => 'You already requested a link today. Please check your email.']);
    exit;
}

$issued = issueIntakeToken($db, (int) $client['lead_id'], $cid);
$name   = trim($client['first_name'] . ' ' . $client['last_name']);
$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('portal_intake_link_requested',:d,'client',:rid)")
   ->execute([':d' => $name . ' requested a new intake link from the portal', ':rid' => $cid]);
if (!sendIntakeLinkEmail($client['email'], $name, $issued['url'], $issued['expires_at'])) {
    queueFailedMail(['to' => $client['email'], 'name' => $name, 'url' => $issued['url'],
        'expires' => $issued['expires_at'], 'kind' => 'intake_link'], 'SMTP send failed');
}
echo json_encode(['success' => true]);
