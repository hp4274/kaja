<?php
/** Printable receipt for one PAID fee row owned by the logged-in client. */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/client-payments.php';
$db = getDbConnection();
$feeId = (int) ($_GET['id'] ?? 0);

if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Admin viewing receipt
    $stmt = $db->prepare('SELECT f.*, s.`start_time` FROM `client_fees` f LEFT JOIN `sessions` s ON s.`id` = f.`session_id`
        WHERE f.`id` = :id AND f.`status` = "paid"');
    $stmt->execute([':id' => $feeId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Client viewing receipt
    $client = requireClient(true);
    $stmt = $db->prepare('SELECT f.*, s.`start_time` FROM `client_fees` f LEFT JOIN `sessions` s ON s.`id` = f.`session_id`
        WHERE f.`id` = :id AND f.`client_id` = :c AND f.`status` = "paid"');
    $stmt->execute([':id' => $feeId, ':c' => (int) $client['id']]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$f) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Receipt not found.');
}
$practice = getSetting('practice_name');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Receipt #<?= (int) $f['id'] ?> - <?= e($practice) ?></title>
<style>
  :root { --clr-primary: #0d7377; --clr-primary-light: #e8f4f4; --clr-success: #34a853; --clr-success-light: #e6f4ea; --clr-bg: #f7f8fa; --clr-surface: #fff; --clr-border: #e5e7eb; --clr-border-light: #f0f1f3; --clr-text: #1f2937; --clr-text-secondary: #6b7280; --radius-md: 12px; --radius-sm: 8px; }
  * { box-sizing: border-box; }
  body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: var(--clr-text); background: var(--clr-bg); margin: 0; padding: 2rem 1rem; }
  .receipt { max-width: 640px; margin: 0 auto; background: var(--clr-surface); border: 1px solid var(--clr-border); border-radius: var(--radius-md); box-shadow: 0 1px 3px rgba(0,0,0,.06); overflow: hidden; }
  .receipt-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--clr-border); }
  h1 { margin: 0; font-size: 1.15rem; font-weight: 600; }
  .sub { color: var(--clr-text-secondary); font-size: .85rem; margin-top: .15rem; }
  .badge { display: inline-block; padding: .25rem .65rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; background: var(--clr-success-light); color: var(--clr-success); }
  table { width: 100%; border-collapse: collapse; }
  td { padding: .75rem 1.5rem; border-bottom: 1px solid var(--clr-border-light); font-size: .9rem; }
  td:first-child { color: var(--clr-text-secondary); width: 40%; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
  tr:last-child td { border-bottom: 0; }
  .receipt-foot { padding: 1rem 1.5rem; border-top: 1px solid var(--clr-border); }
  button { font: inherit; font-size: .85rem; font-weight: 500; padding: .45rem 1rem; border: 0; border-radius: var(--radius-sm); background: var(--clr-primary); color: #fff; cursor: pointer; }
  @media print { body { background: #fff; padding: 0; } .receipt { border: 0; box-shadow: none; } button, .receipt-foot { display: none; } }
</style></head>
<body>
<div class="receipt">
  <div class="receipt-head">
    <div><h1><?= e($practice) ?></h1><div class="sub">Payment receipt #<?= (int) $f['id'] ?></div></div>
    <span class="badge">Paid</span>
  </div>
  <table>
    <tr><td>Received from</td><td><?= e($client['first_name'] . ' ' . $client['last_name']) ?></td></tr>
    <tr><td>Date</td><td><?= e(date('d M Y', strtotime($f['fee_date']))) ?></td></tr>
    <tr><td>Amount</td><td><b>INR <?= number_format((float) $f['amount'], 2) ?></b></td></tr>
    <tr><td>Method</td><td><?= e(clientPaymentMethodLabel($f['method'])) ?></td></tr>
    <tr><td>Reference</td><td><?= e($f['reference'] !== null && $f['reference'] !== '' ? $f['reference'] : '-') ?></td></tr>
    <tr><td>Session</td><td><?= $f['start_time'] ? e(date('d M Y, H:i', strtotime($f['start_time']))) : '-' ?></td></tr>
  </table>
  <div class="receipt-foot"><button onclick="window.print()">Print</button></div>
</div>
</body></html>
