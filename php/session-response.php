<?php
/**
 * Public page behind the Accept / Decline links in the session-booked email.
 *
 * GET only shows the session and asks; the answer is recorded by POST. Mail
 * scanners and link previewers fetch GET URLs, and a link that answered on GET
 * would be "clicked" by them before the client ever saw the mail.
 */

require_once dirname(__DIR__) . '/db-config.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/session-mail.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$db      = getDbConnection();
$token   = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$session = findSessionByToken($db, $token);
$prefer  = ($_GET['r'] ?? '') === 'reject' ? 'reject' : 'accept';
$message = '';
$practice = (string) getSetting('practice_name');

if ($session !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && sessionTokenState($session) === 'open') {
    $accept = ($_POST['choice'] ?? '') === 'accept';
    if (($_POST['choice'] ?? '') === 'accept' || ($_POST['choice'] ?? '') === 'reject') {
        // Only the request that actually flips the row logs and mails.
        if (respondToSession($db, $token, $accept)) {
            $name = trim($session['first_name'] . ' ' . $session['last_name']);
            $db->prepare("INSERT INTO `activity_log` (`action`,`description`,`reference_type`,`reference_id`) VALUES ('session_status_changed',:d,'session',:rid)")
               ->execute([
                   ':d'   => $name . ' ' . ($accept ? 'accepted' : 'declined') . ' the session on '
                           . date('d M Y, h:i A', strtotime($session['start_time'])) . ' via the emailed link',
                   ':rid' => (int) $session['id'],
               ]);
            $message = $accept
                ? 'Thank you. Your session is confirmed, and a confirmation is on its way to your inbox.'
                : 'Thank you for letting us know. We will be in touch to find another time.';
            sendSessionMail($db, (int) $session['id'], $accept ? 'confirmation' : 'admin_rejected');
        }
        $session = findSessionByToken($db, $token);
    }
}

$state = $session === null ? 'missing' : sessionTokenState($session);
if ($message === '') {
    $message = [
        'missing'  => 'This link is not valid.',
        'closed'   => 'This session can no longer be responded to. Please contact us if you need help.',
        'answered' => 'You have already responded to this session. Please contact us if you need to change anything.',
        'open'     => '',
    ][$state];
}

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Your session - <?php echo $h($practice); ?></title>
<style>
  body{margin:0;background:#f3f4f6;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#1f2937;}
  main{max-width:480px;margin:48px auto;padding:0 16px;}
  .card{background:#fff;border-radius:8px;padding:28px 24px;box-shadow:0 1px 3px rgba(0,0,0,.08);}
  h1{font-size:20px;margin:0 0 16px;}
  dl{margin:0 0 20px;} dt{font-size:12px;color:#6b7280;text-transform:uppercase;margin-top:12px;} dd{margin:2px 0 0;font-size:16px;}
  .row{display:flex;gap:12px;flex-wrap:wrap;}
  button{flex:1;min-width:140px;padding:12px 16px;border:0;border-radius:6px;font-size:15px;cursor:pointer;}
  .yes{background:#0d7377;color:#fff;} .no{background:#e5e7eb;color:#1f2937;}
  .pref{outline:3px solid #99d5d7;}
</style>
</head>
<body>
<main><div class="card">
<h1><?php echo $h($practice); ?></h1>
<?php if ($message !== ''): ?>
  <p><?php echo $h($message); ?></p>
<?php endif; ?>
<?php if ($session !== null && $state !== 'missing'): ?>
  <dl>
    <dt>Session</dt><dd><?php echo $h(date('l d M Y \a\t h:i A', strtotime($session['start_time']))); ?></dd>
    <dt>Format</dt><dd><?php echo $session['session_type'] === 'online' ? 'Online' : 'In person'; ?></dd>
  </dl>
<?php endif; ?>
<?php if ($state === 'open'): ?>
  <form method="post">
    <input type="hidden" name="token" value="<?php echo $h($token); ?>">
    <div class="row">
      <button class="yes<?php echo $prefer === 'accept' ? ' pref' : ''; ?>" name="choice" value="accept">Accept this time</button>
      <button class="no<?php echo $prefer === 'reject' ? ' pref' : ''; ?>" name="choice" value="reject">Decline</button>
    </div>
  </form>
<?php endif; ?>
</div></main>
</body>
</html>
