<?php
/* Billing. $client, $db, e() in scope. */
require_once dirname(__DIR__, 2) . '/includes/client-payments.php';
$cid = (int) $client['id'];

$stmt = $db->prepare('SELECT f.*, s.`start_time` FROM `client_fees` f LEFT JOIN `sessions` s ON s.`id` = f.`session_id`
    WHERE f.`client_id` = :c ORDER BY f.`fee_date` DESC, f.`id` DESC');
$stmt->execute([':c' => $cid]);
$fees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Same rules as the admin Fees tab; waived rows are not money owed or paid.
$billed = $paid = $pending = 0;
foreach ($fees as $f) {
    if ($f['status'] === 'waived') continue;
    $billed += $f['amount'];
    if ($f['status'] === 'paid') $paid += $f['amount'];
    else $pending += $f['amount'];
}
$rep = $db->prepare('SELECT * FROM `client_payment_reports` WHERE `client_id` = :c ORDER BY `id` DESC LIMIT 10');
$rep->execute([':c' => $cid]);
$reports = $rep->fetchAll(PDO::FETCH_ASSOC);
$upi  = getSetting('practice_upi_id');
$bank = getSetting('practice_bank_details');
?>
<div class="stat-row">
  <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Total billed</div><div class="stat-tile-value">&#8377;<?= number_format($billed, 2) ?></div></div></div>
  <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Total paid</div><div class="stat-tile-value is-paid">&#8377;<?= number_format($paid, 2) ?></div></div></div>
  <div class="panel"><div class="panel-body stat-tile"><div class="detail-label">Balance due</div><div class="stat-tile-value is-pending">&#8377;<?= number_format($pending, 2) ?></div></div></div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Fees &amp; payments</div></div>
  <div class="panel-body-flush">
  <?php if (!$fees): ?><div class="empty-state"><i class="bi bi-currency-rupee"></i><p>Nothing billed yet.</p></div><?php else: ?>
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>Date</th><th>Session</th><th>Description</th><th>Amount</th><th>Status</th><th class="th-right"></th></tr></thead>
    <tbody>
    <?php foreach ($fees as $f): ?>
      <tr>
        <td class="td-nowrap td-muted"><?= e(date('d M Y', strtotime($f['fee_date']))) ?></td>
        <td class="td-nowrap td-muted"><?= $f['start_time'] ? e(date('d M Y, H:i', strtotime($f['start_time']))) : '-' ?></td>
        <td><?= e($f['description']) ?></td>
        <td class="td-name">&#8377;<?= number_format((float) $f['amount'], 2) ?></td>
        <td><span class="badge badge-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?></span></td>
        <td class="td-right"><?php if ($f['status'] === 'paid'): ?><a class="btn btn-ghost btn-sm" href="api/receipt.php?id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-receipt"></i> Receipt</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">How to pay</div></div>
  <div class="panel-body">
    <div class="detail-grid">
      <?php if ($upi): ?><div><div class="detail-label">UPI ID</div><div class="detail-value"><?= e($upi) ?></div></div><?php endif; ?>
      <?php if ($bank): ?><div><div class="detail-label">Bank details</div><div class="detail-value" style="white-space:pre-line"><?= e($bank) ?></div></div><?php endif; ?>
    </div>
    <?php if (!$upi && !$bank): ?><div class="empty-state"><i class="bi bi-info-circle"></i><p>Please contact us for payment details.</p></div><?php endif; ?>
    <div class="panel-subtitle">Already paid? Tell us below. We will verify it and update your account.</div>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><div class="panel-title">Report a payment</div></div>
  <div class="panel-body">
    <form id="payForm" class="form-inline">
      <div class="form-group"><label class="form-label" for="pay-method">Method</label>
        <select id="pay-method" name="method" class="form-select" required>
          <option value="upi">UPI</option><option value="bank_transfer">Bank Transfer</option><option value="cash">Cash</option>
        </select></div>
      <div class="form-group"><label class="form-label" for="pay-amount">Amount (&#8377;)</label>
        <input id="pay-amount" name="amount" class="form-input is-amount" type="number" step="0.01" min="0.01" max="9999999" required></div>
      <div class="form-group"><label class="form-label" for="pay-ref">Reference ID</label>
        <input id="pay-ref" name="reference" class="form-input" maxlength="100"></div>
      <div class="form-group"><label class="form-label" for="pay-date">Date paid</label>
        <input id="pay-date" name="paid_on" class="form-input" type="date" max="<?= date('Y-m-d') ?>" required></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> Submit</button>
    </form>
    <div class="panel-subtitle" id="payMsg" role="status"></div>
  </div>
  <?php if ($reports): ?>
  <div class="panel-body-flush">
  <div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>Reported</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
      <tr><td class="td-nowrap td-muted"><?= e(date('d M Y', strtotime($r['paid_on']))) ?></td><td><?= e(clientPaymentMethodLabel($r['method'])) ?></td>
          <td class="td-name">&#8377;<?= number_format((float) $r['amount'], 2) ?></td><td class="td-muted"><?= e($r['reference']) ?></td>
          <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  </div>
  <?php endif; ?>
</div>
<script>
  document.getElementById('payForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var m = document.getElementById('payMsg'), fd = new FormData(this);
    fd.append('csrf', document.querySelector('meta[name=csrf-token]').content);
    fetch('api/billing-report.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
      .then(function (j) { if (j.success) { location.reload(); } else { m.textContent = j.error; } })
      .catch(function () { m.textContent = 'Network error.'; });
  });
</script>
