<?php
$db = getDbConnection();

// Overall stats
$totalClients   = $db->query("SELECT COUNT(*) FROM `clients`")->fetchColumn();
$activeClients  = $db->query("SELECT COUNT(*) FROM `clients` WHERE `status`='active'")->fetchColumn();
$totalSessions  = $db->query("SELECT COUNT(*) FROM `sessions`")->fetchColumn();
$completedSess  = $db->query("SELECT COUNT(*) FROM `sessions` WHERE `status`='completed'")->fetchColumn();
$totalRevenue   = $db->query("SELECT COALESCE(SUM(`amount`),0) FROM `client_fees`")->fetchColumn();
$paidRevenue    = $db->query("SELECT COALESCE(SUM(`amount`),0) FROM `client_fees` WHERE `status`='paid'")->fetchColumn();
$pendingRevenue = $db->query("SELECT COALESCE(SUM(`amount`),0) FROM `client_fees` WHERE `status`='pending'")->fetchColumn();

// Lead funnel
$leadNew       = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='new'")->fetchColumn();
$leadContacted = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='contacted'")->fetchColumn();
$leadConverted = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='converted'")->fetchColumn();
$leadArchived  = $db->query("SELECT COUNT(*) FROM `leads` WHERE `status`='archived'")->fetchColumn();
$leadTotal     = $leadNew + $leadContacted + $leadConverted + $leadArchived;

// Monthly sessions (last 6 months)
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('n', strtotime("-{$i} months"));
    $y = date('Y', strtotime("-{$i} months"));
    $label = date('M', strtotime("-{$i} months"));
    $cnt = $db->query("SELECT COUNT(*) FROM `sessions` WHERE MONTH(`start_time`)={$m} AND YEAR(`start_time`)={$y}")->fetchColumn();
    $monthlyData[] = ['label'=>$label, 'count'=>intval($cnt)];
}
$maxSessions = max(array_column($monthlyData, 'count'));
if ($maxSessions === 0) $maxSessions = 1;

// Concern distribution
$concerns = $db->query("SELECT `concern`, COUNT(*) as cnt FROM `clients` WHERE `concern` IS NOT NULL AND `concern`!='' GROUP BY `concern` ORDER BY cnt DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- KPI Summary -->
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon-wrap teal"><i class="bi bi-people"></i></div>
    <div class="kpi-label">Total Clients</div>
    <div class="kpi-value"><?php echo $totalClients; ?></div>
    <div class="kpi-sub"><?php echo $activeClients; ?> active</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap green"><i class="bi bi-calendar-check"></i></div>
    <div class="kpi-label">Total Sessions</div>
    <div class="kpi-value"><?php echo $totalSessions; ?></div>
    <div class="kpi-sub"><?php echo $completedSess; ?> completed</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap blue"><i class="bi bi-currency-rupee"></i></div>
    <div class="kpi-label">Total Revenue</div>
    <div class="kpi-value">₹<?php echo number_format($paidRevenue, 0); ?></div>
    <div class="kpi-sub">₹<?php echo number_format($pendingRevenue, 0); ?> pending</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon-wrap amber"><i class="bi bi-funnel"></i></div>
    <div class="kpi-label">Conversion Rate</div>
    <div class="kpi-value"><?php echo $leadTotal > 0 ? round(($leadConverted / $leadTotal) * 100) : 0; ?>%</div>
    <div class="kpi-sub"><?php echo $leadConverted; ?> of <?php echo $leadTotal; ?> leads</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">
  <!-- Monthly Sessions Chart -->
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Sessions per Month</div></div>
    <div class="panel-body">
      <div style="display:flex;align-items:flex-end;gap:0.75rem;height:160px;">
        <?php foreach ($monthlyData as $md): ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
            <div style="font-size:0.75rem;font-weight:600;margin-bottom:0.35rem;color:var(--clr-text);"><?php echo $md['count']; ?></div>
            <div style="width:100%;background:var(--clr-primary-light);border-radius:6px 6px 0 0;height:<?php echo max(4, round(($md['count']/$maxSessions)*120)); ?>px;transition:height 0.3s ease;">
              <div style="width:100%;height:100%;background:var(--clr-primary);border-radius:6px 6px 0 0;opacity:0.85;"></div>
            </div>
            <div style="font-size:0.7rem;color:var(--clr-text-muted);margin-top:0.35rem;"><?php echo $md['label']; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Lead Funnel -->
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Lead Funnel</div></div>
    <div class="panel-body">
      <?php
      $funnelItems = [
        ['label'=>'New', 'count'=>$leadNew, 'color'=>'var(--clr-info)'],
        ['label'=>'Contacted', 'count'=>$leadContacted, 'color'=>'var(--clr-warning)'],
        ['label'=>'Converted', 'count'=>$leadConverted, 'color'=>'var(--clr-success)'],
        ['label'=>'Archived', 'count'=>$leadArchived, 'color'=>'var(--clr-text-muted)'],
      ];
      foreach ($funnelItems as $fi):
        $pct = $leadTotal > 0 ? round(($fi['count'] / $leadTotal) * 100) : 0;
      ?>
        <div style="margin-bottom:0.85rem;">
          <div style="display:flex;justify-content:space-between;margin-bottom:0.3rem;">
            <span style="font-size:0.82rem;font-weight:500;"><?php echo $fi['label']; ?></span>
            <span style="font-size:0.82rem;font-weight:600;"><?php echo $fi['count']; ?> (<?php echo $pct; ?>%)</span>
          </div>
          <div class="score-bar">
            <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $fi['color']; ?>;border-radius:var(--radius-full);"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Concern Distribution & Revenue -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Top Client Concerns</div></div>
    <div class="panel-body">
      <?php if (empty($concerns)): ?>
        <div class="empty-state" style="padding:1.5rem;"><p>No data yet</p></div>
      <?php else: ?>
        <?php foreach ($concerns as $cn): ?>
          <div style="display:flex;justify-content:space-between;padding:0.55rem 0;border-bottom:1px solid var(--clr-border-light);">
            <span style="font-size:0.85rem;"><?php echo htmlspecialchars($cn['concern']); ?></span>
            <span style="font-size:0.85rem;font-weight:600;"><?php echo $cn['cnt']; ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><div class="panel-title">Revenue Summary</div></div>
    <div class="panel-body">
      <div style="display:grid;grid-template-columns:1fr;gap:1rem;">
        <div style="text-align:center;padding:1rem 0;border-bottom:1px solid var(--clr-border-light);">
          <div class="detail-label">Total Invoiced</div>
          <div style="font-size:1.5rem;font-weight:700;">₹<?php echo number_format($totalRevenue, 2); ?></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center;">
          <div>
            <div class="detail-label">Collected</div>
            <div style="font-size:1.2rem;font-weight:700;color:var(--clr-success);">₹<?php echo number_format($paidRevenue, 2); ?></div>
          </div>
          <div>
            <div class="detail-label">Outstanding</div>
            <div style="font-size:1.2rem;font-weight:700;color:#b45309;">₹<?php echo number_format($pendingRevenue, 2); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
