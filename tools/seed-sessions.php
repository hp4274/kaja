<?php
/**
 * One-time test-data seeder for the `sessions` table.
 *
 *   php tools/seed-sessions.php
 *
 * Populates 5 dedicated "seed" clients (emails seed.clientN@yopmail.com) and
 * books sessions for them across Aug 2026 - Dec 2026, covering every
 * `status` x `session_type` combination plus edge cases: with/without a
 * video link, recurring series, rescheduled counts, reminder flags, response
 * tokens, and guaranteed weekend slots.
 *
 * Idempotent: only ever touches the 5 seed clients it owns. Every run wipes
 * and rebuilds their sessions, so running it twice does not double the data.
 * Never touches `blogs` or any real client/session row.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../db-config.php';

$db = getDbConnection();

// ---------------------------------------------------------------------
// 1. Seed clients (upsert by email so reruns don't duplicate them)
// ---------------------------------------------------------------------

$seedClients = [
    ['first_name' => 'Seed', 'last_name' => 'Pending',   'email' => 'seed.client1@yopmail.com', 'status' => 'pending',   'city' => 'Mumbai',    'concern' => 'anxiety'],
    ['first_name' => 'Seed', 'last_name' => 'Review',    'email' => 'seed.client2@yopmail.com', 'status' => 'review',    'city' => 'Pune',      'concern' => 'stress'],
    ['first_name' => 'Seed', 'last_name' => 'Active',    'email' => 'seed.client3@yopmail.com', 'status' => 'active',    'city' => 'Bengaluru', 'concern' => 'relationships'],
    ['first_name' => 'Seed', 'last_name' => 'Inactive',  'email' => 'seed.client4@yopmail.com', 'status' => 'inactive',  'city' => 'Delhi',     'concern' => 'grief'],
    ['first_name' => 'Seed', 'last_name' => 'Completed', 'email' => 'seed.client5@yopmail.com', 'status' => 'completed', 'city' => 'Chennai',   'concern' => 'depression'],
];

$findClient   = $db->prepare('SELECT id FROM clients WHERE email = ?');
$insertClient = $db->prepare(
    'INSERT INTO clients (first_name, last_name, email, phone, city, concern, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$clientIds = [];
foreach ($seedClients as $i => $c) {
    $findClient->execute([$c['email']]);
    $row = $findClient->fetch();
    if ($row) {
        $clientIds[] = (int) $row['id'];
        continue;
    }
    $insertClient->execute([
        $c['first_name'], $c['last_name'], $c['email'],
        '+1555000' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
        $c['city'], $c['concern'], $c['status'],
    ]);
    $clientIds[] = (int) $db->lastInsertId();
}

// ---------------------------------------------------------------------
// 2. Wipe any sessions this script previously created for these clients
// ---------------------------------------------------------------------

$in = implode(',', array_fill(0, count($clientIds), '?'));
$db->prepare("DELETE FROM sessions WHERE client_id IN ($in)")->execute($clientIds);

// ---------------------------------------------------------------------
// 3. Build the rows
// ---------------------------------------------------------------------

$statuses = ['pending', 'confirmed', 'rejected', 'completed', 'cancelled', 'no-show'];
$types    = ['online', 'inperson'];
$times    = ['09:00', '11:00', '13:00', '15:00', '17:00', '19:00'];
$months   = ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];

function token(): string {
    return bin2hex(random_bytes(32));
}

$rows = [];

// 3a. Full status x type matrix, every month.
foreach ($months as $mi => $month) {
    $i = 0;
    foreach ($statuses as $status) {
        foreach ($types as $type) {
            $day    = 2 + $i * 2; // 2..24, always a valid day in any month
            $date   = "$month-" . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            $time   = $times[$i % count($times)];
            $start  = "$date $time:00";
            $end    = date('Y-m-d H:i:s', strtotime($start) + 3600);
            $client = $clientIds[$i % count($clientIds)];

            $hasLink = $type === 'online' ? ($i % 4 !== 0) : ($i % 5 === 0); // edge cases both ways
            $videoLink = $hasLink ? 'https://meet.rewirewithkajal.test/room-seed-' . $mi . '-' . $i : null;

            $responseToken = null;
            $respondedAt   = null;
            $cancelledReason = null;
            $reminderSent = 0;
            $reminder30   = 0;

            switch ($status) {
                case 'pending':
                    // Half already have a booking link sent, half don't yet.
                    if ($i % 2 === 0) { $responseToken = token(); }
                    break;
                case 'confirmed':
                    $responseToken = token();
                    $respondedAt   = date('Y-m-d H:i:s', strtotime($start) - 86400);
                    break;
                case 'rejected':
                    $responseToken = token();
                    $respondedAt   = date('Y-m-d H:i:s', strtotime($start) - 43200);
                    break;
                case 'completed':
                    $reminderSent = 1;
                    $reminder30   = 1;
                    break;
                case 'cancelled':
                    $cancelledReason = 'Client requested reschedule due to a conflict.';
                    break;
                case 'no-show':
                    $reminderSent = 1;
                    $reminder30   = 1;
                    break;
            }

            $rescheduled = ($i % 7 === 0) ? 2 : (($i % 5 === 0) ? 1 : 0);
            $notes = ($i % 2 === 0) ? "[SEED] $status/$type test session for {$date}." : null;

            $rows[] = [
                $client, $start, $end, $type, $status,
                $responseToken, $respondedAt, $videoLink,
                null, $cancelledReason, $rescheduled,
                $reminderSent, $reminder30, $notes,
            ];
            $i++;
        }
    }

    // 3b. Guaranteed weekend coverage per month (one Sat + one Sun booking).
    $ts = strtotime("$month-01");
    $lastDay = (int) date('t', $ts);
    $sawSat = false;
    $sawSun = false;
    for ($d = 1; $d <= $lastDay; $d++) {
        $dow = (int) date('N', strtotime("$month-" . str_pad((string) $d, 2, '0', STR_PAD_LEFT)));
        if ($dow === 6 || $dow === 7) {
            $isSat  = $dow === 6;
            $date   = "$month-" . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            $start  = "$date " . ($isSat ? '10:00:00' : '16:00:00');
            $end    = date('Y-m-d H:i:s', strtotime($start) + 3600);
            $client = $clientIds[$isSat ? 0 : 1];
            $status = $isSat ? 'confirmed' : 'pending';
            $type   = $isSat ? 'inperson' : 'online';
            $rows[] = [
                $client, $start, $end, $type, $status,
                $isSat ? token() : null, $isSat ? date('Y-m-d H:i:s', strtotime($start) - 86400) : null,
                $isSat ? null : 'https://meet.rewirewithkajal.test/room-weekend-' . $month,
                null, null, 0, 0, 0,
                '[SEED] weekend coverage (' . ($isSat ? 'Saturday' : 'Sunday') . ').',
            ];
            // Only need the first Sat and first Sun found in the month.
            if ($isSat) { $sawSat = true; } else { $sawSun = true; }
            if ($sawSat && $sawSun) { break; }
        }
    }
}

// 3c. Recurring series: 3 series of 4 weekly occurrences each, mixed status.
$seriesDefs = [
    // [series id, start date, type, statuses per occurrence]
    [9001, '2026-08-05 10:00:00', 'online',   ['completed', 'completed', 'confirmed', 'pending']],
    [9002, '2026-09-14 14:00:00', 'inperson', ['completed', 'confirmed', 'confirmed', 'cancelled']],
    [9003, '2026-11-02 18:00:00', 'online',   ['confirmed', 'pending', 'pending', 'pending']],
];

foreach ($seriesDefs as $si => [$seriesId, $startStr, $type, $seriesStatuses]) {
    $client = $clientIds[($si + 2) % count($clientIds)];
    $t = strtotime($startStr);
    foreach ($seriesStatuses as $occIndex => $status) {
        $start = date('Y-m-d H:i:s', $t + $occIndex * 7 * 86400);
        $end   = date('Y-m-d H:i:s', strtotime($start) + 3600);

        $responseToken = null;
        $respondedAt   = null;
        $cancelledReason = null;
        $reminderSent = in_array($status, ['completed', 'no-show'], true) ? 1 : 0;
        $reminder30   = $reminderSent;
        if ($status === 'confirmed') {
            $responseToken = token();
            $respondedAt   = date('Y-m-d H:i:s', strtotime($start) - 86400);
        }
        if ($status === 'cancelled') {
            $cancelledReason = 'Series discontinued after occurrence ' . ($occIndex + 1) . '.';
        }

        $rows[] = [
            $client, $start, $end, $type, $status,
            $responseToken, $respondedAt,
            $type === 'online' ? 'https://meet.rewirewithkajal.test/room-series-' . $seriesId : null,
            $seriesId, $cancelledReason,
            $occIndex > 0 && $status === 'confirmed' ? 1 : 0,
            $reminderSent, $reminder30,
            "[SEED] recurring series $seriesId, occurrence " . ($occIndex + 1) . '/' . count($seriesStatuses) . '.',
        ];
    }
}

// ---------------------------------------------------------------------
// 4. Insert
// ---------------------------------------------------------------------

$insert = $db->prepare(
    'INSERT INTO sessions
        (client_id, start_time, end_time, session_type, status,
         response_token, responded_at, video_link,
         recurring_series_id, cancelled_reason, rescheduled_count,
         reminder_sent, reminder30_sent, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$inserted = 0;
foreach ($rows as $r) {
    $insert->execute($r);
    $inserted++;
}

echo "Seed clients: " . count($clientIds) . " (ids: " . implode(',', $clientIds) . ")\n";
echo "Sessions inserted: $inserted\n";

$idList = implode(',', array_map('intval', $clientIds));
$counts = $db->query(
    "SELECT status, session_type, COUNT(*) c FROM sessions WHERE client_id IN ($idList) GROUP BY status, session_type ORDER BY status, session_type"
);
foreach ($counts as $row) {
    echo "  {$row['status']} / {$row['session_type']}: {$row['c']}\n";
}
