<?php /* Shared intake drawer. Caller sets $allPatientIntakes (patient-intake rows). */ ?>
<!-- Patient Intake Detail Modal -->
<!-- A drawer, not a modal. Thirty-six answers do not fit in a box over the
     middle of the page, and reading one person's form should not hide the list
     you are working through. -->
<div class="drawer-backdrop" id="piDrawerBackdrop" hidden onclick="closeIntakeDrawer()"></div>
<aside class="drawer drawer-wide" id="piDrawer" hidden aria-labelledby="pi-drawer-name" role="dialog" aria-modal="true">
  <div class="drawer-header">
    <div>
      <h2 id="pi-drawer-name">Patient intake</h2>
      <div class="drawer-sub" id="pi-drawer-sub"></div>
    </div>
    <button class="btn btn-icon" type="button" onclick="closeIntakeDrawer()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="drawer-body" id="piDrawerContent">
    <!-- Populated by JS -->
  </div>
</aside>

<script>
// Every submitted form, for the drawer to read without another request --
// every page's worth, not just the one on screen, so the drawer works
// whichever page a form happens to sit on.
var piData = <?php echo json_encode($allPatientIntakes); ?>;

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

function openIntakeDrawer(id) {
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
  html += '<div class="section-title">Personal Details</div>';
  html += '<div class="detail-grid">';
  html += '<div><div class="detail-label">Full Name</div><div class="detail-value">' + escapeHtml(pi.first_name + ' ' + pi.last_name) + '</div></div>';
  html += '<div><div class="detail-label">Email</div><div class="detail-value">' + escapeHtml(pi.email) + '</div></div>';
  html += '<div><div class="detail-label">Phone</div><div class="detail-value">' + escapeHtml(pi.phone) + '</div></div>';
  html += '<div><div class="detail-label">Date of Birth</div><div class="detail-value">' + escapeHtml(pi.dob) + age + '</div></div>';
  html += '<div><div class="detail-label">City</div><div class="detail-value">' + escapeHtml(pi.city) + '</div></div>';
  html += '<div><div class="detail-label">Occupation</div><div class="detail-value">' + escapeHtml(pi.occupation) + '</div></div>';
  html += '<div><div class="detail-label">Primary Concern</div><div class="detail-value">' + escapeHtml(pi.concern) + '</div></div>';
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
  html += '<div class="detail-grid is-thirds">';
  html += '<div><div class="detail-label">Questionnaire 1</div><div class="score-text ' + (q1s >= 12 ? 'high' : (q1s >= 6 ? 'mid' : 'low')) + ' is-lg">' + q1s + ' / 18</div></div>';
  html += '<div><div class="detail-label">Questionnaire 2</div><div class="score-text ' + (q2s >= 12 ? 'high' : (q2s >= 6 ? 'mid' : 'low')) + ' is-lg">' + q2s + ' / 18</div></div>';
  html += '<div><div class="detail-label">Total Score</div><div class="score-text ' + totalCls + ' is-xl">' + total + ' / 36</div>';
  html += '<div class="score-bar"><div class="score-bar-fill ' + totalCls + '" style="width:' + Math.round((total/36)*100) + '%;"></div></div></div>';
  html += '</div>';

  // Q1
  html += '<div class="section-title">Questionnaire 1 — General Self-Awareness</div>';
  for (var key in q1Texts) {
    var ans = pi[key] || 'N/A';
    var aCls = ans.toLowerCase() === 'yes' ? 'yes' : 'no';
    html += '<div class="qa-row"><div class="qa-question">' + escapeHtml(q1Texts[key]) + '</div><div class="qa-answer ' + aCls + '">' + escapeHtml(ans) + '</div></div>';
  }

  // Q2
  html += '<div class="section-title">Questionnaire 2 — Well-being & Life Balance</div>';
  for (var key in q2Texts) {
    var ans = pi[key] || 'N/A';
    var aCls = ans.toLowerCase() === 'yes' ? 'yes' : 'no';
    html += '<div class="qa-row"><div class="qa-question">' + escapeHtml(q2Texts[key]) + '</div><div class="qa-answer ' + aCls + '">' + escapeHtml(ans) + '</div></div>';
  }

  document.getElementById('piDrawerContent').innerHTML = html;

  // The header carries who this is, so the answers below do not have to
  // repeat it and the drawer says whose form you are reading while you scroll.
  document.getElementById('pi-drawer-name').textContent = pi.first_name + ' ' + pi.last_name;
  document.getElementById('pi-drawer-sub').textContent =
    [pi.email, pi.phone].filter(Boolean).join(' · ');

  document.getElementById('piDrawer').hidden = false;
  document.getElementById('piDrawerBackdrop').hidden = false;
  document.getElementById('piDrawer').querySelector('.btn-icon').focus();
}

function closeIntakeDrawer() {
  document.getElementById('piDrawer').hidden = true;
  document.getElementById('piDrawerBackdrop').hidden = true;
}

// Escape closes it, the same as every other panel that covers the page.
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && !document.getElementById('piDrawer').hidden) closeIntakeDrawer();
});
</script>
