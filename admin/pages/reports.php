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

<div class="split-grid">
  <!-- Monthly Sessions Chart -->
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Sessions per Month</div></div>
    <div class="panel-body">
      <div class="bar-chart">
        <?php foreach ($monthlyData as $md): ?>
          <div class="bar-chart-col">
            <div class="bar-chart-value"><?php echo $md['count']; ?></div>
            <div class="bar-chart-bar" style="height:<?php echo max(4, round(($md['count'] / $maxSessions) * 120)); ?>px;"></div>
            <div class="bar-chart-label"><?php echo $md['label']; ?></div>
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
        <div class="meter">
          <div class="meter-head">
            <span class="meter-name"><?php echo $fi['label']; ?></span>
            <span class="meter-value"><?php echo $fi['count']; ?> (<?php echo $pct; ?>%)</span>
          </div>
          <div class="score-bar">
            <div class="meter-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $fi['color']; ?>;"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Concern Distribution & Revenue -->
<div class="split-grid">
  <div class="panel">
    <div class="panel-header"><div class="panel-title">Top Client Concerns</div></div>
    <div class="panel-body">
      <?php if (empty($concerns)): ?>
        <div class="empty-state"><p>No data yet</p></div>
      <?php else: ?>
        <?php foreach ($concerns as $cn): ?>
          <div class="kv-row">
            <span><?php echo htmlspecialchars($cn['concern']); ?></span>
            <span class="kv-value"><?php echo $cn['cnt']; ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header"><div class="panel-title">Revenue Summary</div></div>
    <div class="panel-body">
      <div class="stat-tile is-banner">
        <div class="detail-label">Total Invoiced</div>
        <div class="stat-tile-value">₹<?php echo number_format($totalRevenue, 2); ?></div>
      </div>
      <div class="split-grid">
        <div class="stat-tile">
          <div class="detail-label">Collected</div>
          <div class="stat-tile-value is-paid">₹<?php echo number_format($paidRevenue, 2); ?></div>
        </div>
        <div class="stat-tile">
          <div class="detail-label">Outstanding</div>
          <div class="stat-tile-value is-pending">₹<?php echo number_format($pendingRevenue, 2); ?></div>
        </div>
      </div>
    </div>
  </div>
</div>
