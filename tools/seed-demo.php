<?php
/**
 * Demo data, for looking at the admin panel with something in it.
 *
 *   php tools/seed-demo.php          report what it would write
 *   php tools/seed-demo.php --write  actually write it
 *   php tools/seed-demo.php --clear  remove everything it wrote
 *
 * Every row it creates is tagged by email domain (@demo.rewire.test), which is
 * what makes --clear possible: it deletes exactly what this script inserted and
 * nothing a person typed. Real data has no reason to carry that domain, and
 * nothing else is matched on.
 *
 * Sessions go through createSession() rather than straight into the table, for
 * the same reason everything else does -- the conflict guard is the one door,
 * and demo data that quietly books two people into one hour is worse than no
 * demo data at all. An occurrence that clashes or lands on a holiday is
 * skipped and counted, not forced.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/session-repo.php';
require_once __DIR__ . '/../includes/holidays.php';

const DEMO_DOMAIN = '@demo.rewire.test';

$write = in_array('--write', $argv, true);
$clear = in_array('--clear', $argv, true);
$db    = getDbConnection();

/** A date relative to today, so the calendar always has something on it. */
function demoDay($offsetDays, $time = '11:00') {
    return date('Y-m-d', strtotime($offsetDays . ' days')) . ' ' . $time . ':00';
}

// -- The people --------------------------------------------------------------
// Ordinary names and everyday presenting concerns: demo data that reads as
// plausible is the only kind worth looking at a layout through.
$leads = [
    ['Ananya Iyer',     'ananya', '9812345601', 'home',        'new',       'Struggling to sleep before big meetings.'],
    ['Rohan Mehta',     'rohan',  '9812345602', 'appointment', 'contacted', 'Looking for weekly sessions, evenings preferred.'],
    ['Priya Nair',      'priya',  '9812345603', 'home',        'confirmed', 'Panic attacks on the commute.'],
    ['Vikram Desai',    'vikram', '9812345604', 'appointment', 'converted', 'Wants to continue work started elsewhere.'],
    ['Sneha Kulkarni',  'sneha',  '9812345605', 'intake',      'converted', 'Post-move loneliness, new city.'],
    ['Arjun Menon',     'arjun',  '9812345606', 'home',        'new',       'Burnout after a long release cycle.'],
    ['Divya Rao',       'divya',  '9812345607', 'appointment', 'contacted', 'Asked about fees and the cancellation policy.'],
    ['Kabir Sharma',    'kabir',  '9812345608', 'home',        'converted', 'Relationship strain since the baby arrived.'],
    ['Meera Pillai',    'meera',  '9812345609', 'intake',      'confirmed', 'Grief after losing a parent.'],
    ['Aditya Verma',    'aditya', '9812345610', 'home',        'rejected',  'Looking for medication rather than therapy.'],
    ['Ishita Banerjee', 'ishita', '9812345611', 'appointment', 'new',       'Exam anxiety, final year.'],
    ['Nikhil Joshi',    'nikhil', '9812345612', 'home',        'rejected',  'Automated marketing message, no real enquiry.'],
    ['Tara Krishnan',   'tara',   '9812345613', 'intake',      'converted', 'Wants help holding boundaries at work.'],
    ['Farhan Qureshi',  'farhan', '9812345614', 'appointment', 'contacted', 'Sleep and irritability since night shifts started.'],
];

// The leads above that became people in treatment, by array index.
$clientsFromLeads = [3, 4, 7, 12];

// Clients who arrived before the lead pipeline existed, or through the door.
$extraClients = [
    ['Lakshmi', 'Subramanian', 'lakshmi', 'Chennai',   'Teacher',       '1988-04-12', 'Anxiety',       'active'],
    ['Yusuf',   'Ansari',      'yusuf',   'Hyderabad', 'Chef',          '1993-11-02', 'Stress',        'active'],
    ['Riya',    'Chatterjee',  'riya',    'Kolkata',   'Designer',      '1996-06-25', 'Self-esteem',   'active'],
    ['Manav',   'Gupta',       'manav',   'Delhi',     'Sales manager', '1985-01-30', 'Burnout',       'review'],
    ['Aisha',   'Khan',        'aisha',   'Mumbai',    'Doctor',        '1991-09-17', 'Sleep',         'pending'],
    ['Gautam',  'Reddy',       'gautam',  'Bengaluru', 'Engineer',      '1990-03-08', 'Relationships', 'completed'],
];

$concerns = ['Anxiety', 'Stress', 'Relationships', 'Grief', 'Burnout'];
$cities   = ['Mumbai', 'Pune', 'Bengaluru', 'Chennai', 'Delhi'];

// -- --clear -----------------------------------------------------------------
if ($clear) {
    // Clients cascade to their sessions, notes, fees and documents; leads
    // cascade to their notes and intake links. Nothing outside the demo domain
    // is touched.
    $gone = [];
    foreach (['clients', 'leads'] as $table) {
        $stmt = $db->prepare("DELETE FROM `$table` WHERE `email` LIKE :d");
        $stmt->execute([':d' => '%' . DEMO_DOMAIN]);
        $gone[$table] = $stmt->rowCount();
    }

    $stmt = $db->query("DELETE FROM `activity_log` WHERE `description` LIKE '%[demo]%'");
    $gone['activity_log'] = $stmt->rowCount();

    $stmt = $db->query("DELETE FROM `holidays` WHERE `reason` LIKE '%[demo]%'");
    $gone['holidays'] = $stmt->rowCount();

    foreach ($gone as $t => $n) { echo "  - {$n} from `{$t}`\n"; }
    echo "Demo data removed.\n";
    exit(0);
}

if (!$write) {
    echo "Dry run. Would create:\n";
    echo '  ' . count($leads) . " leads, one in every status the board shows\n";
    echo '  ' . (count($clientsFromLeads) + count($extraClients)) . " clients, four of them converted from those leads\n";
    echo "  up to 24 sessions across the last and next three weeks\n";
    echo "  session notes, payments, lead notes and two holidays\n\n";
    echo "Re-run with --write to insert, or --clear to remove it again.\n";
    exit(0);
}

$made    = ['leads' => 0, 'clients' => 0, 'sessions' => 0, 'notes' => 0, 'fees' => 0, 'holidays' => 0];
$skipped = 0;

// -- Leads -------------------------------------------------------------------
$leadStmt = $db->prepare('
    INSERT INTO `leads` (`name`,`email`,`country_code`,`phone`,`preferred_date`,`preferred_time`,
                         `preference`,`message`,`source`,`status`,`created_at`)
    VALUES (:n,:e,"+91",:p,:pd,:pt,:pref,:m,:s,:st,:c)
');
$leadIds = [];
foreach ($leads as $i => $l) {
    list($name, $handle, $phone, $source, $status, $message) = $l;
    $leadStmt->execute([
        ':n'    => $name,
        ':e'    => $handle . DEMO_DOMAIN,
        ':p'    => $phone,
        ':pd'   => date('Y-m-d', strtotime(($i - 6) . ' days')),
        ':pt'   => ['09:00:00', '11:00:00', '13:00:00', '15:00:00', '17:00:00'][$i % 5],
        ':pref' => ($i % 2) ? 'online' : 'inperson',
        ':m'    => $message,
        ':s'    => $source,
        ':st'   => $status,
        ':c'    => date('Y-m-d H:i:s', strtotime('-' . (20 - $i) . ' days')),
    ]);
    $leadIds[$i] = (int) $db->lastInsertId();
    $made['leads']++;
}

// -- Clients -----------------------------------------------------------------
$clientStmt = $db->prepare('
    INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`phone`,`city`,
                           `occupation`,`dob`,`concern`,`status`,`created_at`)
    VALUES (:l,:f,:ln,:e,:p,:city,:occ,:dob,:con,:st,:c)
');
$clientIds = [];

foreach ($clientsFromLeads as $n => $idx) {
    $parts = explode(' ', $leads[$idx][0], 2);
    $clientStmt->execute([
        ':l'    => $leadIds[$idx],
        ':f'    => $parts[0],
        ':ln'   => isset($parts[1]) ? $parts[1] : '',
        ':e'    => $leads[$idx][1] . DEMO_DOMAIN,
        ':p'    => $leads[$idx][2],
        ':city' => $cities[$n % count($cities)],
        ':occ'  => ['Analyst', 'Teacher', 'Founder', 'Nurse'][$n % 4],
        ':dob'  => date('Y-m-d', strtotime('-' . (26 + $n * 3) . ' years')),
        ':con'  => $concerns[$n % count($concerns)],
        ':st'   => 'active',
        ':c'    => date('Y-m-d H:i:s', strtotime('-' . (18 - $n) . ' days')),
    ]);
    $clientId    = (int) $db->lastInsertId();
    $clientIds[] = $clientId;
    $made['clients']++;

    // The lead that became this client points at it, which is what the lead
    // detail drawer follows to say "already a client".
    $db->prepare('UPDATE `leads` SET `client_id` = :c WHERE `id` = :l')
       ->execute([':c' => $clientId, ':l' => $leadIds[$idx]]);
}

foreach ($extraClients as $n => $c) {
    list($first, $last, $handle, $city, $occupation, $dob, $concern, $status) = $c;
    $clientStmt->execute([
        ':l'    => null,
        ':f'    => $first,
        ':ln'   => $last,
        ':e'    => $handle . DEMO_DOMAIN,
        ':p'    => '98123457' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        ':city' => $city,
        ':occ'  => $occupation,
        ':dob'  => $dob,
        ':con'  => $concern,
        ':st'   => $status,
        ':c'    => date('Y-m-d H:i:s', strtotime('-' . (40 - $n * 4) . ' days')),
    ]);
    // Only bookable clients get sessions below; the held ones are here exactly
    // so the client picker has something to explain.
    if ($status === 'active') {
        $clientIds[] = (int) $db->lastInsertId();
    }
    $made['clients']++;
}

// -- Holidays ----------------------------------------------------------------
// Marked before the sessions are booked, so the skip path is exercised rather
// than described.
markHolidays($db, [date('Y-m-d', strtotime('+9 days'))],  '[demo] Public holiday');
markHolidays($db, [date('Y-m-d', strtotime('+10 days'))], '[demo] Practice closed');
$made['holidays'] = 2;

// -- Sessions ----------------------------------------------------------------
// Three weeks back and three forward, so the calendar, the dashboard counters
// and the reports page all have something to show.
$slots = ['09:00', '11:00', '13:00', '15:00', '17:00'];
for ($i = 0; $i < 24; $i++) {
    $offset   = -18 + $i * 2;                 // every other day
    $clientId = $clientIds[$i % count($clientIds)];
    $start    = demoDay($offset, $slots[$i % count($slots)]);

    try {
        $id = createSession($db, $clientId, $start, 60, ($i % 3) ? 'online' : 'inperson');
    } catch (Throwable $e) {
        $skipped++;                            // clash or holiday: both fine here
        continue;
    }
    $made['sessions']++;

    // A past session has an outcome; a future one is still ahead of everybody.
    if ($offset >= 0) {
        if ($i % 2 === 0) {
            setSessionStatus($db, $id, 'confirmed');
        }
        continue;
    }

    if ($i % 7 === 3) {
        cancelSession($db, $id, 'Client asked to move it');
        continue;
    }
    if ($i % 7 === 5) {
        setSessionStatus($db, $id, 'no-show');
        continue;
    }

    setSessionStatus($db, $id, 'completed');

    $db->prepare('INSERT INTO `client_notes` (`client_id`,`session_id`,`note_type`,`note_kind`,`content`)
                  VALUES (:c,:s,"session","session",:n)')
       ->execute([
           ':c' => $clientId,
           ':s' => $id,
           ':n' => '[demo] Worked on grounding techniques. Homework: three-minute breathing space, daily.',
       ]);
    $made['notes']++;

    $db->prepare('INSERT INTO `client_fees` (`client_id`,`session_id`,`amount`,`description`,`status`,`method`,`fee_date`)
                  VALUES (:c,:s,:a,"[demo] Session fee",:st,:m,:d)')
       ->execute([
           ':c'  => $clientId,
           ':s'  => $id,
           ':a'  => 1500.00,
           ':st' => ($i % 4 === 1) ? 'pending' : 'paid',
           ':m'  => ($i % 3) ? 'upi' : 'cash',
           ':d'  => date('Y-m-d', strtotime($start)),
       ]);
    $made['fees']++;
}

// -- Notes on leads, and a trail on the dashboard ----------------------------
$noteStmt = $db->prepare('INSERT INTO `lead_notes` (`lead_id`,`user_id`,`content`) VALUES (:l,NULL,:c)');
foreach ([1, 6, 9, 13] as $idx) {
    $noteStmt->execute([':l' => $leadIds[$idx], ':c' => '[demo] Called, no answer. Trying again tomorrow.']);
    $made['notes']++;
}

$db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`)
              VALUES ('demo_seeded',:d,'system',NULL)")
   ->execute([':d' => '[demo] Demo data seeded: ' . $made['leads'] . ' leads, '
                    . $made['clients'] . ' clients, ' . $made['sessions'] . ' sessions']);

foreach ($made as $what => $n) { echo "  + {$n} {$what}\n"; }
if ($skipped) { echo "  . {$skipped} session(s) skipped (clash or holiday)\n"; }
echo "\nDone. Remove it all again with: php tools/seed-demo.php --clear\n";
