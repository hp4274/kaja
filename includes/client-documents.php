<?php
/**
 * Client documents: consent forms, ID proof, referral letters.
 *
 * The file itself is written under document_storage_path, which defaults to a
 * directory OUTSIDE the project. Nothing Apache or nginx can be configured to
 * do will serve it; the only way out is admin/document.php, which checks the
 * session and logs the download.
 *
 * The uploaded filename is never used as a path. It is attacker-controlled,
 * and "consent.pdf" and "../../../index.php" arrive through the same field.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/settings.php';

function documentStorageDir() {
    $configured = getSetting('document_storage_path', '');
    if ($configured !== '' && $configured !== null) {
        return rtrim($configured, '/\\');
    }
    // Default: one level ABOVE the document root, not merely outside the
    // project. A sibling of the project would still sit inside htdocs and be
    // served at /kaja-storage/... by any default Apache config.
    //
    // includes/ -> project -> htdocs -> the directory holding it.
    return dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'kaja-storage'
         . DIRECTORY_SEPARATOR . 'documents';
}

/**
 * Extension whitelist. The mime type the browser claims is a hint, not proof —
 * it is chosen by whoever is uploading.
 */
function documentAllowedExtensions() {
    $configured = trim((string) getSetting('upload_allowed_types', ''));
    if ($configured === '') {
        return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    }
    return array_values(array_filter(array_map(function ($e) {
        return strtolower(trim($e, " \t.\n"));
    }, explode(',', $configured))));
}

function documentMaxBytes() {
    return max(1, getSettingInt('upload_max_mb', 10)) * 1024 * 1024;
}

/**
 * Maximum total storage in bytes allowed across all client-uploaded documents for one client.
 * Defaults to 50 MB.
 */
function clientMaxTotalDocumentBytes() {
    return max(1, getSettingInt('client_max_total_documents_mb', 50)) * 1024 * 1024;
}

/**
 * Total size in bytes of active (non-archived) documents stored for a client.
 * By design, admin/therapist uploads do NOT count towards the client's document storage limit.
 * If $clientUploadedOnly is true (default), sums ONLY client-uploaded documents (`client_uploaded` = 1).
 * If $clientUploadedOnly is false, sums all documents stored for the client.
 */
function clientTotalDocumentBytes(PDO $db, $clientId, $clientUploadedOnly = true) {
    if ($clientUploadedOnly) {
        $stmt = $db->prepare('
            SELECT COALESCE(SUM(`size_bytes`), 0)
            FROM `client_documents`
            WHERE `client_id` = :c AND `archived_at` IS NULL AND `client_uploaded` = 1
        ');
    } else {
        $stmt = $db->prepare('
            SELECT COALESCE(SUM(`size_bytes`), 0)
            FROM `client_documents`
            WHERE `client_id` = :c AND `archived_at` IS NULL
        ');
    }
    $stmt->execute([':c' => (int) $clientId]);
    return (int) $stmt->fetchColumn();
}

function documentTypeIsAllowed($mimeType, $originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return in_array($ext, documentAllowedExtensions(), true);
}

/**
 * A random name plus the whitelisted extension. Nothing from the upload
 * survives into the path, so traversal and double extensions have nowhere to
 * land.
 */
function generateStoredName($originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, documentAllowedExtensions(), true)) {
        $ext = 'bin';
    }
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

/**
 * Move an uploaded file into storage and record it.
 * $upload is one entry of $_FILES.
 * $userId: admin user ID when uploaded from admin, or null when uploaded from client portal.
 * $isClientUpload: true when uploaded by the client from portal; false for therapist/admin uploads.
 */
function storeClientDocument(PDO $db, $clientId, array $upload, $userId = null, $isClientUpload = false) {
    if (!isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file did not upload correctly.');
    }
    if ($upload['size'] > documentMaxBytes()) {
        throw new RuntimeException('That file is larger than the '
            . getSettingInt('upload_max_mb', 10) . ' MB limit.');
    }
    if (!documentTypeIsAllowed($upload['type'], $upload['name'])) {
        throw new RuntimeException('That file type is not accepted.');
    }

    // Enforce per-client cumulative storage limit (default 50 MB) ONLY for client uploads.
    // Admin uploads do not count against the client document storage limit, nor are they blocked by it.
    if ($isClientUpload) {
        $currentTotalBytes = clientTotalDocumentBytes($db, (int) $clientId, true);
        $maxTotalBytes     = clientMaxTotalDocumentBytes();
        if (($currentTotalBytes + (int) $upload['size']) > $maxTotalBytes) {
            $maxMb  = (int) round($maxTotalBytes / (1024 * 1024));
            $usedMb = round($currentTotalBytes / (1024 * 1024), 1);
            $fileMb = round($upload['size'] / (1024 * 1024), 1);
            $remMb  = max(0, round(($maxTotalBytes - $currentTotalBytes) / (1024 * 1024), 1));
            throw new RuntimeException(
                "Total document storage limit of {$maxMb} MB per client reached. " .
                "Current usage: {$usedMb} MB ({$remMb} MB remaining). " .
                "This file ({$fileMb} MB) exceeds available space. Please delete or archive older documents before uploading."
            );
        }
    }

    $dir = documentStorageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Document storage is not writable.');
    }

    $stored = generateStoredName($upload['name']);
    $target = $dir . DIRECTORY_SEPARATOR . $stored;

    // move_uploaded_file, not rename: it refuses anything that did not arrive
    // as an HTTP upload, which is what makes a forged tmp_name useless.
    if (!@move_uploaded_file($upload['tmp_name'], $target)) {
        throw new RuntimeException('The file could not be saved.');
    }

    $db->prepare('
        INSERT INTO `client_documents`
            (`client_id`,`original_name`,`stored_name`,`mime_type`,`size_bytes`,`uploaded_by`,`client_uploaded`)
        VALUES (:c,:orig,:stored,:mime,:size,:by,:cu)
    ')->execute([
        ':c'      => (int) $clientId,
        ':orig'   => mb_substr($upload['name'], 0, 255),
        ':stored' => $stored,
        ':mime'   => mb_substr((string) $upload['type'], 0, 120),
        ':size'   => (int) $upload['size'],
        ':by'     => $userId === null ? null : (int) $userId,
        ':cu'     => $isClientUpload ? 1 : 0,
    ]);

    return (int) $db->lastInsertId();
}

function clientDocuments(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT d.*, COALESCE(u.`username`, "System") AS `uploader`
        FROM `client_documents` d
        LEFT JOIN `users` u ON u.`id` = d.`uploaded_by`
        WHERE d.`client_id` = :c AND d.`archived_at` IS NULL
        ORDER BY d.`uploaded_at` DESC
    ');
    $stmt->execute([':c' => (int) $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function findClientDocument(PDO $db, $id) {
    $stmt = $db->prepare('SELECT * FROM `client_documents` WHERE `id` = :id AND `archived_at` IS NULL');
    $stmt->execute([':id' => (int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Soft delete, matching the client itself. The file stays on disk. */
function archiveClientDocument(PDO $db, $id) {
    $db->prepare('UPDATE `client_documents` SET `archived_at` = NOW() WHERE `id` = :id')
       ->execute([':id' => (int) $id]);
}
