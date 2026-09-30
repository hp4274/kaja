<?php
require_once dirname(__DIR__) . '/includes/auth.php';
$client = requireClient(true);
portalRequireCsrf();
// Every session (including this one) now carries a stale session_version.
getDbConnection()->prepare('UPDATE `clients` SET `session_version` = `session_version` + 1 WHERE `id` = :id')
    ->execute([':id' => (int) $client['id']]);
session_destroy();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
