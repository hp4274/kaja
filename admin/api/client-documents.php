<?php
/**
 * Document upload and archive.
 *
 * Kept apart from api/clients.php because this is the only endpoint in the
 * admin that accepts a file, and a multipart handler deserves to be read on
 * its own rather than buried in a switch.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/client-documents.php';
require_once __DIR__ . '/../../includes/client-repo.php';

$db     = getDbConnection();
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

try {
    switch ($action) {
        case 'upload':
            $clientId = intval($_POST['client_id'] ?? 0);

            if (!$clientId || fetchClient($db, $clientId) === null) {
                echo json_encode(['success' => false, 'error' => 'Client not found']);
                exit;
            }
            if (!isset($_FILES['document'])) {
                echo json_encode(['success' => false, 'error' => 'No file was sent']);
                exit;
            }

            try {
                $docId = storeClientDocument($db, $clientId, $_FILES['document'], $userId);
            } catch (RuntimeException $e) {
                // The message is written for the person uploading; the reasons
                // are all things they can fix (size, type, a failed transfer).
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }

            $db->prepare("
                INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
                VALUES ('document_uploaded', :d, 'client', :rid)
            ")->execute([
                ':d'   => 'Uploaded "' . $_FILES['document']['name'] . '"',
                ':rid' => $clientId,
            ]);

            echo json_encode([
                'success'   => true,
                'document'  => $docId,
                'documents' => clientDocuments($db, $clientId),
            ]);
            break;

        case 'archive':
            $docId = intval($_POST['document_id'] ?? 0);
            $doc   = $docId ? findClientDocument($db, $docId) : null;

            if ($doc === null) {
                echo json_encode(['success' => false, 'error' => 'Document not found']);
                exit;
            }

            // Soft delete, matching the client itself. The file stays on disk:
            // a consent form removed by a misclick should be recoverable.
            archiveClientDocument($db, $docId);

            $db->prepare("
                INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
                VALUES ('document_archived', :d, 'client', :rid)
            ")->execute([
                ':d'   => 'Archived "' . $doc['original_name'] . '"',
                ':rid' => (int) $doc['client_id'],
            ]);

            echo json_encode([
                'success'   => true,
                'documents' => clientDocuments($db, (int) $doc['client_id']),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('[client-documents] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
