<?php
/**
 * One-time test-data seeder for the whole client lifecycle: leads -> intake
 * links -> patient intake -> client -> sessions -> notes/documents.
 *
 *   php tools/seed-sessions.php
 *
 * Populates 10 dedicated "seed" clients (seed.client1..10@yopmail.com), two
 * per `clients.status` value, each with a coherent trail behind it (a lead
 * that converted, an intake link at some stage of its lifecycle, and -- for
 * the ones whose status implies intake happened -- a `patient-intake` row
 * plus the encrypted `clients.intake_data` written through the one function
 * allowed to touch it). A handful of standalone `prospectN@yopmail.com` leads
 * are also seeded pre-conversion, so `leads` isn't only ever seen already
 * converted. Sessions are then booked for the 10 seed clients across
 * Aug 2026 - Dec 2026, covering every `status` x `session_type` combination
 * plus edge cases (video link, recurring series, reschedules, reminders,
 * response tokens, weekends). Each client also gets a couple of `client_notes`
 * and `client_documents` rows.
 *
 * Idempotent: only ever touches the 10 seed clients + seed/prospect leads it
 * owns. Every run wipes and rebuilds their child rows, so running it twice
 * does not double the data. Never touches `blogs` or any real client row.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/intake-data.php'; // saveClientIntakeData() -- the only code allowed to write clients.intake_data

$db = getDbConnection();

function token(): string {
    return bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------
// 1. Seed clients (upsert by email so reruns don't duplicate them)
// ---------------------------------------------------------------------

// Two per clients.status value. seed.clientN@yopmail.com, N = 1..10.
$seedClients = [];
$people = [
    ['Ananya', 'pending',   'Mumbai',    'anxiety'],
    ['Rohan',  'pending',   'Pune',      'stress'],
    ['Meera',  'review',    'Bengaluru', 'relationships'],
    ['Karan',  'review',    'Delhi',     'grief'],
    ['Priya',  'active',    'Chennai',   'depression'],
    ['Dev',    'active',    'Hyderabad', 'anxiety'],
    ['Isha',   'inactive',  'Kolkata',   'stress'],
    ['Nikhil', 'inactive',  'Jaipur',    'sleep'],
    ['Sara',   'completed', 'Ahmedabad', 'depression'],
    ['Vivek',  'completed', 'Kochi',     'relationships'],
];
foreach ($people as $n => [$first, $status, $city, $concern]) {
    $seedClients[] = [
        'first_name' => $first, 'last_name' => 'Seed', 'email' => 'seed.client' . ($n + 1) . '@yopmail.com',
        'status' => $status, 'city' => $city, 'concern' => $concern,
    ];
}

$findClient   = $db->prepare('SELECT id FROM clients WHERE email = ?');
$insertClient = $db->prepare(
    'INSERT INTO clients (first_name, last_name, email, phone, city, concern, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
// Existing seed rows are brought back in line too: a rerun after the seed
// definition changes must not leave an old status or name behind.
$updateClient = $db->prepare(
    'UPDATE clients SET first_name = ?, last_name = ?, phone = ?, city = ?, concern = ?, status = ?,
            archived_at = NULL, merged_into_id = NULL
     WHERE id = ?'
);

$clientIds = [];
foreach ($seedClients as $i => $c) {
    $phone = '+1555000' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
    $findClient->execute([$c['email']]);
    $row = $findClient->fetch();
    if ($row) {
        $updateClient->execute([$c['first_name'], $c['last_name'], $phone, $c['city'], $c['concern'], $c['status'], $row['id']]);
        $clientIds[] = (int) $row['id'];
        continue;
    }
    $insertClient->execute([$c['first_name'], $c['last_name'], $c['email'], $phone, $c['city'], $c['concern'], $c['status']]);
    $clientIds[] = (int) $db->lastInsertId();
}

// Client ids grouped by status, for handing out sessions that fit the status.
$byStatus = [];
foreach ($seedClients as $i => $c) {
    $byStatus[$c['status']][] = $clientIds[$i];
}

// ---------------------------------------------------------------------
// 2. Leads -> intake links -> patient intake, one coherent trail per client
// ---------------------------------------------------------------------

$idListSql = implode(',', array_map('intval', $clientIds));

// Cascades intake_links (FK ON DELETE CASCADE); patient-intake has no FK so
// it's cleared explicitly. Reset every seed client's intake_data too, since
// which of them "has intake" is reassigned fresh below on every run.
$db->exec("DELETE FROM `patient-intake` WHERE client_id IN ($idListSql)");
$db->prepare("DELETE FROM leads WHERE client_id IN ($idListSql) OR email LIKE 'prospect%@yopmail.com'")->execute();
$db->exec("UPDATE clients SET intake_data = NULL, intake_form_version = NULL, intake_submitted_at = NULL WHERE id IN ($idListSql)");

$insertLead = $db->prepare(
    'INSERT INTO leads (name, email, country_code, phone, preferred_date, preferred_time, preference, message, source, status, client_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

// 2a. Standalone prospects, never converted -- leads.status coverage for the
// states a client trail alone wouldn't reach.
$prospects = [
    ['Prospect New',       'prospect1@yopmail.com', 'home',        'new'],
    ['Prospect Contacted', 'prospect2@yopmail.com', 'appointment', 'contacted'],
    ['Prospect Confirmed', 'prospect3@yopmail.com', 'intake',      'confirmed'],
    ['Prospect Rejected',  'prospect4@yopmail.com', 'home',        'rejected'],
];
$prospectLeadIds = [];
foreach ($prospects as $p) {
    [$name, $email, $source, $status] = $p;
    $insertLead->execute([
        $name, $email, '+1', '+15550009999', '2026-07-25', '10:00', 'online',
        '[SEED] prospect lead, never converted.', $source, $status, null, '2026-07-10 09:00:00',
    ]);
    $prospectLeadIds[] = (int) $db->lastInsertId();
}

// 2b. One converted lead per seed client -- the trail sessions hang off of.
$leadIds = [];
foreach ($seedClients as $idx => $c) {
    $insertLead->execute([
        $c['first_name'] . ' ' . $c['last_name'], $c['email'], '+1', '+155500' . (1000 + $idx),
        '2026-07-28', '11:00', 'online', '[SEED] converted to client.', 'appointment', 'converted',
        $clientIds[$idx], '2026-07-20 ' . (9 + $idx) . ':00:00',
    ]);
    $leadIds[$idx] = (int) $db->lastInsertId();
}

$insertLink = $db->prepare(
    'INSERT INTO intake_links (lead_id, client_id, token, form_version, status, expires_at, opened_at, filled_at, submitted_at, reminder_sent, created_at)
     VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
);

function makeIntakeAnswers(array $c): array {
    $answers = [
        'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['email'],
        'phone' => '+15550001000', 'city' => $c['city'], 'occupation' => 'Seed test occupation',
        'dob' => '1990-01-01', 'concern' => $c['concern'],
        'pref_consult' => 'video', 'pref_date' => '2026-08-01', 'pref_time' => 'morning',
        'consent_given' => '1',
    ];
    for ($q = 1; $q <= 18; $q++) {
        $answers["q1_$q"] = (string) ($q % 4); // 0-3 severity scale, cyclic
        $answers["q2_$q"] = (string) (($q + 2) % 4);
    }
    return $answers;
}

$insertPatientIntake = $db->prepare(
    'INSERT INTO `patient-intake` (
        first_name, last_name, email, phone, city, occupation, dob, concern,
        pref_consult, pref_date, pref_time,
        q1_1,q1_2,q1_3,q1_4,q1_5,q1_6,q1_7,q1_8,q1_9,q1_10,q1_11,q1_12,q1_13,q1_14,q1_15,q1_16,q1_17,q1_18,
        q2_1,q2_2,q2_3,q2_4,q2_5,q2_6,q2_7,q2_8,q2_9,q2_10,q2_11,q2_12,q2_13,q2_14,q2_15,q2_16,q2_17,q2_18,
        intake_link_id, client_id, form_version, consent_given, consent_at, consent_version
    ) VALUES (' . implode(',', array_fill(0, 53, '?')) . ')'
);

function insertPatientIntakeRow(PDOStatement $stmt, array $answers, int $linkId, int $clientId, string $consentAt): void {
    $vals = [
        $answers['first_name'], $answers['last_name'], $answers['email'], $answers['phone'],
        $answers['city'], $answers['occupation'], $answers['dob'], $answers['concern'],
        $answers['pref_consult'], $answers['pref_date'], $answers['pref_time'],
    ];
    for ($q = 1; $q <= 18; $q++) { $vals[] = $answers["q1_$q"]; }
    for ($q = 1; $q <= 18; $q++) { $vals[] = $answers["q2_$q"]; }
    $vals[] = $linkId;
    $vals[] = $clientId;
    $vals[] = 1; // form_version
    $vals[] = 1; // consent_given
    $vals[] = $consentAt;
    $vals[] = 1; // consent_version
    $stmt->execute($vals);
}

// 2c. Intake link(s) per seed client, then patient intake + encrypted
// clients.intake_data for every client whose status means intake came back.
// Pending is the one status that by definition has no intake yet
// ("created when a lead was confirmed, intake not yet returned"), so its
// chain stops at the link. Across the set every intake_links.status occurs:
//   pending #1   -> 'sent', not opened yet.
//   pending #2   -> first link 'expired' unopened, the resend 'opened'.
//   completed #1 -> an abandoned 'filled' link, then the resend 'submitted'.
//   everyone else -> 'submitted' cleanly.
$seenStatus = [];
foreach ($seedClients as $idx => $c) {
    $leadId   = $leadIds[$idx];
    $clientId = $clientIds[$idx];
    $nth      = $seenStatus[$c['status']] = ($seenStatus[$c['status']] ?? 0) + 1;

    if ($c['status'] === 'pending') {
        if ($nth === 1) {
            $insertLink->execute([$leadId, $clientId, token(), 'sent', '2026-10-07 00:00:00', null, null, null, 0, '2026-09-28 10:00:00']);
        } else {
            $insertLink->execute([$leadId, $clientId, token(), 'expired', '2026-09-10 00:00:00', null, null, null, 1, '2026-09-03 10:00:00']);
            $insertLink->execute([$leadId, $clientId, token(), 'opened', '2026-10-06 00:00:00', '2026-09-29 18:00:00', null, null, 0, '2026-09-29 10:00:00']);
        }
        continue;
    }

    if ($c['status'] === 'completed' && $nth === 1) {
        $insertLink->execute([$leadId, $clientId, token(), 'filled', '2026-07-15 00:00:00', '2026-07-08 09:00:00', '2026-07-08 09:20:00', null, 1, '2026-07-05 09:00:00']);
    }
    $insertLink->execute([$leadId, $clientId, token(), 'submitted', '2026-08-15 00:00:00', '2026-07-30 09:00:00', '2026-07-30 09:20:00', '2026-07-30 09:30:00', 0, '2026-07-29 10:00:00']);
    $finalLinkId = (int) $db->lastInsertId();

    $answers = makeIntakeAnswers($c);
    $consentAt = '2026-07-30 09:30:00';
    insertPatientIntakeRow($insertPatientIntake, $answers, $finalLinkId, $clientId, $consentAt);
    saveClientIntakeData($db, $clientId, $answers, 1);
}

// 2d. Standalone prospect mid-pipeline: opened the link, hasn't finished --
// covers intake_links.status = 'opened' without needing a client yet.
$insertLink->execute([$prospectLeadIds[2], null, token(), 'opened', '2026-08-20 00:00:00', '2026-07-26 14:00:00', null, null, 0, '2026-07-25 10:00:00']);

// ---------------------------------------------------------------------
// 3. Wipe any sessions this script previously created for these clients
// ---------------------------------------------------------------------

$in = implode(',', array_fill(0, count($clientIds), '?'));
$db->prepare("DELETE FROM sessions WHERE client_id IN ($in)")->execute($clientIds);

// ---------------------------------------------------------------------
// 4. Build the session rows
// ---------------------------------------------------------------------

$statuses = ['pending', 'confirmed', 'rejected', 'completed', 'cancelled', 'no-show'];
$types    = ['online', 'inperson'];
$times    = ['09:00', '11:00', '13:00', '15:00', '17:00', '19:00'];
$months   = ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];

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
            // The full matrix books the active clients: they're the only
            // ones a confirmed/completed session is coherent for all year.
            $client = $byStatus['active'][$i % 2];

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
            // Saturday: an active client's confirmed slot. Sunday: a booking
            // request still pending, from someone not yet bookable (pending
            // or review) -- rotated so each of those four gets one.
            $notYetBookable = array_merge($byStatus['pending'], $byStatus['review']);
            $client = $isSat ? $byStatus['active'][$mi % 2] : $notYetBookable[$mi % 4];
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

// 3c. Recurring series, 4 weekly occurrences each. Inactive and completed
// clients get past series that ended (dropped off / finished treatment);
// an active client gets an upcoming one.
$seriesDefs = [
    // [series id, client, start, type, statuses per occurrence]
    [9001, $byStatus['inactive'][0],  '2026-08-05 10:00:00', 'online',   ['completed', 'completed', 'no-show', 'cancelled']],
    [9002, $byStatus['inactive'][1],  '2026-08-12 14:00:00', 'inperson', ['completed', 'completed', 'completed', 'cancelled']],
    [9003, $byStatus['completed'][0], '2026-08-03 10:00:00', 'online',   ['completed', 'completed', 'completed', 'completed']],
    [9004, $byStatus['completed'][1], '2026-08-20 14:00:00', 'inperson', ['completed', 'completed', 'completed', 'completed']],
    [9005, $byStatus['active'][1],    '2026-11-02 18:00:00', 'online',   ['confirmed', 'pending', 'pending', 'pending']],
];

foreach ($seriesDefs as [$seriesId, $client, $startStr, $type, $seriesStatuses]) {
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
// 5. Insert sessions
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

$idList = implode(',', array_map('intval', $clientIds));

// ---------------------------------------------------------------------
// 6. Client notes + documents -- one clinical note tied to a real completed
// session where the client has one, plus an administrative note, plus one
// document. Simple, table-native fields only; no fee/payment rows (not
// asked for) and no actual files on disk (documents here are metadata rows,
// same as the app stores -- the file itself lives outside the DB).
// ---------------------------------------------------------------------

$db->exec("DELETE FROM client_notes WHERE client_id IN ($idList)");
$db->exec("DELETE FROM client_documents WHERE client_id IN ($idList)");

$adminUserId = (int) ($db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0) ?: null;

$insertNote = $db->prepare(
    'INSERT INTO client_notes (client_id, note_type, user_id, note_kind, session_id, content)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insertDoc = $db->prepare(
    'INSERT INTO client_documents (client_id, original_name, stored_name, mime_type, size_bytes, uploaded_by, shared_with_client, client_uploaded)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($clientIds as $idx => $clientId) {
    // Administrative note -- a status-history-style entry every client gets.
    $insertNote->execute([
        $clientId, 'general', $adminUserId, 'administrative', null,
        "[SEED] Client record created via lead conversion; current status: {$seedClients[$idx]['status']}.",
    ]);

    // Clinical note on a real completed session, when this client has one.
    $completedSessionId = $db->prepare('SELECT id FROM sessions WHERE client_id = ? AND status = "completed" ORDER BY start_time LIMIT 1');
    $completedSessionId->execute([$clientId]);
    $sessionId = $completedSessionId->fetchColumn();
    if ($sessionId) {
        $insertNote->execute([
            $clientId, 'session', $adminUserId, 'session', (int) $sessionId,
            '[SEED] Session notes: client engaged well, discussed presenting concern, plan reviewed.',
        ]);
    }

    // Document metadata -- only once intake has come back, i.e. not pending.
    if ($seedClients[$idx]['status'] !== 'pending') {
        $insertDoc->execute([
            $clientId, 'consent-form.pdf', 'seed-doc-client' . ($idx + 1) . '.pdf',
            'application/pdf', 102400, $adminUserId, 1, 0,
        ]);
    }
}

// ---------------------------------------------------------------------
// 7. Report
// ---------------------------------------------------------------------

echo "Seed clients: " . count($clientIds) . " (ids: " . implode(',', $clientIds) . ")\n";
echo "Prospect leads (unconverted): " . count($prospectLeadIds) . "\n";
echo "Converted leads: " . count($leadIds) . "\n";

$linkCount = $db->query("SELECT COUNT(*) FROM intake_links WHERE lead_id IN (" . implode(',', array_merge($leadIds, $prospectLeadIds)) . ")")->fetchColumn();
echo "Intake links: $linkCount\n";

$piCount = $db->query("SELECT COUNT(*) FROM `patient-intake` WHERE client_id IN ($idList)")->fetchColumn();
echo "Patient intake rows: $piCount\n";

echo "Sessions inserted: $inserted\n";
$counts = $db->query(
    "SELECT status, session_type, COUNT(*) c FROM sessions WHERE client_id IN ($idList) GROUP BY status, session_type ORDER BY status, session_type"
);
foreach ($counts as $row) {
    echo "  {$row['status']} / {$row['session_type']}: {$row['c']}\n";
}

$noteCount = $db->query("SELECT COUNT(*) FROM client_notes WHERE client_id IN ($idList)")->fetchColumn();
$docCount  = $db->query("SELECT COUNT(*) FROM client_documents WHERE client_id IN ($idList)")->fetchColumn();
echo "Client notes: $noteCount\n";
echo "Client documents: $docCount\n";

echo "\nChain per client (client -> lead -> intake links -> patient intake -> intake_data -> sessions):\n";
$chain = $db->query("
    SELECT c.id, c.email, c.status,
           (SELECT GROUP_CONCAT(l.status) FROM leads l WHERE l.client_id = c.id) AS lead,
           (SELECT GROUP_CONCAT(il.status ORDER BY il.id) FROM intake_links il WHERE il.client_id = c.id) AS links,
           (SELECT COUNT(*) FROM `patient-intake` pi WHERE pi.client_id = c.id) AS intake,
           c.intake_data IS NOT NULL AS intake_data,
           (SELECT COUNT(*) FROM sessions s WHERE s.client_id = c.id) AS sessions
    FROM clients c WHERE c.id IN ($idList) ORDER BY c.id
");
foreach ($chain as $r) {
    printf("  #%d %-26s %-9s lead=%s links=%s intake=%d intake_data=%d sessions=%d\n",
        $r['id'], $r['email'], $r['status'], $r['lead'], $r['links'], $r['intake'], $r['intake_data'], $r['sessions']);
}
