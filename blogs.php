<?php
require_once __DIR__ . '/db-config.php';
$db = getDbConnection();

// Fetch all published blog posts
$stmt = $db->query("SELECT * FROM `blogs` WHERE `status`='published' ORDER BY `created_at` DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate featured (first) and remaining
$featured = !empty($posts) ? array_shift($posts) : null;

// Get distinct categories for filter pills
$catStmt = $db->query("SELECT DISTINCT `category` FROM `blogs` WHERE `status`='published' ORDER BY `category`");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Category background colors
$catColors = [
    'Anxiety'       => '#f4e8db',
    'Relationships' => '#e6ebe0',
    'Self-Growth'   => '#eae6eb',
    'Trauma'        => '#f1edee',
    'Mindfulness'   => '#e8efe6',
];

// Category emojis
$catEmojis = [
    'Anxiety'       => '💡',
    'Relationships' => '🌿',
    'Self-Growth'   => '✨',
    'Trauma'        => '🤍',
    'Mindfulness'   => '🧘',
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="Blog — mental wellness insights from Rewire With Kajal." />
    <title>Blog Insights | Rewire With Kajal</title>
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
      crossorigin="anonymous"
    />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="css/main-from-app.css" />
    <link rel="stylesheet" href="css/site-overrides.css" />
    <link rel="icon" type="image/png" href="images/logo3.png" />
    <noscript>
      <style>
        .rw-reveal {
          opacity: 1 !important;
          transform: none !important;
        }
      </style>
    </noscript>
  </head>
  <body class="rw-site-bg-lines">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="home-hero-navbar-wrapper" id="site-nav">
      <div class="home-hero-navbar">
        <a class="hhn-logo" href="index.html" aria-label="Rewire With Kajal home">
          <img src="images/logo3.png" width="160" height="167" alt="Rewire With Kajal" />
        </a>
        <nav class="hhn-links d-none d-lg-flex align-items-center" aria-label="Primary">
          <a href="index.html#plain-english">How it works</a>
          <a href="about.html">About</a>
          <a href="blogs.php">Blog</a>
          <a class="hhn-book-btn" href="appointment.html">Book Appointment</a>
        </nav>
        <div class="hhn-socials d-none d-lg-flex align-items-center">
          <a href="https://www.instagram.com/rewirewithkajal/" aria-label="Instagram" rel="noopener noreferrer" target="_blank"
            ><i class="bi bi-instagram" aria-hidden="true"></i
          ></a>
          <a href="index.html" aria-label="Home"><i class="bi bi-globe2" aria-hidden="true"></i></a>
          <a href="mailto:hello@rewirewithkajal.com" aria-label="Email"><i class="bi bi-envelope" aria-hidden="true"></i></a>
        </div>
        <button type="button" class="hhn-menu-toggle d-flex d-lg-none" aria-label="Open menu" aria-expanded="false" data-nav-toggle>
          <i class="bi bi-list" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <div class="rw-site-line-art" aria-hidden="true">
      <div class="rw-site-line-art__float">
        <svg
          class="rw-site-line-art__svg"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 1200 520"
          preserveAspectRatio="xMidYMid slice"
          focusable="false"
        >
          <defs>
            <linearGradient id="rw-site-orange-grad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="rgba(249, 115, 22, 0.09)" />
              <stop offset="45%" stop-color="rgba(249, 115, 22, 0.16)" />
              <stop offset="100%" stop-color="rgba(249, 115, 22, 0.07)" />
            </linearGradient>
          </defs>
          <path class="rw-site-line-art__path rw-site-line-art__path--1" fill="none" stroke="url(#rw-site-orange-grad)" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" d="M 32 168 C 32 78 118 42 228 72 C 338 102 372 196 308 278 C 244 360 118 348 76 268 C 48 218 32 198 32 168" />
          <path class="rw-site-line-art__path rw-site-line-art__path--2" fill="none" stroke="rgba(249, 115, 22, 0.13)" stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round" d="M 468 52 C 612 8 792 36 892 118 C 992 200 968 292 862 332 C 756 372 628 328 564 248 C 508 178 468 128 468 52" />
          <path class="rw-site-line-art__path rw-site-line-art__path--3" fill="none" stroke="rgba(249, 115, 22, 0.11)" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" d="M 128 88 C 268 12 396 28 528 96 C 652 158 592 212 484 232 C 388 250 268 198 196 128 C 158 92 138 82 128 88" />
          <path class="rw-site-line-art__path rw-site-line-art__path--4" fill="none" stroke="rgba(249, 115, 22, 0.12)" stroke-width="1.05" stroke-linecap="round" stroke-linejoin="round" d="M 636 198 C 712 128 824 152 868 228 C 912 304 836 372 748 372 C 660 372 584 308 604 236 C 616 198 628 192 636 198" />
          <path class="rw-site-line-art__path rw-site-line-art__path--5" fill="none" stroke="rgba(249, 115, 22, 0.09)" stroke-width="0.95" stroke-linecap="round" stroke-linejoin="round" d="M 88 412 C 276 348 484 432 712 384 C 928 338 1088 408 1168 364 C 1192 350 1196 346 1196 346" />
        </svg>
        <span class="rw-site-line-art__orb rw-site-line-art__orb--1"></span>
        <span class="rw-site-line-art__orb rw-site-line-art__orb--2"></span>
        <span class="rw-site-line-art__orb rw-site-line-art__orb--3"></span>
      </div>
    </div>

    <div class="abt2-wrapper" id="main-content">
      <div class="container blog-premium-toolbar-wrap">
        <div class="blog-premium-filters rw-reveal" id="blog-filters" role="tablist" aria-label="Filter posts by topic">
          <button type="button" class="blog-premium-filter-pill active" data-topic="all">All Posts</button>
          <?php foreach ($categories as $cat): ?>
            <button type="button" class="blog-premium-filter-pill" data-topic="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($featured): ?>
      <section class="blog-premium-featured-section">
        <div class="blog-premium-featured-inner">
          <div class="blog-premium-featured-label rw-reveal">Featured Article</div>
          <a href="blog-detail.php?slug=<?php echo urlencode($featured['slug']); ?>" class="blog-premium-featured-card rw-reveal text-decoration-none text-reset" data-topics="<?php echo htmlspecialchars($featured['category']); ?>">
            <div class="blog-premium-featured-img">
              <?php if ($featured['cover_image']): ?>
                <img src="<?php echo htmlspecialchars($featured['cover_image']); ?>" alt="" loading="eager" />
              <?php else: ?>
                <div style="width:100%;height:100%;background:<?php echo $catColors[$featured['category']] ?? '#f4e8db'; ?>;display:flex;align-items:center;justify-content:center;font-size:4rem;"><?php echo $catEmojis[$featured['category']] ?? '📝'; ?></div>
              <?php endif; ?>
              <div class="blog-premium-cat-badge"><?php echo htmlspecialchars($featured['category']); ?></div>
            </div>
            <div class="blog-premium-featured-body">
              <div class="blog-premium-date-time"><?php echo date('F j, Y', strtotime($featured['created_at'])); ?> · <?php echo $featured['read_time']; ?> min read</div>
              <div class="blog-premium-featured-title"><?php echo htmlspecialchars($featured['title']); ?></div>
              <div class="blog-premium-featured-excerpt">
                <?php echo htmlspecialchars($featured['excerpt']); ?>
              </div>
              <div class="blog-premium-featured-footer">
                <div class="blog-premium-author">
                  <div class="blog-premium-author-avatar">K</div>
                  <div class="blog-premium-author-info">
                    <strong>Kajal</strong>
                    <span>Licensed Therapist</span>
                  </div>
                </div>
                <span class="blog-premium-read-btn">Read Article <span class="arrow">→</span></span>
              </div>
            </div>
          </a>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!empty($posts)): ?>
      <section class="blog-premium-grid-section">
        <div class="blog-premium-grid-inner">
          <header class="blog-premium-grid-header rw-reveal">
            <h2 class="blog-premium-grid-title">More <em>Insights</em></h2>
          </header>
          <div class="blog-premium-grid" id="blog-grid">
            <?php foreach ($posts as $post): ?>
              <article class="blog-premium-card rw-reveal" data-topics="<?php echo htmlspecialchars($post['category']); ?>">
                <a href="blog-detail.php?slug=<?php echo urlencode($post['slug']); ?>" class="text-decoration-none text-reset">
                  <div class="blog-premium-card-img" style="background: <?php echo $catColors[$post['category']] ?? '#f4e8db'; ?>">
                    <?php if ($post['cover_image']): ?>
                      <img src="<?php echo htmlspecialchars($post['cover_image']); ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;" />
                    <?php else: ?>
                      <div class="blog-premium-emoji-fallback" style="font-size: 3rem"><?php echo $catEmojis[$post['category']] ?? '📝'; ?></div>
                    <?php endif; ?>
                    <div class="blog-premium-card-cat"><?php echo htmlspecialchars($post['category']); ?></div>
                  </div>
                  <div class="blog-premium-card-body">
                    <div class="blog-premium-card-meta"><?php echo date('F j, Y', strtotime($post['created_at'])); ?> · <?php echo $post['read_time']; ?> min read</div>
                    <h3 class="blog-premium-card-title"><?php echo htmlspecialchars($post['title']); ?></h3>
                    <p class="blog-premium-card-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                    <div class="blog-premium-card-footer">
                      <span class="blog-premium-card-author">By Kajal</span>
                      <span class="blog-premium-card-arrow">→</span>
                    </div>
                  </div>
                </a>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!$featured && empty($posts)): ?>
      <section class="blog-premium-grid-section">
        <div class="blog-premium-grid-inner" style="text-align:center; padding:4rem 1rem;">
          <h2 class="blog-premium-grid-title">Coming Soon</h2>
          <p style="color:#6b7280; margin-top:0.5rem;">New articles are on the way. Stay tuned!</p>
        </div>
      </section>
      <?php endif; ?>
    </div>

    <footer class="site-footer">
      <div class="footer-wave" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
          <path
            d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z"
            class="shape-fill"
          ></path>
        </svg>
      </div>
      <div class="container footer-container">
        <div class="footer-panel">
          <div class="footer-col brand-col">
            <img src="images/logo3.png" alt="Rewire With Kajal" class="footer-logo" width="180" height="188" loading="lazy" decoding="async" />
            <p class="footer-description">Calm, professional support for your mind.</p>
          </div>
          <div class="footer-col links-col">
            <h3 class="footer-heading">Quick Links</h3>
            <p class="footer-col-lead">Stories, services, and easy booking here.</p>
            <ul class="footer-links">
              <li><a href="index.html">Home</a></li>
              <li><a href="about.html">About Us</a></li>
              <li><a href="blogs.php">Blog Insights</a></li>
              <li><a href="appointment.html">Contact</a></li>
            </ul>
          </div>
          <div class="footer-col contact-col">
            <h3 class="footer-heading">Get in Touch</h3>
            <p class="footer-col-lead">Appointment-based care in person or online.</p>
            <ul class="footer-contact-info">
              <li><i class="bi bi-envelope contact-icon" aria-hidden="true"></i><span><a href="mailto:hello@rewirewithkajal.com">hello@rewirewithkajal.com</a></span></li>
            </ul>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <div class="container footer-bottom-inner">
          <p>© <span id="y"></span> Rewire With Kajal. All rights reserved.</p>
          <div class="social-links">
            <a href="https://www.instagram.com/rewirewithkajal/" aria-label="Instagram" rel="noopener noreferrer" target="_blank"
              ><i class="bi bi-instagram"></i
            ></a>
            <a href="https://www.linkedin.com/" aria-label="LinkedIn" rel="noopener noreferrer" target="_blank"><i class="bi bi-linkedin"></i></a>
            <a href="https://twitter.com/" aria-label="Twitter" rel="noopener noreferrer" target="_blank"><i class="bi bi-twitter-x"></i></a>
          </div>
        </div>
      </div>
    </footer>
    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
      integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
      crossorigin="anonymous"
    ></script>
    <script>
      document.getElementById("y").textContent = new Date().getFullYear();
    </script>
    <script src="js/main.js"></script>
    <script src="js/common.js"></script>
    <script src="js/blogs.js"></script>
  </body>
</html>
