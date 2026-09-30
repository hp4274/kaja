<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/client-documents.php';
$client = requireClient(true);
portalRequireCsrf();
header('Content-Type: application/json; charset=utf-8');

function fail($msg) {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request method.');
}

/**
 * Extract and normalize all uploaded files from $_FILES
 * Supports both single upload (name="document") and multiple uploads (name="documents[]")
 */
function extractUploadedFiles() {
    $keys = ['documents', 'document'];
    $normalized = [];

    foreach ($keys as $k) {
        if (!isset($_FILES[$k])) {
            continue;
        }
        $raw = $_FILES[$k];
        if (is_array($raw['name'])) {
            $count = count($raw['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $normalized[] = [
                    'name'     => $raw['name'][$i],
                    'type'     => $raw['type'][$i] ?? '',
                    'tmp_name' => $raw['tmp_name'][$i],
                    'error'    => $raw['error'][$i],
                    'size'     => (int) $raw['size'][$i],
                ];
            }
        } elseif ($raw['error'] !== UPLOAD_ERR_NO_FILE && is_string($raw['name'] ?? null)) {
            $normalized[] = [
                'name'     => $raw['name'],
                'type'     => $raw['type'] ?? '',
                'tmp_name' => $raw['tmp_name'],
                'error'    => $raw['error'],
                'size'     => (int) $raw['size'],
            ];
        }
    }
    return $normalized;
}

$uploadedFiles = extractUploadedFiles();
if (empty($uploadedFiles)) {
    fail('Please select at least one document to upload.');
}

// Extension AND real content type whitelist
$mimes = [
    'pdf'  => ['application/pdf'],
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];

$batchTotalBytes = 0;
$validFiles = [];

// Phase 1: Validate every file in the batch before storing anything
foreach ($uploadedFiles as $f) {
    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        fail('Upload error on file "' . htmlspecialchars($f['name']) . '" (code ' . ($f['error'] ?? 'unknown') . ').');
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($mimes[$ext])) {
        fail('"' . htmlspecialchars($f['name']) . '" is not accepted. Only PDF, PNG, JPG or DOCX files are accepted.');
    }

    if ($f['size'] > 10 * 1024 * 1024 || $f['size'] > documentMaxBytes()) {
        fail('"' . htmlspecialchars($f['name']) . '" is larger than the 10 MB limit.');
    }

    if (!is_uploaded_file($f['tmp_name'])) {
        fail('Invalid upload for "' . htmlspecialchars($f['name']) . '".');
    }

    $real = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($real, $mimes[$ext], true)) {
        fail('The file contents of "' . htmlspecialchars($f['name']) . '" do not match its extension.');
    }
    $f['type'] = $real;

    $batchTotalBytes += $f['size'];
    $validFiles[] = $f;
}

// Phase 2: Check cumulative 50 MB storage quota
$db = getDbConnection();
$currentTotalBytes = clientTotalDocumentBytes($db, (int) $client['id']);
$maxTotalBytes     = clientMaxTotalDocumentBytes();

if (($currentTotalBytes + $batchTotalBytes) > $maxTotalBytes) {
    $maxMb   = (int) round($maxTotalBytes / (1024 * 1024));
    $usedMb  = round($currentTotalBytes / (1024 * 1024), 1);
    $batchMb = round($batchTotalBytes / (1024 * 1024), 1);
    $remMb   = max(0, round(($maxTotalBytes - $currentTotalBytes) / (1024 * 1024), 1));
    fail(
        "Total document storage limit of {$maxMb} MB per client reached. " .
        "Current usage: {$usedMb} MB ({$remMb} MB remaining). " .
        "These files ({$batchMb} MB) exceed available space. Please delete older documents before uploading."
    );
}

// Phase 3: Store all validated files
$uploadedCount = 0;
$uploadedNames = [];

foreach ($validFiles as $f) {
    try {
        $id = storeClientDocument($db, (int) $client['id'], $f, null, true);
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }

    $uploadedCount++;
    $uploadedNames[] = $f['name'];
}

$desc = $uploadedCount === 1
    ? 'Client uploaded "' . mb_substr($uploadedNames[0], 0, 200) . '"'
    : 'Client uploaded ' . $uploadedCount . ' documents: ' . mb_substr(implode(', ', $uploadedNames), 0, 200);

$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
              VALUES ('document_uploaded_by_client', :d, 'client', :rid)")
   ->execute([':d' => $desc, ':rid' => (int) $client['id']]);

echo json_encode([
    'success' => true,
    'count'   => $uploadedCount,
    'message' => $uploadedCount . ' document' . ($uploadedCount === 1 ? '' : 's') . ' uploaded successfully.'
]);

