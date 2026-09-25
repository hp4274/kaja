<?php
/**
 * Public API: Intake form writer (POST).
 *
 * Everything below the transaction boundary commits together:
 *   1. re-read the link FOR UPDATE and re-check its state
 *   2. INSERT the questionnaire
 *   3. create or fill the client, flip it to 'review'
 *   4. mark the link submitted
 *   5. move the lead to 'converted'
 * then COMMIT.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

session_start();

require_once dirname(__DIR__) . '/db-config.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/intake-token.php';
require_once dirname(__DIR__) . '/includes/intake-status.php';
require_once dirname(__DIR__) . '/includes/intake-repo.php';
require_once dirname(__DIR__) . '/includes/intake-schema.php';
require_once dirname(__DIR__) . '/includes/intake-data.php';
require_once dirname(__DIR__) . '/includes/mail-queue.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

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
// 2. Validate, against the schema for this form version.
// ---------------------------------------------------------------------------

$formVersion = $link ? (int) $link['form_version'] : max(1, getSettingInt('intake_form_version'));
$schemaFields = intakeSchemaFields($formVersion);

$values = [];
foreach ($schemaFields as $id => $field) {
    $values[$id] = isset($_POST[$id]) ? trim((string) $_POST[$id]) : '';
}

foreach (intakeRequiredFields($formVersion, $values) as $id) {
    if ($values[$id] === '') {
        intakeFail($schemaFields[$id]['label'] . ' is required.');
    }
}

if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    intakeFail('Please provide a valid email address.');
}

$values['consent_version'] = INTAKE_CONSENT_VERSION;

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

    // 3.2 The archive row.
    $archive = [];
    foreach (intakeSchemaFields(1) as $id => $field) {
        $archive[$id] = isset($values[$id]) ? $values[$id] : '';
    }
    $archive['consent_given']   = $values['consent_given'] !== '' ? 1 : 0;
    $archive['consent_version'] = $values['consent_version'];
    $archive['form_version']    = $formVersion;

    if ($link) {
        $archive['intake_link_id'] = $link['id'];
    }

    $columns = array_keys($archive);
    $columnSql      = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $columns)) . ', `consent_at`';
    $placeholderSql = implode(', ', array_map(function ($c) { return ":{$c}"; }, $columns)) . ', NOW()';

    $params = [];
    foreach ($columns as $c) {
        $params[":{$c}"] = $archive[$c];
    }

    $db->prepare("INSERT INTO `patient-intake` ({$columnSql}) VALUES ({$placeholderSql})")
       ->execute($params);
    $intakeId = (int) $db->lastInsertId();

    // 3.3 Resolve the client.
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
                `patient_intake_id` = :piid, `status` = 'review'
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
            VALUES (:lid, :piid, :fn, :ln, :em, :ph, :ci, :oc, :dob, :co, 'review')
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

    // The canonical answers: encrypted at rest
    saveClientIntakeData($db, $clientId, $values, $formVersion);

    // 3.4 Close the link out.
    if ($link) {
        $db->prepare("
            UPDATE `intake_links` SET
                `status` = 'submitted', `submitted_at` = NOW(),
                `patient_intake_id` = :piid, `client_id` = :cid
            WHERE `id` = :id
        ")->execute([':piid' => $intakeId, ':cid' => $clientId, ':id' => $link['id']]);

        clearIntakeDraft($db, (int) $link['id']);

        // 3.5 Move lead to converted
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

// ---------------------------------------------------------------------------
// 4. Tell the therapist.
// ---------------------------------------------------------------------------

$who      = trim($values['first_name'] . ' ' . $values['last_name']);
$notifyTo = getSetting('practice_email');

if ($notifyTo) {
    $reviewUrl = siteBaseUrl() . 'admin/index.php?page=client-profile&id=' . $clientId;
    $subject   = 'Intake ready for review - ' . $who;
    $body      = $who . " has submitted their intake form.\n\n"
               . "They are held at 'review' and are not bookable until you mark them reviewed:\n"
               . $reviewUrl . "\n";
    if (!sendMail($notifyTo, $subject, $body)) {
        queueFailedMail([
            'to'      => $notifyTo,
            'name'    => $who,
            'url'     => $reviewUrl,
            'expires' => '',
            'kind'    => 'intake_review',
        ], 'mail() returned false');
    }
}

// ---------------------------------------------------------------------------
// 5. Hand the browser somewhere to go.
// ---------------------------------------------------------------------------

if ($adminMode) {
    $redirect = 'admin/index.php?page=client-profile&id=' . $clientId;
} else {
    $_SESSION['intake_done'] = [
        'first_name' => $values['first_name'],
        'email'      => $values['email'],
        'sections'   => count(intakeSchema($formVersion)),
    ];
    $redirect = 'intake-thank-you.php';
}

echo json_encode([
    'success'   => true,
    'message'   => 'Thank you! Your intake information has been received successfully.',
    'client_id' => $clientId,
    'redirect'  => $redirect,
]);
