<?php
/**
 * Public API: Draft autosave (POST).
 * Stores partial answers so a long intake questionnaire can be resumed.
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
$json  = isset($_POST['answers']) ? $_POST['answers'] : '';

if ($token === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Missing token.']);
    exit;
}

$answers = json_decode($json, true);
if (!is_array($answers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Malformed answers.']);
    exit;
}

// Cap draft size so crafted requests cannot abuse storage
if (count($answers) > 200) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Too many fields.']);
    exit;
}

$db   = getDbConnection();
$link = findIntakeLink($db, $token);

if (empty($link)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unknown token.']);
    exit;
}

$clean = [];
foreach ($answers as $key => $value) {
    if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,40}$/i', $key)) {
        continue;
    }
    if (is_array($value) || is_object($value)) {
        continue;
    }
    $clean[$key] = mb_substr((string) $value, 0, 2000);
}

$saved = saveIntakeDraft($db, (int) $link['id'], $clean);
advanceIntakeLink($db, (int) $link['id'], 'filled');

echo json_encode(['success' => true, 'saved' => $saved]);
