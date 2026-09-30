<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/client-documents.php';
$client = requireClient(true);
portalRequireCsrf();
header('Content-Type: application/json; charset=utf-8');

$rawIds = $_POST['ids'] ?? ($_POST['id'] ?? null);
$targetIds = [];
if (is_array($rawIds)) {
    $targetIds = array_map('intval', $rawIds);
} elseif (is_string($rawIds) && strpos($rawIds, ',') !== false) {
    $targetIds = array_map('intval', explode(',', $rawIds));
} elseif ($rawIds !== null && is_numeric($rawIds)) {
    $targetIds = [intval($rawIds)];
}

$targetIds = array_values(array_filter(array_unique($targetIds), function ($id) { return $id > 0; }));

if (empty($targetIds)) {
    echo json_encode(['success' => false, 'error' => 'No valid document specified.']);
    exit;
}

$db = getDbConnection();
$inClause = implode(',', array_fill(0, count($targetIds), '?'));
$params = array_merge([(int) $client['id']], $targetIds);

$stmt = $db->prepare("SELECT * FROM `client_documents`
    WHERE `client_id` = ? AND `id` IN ($inClause) AND `client_uploaded` = 1 AND `archived_at` IS NULL");
$stmt->execute($params);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($docs)) {
    echo json_encode(['success' => false, 'error' => 'Document(s) not found or already deleted.']);
    exit;
}

$archivedCount = 0;
$archivedNames = [];
foreach ($docs as $doc) {
    archiveClientDocument($db, (int) $doc['id']);
    $archivedCount++;
    $archivedNames[] = $doc['original_name'];
}

$desc = $archivedCount === 1
    ? 'Client removed own upload "' . mb_substr($archivedNames[0], 0, 200) . '"'
    : 'Client removed ' . $archivedCount . ' documents: ' . mb_substr(implode(', ', $archivedNames), 0, 200);

$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
              VALUES ('document_archived', :d, 'client', :rid)")
   ->execute([':d' => $desc, ':rid' => (int) $client['id']]);

echo json_encode([
    'success' => true,
    'count'   => $archivedCount,
    'message' => $archivedCount . ' document' . ($archivedCount === 1 ? '' : 's') . ' removed.'
]);

