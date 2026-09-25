<?php
/**
 * Token-gated intake form renderer (GET).
 *
 * Serves patient-intake-form.html verbatim, with two in-memory rewrites:
 *   1. a hidden token field appended after the existing form_type input
 *   2. the fetch() target repointed at submit_intake.php
 *
 * The .html file on disk is never modified, so the UI stays byte-identical.
 * If either rewrite fails to match, we refuse to render rather than serve an
 * ungated form -- a markup edit must never silently reopen the public door.
 *
 * Entry modes:
 *   ?token=<64 hex>   visitor, via their emailed link
 *   (admin session)   therapist filling the form on someone's behalf,
 *                     optionally ?client_id=N to attach it to a client
 */

session_start();

require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/intake-token.php';
require_once __DIR__ . '/../includes/intake-repo.php';
require_once __DIR__ . '/../includes/lead-repo.php';
require_once __DIR__ . '/../includes/intake-extra-questions.php';

define('INTAKE_FORM_FILE', __DIR__ . '/../patient-intake-form.html');

$token     = isset($_GET['token']) ? trim($_GET['token']) : '';
$isAdmin   = !empty($_SESSION['logged_in']);
$adminMode = false;
$link      = null;

$db = getDbConnection();

if ($token !== '') {
    $link  = findIntakeLink($db, $token);
    $state = intakeLinkState($link);

    if ($state === 'expired' && $link) {
        markIntakeLinkExpired($db, $link['id']);
    }

    if ($state !== 'valid') {
        renderIntakeNotice($state, $isAdmin);
        exit;
    }

    markIntakeLinkOpened($db, $link['id']);

} elseif ($isAdmin) {
    // Admin bypass: no token, but an authenticated therapist.
    $adminMode = true;

} else {
    renderIntakeNotice('no_token', false);
    exit;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

$html = @file_get_contents(INTAKE_FORM_FILE);
if ($html === false) {
    http_response_code(500);
    exit('Intake form template is missing.');
}

$formTypeInput = '<input type="hidden" name="form_type" value="patient_intake" />';
$fetchTarget   = 'fetch("api/submit-form.php"';

// Fail closed if the template no longer contains what we need to gate.
if (strpos($html, $formTypeInput) === false || strpos($html, $fetchTarget) === false) {
    http_response_code(500);
    error_log('[intake] patient-intake-form.html no longer matches the expected gate anchors');
    exit('Intake form could not be prepared securely. Please contact the practice.');
}

$adminClientId = ($adminMode && isset($_GET['client_id'])) ? (int) $_GET['client_id'] : 0;

$injected  = $formTypeInput;
$injected .= "\n        " . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '" />';
if ($adminMode) {
    $injected .= "\n        " . '<input type="hidden" name="admin_mode" value="1" />';
    $injected .= "\n        " . '<input type="hidden" name="client_id" value="' . $adminClientId . '" />';
}

$html = str_replace($formTypeInput, $injected, $html);
$html = str_replace($fetchTarget, 'fetch("api/submit_intake.php"', $html);

// Pinned from the link when there is one, exactly as submit_intake.php does:
// the form someone is shown and the schema their answers are checked against
// have to be the same version, or a question can be asked and then rejected.
$formVersion = $link ? (int) $link['form_version'] : max(1, getSettingInt('intake_form_version'));

// A question added in the form module is validated and stored -- both read the
// schema -- but the template has no input for it, so without this it would
// never be asked. Rendered here rather than written into the .html, same rule
// as the token field: the file on disk stays byte-identical.
$html = intakeRenderExtraQuestions($html, $formVersion);

// ---------------------------------------------------------------------------
// Wizard boot data: what we already know, plus anything typed and left behind.
// ---------------------------------------------------------------------------

// Pre-fill from what the lead already told us. Asking someone to retype the
// name and email they gave us ten minutes ago is the fastest way to lose them.
$prefill = [];
if ($link) {
    $lead = fetchLead($db, (int) $link['lead_id']);
    if ($lead) {
        $nameParts = explode(' ', trim($lead['name']), 2);
        $prefill = [
            'first_name' => $nameParts[0],
            'last_name'  => isset($nameParts[1]) ? $nameParts[1] : '',
            'email'      => (string) $lead['email'],
            'phone'      => (string) $lead['phone'],
        ];
    }
}

// A saved draft outranks the pre-fill: it is what this person actually typed.
$draft = $link ? readIntakeDraft($db, (int) $link['id']) : [];
$boot  = [
    'token'     => $token,
    'draft'     => array_merge($prefill, $draft),
    'endpoints' => [
        'markFilled' => 'api/mark_filled.php',
        'saveDraft'  => 'api/save_draft.php',
    ],
];

// Injected rather than added to the .html on disk -- same rule as the token
// field: the template stays byte-identical for every other use of it.
//
// The HEX flags matter. The boot object carries a name typed by a member of
// the public into a <script> block; without them a name containing the closing
// script tag would end it early.
$wizard  = '<link rel="stylesheet" href="css/intake-wizard.css" />';
$wizard .= '<script>window.INTAKE_BOOT = '
         . json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
         . ';</script>';
$wizard .= '<script src="js/intake-wizard.js" defer></script>';

if (strpos($html, '</head>') === false) {
    http_response_code(500);
    error_log('[intake] patient-intake-form.html has no </head> to inject the wizard into');
    exit('Intake form could not be prepared securely. Please contact the practice.');
}
$html = str_replace('</head>', $wizard . '</head>', $html);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');   // personal links must not be indexed
header('Referrer-Policy: no-referrer');      // keep the token out of Referer
header('Cache-Control: no-store, private');
echo $html;
exit;


/**
 * Standalone notice page for links that cannot be opened.
 * Kept visually consistent with the public site palette; it is a new page,
 * not a modification of any existing one.
 */
function renderIntakeNotice($state, $isAdmin) {
    $map = [
        'submitted' => [
            'icon'  => '&#10003;',
            'title' => 'This form was already submitted',
            'body'  => 'We have your intake responses on file, so there is nothing more to fill in. If you need to change an answer, just reply to our email and we will help.',
        ],
        'expired' => [
            'icon'  => '&#8987;',
            'title' => 'This link has expired',
            'body'  => 'For your privacy, intake links stay active for a limited time. Reply to our email and we will send you a fresh one right away.',
        ],
        'not_found' => [
            'icon'  => '&#63;',
            'title' => 'We could not find that link',
            'body'  => 'The link may have been copied incompletely. Try opening it directly from your email, or reply to us and we will resend it.',
        ],
        'no_token' => [
            'icon'  => '&#128274;',
            'title' => 'This form opens from your personal link',
            'body'  => 'The intake questionnaire is private to you. Please use the link we emailed you. If you have not received one, get in touch and we will send it over.',
        ],
    ];

    $notice = isset($map[$state]) ? $map[$state] : $map['not_found'];
    $email  = getSetting('practice_email');
    $name   = getSetting('practice_name');

    http_response_code($state === 'no_token' ? 403 : 410);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo htmlspecialchars($notice['title']); ?> | <?php echo htmlspecialchars($name); ?></title>
  <link rel="icon" type="image/png" href="images/logo3.png" />
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&amp;family=Inter:wght@300;400;500&amp;display=swap" rel="stylesheet" />
  <style>
    *{ box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      padding: 24px;
      background: #FAF7F2;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      color: #1C2A29;
    }
    .card {
      background: #FFFDFB; max-width: 520px; width: 100%;
      padding: 48px 40px; border-radius: 24px; text-align: center;
      box-shadow: 0 18px 50px rgba(15, 59, 54, 0.08);
      border: 1px solid rgba(15, 59, 54, 0.06);
    }
    .badge {
      width: 64px; height: 64px; margin: 0 auto 24px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 50%; background: rgba(224, 122, 95, 0.12);
      color: #E07A5F; font-size: 28px; line-height: 1;
    }
    h1 {
      font-family: 'Playfair Display', Georgia, serif;
      font-weight: 600; font-size: 1.6rem; line-height: 1.3;
      margin: 0 0 14px; color: #0F3B36;
    }
    p { font-size: 0.97rem; line-height: 1.65; color: #4A5C5A; margin: 0 0 28px; font-weight: 300; }
    .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    a.btn {
      display: inline-block; padding: 13px 26px; border-radius: 60px;
      font-size: 0.9rem; font-weight: 500; text-decoration: none; transition: opacity .2s;
    }
    a.btn:hover { opacity: .88; }
    .btn-primary { background: #0F3B36; color: #fff; }
    .btn-ghost { background: transparent; color: #0F3B36; border: 1px solid rgba(15, 59, 54, 0.2); }
    @media (max-width: 480px) { .card { padding: 36px 24px; } }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge"><?php echo $notice['icon']; ?></div>
    <h1><?php echo htmlspecialchars($notice['title']); ?></h1>
    <p><?php echo htmlspecialchars($notice['body']); ?></p>
    <div class="actions">
      <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($email); ?>">Email <?php echo htmlspecialchars($name); ?></a>
      <a class="btn btn-ghost" href="index.html">Back to site</a>
    </div>
  </div>
</body>
</html>
    <?php
}
