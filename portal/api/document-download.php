<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/client-documents.php';
$client = requireClient(true);
$db = getDbConnection();

// Ownership + visibility in the query: another client's id simply is not found.
$stmt = $db->prepare('
    SELECT * FROM `client_documents`
    WHERE `id` = :id AND `client_id` = :c AND `archived_at` IS NULL
      AND (`shared_with_client` = 1 OR `client_uploaded` = 1)');
$stmt->execute([':id' => (int) ($_GET['id'] ?? 0), ':c' => (int) $client['id']]);
$doc  = $stmt->fetch(PDO::FETCH_ASSOC);
$path = $doc ? documentStorageDir() . DIRECTORY_SEPARATOR . $doc['stored_name'] : '';
if (!$doc || !is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

$types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$ext  = strtolower(pathinfo($doc['stored_name'], PATHINFO_EXTENSION));
$safe = str_replace(['"', '\\', "\r", "\n"], '', $doc['original_name']);
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($safe));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
