<?php
/**
 * Filled beacon (POST).
 *
 * The form calls this once, the first time anyone types. It exists so the
 * dashboard can tell "opened the link and walked away" from "started
 * answering and got interrupted" — two very different follow-ups.
 *
 * Token-authenticated only. No session, no ids in the request: possession of
 * the link is the whole credential, exactly as submit_intake.php treats it.
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

// advanceIntakeLink() carries the precondition in its WHERE clause, so a
// beacon that arrives after submission simply changes nothing.
$moved = advanceIntakeLink($db, (int) $link['id'], 'filled');

echo json_encode(['success' => true, 'advanced' => $moved]);
