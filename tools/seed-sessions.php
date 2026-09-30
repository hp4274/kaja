<?php
/**
 * Local test data for the whole client lifecycle:
 * lead -> intake link -> patient intake -> client -> sessions -> fees/notes/documents.
 *
 *   php tools/seed-sessions.php
 *
 * 10 clients, two per clients.status, each with the trail its status implies,
 * plus 4 leads that never converted. Sessions are dense around the database
 * clock and every status is placed where it is possible: completed / no-show
 * only before now, pending / confirmed only after, rejected / cancelled
 * either side.
 * "Now" is the database's NOW(), read once -- PHP and MySQL clocks disagree
 * on this machine, so nothing here uses PHP's time().
 *
 * Idempotent: every run deletes the clients and leads whose email is in the
 * fixed lists below (plus the old seed.client* / prospect* addresses from
 * earlier versions of this script) with all their child rows, then rebuilds.
 * Nothing else is touched -- no real client, and never `blogs`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/intake-data.php'; // saveClientIntakeData(): the only writer of clients.intake_data

$db  = getDbConnection();
$now = strtotime($db->query('SELECT NOW()')->fetchColumn());

function token(): string { return bin2hex(random_bytes(32)); }
function dt(int $ts): string { return date('Y-m-d H:i:s', $ts); }
/** A moment that has already happened: $ts, or an hour ago if $ts is later. */
function past(int $ts, int $now): string { return dt(min($ts, $now - 3600)); }

// ---------------------------------------------------------------------
// The people. Emails are the idempotency key.
// ---------------------------------------------------------------------

$clients = [
    // first, last, status, city, occupation, concern
    ['Harsh',   'Patel',   'pending',   'Ahmedabad', 'Chartered accountant', 'anxiety'],
    ['Emily',   'Carter',  'pending',   'Mumbai',    'Product designer',     'stress'],
    ['Priya',   'Sharma',  'review',    'Delhi',     'School teacher',       'relationships'],
    ['Rahul',   'Mehta',   'review',    'Pune',      'Sales manager',        'grief'],
    ['Ananya',  'Iyer',    'active',    'Chennai',   'Software engineer',    'anxiety'],
    ['Arjun',   'Reddy',   'active',    'Hyderabad', 'Architect',            'depression'],
    ['Sneha',   'Nair',    'inactive',  'Kochi',     'Nurse',                'stress'],
    ['Daniel',  'Brooks',  'inactive',  'Bengaluru', 'Data analyst',         'sleep'],
    ['Kavya',   'Joshi',   'completed', 'Jaipur',    'Lawyer',               'depression'],
    ['Vikram',  'Singh',   'completed', 'Chandigarh','Civil engineer',       'relationships'],
];
$prospects = [
    // first, last, lead status, source, message
    ['Neha',   'Kapoor', 'new',       'home',        'Hi, I have been feeling overwhelmed at work lately and would like to talk to someone.'],
    ['Sophie', 'Turner', 'contacted', 'appointment', 'Looking for online sessions in the evenings, ideally weekly.'],
    ['Aditya', 'Rao',    'confirmed', 'intake',      'My GP suggested therapy for ongoing low mood. Weekends suit me best.'],
    ['Rohit',  'Verma',  'rejected',  'home',        'Can you prescribe medication for sleep?'],
];

$email = fn(string $first, string $last): string => strtolower("$first.$last") . '@yopmail.com';

// ---------------------------------------------------------------------
// 1. Clean slate for exactly these addresses
// ---------------------------------------------------------------------

$emails = [];
foreach ($clients as [$f, $l]) { $emails[] = $email($f, $l); }
foreach ($prospects as [$f, $l]) { $emails[] = $email($f, $l); }
// Addresses earlier versions of this script created.
for ($n = 1; $n <= 10; $n++) { $emails[] = "seed.client$n@yopmail.com"; }
for ($n = 1; $n <= 4; $n++)  { $emails[] = "prospect$n@yopmail.com"; }

$in = implode(',', array_fill(0, count($emails), '?'));
$stmt = $db->prepare("SELECT id FROM clients WHERE email IN ($in)");
$stmt->execute($emails);
$oldIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if ($oldIds) {
    $ids = implode(',', $oldIds);
    // No FK on these three, so they would not go with the client row.
    $db->exec("DELETE FROM `patient-intake` WHERE client_id IN ($ids)");
    $db->exec("DELETE FROM client_otps WHERE client_id IN ($ids)");
    $db->exec("DELETE FROM leads WHERE client_id IN ($ids)");
    // Cascades sessions, client_notes, client_fees, client_documents,
    // client_payment_reports; intake_links.client_id is SET NULL and those
    // links go with their lead below.
    $db->exec("DELETE FROM clients WHERE id IN ($ids)");
}
$db->prepare("DELETE FROM `patient-intake` WHERE email IN ($in)")->execute($emails);
$db->prepare("DELETE FROM leads WHERE email IN ($in)")->execute($emails); // cascades intake_links, lead_notes

// ---------------------------------------------------------------------
// 2. Clients, their converted leads, intake links and intake answers
// ---------------------------------------------------------------------

$insertClient = $db->prepare(
    'INSERT INTO clients (first_name, last_name, email, phone, city, occupation, dob, concern, status, pref_mode, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertLead = $db->prepare(
    'INSERT INTO leads (name, email, country_code, phone, preferred_date, preferred_time, preference, message, source, status, client_id, created_at)
     VALUES (?, ?, "+91", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertLeadNote = $db->prepare('INSERT INTO lead_notes (lead_id, user_id, content, created_at) VALUES (?, ?, ?, ?)');
$insertLink = $db->prepare(
    'INSERT INTO intake_links (lead_id, client_id, token, form_version, status, expires_at, opened_at, filled_at, submitted_at, reminder_sent, created_at)
     VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
);
$qCols = [];
for ($q = 1; $q <= 18; $q++) { $qCols[] = "q1_$q"; }
for ($q = 1; $q <= 18; $q++) { $qCols[] = "q2_$q"; }
$piCols = array_merge(
    ['first_name', 'last_name', 'email', 'phone', 'city', 'occupation', 'dob', 'concern', 'pref_consult', 'pref_date', 'pref_time'],
    $qCols,
    ['intake_link_id', 'client_id', 'form_version', 'consent_given', 'consent_at', 'consent_version', 'created_at']
);
$insertIntake = $db->prepare(
    'INSERT INTO `patient-intake` (`' . implode('`,`', $piCols) . '`) VALUES (' . implode(',', array_fill(0, count($piCols), '?')) . ')'
);

$leadMessages = [
    'anxiety'       => 'I keep getting anxious before meetings and it is affecting my sleep.',
    'stress'        => 'Work has been very stressful for months and I would like some support.',
    'relationships' => 'Going through a difficult phase with my partner and want to talk it through.',
    'grief'         => 'I lost my father earlier this year and am struggling to cope.',
    'depression'    => 'Feeling low and unmotivated most days for a while now.',
    'sleep'         => 'Trouble falling asleep most nights, mind will not switch off.',
];

$clientIds = [];
$byStatus  = [];
$nth       = [];
$adminId   = $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: null;
foreach ($clients as $i => [$first, $last, $status, $city, $job, $concern]) {
    $addr  = $email($first, $last);
    $phone = '+91 98' . str_pad((string) (20431577 + $i * 7919), 8, '0', STR_PAD_LEFT);
    $dob   = (1984 + $i * 2) . '-0' . (1 + $i % 9) . '-1' . ($i % 9);
    $mode  = $i % 3 === 0 ? 'inperson' : 'online';
    $k     = $nth[$status] = ($nth[$status] ?? 0) + 1;

    // Pending clients arrived recently; everyone else came in over the summer.
    $leadAt   = $status === 'pending' ? '2026-09-2' . (1 + $i) . ' 10:15:00' : '2026-07-0' . (1 + $i % 9) . ' 11:30:00';
    $leadTs   = strtotime($leadAt);
    $clientAt = dt($leadTs + 2 * 86400);

    $insertClient->execute([$first, $last, $addr, $phone, $city, $job, $dob, $concern, $status, $mode, $clientAt]);
    $clientId = (int) $db->lastInsertId();
    $clientIds[$i] = $clientId;
    $clientSince[$i] = strtotime($clientAt);
    $byStatus[$status][] = $clientId;

    $insertLead->execute([
        "$first $last", $addr, substr($phone, 4), date('Y-m-d', $leadTs + 7 * 86400), '11:00:00', $mode,
        $leadMessages[$concern], $i % 2 ? 'appointment' : 'home', 'converted', $clientId, $leadAt,
    ]);
    $leadId = (int) $db->lastInsertId();
    $insertLeadNote->execute([$leadId, $adminId,
        "Screened by phone. Fit looks appropriate for $concern work; explained intake, fees and confidentiality before conversion.",
        past($leadTs + 2 * 3600, $now)]);

    $linkAt = $leadTs + 2 * 86400;
    if ($status === 'pending') {
        // Intake not back yet -- that is what pending means.
        if ($k === 1) {
            $insertLink->execute([$leadId, $clientId, token(), 'sent', dt($linkAt + 7 * 86400), null, null, null, 0, dt($linkAt)]);
        } else {
            $insertLink->execute([$leadId, $clientId, token(), 'opened', dt($linkAt + 7 * 86400), past($linkAt + 5 * 3600, $now), null, null, 0, dt($linkAt)]);
        }
        continue;
    }

    if ($status === 'inactive' && $k === 2) {
        // First link lapsed unopened; the resend below is the one they used.
        $insertLink->execute([$leadId, $clientId, token(), 'expired', dt($linkAt - 86400 * 2), null, null, null, 1, dt($linkAt - 86400 * 9)]);
    }
    if ($status === 'completed' && $k === 1) {
        // Started the form, got interrupted, finished it on a resent link.
        $insertLink->execute([$leadId, $clientId, token(), 'filled', dt($linkAt + 7 * 86400), dt($linkAt + 3600), dt($linkAt + 4000), null, 1, dt($linkAt - 86400)]);
    }
    $submittedTs = $status === 'review' ? $now - 2 * 86400 : $linkAt + 86400;
    $insertLink->execute([$leadId, $clientId, token(), 'submitted', dt($submittedTs + 6 * 86400),
        dt($submittedTs - 1800), dt($submittedTs - 1500), dt($submittedTs), 0, dt($submittedTs - 86400)]);
    $linkId = (int) $db->lastInsertId();

    $answers = [
        'first_name' => $first, 'last_name' => $last, 'email' => $addr, 'phone' => $phone,
        'city' => $city, 'occupation' => $job, 'dob' => $dob, 'concern' => $concern,
        'pref_consult' => $mode === 'online' ? 'video' : 'in-person',
        'pref_date' => date('Y-m-d', $submittedTs + 5 * 86400), 'pref_time' => $i % 2 ? 'evening' : 'morning',
        'consent_given' => '1',
    ];
    foreach ($qCols as $n => $col) { $answers[$col] = (string) (($n * 7 + $i * 3) % 4); } // 0-3 scale

    $row = [];
    foreach (array_slice($piCols, 0, 11 + count($qCols)) as $col) { $row[] = $answers[$col]; }
    $insertIntake->execute(array_merge($row, [$linkId, $clientId, 1, 1, dt($submittedTs), 1, dt($submittedTs)]));
    saveClientIntakeData($db, $clientId, $answers, 1);
}

// Leads that never became clients.
$prospectLeadIds = [];
foreach ($prospects as $p => [$first, $last, $status, $source, $message]) {
    $at = '2026-09-' . (14 + $p * 3) . ' 16:40:00';
    $insertLead->execute([
        "$first $last", $email($first, $last), '98' . (76543210 - $p * 1111), '2026-10-0' . (5 + $p), '18:00:00',
        $p % 2 ? 'online' : 'inperson', $message, $source, $status, null, $at,
    ]);
    $leadId = (int) $db->lastInsertId();
    $prospectLeadIds[] = $leadId;
    $insertLeadNote->execute([$leadId, $adminId, [
        'New enquiry awaiting first review; check availability and preferred mode before responding.',
        'Sent a warm reply with available evening slots and asked for consent to share intake link.',
        'Accepted as a fit. Intake link opened, but the form has not come back yet.',
        'Outside scope for therapy-only practice; replied with medication/psychiatry referral guidance.',
    ][$p], dt(strtotime($at) + 45 * 60)]);
    if ($status === 'confirmed') { // intake link sent, opened, not started
        $insertLink->execute([$leadId, null, token(), 'opened', dt(strtotime($at) + 8 * 86400),
            dt(strtotime($at) + 86400 + 3600), null, null, 0, dt(strtotime($at) + 86400)]);
    }
}

// ---------------------------------------------------------------------
// 3. Sessions -- status decided by where the slot falls relative to now
// ---------------------------------------------------------------------

const PRACTICE_ROOM = 'https://meet.jit.si/RewireWithKajalPractice';

$pastCycle   = ['completed', 'completed', 'no-show', 'completed', 'cancelled', 'rejected'];
$futureCycle = ['confirmed', 'pending', 'confirmed', 'cancelled', 'rejected', 'pending'];

$sessionNotes = [
    'completed' => [
        'Reviewed mood and anxiety ratings since last appointment. Client linked spikes to presentation deadlines and agreed to track anticipatory thoughts before meetings.',
        'Practised box breathing and 5-4-3-2-1 grounding in session. Homework: 10 minutes daily plus a trigger log with intensity before and after.',
        'Explored a conflict at work and rehearsed a boundary-setting conversation. Client reported relief after naming what they can and cannot control.',
        'Reviewed sleep diary. Agreed fixed wake time, wind-down routine and no phone in bed for one week before changing anything else.',
        'Mapped grief waves across the week and normalised the non-linear pattern. Client chose one supportive person to contact before the next session.',
        'Checked risk and protective factors. No current self-harm intent reported; crisis contacts reviewed and copied into the safety plan.',
    ],
    'no-show'   => [
        'Client did not attend and gave no notice. Follow-up message sent with a check-in and rebooking options.',
        'Waited 15 minutes in the online room. Logged as no-show; fee policy to be discussed before next booking.',
    ],
    'rejected'  => [
        'Requested slot clashed with another booking; alternative times offered for later in the week.',
        'Request declined because the client asked for a time outside working hours. Sent two available options.',
    ],
    'confirmed' => [
        'Follow-up session confirmed: review homework, mood tracking and any barriers to practice.',
        'First full therapy session confirmed after intake review; agenda is history, goals and safety planning.',
        null,
    ],
    'pending'   => [
        'Booking request received through the client portal; waiting for client to accept or choose another slot.',
        'Therapist proposed this slot after intake review. Confirmation link sent.',
        null,
    ],
    'cancelled' => [
        'Cancelled with notice; reschedule options sent.',
        null,
    ],
];
$cancelReasons = [
    'Client had a family commitment and asked to move the appointment.',
    'Client unwell; will rebook once recovered.',
    'Therapist unavailable -- session moved.',
];

$rows = [];
$count = [];
$addSession = function (int $client, int $start, string $type, string $status, ?int $series = null)
    use (&$rows, &$count, $now, $sessionNotes, $cancelReasons) {
    $isPast = $start < $now;
    $valid  = $isPast ? ['completed', 'no-show', 'cancelled', 'rejected'] : ['pending', 'confirmed', 'cancelled', 'rejected'];
    if (!in_array($status, $valid, true)) {
        throw new LogicException("$status is impossible for a session at " . dt($start));
    }
    $n = $count[$status] = ($count[$status] ?? 0) + 1;

    $created   = past($start - 10 * 86400, $now);
    $token     = null;
    $responded = null;
    if ($status !== 'cancelled' && ($status !== 'pending' || $n % 2)) {
        $token = token(); // booking link went out; half the pending ones not yet
    }
    if ($status !== 'pending' && $token) {
        $responded = past($start - 3 * 86400, $now); // never in the future
    }
    // Reminders only go to sessions that were on, and only once their time came.
    $remindable = in_array($status, ['confirmed', 'completed', 'no-show'], true);
    $rem24 = $remindable && $start - 86400 <= $now ? 1 : 0;
    $rem30 = $remindable && $start - 1800  <= $now ? 1 : 0;

    $notes = $sessionNotes[$status][$n % count($sessionNotes[$status])];
    $link  = $type === 'online' && $n % 5 !== 0 ? PRACTICE_ROOM : null; // an online one now and then without a link yet

    $rows[] = [
        $client, dt($start), dt($start + 3600), $type, $status, $token, $responded, $link, $series,
        $status === 'cancelled' ? $cancelReasons[$n % count($cancelReasons)] : null,
        $n % 7 === 0 ? 1 : 0, $rem24, $rem30, $notes, $created,
    ];
};

$months = ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'];
$times  = ['09:00', '11:00', '13:00', '15:00', '17:00', '19:00'];

// 3a. Active clients: 12 slots a month, types alternating, status from
// whichever cycle fits the slot. The monthly offset rotates which type
// meets which status, so every status meets both types.
foreach ($months as $mi => $month) {
    for ($i = 0; $i < 12; $i++) {
        $start  = strtotime(sprintf('%s-%02d %s:00', $month, 2 + $i * 2, $times[$i % 6]));
        $cycle  = $start < $now ? $pastCycle : $futureCycle;
        $addSession($byStatus['active'][$i % 2], $start, $i % 2 ? 'inperson' : 'online', $cycle[($i + $mi) % 6]);
    }
    // 3b. First Saturday and Sunday of the month, too.
    foreach (['saturday' => '10:00', 'sunday' => '16:00'] as $day => $time) {
        $start = strtotime("first $day of " . date('F Y', strtotime("$month-01")) . " $time");
        $addSession($byStatus['active'][$day === 'sunday' ? 1 : 0], $start, $day === 'sunday' ? 'online' : 'inperson',
            $start < $now ? 'completed' : ($day === 'sunday' ? 'pending' : 'confirmed'));
    }
}

// 3c. A dense, relative-to-now matrix that guarantees each meaningful
// status/type/time variant even when the database clock moves.
$pastVariants = [
    ['completed', 'online'], ['completed', 'inperson'],
    ['no-show', 'online'], ['no-show', 'inperson'],
    ['cancelled', 'online'], ['cancelled', 'inperson'],
    ['rejected', 'online'], ['rejected', 'inperson'],
];
foreach ($pastVariants as $v => [$status, $type]) {
    $addSession($byStatus['active'][$v % 2], $now - (20 - $v) * 3600, $type, $status);
}
$futureVariants = [
    ['pending', 'online'], ['pending', 'inperson'],
    ['confirmed', 'online'], ['confirmed', 'inperson'],
    ['cancelled', 'online'], ['cancelled', 'inperson'],
    ['rejected', 'online'], ['rejected', 'inperson'],
];
foreach ($futureVariants as $v => [$status, $type]) {
    $addSession($byStatus['active'][$v % 2], $now + (4 + $v) * 86400 + 2 * 3600, $type, $status);
}

// 3d. Pending / review clients are not bookable yet: one booking request each.
foreach (array_merge($byStatus['pending'], $byStatus['review']) as $k => $client) {
    $addSession($client, strtotime('2026-10-' . sprintf('%02d', 4 + $k * 7) . ' 12:00:00'), $k % 2 ? 'inperson' : 'online', 'pending');
}

// 3e. Weekly recurring series. Past occurrences take the $past pattern,
// future ones the $future pattern, so a series spanning now switches over.
$series = [
    // id, client, first start, weeks, type, past pattern, future pattern
    [9001, $byStatus['inactive'][0],  '2026-08-05 10:00', 4, 'online',   ['completed', 'completed', 'no-show', 'cancelled'], []],
    [9002, $byStatus['inactive'][1],  '2026-08-12 14:00', 4, 'inperson', ['completed', 'completed', 'completed', 'cancelled'], []],
    [9003, $byStatus['completed'][0], '2026-08-03 10:00', 6, 'online',   ['completed'], []],
    [9004, $byStatus['completed'][1], '2026-08-20 14:00', 5, 'inperson', ['completed', 'completed', 'no-show', 'completed', 'completed'], []],
    [9005, $byStatus['active'][1],    '2026-09-09 18:00', 8, 'online',   ['completed', 'completed', 'no-show'], ['confirmed', 'confirmed', 'pending']],
    [9006, $byStatus['active'][0],    '2026-11-03 08:00', 6, 'online',   ['completed'], ['confirmed', 'pending']],
];
foreach ($series as [$id, $client, $first, $weeks, $type, $pastPat, $futurePat]) {
    $p = $f = 0;
    for ($w = 0; $w < $weeks; $w++) {
        $start = strtotime($first) + $w * 7 * 86400;
        $status = $start < $now
            ? $pastPat[min($p++, count($pastPat) - 1)]
            : $futurePat[min($f++, count($futurePat) - 1)];
        $addSession($client, $start, $type, $status, $id);
    }
}

$insertSession = $db->prepare(
    'INSERT INTO sessions (client_id, start_time, end_time, session_type, status, response_token, responded_at,
        video_link, recurring_series_id, cancelled_reason, rescheduled_count, reminder_sent, reminder30_sent, notes, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($rows as $r) { $insertSession->execute($r); }

// ---------------------------------------------------------------------
// 4. Fee transactions, notes and documents, as a therapist would have them
// ---------------------------------------------------------------------

$adminNote = [
    'pending'   => 'Initial phone consultation done. Intake form sent with a reminder scheduled if it is not opened by tomorrow evening. Client asked about online availability and fees.',
    'review'    => 'Intake form received. Review PHQ/GAD scores, presenting concern and risk answers before offering a first appointment.',
    'active'    => 'Intake reviewed. Agreed weekly sessions; fee and cancellation policy explained. Client prefers structured homework and evening slots where possible.',
    'inactive'  => 'Client has paused therapy because of work travel. Open to resuming; send a low-pressure check-in next month and offer updated availability.',
    'completed' => 'Treatment goals met. Closing summary shared; client knows they can return for booster sessions if symptoms recur.',
];
$statusReviewNote = [
    'pending'   => 'Admin task: verify that the intake link is still active and resend if no submission arrives within 48 hours.',
    'review'    => 'Clinical review queue: check sleep, appetite, functioning and support system before finalising the treatment plan.',
    'active'    => 'Care plan: continue weekly CBT-informed work; track attendance, homework completion and symptom ratings monthly.',
    'inactive'  => 'Pause note: client was stable at last contact, with no acute risk. Do not discharge until the planned check-in has happened.',
    'completed' => 'Closure note: relapse-prevention plan documented; client has warning signs, coping steps and re-entry instructions.',
];
$docs = [
    'review'    => ['Signed consent form.pdf'],
    'active'    => ['Signed consent form.pdf', 'Safety plan.pdf'],
    'inactive'  => ['Signed consent form.pdf'],
    'completed' => ['Signed consent form.pdf', 'Closing summary.pdf'],
];
$insertFee = $db->prepare('INSERT INTO client_fees (client_id, session_id, amount, description, status, method, reference, fee_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insertNote = $db->prepare('INSERT INTO client_notes (client_id, note_type, user_id, note_kind, session_id, content, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insertDoc  = $db->prepare('INSERT INTO client_documents (client_id, original_name, stored_name, mime_type, size_bytes, uploaded_by, uploaded_at, shared_with_client, client_uploaded) VALUES (?, ?, ?, "application/pdf", ?, ?, ?, ?, 0)');
$firstDone  = $db->prepare('SELECT id, start_time FROM sessions WHERE client_id = ? AND status = "completed" ORDER BY start_time LIMIT 1');
$feeDone    = $db->prepare('SELECT id, start_time, session_type FROM sessions WHERE client_id = ? AND status = "completed" ORDER BY start_time LIMIT 3');
$feeFuture  = $db->prepare('SELECT id, start_time, session_type FROM sessions WHERE client_id = ? AND status IN ("pending","confirmed") AND start_time >= NOW() ORDER BY start_time LIMIT 1');
$feeNoShow  = $db->prepare('SELECT id, start_time FROM sessions WHERE client_id = ? AND status = "no-show" ORDER BY start_time DESC LIMIT 1');
$methods    = ['upi', 'bank_transfer', 'cash', 'other'];

foreach ($clients as $i => [$first, $last, $status, , , $concern]) {
    $clientId = $clientIds[$i];
    $insertNote->execute([$clientId, 'general', $adminId, 'administrative', null, $adminNote[$status], past($clientSince[$i] + 3600, $now)]);
    $insertNote->execute([$clientId, 'general', $adminId, 'administrative', null, $statusReviewNote[$status], past($clientSince[$i] + 2 * 3600, $now)]);

    $feeDone->execute([$clientId]);
    foreach ($feeDone->fetchAll() as $p => $s) {
        $method = $methods[($i + $p) % count($methods)];
        $amount = $s['session_type'] === 'online' ? '2500.00' : '3000.00';
        $insertFee->execute([$clientId, (int) $s['id'], $amount,
            ($s['session_type'] === 'online' ? 'Online' : 'In-person') . ' therapy session fee',
            'paid', $method, strtoupper($method) . '-SEED-' . $clientId . '-' . ($p + 1), date('Y-m-d', strtotime($s['start_time']))]);
    }
    $feeFuture->execute([$clientId]);
    if ($s = $feeFuture->fetch()) {
        $insertFee->execute([$clientId, (int) $s['id'], $s['session_type'] === 'online' ? '2500.00' : '3000.00',
            'Upcoming therapy session fee', 'pending', 'upi', '', date('Y-m-d', strtotime($s['start_time']))]);
    }
    $feeNoShow->execute([$clientId]);
    if ($s = $feeNoShow->fetch()) {
        $insertFee->execute([$clientId, (int) $s['id'], '1500.00',
            'No-show fee waived after clinical follow-up', 'waived', 'other', 'WAIVED-SEED-' . $clientId, date('Y-m-d', strtotime($s['start_time']))]);
    }
    if (in_array($status, ['pending', 'review'], true)) {
        $insertFee->execute([$clientId, null, '2500.00', 'First-session fee estimate pending booking', 'pending', 'upi', '', date('Y-m-d', $clientSince[$i])]);
    }

    $firstDone->execute([$clientId]);
    if ($s = $firstDone->fetch()) {
        $insertNote->execute([$clientId, 'session', $adminId, 'session', (int) $s['id'],
            "First session. Presenting concern: $concern. Built rapport, took history, agreed three goals for the next six sessions, and checked consent for between-session email reminders.",
            dt(strtotime($s['start_time']) + 7200)]);
    }
    foreach ($docs[$status] ?? [] as $d => $name) {
        // stored_name is generated, like an upload -- never a readable filename.
        $insertDoc->execute([$clientId, $name, bin2hex(random_bytes(16)) . '.pdf', 80000 + $i * 5120 + $d * 999, $adminId,
            // Consent form once intake is in; anything else a few weeks later.
            past(($status === 'review' ? $now - 86400 : $clientSince[$i] + 5 * 86400) + $d * 21 * 86400, $now), $d === 0 ? 1 : 0]);
    }
}

// ---------------------------------------------------------------------
// 5. Report
// ---------------------------------------------------------------------

$ids = implode(',', $clientIds);
$one = fn(string $sql) => $db->query($sql)->fetchColumn();
$leadIds = implode(',', array_merge($prospectLeadIds, array_map('intval', $db->query("SELECT id FROM leads WHERE client_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN))));

echo "Database now: " . dt($now) . "\n";
echo "clients: " . count($clientIds) . "\n";
echo "leads: " . $one("SELECT COUNT(*) FROM leads WHERE id IN ($leadIds)") . "\n";
echo "intake_links: " . $one("SELECT COUNT(*) FROM intake_links WHERE lead_id IN ($leadIds)") . "\n";
echo "lead_notes: " . $one("SELECT COUNT(*) FROM lead_notes WHERE lead_id IN ($leadIds)") . "\n";
echo "patient-intake: " . $one("SELECT COUNT(*) FROM `patient-intake` WHERE client_id IN ($ids)") . "\n";
echo "sessions: " . count($rows) . "\n";
foreach ($db->query("SELECT status, SUM(start_time < NOW()) past, SUM(start_time >= NOW()) future FROM sessions WHERE client_id IN ($ids) GROUP BY status") as $r) {
    printf("  %-10s past %3d  future %3d\n", $r['status'], $r['past'], $r['future']);
}
echo "client_fees: " . $one("SELECT COUNT(*) FROM client_fees WHERE client_id IN ($ids)") . "\n";
echo "client_notes: " . $one("SELECT COUNT(*) FROM client_notes WHERE client_id IN ($ids)") . "\n";
echo "client_documents: " . $one("SELECT COUNT(*) FROM client_documents WHERE client_id IN ($ids)") . "\n";
