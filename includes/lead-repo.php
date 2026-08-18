<?php
/**
 * Every list read of `leads` goes through here.
 *
 * The page, the CSV export and the dashboard share one query builder so that
 * identical filters can never produce different result sets — the failure this
 * prevents is an export that quietly disagrees with the table above it.
 *
 * Sort columns and directions are whitelisted, never interpolated: they cannot
 * be bound as parameters, so a whitelist is the only safe way to accept them.
 */

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/lead-status.php';

/**
 * Build the list SQL and its bound parameters.
 * Unknown filter values are dropped rather than applied, so a mangled query
 * string shows everything instead of silently showing nothing.
 */
function leadListSql(array $filters) {
    $where  = [];
    $params = [];

    $q = isset($filters['q']) ? trim($filters['q']) : '';
    if ($q !== '') {
        $where[] = '(`name` LIKE :q_name OR `email` LIKE :q_email OR `phone` LIKE :q_phone)';
        $params[':q_name']  = '%' . $q . '%';
        $params[':q_email'] = '%' . $q . '%';
        $params[':q_phone'] = '%' . $q . '%';
    }

    $status = isset($filters['status']) ? $filters['status'] : '';
    if ($status !== '' && $status !== 'all' && isValidLeadStatus($status)) {
        $where[] = '`status` = :status';
        $params[':status'] = $status;
    }

    $source = isset($filters['source']) ? trim($filters['source']) : '';
    if ($source !== '' && $source !== 'all') {
        $where[] = '`source` = :source';
        $params[':source'] = $source;
    }

    // Dates arrive as YYYY-MM-DD from <input type="date">. The upper bound is
    // pushed to the end of the day, otherwise "to 15 Aug" excludes 15 Aug.
    $from = isset($filters['date_from']) ? trim($filters['date_from']) : '';
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = '`created_at` >= :date_from';
        $params[':date_from'] = $from . ' 00:00:00';
    }
    $to = isset($filters['date_to']) ? trim($filters['date_to']) : '';
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = '`created_at` <= :date_to';
        $params[':date_to'] = $to . ' 23:59:59';
    }

    $sql = 'SELECT * FROM `leads`';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $dir = (isset($filters['dir']) && strtolower($filters['dir']) === 'asc') ? 'ASC' : 'DESC';

    if (isset($filters['sort']) && $filters['sort'] === 'status') {
        // FIELD() sorts by pipeline position. Plain alphabetical order would
        // read confirmed, contacted, converted, new — which means nothing.
        $order = "FIELD(`status`,'new','contacted','confirmed','converted','rejected','spam')";
        $sql .= ' ORDER BY ' . $order . ' ' . $dir . ', `created_at` DESC';
    } else {
        $sql .= ' ORDER BY `created_at` ' . $dir;
    }

    return ['sql' => $sql, 'params' => $params];
}

function fetchLeads(PDO $db, array $filters) {
    $built = leadListSql($filters);
    $stmt  = $db->prepare($built['sql']);
    $stmt->execute($built['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLead(PDO $db, $id) {
    $stmt = $db->prepare('SELECT * FROM `leads` WHERE `id` = :id');
    $stmt->execute([':id' => (int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Counts for every status, plus 'all'. Statuses with no rows return 0. */
function leadStatusCounts(PDO $db) {
    $counts = array_fill_keys(leadStatuses(), 0);

    $rows = $db->query('SELECT `status`, COUNT(*) AS `n` FROM `leads` GROUP BY `status`');
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['n'];
        }
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

function leadSources(PDO $db) {
    $rows = $db->query('SELECT DISTINCT `source` FROM `leads` WHERE `source` <> "" ORDER BY `source` ASC');
    $out  = [];
    foreach ($rows as $row) {
        $out[] = $row['source'];
    }
    return $out;
}

/**
 * Move many leads at once. Each lead is checked against the pipeline
 * individually: a bulk action must not become a way to make a transition the
 * single-lead path would refuse. Returns what moved and what did not, so the
 * UI can say "4 updated, 1 skipped" rather than claiming a clean sweep.
 */
function bulkUpdateLeadStatus(PDO $db, array $ids, $status) {
    if (!isValidLeadStatus($status)) {
        throw new InvalidArgumentException('Unknown lead status: ' . $status);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return ['updated' => 0, 'skipped' => []];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT `id`, `status` FROM `leads` WHERE `id` IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $current = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $movable = [];
    $skipped = [];
    foreach ($current as $row) {
        if (leadCanTransition($row['status'], $status)) {
            $movable[] = (int) $row['id'];
        } else {
            $skipped[] = (int) $row['id'];
        }
    }

    if ($movable) {
        $ph  = implode(',', array_fill(0, count($movable), '?'));
        $upd = $db->prepare('UPDATE `leads` SET `status` = ? WHERE `id` IN (' . $ph . ')');
        $upd->execute(array_merge([$status], $movable));
    }

    return ['updated' => count($movable), 'skipped' => $skipped];
}

/**
 * Render leads as CSV.
 *
 * Any value opening with = + - @ is prefixed with an apostrophe. Without it a
 * lead whose name is "=cmd|..." becomes a live formula the moment the export
 * is opened in Excel — a spreadsheet is an execution environment, not a
 * document.
 */
function leadsCsv(array $leads) {
    $columns = [
        'id' => 'ID', 'created_at' => 'Date', 'name' => 'Name', 'email' => 'Email',
        'phone' => 'Phone', 'source' => 'Source', 'status' => 'Status',
        'preferred_date' => 'Preferred Date', 'preferred_time' => 'Preferred Time',
        'preference' => 'Preference', 'message' => 'Message',
    ];

    $out = fopen('php://temp', 'r+');

    // Escape character is explicitly empty. PHP's default backslash escaping
    // is not CSV at all — it corrupts any value containing a backslash — and
    // 8.4 deprecates leaving the argument off.
    fputcsv($out, array_values($columns), ',', '"', '');

    foreach ($leads as $lead) {
        $row = [];
        foreach (array_keys($columns) as $key) {
            $value = isset($lead[$key]) ? (string) $lead[$key] : '';
            if ($value !== '' && strpos('=+-@', $value[0]) !== false) {
                $value = "'" . $value;
            }
            $row[] = $value;
        }
        fputcsv($out, $row, ',', '"', '');
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Stamp the first time a lead's detail drawer was opened.
 * The WHERE clause carries the "only once" rule so two admins opening the same
 * lead at the same moment cannot race the stamp forward.
 */
function markLeadViewed(PDO $db, $id) {
    $stmt = $db->prepare('UPDATE `leads` SET `first_viewed_at` = NOW() WHERE `id` = :id AND `first_viewed_at` IS NULL');
    $stmt->execute([':id' => (int) $id]);
}
