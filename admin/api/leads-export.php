<?php
/**
 * CSV export. GET, because it is a download, and it deliberately accepts the
 * same filter query string the list page uses — the export is the table you
 * are looking at, not a separate query that might disagree with it.
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/lead-repo.php';

$db = getDbConnection();

$filters = [
    'q'         => isset($_GET['q']) ? trim($_GET['q']) : '',
    'status'    => isset($_GET['status']) ? trim($_GET['status']) : '',
    'source'    => isset($_GET['source']) ? trim($_GET['source']) : '',
    'date_from' => isset($_GET['date_from']) ? trim($_GET['date_from']) : '',
    'date_to'   => isset($_GET['date_to']) ? trim($_GET['date_to']) : '',
    'sort'      => isset($_GET['sort']) ? $_GET['sort'] : 'date',
    'dir'       => isset($_GET['dir']) ? $_GET['dir'] : 'desc',
];

// An explicit id list wins over the filters: that is the "export selected" path.
if (isset($_GET['ids']) && $_GET['ids'] !== '') {
    $ids   = array_values(array_filter(array_map('intval', explode(',', $_GET['ids']))));
    $leads = [];
    foreach ($ids as $id) {
        $row = fetchLead($db, $id);
        if ($row !== null) {
            $leads[] = $row;
        }
    }
} else {
    $leads = fetchLeads($db, $filters);
}

$filename = 'leads-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// BOM so Excel opens UTF-8 names correctly instead of mojibake.
echo "\xEF\xBB\xBF";
echo leadsCsv($leads);
