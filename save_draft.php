<?php
/**
 * Draft autosave (POST).
 *
 * Stores partial answers so a long questionnaire can be left and resumed. This
 * is working state, not a record: it is refused once the form is submitted and
 * cleared when it is.
 *
 * Token-authenticated only, same contract as mark_filled.php.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/intake-token.php';
require_once __DIR__ . '/includes/intake-repo.php';

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

// A draft is a convenience, not a payload to build a record from. Cap it so a
// crafted request cannot use the column as free storage.
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

// Only keys that look like form field names, only scalar values, each capped.
// The draft is written back into the form on the next visit, so what goes in
// here is what comes back out.
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

// Typing also counts as having started, so the two beacons agree without the
// browser having to send both.
advanceIntakeLink($db, (int) $link['id'], 'filled');

echo json_encode(['success' => true, 'saved' => $saved]);
