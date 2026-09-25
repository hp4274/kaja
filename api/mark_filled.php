<?php
/**
 * Public API: Filled beacon (POST).
 * Records that a visitor has started typing in the intake questionnaire.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once dirname(__DIR__) . '/db-config.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/intake-token.php';
require_once dirname(__DIR__) . '/includes/intake-repo.php';

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
if ($token === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Missing token.']);
    exit;
}

$db   = getDbConnection();
$link = findIntakeLink($db, $token);

if (empty($link)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unknown token.']);
    exit;
}

$moved = advanceIntakeLink($db, (int) $link['id'], 'filled');

echo json_encode(['success' => true, 'advanced' => $moved]);
