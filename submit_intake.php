<?php
/**
 * Intake form writer (POST).
 *
 * Everything below the transaction boundary commits together. The failure this
 * guards against: the patient row lands but the link is never marked submitted,
 * leaving a filled client attached to a token that still reads "open" forever
 * and a dashboard that is permanently wrong.
 *
 * Order inside the transaction:
 *   1. re-read the link FOR UPDATE and re-check its state (a link that was
 *      valid at render time may have been used or expired since)
 *   2. INSERT the questionnaire
 *   3. create or fill the client, flip it to 'active'
 *   4. mark the link submitted
 *   5. move the lead to 'converted'
 * then COMMIT.
 *
 * Step 1 taking a row lock is what makes a double submit safe: the second
 * request blocks, then finds status='submitted' and is rejected.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

session_start();

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/intake-token.php';
require_once __DIR__ . '/includes/intake-status.php';
require_once __DIR__ . '/includes/intake-repo.php';

function intakeFail($error, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$db = getDbConnection();

// ---------------------------------------------------------------------------
// 1. Establish the caller: token, or authenticated admin. Never both-optional.
// ---------------------------------------------------------------------------

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
// admin_mode arrives from the client, so it only ever *narrows* a real session.
$isAdmin   = !empty($_SESSION['logged_in']);
$adminMode = ($token === '' && $isAdmin);

if ($token === '' && !$isAdmin) {
    intakeFail('This form must be opened from your personal intake link.', 403);
}

$link = null;
if ($token !== '') {
    $link = findIntakeLink($db, $token);
    $state = intakeLinkState($link);

    if ($state === 'not_found') {
        intakeFail('We could not find that intake link. Please use the link from your email.', 403);
    }
    if ($state === 'submitted') {
        intakeFail('This intake form has already been submitted. There is nothing more to fill in.', 409);
    }
    if ($state === 'expired') {
        markIntakeLinkExpired($db, $link['id']);
        intakeFail('This intake link has expired. Please contact us and we will send you a new one.', 410);
    }
}

// ---------------------------------------------------------------------------
// 2. Validate. Same field contract the form has always posted.
// ---------------------------------------------------------------------------

$personal = [
    'first_name' => 'First name',
    'last_name'  => 'Last name',
    'email'      => 'Email',
    'phone'      => 'Phone',
    'city'       => 'City',
    'occupation' => 'Occupation',
    'dob'        => 'Date of birth',
    'concern'    => 'Primary concern',
];
$prefs = [
    'pref_consult' => 'Consultation preference',
    'pref_date'    => 'Preferred date',
    'pref_time'    => 'Preferred time',
];

$values = [];

foreach ($personal + $prefs as $field => $label) {
    $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    if ($value === '') {
        intakeFail("{$label} is required.");
    }
    $values[$field] = $value;
}

if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    intakeFail('Please provide a valid email address.');
}

foreach ([1, 2] as $set) {
    for ($i = 1; $i <= 18; $i++) {
        $field = "q{$set}_{$i}";
        $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';
        if ($value === '') {
            intakeFail("Questionnaire {$set}, question {$i} must be answered.");
        }
        $values[$field] = $value;
    }
}

// Consent is checked here as well as in the markup. A required attribute is a
// convenience for the person filling the form, not a control: anything can
// POST straight to this endpoint.
if (empty($_POST['consent_given'])) {
    intakeFail('Please confirm the consent statement before submitting.');
}
$values['consent_given']   = 1;
$values['consent_version'] = INTAKE_CONSENT_VERSION;

// Version recorded against the answers: pinned from the link when there is one,
// otherwise whatever is live now (admin filling the form by hand).
$formVersion = $link ? (int) $link['form_version'] : max(1, getSettingInt('intake_form_version'));

// ---------------------------------------------------------------------------
// 3. Write. One transaction.
// ---------------------------------------------------------------------------

try {
    $db->beginTransaction();

    // 3.1 Re-check the link under a row lock.
    if ($link) {
        $locked = findIntakeLink($db, $token, true);
        $lockedState = intakeLinkState($locked);
        if ($lockedState !== 'valid') {
            $db->rollBack();
            if ($lockedState === 'submitted') {
                intakeFail('This intake form has already been submitted.', 409);
            }
            intakeFail('This intake link is no longer valid. Please contact us for a new one.', 410);
        }
        $link = $locked;
    }

    // 3.2 The questionnaire itself.
    $columns = array_keys($values);
    $columns[] = 'form_version';
    $values['form_version'] = $formVersion;

    if ($link) {
        $columns[] = 'intake_link_id';
        $values['intake_link_id'] = $link['id'];
    }

    // consent_at is stamped by MySQL rather than PHP. The two clocks disagree
    // on this install, and every other timestamp on the row already comes from
    // the database.
    $columnSql      = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $columns)) . ', `consent_at`';
    $placeholderSql = implode(', ', array_map(function ($c) { return ":{$c}"; }, $columns)) . ', NOW()';

    $params = [];
    foreach ($columns as $c) {
        $params[":{$c}"] = $values[$c];
    }

    $db->prepare("INSERT INTO `patient-intake` ({$columnSql}) VALUES ({$placeholderSql})")
       ->execute($params);
    $intakeId = (int) $db->lastInsertId();

    // 3.3 Resolve the client.
    // Pre-existing from lead-confirm, chosen by an admin, or brand new.
    $clientId = null;
    if ($link && !empty($link['client_id'])) {
        $clientId = (int) $link['client_id'];
    } elseif ($adminMode && !empty($_POST['client_id'])) {
        $clientId = (int) $_POST['client_id'];
    }

    if ($clientId) {
        $db->prepare("
            UPDATE `clients` SET
                `first_name` = :fn, `last_name` = :ln, `email` = :em, `phone` = :ph,
                `city` = :ci, `occupation` = :oc, `dob` = :dob, `concern` = :co,
                `patient_intake_id` = :piid, `status` = 'active'
            WHERE `id` = :id
        ")->execute([
            ':fn' => $values['first_name'], ':ln' => $values['last_name'],
            ':em' => $values['email'],      ':ph' => $values['phone'],
            ':ci' => $values['city'],       ':oc' => $values['occupation'],
            ':dob' => $values['dob'],       ':co' => $values['concern'],
            ':piid' => $intakeId,           ':id' => $clientId,
        ]);
    } else {
        $db->prepare("
            INSERT INTO `clients`
                (`lead_id`, `patient_intake_id`, `first_name`, `last_name`, `email`,
                 `phone`, `city`, `occupation`, `dob`, `concern`, `status`)
            VALUES (:lid, :piid, :fn, :ln, :em, :ph, :ci, :oc, :dob, :co, 'active')
        ")->execute([
            ':lid' => $link ? $link['lead_id'] : null,
            ':piid' => $intakeId,
            ':fn' => $values['first_name'], ':ln' => $values['last_name'],
            ':em' => $values['email'],      ':ph' => $values['phone'],
            ':ci' => $values['city'],       ':oc' => $values['occupation'],
            ':dob' => $values['dob'],       ':co' => $values['concern'],
        ]);
        $clientId = (int) $db->lastInsertId();
    }

    $db->prepare("UPDATE `patient-intake` SET `client_id` = :cid WHERE `id` = :id")
       ->execute([':cid' => $clientId, ':id' => $intakeId]);

    // 3.4 Close the link out. Same commit as the client write, by design.
    if ($link) {
        $db->prepare("
            UPDATE `intake_links` SET
                `status` = 'submitted', `submitted_at` = NOW(),
                `patient_intake_id` = :piid, `client_id` = :cid
            WHERE `id` = :id
        ")->execute([':piid' => $intakeId, ':cid' => $clientId, ':id' => $link['id']]);

        // The draft was scaffolding for the answers that just landed. Keeping
        // it would leave a second, staler copy of the same personal data.
        clearIntakeDraft($db, (int) $link['id']);

        // 3.5 The lead has now become a client.
        $db->prepare("UPDATE `leads` SET `status` = 'converted', `client_id` = :cid WHERE `id` = :lid")
           ->execute([':cid' => $clientId, ':lid' => $link['lead_id']]);
    }

    $who = $values['first_name'] . ' ' . $values['last_name'];
    $how = $adminMode ? 'entered by admin' : 'submitted via intake link';
    $db->prepare("
        INSERT INTO `activity_log` (`action`, `description`, `reference_type`, `reference_id`)
        VALUES ('intake_submitted', :d, 'client', :rid)
    ")->execute([':d' => "Intake form for {$who} {$how}", ':rid' => $clientId]);

    $db->commit();

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[submit_intake] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'We could not save your responses just now. Please try again in a moment.',
    ]);
    exit;
}

echo json_encode([
    'success'   => true,
    'message'   => 'Thank you! Your intake information has been received successfully.',
    'client_id' => $clientId,
]);
