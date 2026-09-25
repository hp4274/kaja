<?php
/**
 * Intake confirmation page.
 *
 * The tail of the intake flow. It is a page of its own rather than a banner
 * rendered into the questionnaire for one reason: a message shown inside the
 * form's own page leaves the form standing behind it, so anyone who scrolls
 * back can fill it in and post it a second time. Sending the browser here with
 * location.replace() takes the filled form out of the history stack as well,
 * so Back does not return to it either.
 *
 * The name, section count and reply-to address come from a session flash that
 * submit_intake.php writes just before it answers. The flash is deliberately
 * not cleared on read, so refreshing this page still works; it holds a first
 * name, an email address and an integer, and nothing else.
 */

session_start();

require_once __DIR__ . '/../includes/settings.php';

$done = isset($_SESSION['intake_done']) ? $_SESSION['intake_done'] : null;

// No flash means there is no submission behind this page. Send them somewhere
// real rather than thanking them for something that never happened.
if (!is_array($done)) {
    header('Location: index.html');
    exit;
}

/** Spelled-out numerals read as prose; digits in a sentence read as data. */
function itkWord($n) {
    $words = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
        7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve',
    ];
    return isset($words[$n]) ? $words[$n] : (string) $n;
}

function itkOut($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$firstName = isset($done['first_name']) ? trim((string) $done['first_name']) : '';
$sections  = isset($done['sections']) ? (int) $done['sections'] : 0;
$replyTo   = isset($done['email']) ? trim((string) $done['email']) : '';

$practiceName  = getSetting('practice_name');
$practiceEmail = getSetting('practice_email');
$replyDays     = max(1, getSettingInt('intake_reply_days', 2));

$greeting = ($firstName !== '') ? 'Thank you, ' . $firstName . '.' : 'Thank you.';
$dayPhrase = itkWord($replyDays) . ' working day' . ($replyDays === 1 ? '' : 's');

// Below about four sections the sentence stops being true to the effort it is
// acknowledging, so it is dropped rather than shrunk.
$effortLine = ($sections >= 4)
    ? 'You worked through ' . itkWord($sections) . ' sections of questions that are not easy to answer. Thank you for the care you took over them.'
    : 'Thank you for the care you took over your answers.';

// Falls back to the practice address so step two never reads "we will write to
// you at" followed by nothing.
$writeToAddress = ($replyTo !== '') ? $replyTo : $practiceEmail;

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="description" content="Your intake form has been received." />
  <title>Intake received | <?php echo itkOut($practiceName); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <!-- Same cascade as the questionnaire this page follows: components, then
       site chrome, then the overrides that have the last word. The page's own
       stylesheet loads after all three so its scoped rules win. -->
  <link rel="stylesheet" href="css/main-from-app.css" />
  <link rel="stylesheet" href="css/index-redesign.css" />
  <link rel="stylesheet" href="css/site-overrides.css" />
  <link rel="stylesheet" href="css/intake-thankyou.css" />
  <link rel="icon" type="image/png" href="images/logo3.png" />
</head>

<body>
  <!-- Navbar -->
  <nav class="rw-navbar">
    <div class="rw-container">
      <a href="index.html" class="rw-logo">
        <img src="images/logo3.png" alt="Rewire with Kajal Logo">
      </a>

      <div class="rw-nav-links d-none d-lg-flex">
        <a href="index.html">Home</a>
        <a href="index.html#how-it-works">How it Works</a>
        <a href="about.html">About</a>
        <a href="blogs.html">Blog</a>
      </div>

      <div class="rw-nav-actions d-none d-lg-flex">
        <a href="appointment.html" class="rw-btn rw-btn-primary">Book Appointment</a>
        <a href="https://www.instagram.com/rewirewithkajal/" class="rw-social-icon" target="_blank"
          aria-label="Instagram"><i class="bi bi-instagram"></i></a>
        <a href="index.html" class="rw-social-icon" aria-label="Website"><i class="bi bi-globe2"></i></a>
        <a href="mailto:hello@rewirewithkajal.com" class="rw-social-icon" aria-label="Email"><i
            class="bi bi-envelope"></i></a>
      </div>

      <button class="navbar-toggler d-lg-none" type="button"
        style="border:none; background:transparent; font-size:24px;">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </nav>
  <div class="rw-site-line-art" aria-hidden="true">
    <div class="rw-site-line-art__float">
      <svg class="rw-site-line-art__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 520"
        preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
          <linearGradient id="rw-site-orange-grad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="rgba(249, 115, 22, 0.09)" />
            <stop offset="45%" stop-color="rgba(249, 115, 22, 0.16)" />
            <stop offset="100%" stop-color="rgba(249, 115, 22, 0.07)" />
          </linearGradient>
        </defs>
        <path class="rw-site-line-art__path rw-site-line-art__path--1" fill="none" stroke="url(#rw-site-orange-grad)"
          stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"
          d="M 32 168 C 32 78 118 42 228 72 C 338 102 372 196 308 278 C 244 360 118 348 76 268 C 48 218 32 198 32 168" />
        <path class="rw-site-line-art__path rw-site-line-art__path--2" fill="none" stroke="rgba(249, 115, 22, 0.13)"
          stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round"
          d="M 468 52 C 612 8 792 36 892 118 C 992 200 968 292 862 332 C 756 372 628 328 564 248 C 508 178 468 128 468 52" />
        <path class="rw-site-line-art__path rw-site-line-art__path--3" fill="none" stroke="rgba(249, 115, 22, 0.11)"
          stroke-width="1" stroke-linecap="round" stroke-linejoin="round"
          d="M 128 88 C 268 12 396 28 528 96 C 652 158 592 212 484 232 C 388 250 268 198 196 128 C 158 92 138 82 128 88" />
        <path class="rw-site-line-art__path rw-site-line-art__path--4" fill="none" stroke="rgba(249, 115, 22, 0.12)"
          stroke-width="1.05" stroke-linecap="round" stroke-linejoin="round"
          d="M 636 198 C 712 128 824 152 868 228 C 912 304 836 372 748 372 C 660 372 584 308 604 236 C 616 198 628 192 636 198" />
        <path class="rw-site-line-art__path rw-site-line-art__path--5" fill="none" stroke="rgba(249, 115, 22, 0.09)"
          stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round"
          d="M 88 412 C 276 348 484 432 712 384 C 928 338 1088 408 1168 364 C 1192 350 1196 346 1196 346" />
      </svg>
      <span class="rw-site-line-art__orb rw-site-line-art__orb--1"></span>
      <span class="rw-site-line-art__orb rw-site-line-art__orb--2"></span>
      <span class="rw-site-line-art__orb rw-site-line-art__orb--3"></span>
    </div>
  </div>


  <main class="itk-page" id="main-content">
    <section class="itk-shell">
      <div class="itk-card">
        <div class="itk-reveal itk-reveal-1">
          <p class="itk-eyebrow">Received</p>
          <h1 class="itk-title"><?php echo itkOut($greeting); ?><span>Your answers have reached us.</span></h1>
          <p class="itk-lede"><?php echo itkOut($effortLine); ?></p>
          <p class="itk-note">Nothing else is needed from you right now.</p>
        </div>

        <hr class="itk-rule" />

        <h2 class="itk-label">What happens next</h2>
        <!-- role="list" restores the list semantics that list-style:none strips
             in Safari/VoiceOver: three ordered steps that read as three
             unrelated paragraphs are the wrong thing to hear. -->
        <ol class="itk-steps" role="list">
          <li class="itk-step itk-reveal">
            <div class="itk-step-body">
              <h3>Your responses are read</h3>
              <p>Usually within <?php echo itkOut($dayPhrase); ?>.</p>
            </div>
          </li>
          <li class="itk-step itk-reveal">
            <div class="itk-step-body">
              <h3>We write to you</h3>
              <p>At <?php echo itkOut($writeToAddress); ?>. If nothing has arrived, check your spam folder before
                getting in touch.</p>
            </div>
          </li>
          <li class="itk-step itk-reveal">
            <div class="itk-step-body">
              <h3>You settle on a first session</h3>
              <p>We offer times that fit what you told us about your availability, and you pick the one that works.</p>
            </div>
          </li>
        </ol>

        <hr class="itk-rule" />

        <div class="itk-reveal itk-reveal-2">
          <p class="itk-after-text">Remembered something, or want to change an answer? Write to
            <a href="mailto:<?php echo itkOut($practiceEmail); ?>"><?php echo itkOut($practiceEmail); ?></a>
            and we will update it for you. There is no need to fill the form in again.
          </p>
          <a class="itk-back" href="index.html">Back to the site</a>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="footer-wave" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
        <path
          d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z"
          class="shape-fill"></path>
      </svg>
    </div>
    <div class="container footer-container">
      <div class="footer-panel">
        <div class="footer-col brand-col">
          <img src="images/logo3.png" alt="Rewire With Kajal" class="footer-logo" width="180" height="188"
            loading="lazy" decoding="async" />
          <p class="footer-description">Calm, professional support for your mind.</p>
        </div>
        <div class="footer-col links-col">
          <h3 class="footer-heading">Quick Links</h3>
          <p class="footer-col-lead">Stories, services, and easy booking here.</p>
          <ul class="footer-links">
            <li><a href="index.html">Home</a></li>
            <li><a href="about.html">About Us</a></li>
            <li><a href="blogs.html">Blog Insights</a></li>
            <li><a href="appointment.html">Contact</a></li>
          </ul>
        </div>
        <div class="footer-col contact-col">
          <h3 class="footer-heading">Get in Touch</h3>
          <p class="footer-col-lead">Appointment-based care in person or online.</p>
          <ul class="footer-contact-info">
            <li><i class="bi bi-envelope contact-icon" aria-hidden="true"></i><span><a
                  href="mailto:hello@rewirewithkajal.com">hello@rewirewithkajal.com</a></span></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="container footer-bottom-inner">
        <p>© <span id="y"></span> Rewire With Kajal. All rights reserved.</p>
        <div class="social-links">
          <a href="https://www.instagram.com/rewirewithkajal/" aria-label="Instagram" rel="noopener noreferrer"
            target="_blank"><i class="bi bi-instagram"></i></a>
          <a href="https://www.linkedin.com/" aria-label="LinkedIn" rel="noopener noreferrer" target="_blank"><i
              class="bi bi-linkedin"></i></a>
          <a href="https://twitter.com/" aria-label="Twitter" rel="noopener noreferrer" target="_blank"><i
              class="bi bi-twitter-x"></i></a>
        </div>
      </div>
    </div>
  </footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>
  <script>
    document.getElementById("y").textContent = new Date().getFullYear();
  </script>
  <script src="js/main.js"></script>
  <script src="js/common.js"></script>
</body>

</html>
