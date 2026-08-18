<?php
require_once __DIR__ . '/../../includes/lead-status.php';

$db = getDbConnection();
$stmtPI = $db->query("
    SELECT pi.*, 
           l.id as lead_id, 
           l.status as lead_status, 
           l.client_id as lead_client_id 
    FROM `patient-intake` pi
    LEFT JOIN `leads` l ON l.email = pi.email AND l.phone = pi.phone
    ORDER BY pi.created_at DESC
");
$patientIntakes = $stmtPI->fetchAll(PDO::FETCH_ASSOC);

// Score helper
function calcScore($row, $prefix, $count = 18) {
    $score = 0;
    for ($i = 1; $i <= $count; $i++) {
        $key = $prefix . '_' . $i;
        if (isset($row[$key]) && strtolower(trim($row[$key])) === 'yes') {
            $score++;
        }
    }
    return $score;
}

function scoreClass($score, $max = 18) {
    $pct = ($score / $max) * 100;
    if ($pct >= 67) return 'high';
    if ($pct >= 33) return 'mid';
    return 'low';
}
?>

<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Patient Intake Forms (<?php echo count($patientIntakes); ?>)</div>
    <div class="view-toggle" data-page="patient-intake">
      <button class="view-toggle-btn" data-view="list" title="List View"><i class="bi bi-list-ul"></i></button>
      <button class="view-toggle-btn" data-view="grid" title="Grid View"><i class="bi bi-grid"></i></button>
    </div>
  </div>
  <div class="panel-body-flush">
    <?php if (empty($patientIntakes)): ?>
      <div class="empty-state">
        <i class="bi bi-clipboard2-pulse"></i>
        <p>No patient intake forms submitted</p>
      </div>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Name</th>
              <th>Email</th>
              <th>Concern</th>
              <th>Q1 Score</th>
              <th>Q2 Score</th>
              <th>Total</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($patientIntakes as $pi):
              $q1 = calcScore($pi, 'q1');
              $q2 = calcScore($pi, 'q2');
              $total = $q1 + $q2;
              $totalClass = scoreClass($total, 36);
              $lstatus = $pi['lead_status'] ?? 'new';
              $lclientId = $pi['lead_client_id'] ?? null;
            ?>
              <tr>
                <td class="td-nowrap td-muted"><?php echo date('d M Y', strtotime($pi['created_at'])); ?></td>
                <td class="td-name"><?php echo htmlspecialchars($pi['first_name'] . ' ' . $pi['last_name']); ?></td>
                <td class="td-email"><a href="mailto:<?php echo htmlspecialchars($pi['email']); ?>"><?php echo htmlspecialchars($pi['email']); ?></a></td>
                <td><?php echo htmlspecialchars($pi['concern']); ?></td>
                <td>
                  <span class="score-text <?php echo scoreClass($q1); ?>"><?php echo $q1; ?>/18</span>
                </td>
                <td>
                  <span class="score-text <?php echo scoreClass($q2); ?>"><?php echo $q2; ?>/18</span>
                </td>
                <td>
                  <span class="score-text <?php echo $totalClass; ?>" style="font-size:0.92rem;"><?php echo $total; ?>/36</span>
                  <div class="score-bar" style="width:80px;margin-top:3px;">
                    <div class="score-bar-fill <?php echo $totalClass; ?>" style="width:<?php echo round(($total/36)*100); ?>%;"></div>
                  </div>
                </td>
                <td>
                  <select class="status-select" onchange="updateIntakeStatus('<?php echo htmlspecialchars($pi['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pi['phone'], ENT_QUOTES); ?>', this.value)" <?php echo $lstatus === 'converted' ? 'disabled' : ''; ?>>
                    <?php foreach (leadStatuses() as $optS): ?>
                      <option value="<?php echo $optS; ?>" <?php echo $lstatus === $optS ? 'selected' : ''; ?>><?php echo leadStatusLabel($optS); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td style="text-align:right; white-space:nowrap;">
                  <div style="display:inline-flex; align-items:center; gap:0.5rem; justify-content:flex-end;">
                    <button class="btn btn-ghost btn-sm" onclick="openPIModal(<?php echo $pi['id']; ?>)"><i class="bi bi-eye"></i> Details</button>
                    <?php if ($lstatus !== 'converted'): ?>
                      <button class="btn btn-success btn-sm" onclick="convertIntake(<?php echo $pi['id']; ?>, '<?php echo htmlspecialchars(addslashes($pi['first_name'] . ' ' . $pi['last_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['phone']), ENT_QUOTES); ?>')">
                        <i class="bi bi-person-plus"></i> Convert
                      </button>
                    <?php else: ?>
                      <a href="index.php?page=client-profile&id=<?php echo $lclientId; ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:0.25rem;"><i class="bi bi-eye"></i> View Client</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
      <div class="grid-view-container">
        <?php foreach ($patientIntakes as $pi):
          $q1 = calcScore($pi, 'q1');
          $q2 = calcScore($pi, 'q2');
          $total = $q1 + $q2;
          $totalClass = scoreClass($total, 36);
          $lstatus = $pi['lead_status'] ?? 'new';
          $lclientId = $pi['lead_client_id'] ?? null;
          $fullName = htmlspecialchars($pi['first_name'] . ' ' . $pi['last_name']);
        ?>
          <div class="grid-card">
            <div style="flex: 1; display: flex; flex-direction: column;">
              <div class="grid-card-header">
                <div class="grid-card-title">
                  <i class="bi bi-clipboard2-pulse" style="color:var(--clr-primary); font-size:1.15rem;"></i>
                  <span><?php echo $fullName; ?></span>
                </div>
                <div style="font-size:0.75rem;color:var(--clr-text-muted);"><?php echo date('d M Y', strtotime($pi['created_at'])); ?></div>
              </div>
              <div class="grid-card-body" style="margin-top: 0.5rem;">
                <div class="grid-card-item" title="Email" style="margin-bottom:0.25rem;">
                  <i class="bi bi-envelope"></i>
                  <a href="mailto:<?php echo htmlspecialchars($pi['email']); ?>"><?php echo htmlspecialchars($pi['email']); ?></a>
                </div>
                <div class="grid-card-item" title="Primary Concern" style="margin-bottom:0.25rem;">
                  <i class="bi bi-heart-pulse"></i>
                  <span>Concern: <?php echo htmlspecialchars($pi['concern']); ?></span>
                </div>
                
                <!-- Score displays -->
                <div style="margin-top:0.5rem; background:var(--clr-bg); padding:0.75rem; border-radius:var(--radius-sm); border:1px solid var(--clr-border-light);">
                  <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:0.35rem;">
                    <span>Self-Awareness (Q1):</span>
                    <span class="score-text <?php echo scoreClass($q1); ?>" style="font-weight:600;"><?php echo $q1; ?>/18</span>
                  </div>
                  <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:0.5rem;">
                    <span>Well-being (Q2):</span>
                    <span class="score-text <?php echo scoreClass($q2); ?>" style="font-weight:600;"><?php echo $q2; ?>/18</span>
                  </div>
                  <div style="border-top:1px solid var(--clr-border-light); padding-top:0.5rem; display:flex; justify-content:space-between; align-items:center; font-size:0.82rem;">
                    <strong>Total Score:</strong>
                    <div>
                      <span class="score-text <?php echo $totalClass; ?>" style="font-weight:700; font-size:0.9rem;"><?php echo $total; ?>/36</span>
                      <div class="score-bar" style="width:70px; margin-top:2px;">
                        <div class="score-bar-fill <?php echo $totalClass; ?>" style="width:<?php echo round(($total/36)*100); ?>%;"></div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="grid-card-item" style="margin-top: 0.5rem;">
                  <i class="bi bi-flag"></i>
                  <span style="font-size:0.8rem; font-weight:500; color:var(--clr-text-secondary); margin-right: 0.35rem;">Status:</span>
                  <select class="status-select" onchange="updateIntakeStatus('<?php echo htmlspecialchars($pi['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pi['phone'], ENT_QUOTES); ?>', this.value)" <?php echo $lstatus === 'converted' ? 'disabled' : ''; ?>>
                    <?php foreach (leadStatuses() as $optS): ?>
                      <option value="<?php echo $optS; ?>" <?php echo $lstatus === $optS ? 'selected' : ''; ?>><?php echo leadStatusLabel($optS); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="grid-card-footer" style="margin-top: 1rem;">
              <button class="btn btn-ghost btn-sm" onclick="openPIModal(<?php echo $pi['id']; ?>)"><i class="bi bi-eye"></i> Details</button>
              <?php if ($lstatus !== 'converted'): ?>
                <button class="btn btn-success btn-sm" onclick="convertIntake(<?php echo $pi['id']; ?>, '<?php echo htmlspecialchars(addslashes($pi['first_name'] . ' ' . $pi['last_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['email']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($pi['phone']), ENT_QUOTES); ?>')">
                  <i class="bi bi-person-plus"></i> Convert
                </button>
              <?php else: ?>
                <a href="index.php?page=client-profile&id=<?php echo $lclientId; ?>" class="btn btn-ghost btn-sm" style="display:inline-flex; align-items:center; gap:0.25rem;"><i class="bi bi-eye"></i> View Client</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Patient Intake Detail Modal -->
<div class="modal-overlay" id="piModal">
  <div class="modal-box" style="max-width:800px;">
    <div class="modal-header">
      <div class="modal-title">Patient Intake Details</div>
      <button class="modal-close" onclick="closePIModal()">&times;</button>
    </div>
    <div class="modal-body" id="piModalContent">
      <!-- Populated by JS -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closePIModal()">Close</button>
    </div>
  </div>
</div>

<script>
// Store all patient intake data for modal use
var piData = <?php echo json_encode($patientIntakes); ?>;

var q1Texts = {
  "q1_1":"Have you ever walked in your sleep during your adult life?",
  "q1_2":"As a teenager did you feel comfortable expressing your feelings to one or both of your parents or friends?",
  "q1_3":"Do you have a tendency to look directly into a person's eyes and/or move closely to them when discussing an interesting subject?",
  "q1_4":"Do you feel that most people, when you first meet them, are uncritical of your appearance?",
  "q1_5":"In a group situation, with people that you have just met, would you feel comfortable drawing attention to yourself by initiating a conversation?",
  "q1_6":"Do you feel comfortable holding hands or showing physical affection in public?",
  "q1_7":"When you hear about a disaster or a tragic event, do you feel a strong emotional response even if it doesn't affect you personally?",
  "q1_8":"Would you feel comfortable confronting a friend or family member about their hurtful behavior?",
  "q1_9":"Do you find it easy to trust someone when you first meet them?",
  "q1_10":"If you made a mistake at work or school, would you feel comfortable admitting it to your supervisor or teacher?",
  "q1_11":"Do you feel comfortable speaking in public or presenting your ideas to a group of people?",
  "q1_12":"When you feel sad or upset, do you seek comfort and support from others rather than keeping it to yourself?",
  "q1_13":"Would you feel comfortable asking for help when you are struggling with a personal problem?",
  "q1_14":"Do you feel that most people are generally good and have well-meaning intentions?",
  "q1_15":"In a professional setting, would you feel comfortable negotiating your salary or asking for a promotion?",
  "q1_16":"Do you feel comfortable expressing your anger or frustration in a constructive manner?",
  "q1_17":"When you are in a romantic relationship, do you find it easy to open up and share your deepest thoughts and feelings?",
  "q1_18":"Do you feel that you are generally worthy of love and respect from others?"
};

var q2Texts = {
  "q2_1":"Do you feel that you have a clear sense of purpose and direction in your life?",
  "q2_2":"Are you satisfied with the quality of your close relationships (friends, family, partner)?",
  "q2_3":"Do you find it easy to manage your stress and anxiety in daily life?",
  "q2_4":"Do you feel that you have a good balance between your professional work and personal life?",
  "q2_5":"Are you content with your current physical health and level of fitness?",
  "q2_6":"Do you feel that you are able to express your creativity and pursue your hobbies?",
  "q2_7":"Are you satisfied with your current financial situation and financial security?",
  "q2_8":"Do you feel that you have a supportive community or social network that you can rely on?",
  "q2_9":"Are you content with your current living environment and housing situation?",
  "q2_10":"Do you feel that you are continually learning and growing as a person?",
  "q2_11":"Are you satisfied with your ability to communicate your needs and boundaries to others?",
  "q2_12":"Do you feel that you have a healthy relationship with technology and social media?",
  "q2_13":"Are you content with the amount of time you spend in nature or outdoors?",
  "q2_14":"Do you feel that you are able to find moments of joy and gratitude in your daily life?",
  "q2_15":"Are you satisfied with your level of self-acceptance and self-compassion?",
  "q2_16":"Do you feel that you are able to forgive yourself and others for past mistakes?",
  "q2_17":"Are you content with your current sleep quality and sleep patterns?",
  "q2_18":"Do you feel that you are generally optimistic about your future?"
};

function openPIModal(id) {
  var pi = null;
  for (var i = 0; i < piData.length; i++) {
    if (parseInt(piData[i].id) === id) { pi = piData[i]; break; }
  }
  if (!pi) return;

  var age = '';
  if (pi.dob) {
    var bd = new Date(pi.dob), today = new Date();
    age = today.getFullYear() - bd.getFullYear();
    var m = today.getMonth() - bd.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) age--;
    age = ' (Age: ' + age + ')';
  }

  var html = '';

  // Personal info
  html += '<div class="section-title" style="margin-top:0;">Personal Details</div>';
  html += '<div class="detail-grid">';
  html += '<div><div class="detail-label">Full Name</div><div class="detail-value">' + escapeHtml(pi.first_name + ' ' + pi.last_name) + '</div></div>';
  html += '<div><div class="detail-label">Email</div><div class="detail-value">' + escapeHtml(pi.email) + '</div></div>';
  html += '<div><div class="detail-label">Phone</div><div class="detail-value">' + escapeHtml(pi.phone) + '</div></div>';
  html += '<div><div class="detail-label">Date of Birth</div><div class="detail-value">' + escapeHtml(pi.dob) + age + '</div></div>';
  html += '<div><div class="detail-label">City</div><div class="detail-value">' + escapeHtml(pi.city) + '</div></div>';
  html += '<div><div class="detail-label">Occupation</div><div class="detail-value">' + escapeHtml(pi.occupation) + '</div></div>';
  html += '<div><div class="detail-label">Primary Concern</div><div class="detail-value" style="font-weight:600;">' + escapeHtml(pi.concern) + '</div></div>';
  html += '</div>';

  // Preferences
  html += '<div class="section-title">Consultation Preferences</div>';
  html += '<div class="detail-grid">';
  html += '<div><div class="detail-label">Mode</div><div class="detail-value"><span class="badge badge-' + escapeHtml(pi.pref_consult) + '">' + escapeHtml(pi.pref_consult) + '</span></div></div>';
  html += '<div><div class="detail-label">Preferred Date</div><div class="detail-value">' + escapeHtml(pi.pref_date) + '</div></div>';
  html += '<div><div class="detail-label">Preferred Time</div><div class="detail-value">' + escapeHtml(pi.pref_time) + '</div></div>';
  html += '</div>';

  // Score summary
  var q1s = 0, q2s = 0;
  for (var k in q1Texts) { if (pi[k] && pi[k].toLowerCase() === 'yes') q1s++; }
  for (var k in q2Texts) { if (pi[k] && pi[k].toLowerCase() === 'yes') q2s++; }
  var total = q1s + q2s;
  var totalCls = total >= 24 ? 'high' : (total >= 12 ? 'mid' : 'low');

  html += '<div class="section-title">Score Summary</div>';
  html += '<div class="detail-grid" style="grid-template-columns:repeat(3,1fr);">';
  html += '<div><div class="detail-label">Questionnaire 1</div><div class="score-text ' + (q1s >= 12 ? 'high' : (q1s >= 6 ? 'mid' : 'low')) + '" style="font-size:1.1rem;">' + q1s + ' / 18</div></div>';
  html += '<div><div class="detail-label">Questionnaire 2</div><div class="score-text ' + (q2s >= 12 ? 'high' : (q2s >= 6 ? 'mid' : 'low')) + '" style="font-size:1.1rem;">' + q2s + ' / 18</div></div>';
  html += '<div><div class="detail-label">Total Score</div><div class="score-text ' + totalCls + '" style="font-size:1.3rem;font-weight:700;">' + total + ' / 36</div>';
  html += '<div class="score-bar" style="margin-top:6px;"><div class="score-bar-fill ' + totalCls + '" style="width:' + Math.round((total/36)*100) + '%;"></div></div></div>';
  html += '</div>';

  // Q1
  html += '<div class="section-title">Questionnaire 1 — General Self-Awareness</div>';
  for (var key in q1Texts) {
    var ans = pi[key] || 'N/A';
    var aCls = ans.toLowerCase() === 'yes' ? 'yes' : 'no';
    html += '<div class="qa-row"><div class="qa-question">' + escapeHtml(q1Texts[key]) + '</div><div class="qa-answer ' + aCls + '">' + escapeHtml(ans) + '</div></div>';
  }

  // Q2
  html += '<div class="section-title" style="margin-top:1.5rem;">Questionnaire 2 — Well-being & Life Balance</div>';
  for (var key in q2Texts) {
    var ans = pi[key] || 'N/A';
    var aCls = ans.toLowerCase() === 'yes' ? 'yes' : 'no';
    html += '<div class="qa-row"><div class="qa-question">' + escapeHtml(q2Texts[key]) + '</div><div class="qa-answer ' + aCls + '">' + escapeHtml(ans) + '</div></div>';
  }

  document.getElementById('piModalContent').innerHTML = html;
  document.getElementById('piModal').classList.add('open');
}

function closePIModal() {
  document.getElementById('piModal').classList.remove('open');
}

// Close modal on overlay click
document.getElementById('piModal').addEventListener('click', function(e) {
  if (e.target === this) closePIModal();
});

function updateIntakeStatus(email, phone, status) {
  var fd = new FormData();
  fd.append('action', 'update_intake_status');
  fd.append('email', email);
  fd.append('phone', phone);
  fd.append('status', status);
  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Status updated');
      } else {
        showToast(data.error || 'Error', 'error');
      }
    })
    .catch(function() { showToast('Network error', 'error'); });
}

function convertIntake(id, name, email, phone) {
  if (!confirm('Convert "' + name + '" to a client?')) return;
  var fd = new FormData();
  fd.append('action', 'convert_intake');
  fd.append('id', id);
  fd.append('name', name);
  fd.append('email', email);
  fd.append('phone', phone);
  fetch('api/leads.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Converted to client!');
        setTimeout(function() { location.reload(); }, 800);
      } else {
        showToast(data.error || 'Error', 'error');
      }
    })
    .catch(function() { showToast('Network error', 'error'); });
}
</script>

<script>
(function() {
  const page = 'patient-intake';
  const container = document.querySelector('.panel');
  const toggleButtons = document.querySelectorAll('.view-toggle[data-page="' + page + '"] .view-toggle-btn');
  
  function setView(view) {
    localStorage.setItem('view-pref-' + page, view);
    toggleButtons.forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-view') === view);
    });
    if (view === 'grid') {
      container.classList.add('view-mode-grid');
      container.classList.remove('view-mode-list');
    } else {
      container.classList.add('view-mode-list');
      container.classList.remove('view-mode-grid');
    }
  }

  const savedView = localStorage.getItem('view-pref-' + page) || 'list';
  setView(savedView);

  toggleButtons.forEach(btn => {
    btn.addEventListener('click', function() {
      setView(this.getAttribute('data-view'));
    });
  });
})();
</script>
