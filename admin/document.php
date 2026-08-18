<?php
/**
 * The only way a stored document reaches a browser.
 *
 * Session-checked, and every download is written to activity_log before a byte
 * is sent. The file path is rebuilt from the database row: the id is the only
 * thing the caller gets to choose, so there is no filename to traverse with.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/client-documents.php';

$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$db  = getDbConnection();
$doc = $id ? findClientDocument($db, $id) : null;

if ($doc === null) {
    http_response_code(404);
    exit('Not found.');
}

$path = documentStorageDir() . DIRECTORY_SEPARATOR . $doc['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    error_log('[document] row ' . $id . ' has no file at ' . $path);
    exit('Not found.');
}

$db->prepare('
    INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
    VALUES ("document_downloaded", :d, "client", :rid)
')->execute([
    ':d'   => 'Downloaded "' . $doc['original_name'] . '" by '
            . (isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown'),
    ':rid' => (int) $doc['client_id'],
]);

// Always an attachment, always a generic type. Serving a stored file inline
// would let an uploaded HTML or SVG run script in the admin's session against
// the admin's own origin.
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['original_name']) . '"');
header('Content-Length: ' . (int) $doc['size_bytes']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

readfile($path);
