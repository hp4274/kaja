# Full Page UI Design & Implementation Specification (`ui.md`)

> **Rewire With Kajal Landing Page**
> Comprehensive UI specification, component breakdown, design tokens, responsive rules, and layout architecture based on the reference design.

---

## 1. Global Visual Aesthetics & Design System Tokens

### A. Color Palette Tokens
| Token Name | Hex Code | Purpose / Usage |
|---|---|---|
| `--rw-bg-hero` | `#FAF7F2` | Warm cream off-white background for Hero section. |
| `--rw-bg-card` | `#FFFFFF` / `#FFFDFB` | Card surfaces & floating quote components. |
| `--rw-bg-section` | `#FAF5F0` / `#FFFBF7` | Alternating section backgrounds. |
| `--rw-primary` | `#E07A5F` / `#E88663` | Terracotta / Burnt orange primary brand accent. |
| `--rw-dark-teal` | `#0F3B36` / `#0B3532` | Deep slate teal primary CTA button & highlight cards. |
| `--rw-footer-bg` | `#082624` | Deep charcoal teal footer background. |
| `--rw-text-dark` | `#1C2A29` | Deep charcoal slate heading color. |
| `--rw-text-body` | `#586865` / `#4A5568` | Muted slate text for subtext & paragraphs. |
| `--rw-border-light` | `#F0ECE6` / `#EFEAE4` | Card borders, dividers, & accordion borders. |

### B. Typography Tokens
- **Heading Font Family**: `'Playfair Display', serif`
  - Font Weights: 500 (Regular/Medium), 600 (Semi-bold), 700 (Bold).
- **Body Font Family**: `'Inter', sans-serif` / `'Plus Jakarta Sans', sans-serif`
  - Font Weights: 300, 400, 500, 600.
- **Eyebrow Styling**:
  - `font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; color: var(--rw-primary); margin-bottom: 16px; display: block;`

---

## 2. Detailed Component & Section Specifications

### Section 1: Navigation Bar (`rw-navbar`)
- **Logo**: Rewire with Kajal logo (`images/logo3.png`).
- **Nav Links (4 Items)**: `Home`, `How it Works`, `About`, `Blog`. Active link features terracotta text `#E07A5F` and bottom indicator line.
- **Nav Actions**:
  - Primary Pill CTA Button: `Book Appointment` (`appointment.html`, Deep Slate Teal `#0F3B36`).
  - Social & Contact Icons: Instagram (`bi-instagram`), Globe/Website (`bi-globe2`), Email (`bi-envelope`).

---

### Section 2: Hero Section & Stats Bar (`rw-hero` & `rw-stats-section`)
- **Left Column**:
  - Eyebrow: `HYPNOTHERAPY FOR LASTING CHANGE`
  - Main Title: `Understand. Heal.` (Dark slate `#1C2A29`) `<br>` `<span class="rw-accent-text">Rewire.</span> Grow.` (Terracotta `#E07A5F`).
  - Subtext: `Your mind holds the answers. Hypnotherapy helps you access them, heal the past, and create the life you truly want.` (Max-width 470px).
  - CTA Buttons:
    - Primary CTA: `Book a Free Intro Call →` (`#0F3B36` pill button).
    - Secondary CTA: `How It Works ▷` (White button with `#E07A5F` border + circular play button icon).
  - Trust Badges: `Safe & Confidential`, `Evidence-Informed`, `Personalized for You`.
- **Right Column**:
  - Asymmetric Organic Backdrop (`.rw-hero-bg-backdrop`): Asymmetrical fluid organic shape (`border-radius: 52% 48% 38% 62% / 60% 42% 58% 40%`, warm color `#F4ECE4`).
  - Hero Woman Photo (`images/hero-woman.png`): Asymmetrical organic liquid shape mask (`border-radius: 48% 52% 35% 65% / 58% 38% 62% 42%`).
  - Botanical Floral Branch SVG (`images/noun-floral-5095798.svg`): Rotated `16deg` rightward, positioned with `z-index: 1.5` under the hero woman photo (`z-index: 2`) so the image overlaps the right side of the leaf branch, matching reference design.
  - Floating Glassmorphism Quote Card (`rw-hero-quote`):
    - Frosted Glassmorphism: `background: rgba(255, 255, 255, 0.78); backdrop-filter: blur(18px) saturate(180%); border: 1px solid rgba(255, 255, 255, 0.85); box-shadow: 0 20px 50px rgba(0, 0, 0, 0.06);`.
    - Reversed Quotation Mark Icon: Terracotta `66` icon (`48px` SVG `#E07A5F`) horizontally reversed with `transform: scaleX(-1)`.
    - Quote text: `"Calm is not something you find, it's something you remember."` (Size `21px`, `Playfair Display`).
    - Author: `- Unknown` (`14px`, `#7B8B88`).
- **Stats Floating Card (`rw-stats-container`)**:
  - White floating pill card with vertical border dividers:
    1. `200+` Clients Transformed
    2. `8+` Years of Experience
    3. `98%` Client Satisfaction
    4. `100%` Confidential & Safe Space

---

### Section 2: What is Hypnotherapy? (`rw-what-is`)
- **Spacing**: Reduced vertical section padding to `45px 0` for compact, balanced spacing above and below.
- **Middle Image Graphic**: Increased `images/brain-spiral.png` graphic size to `max-width: 450px` for prominent visual impact.
- **Left Column**: Section eyebrow `WHAT IS HYPNOTHERAPY?`, heading `It's not magic. It's science.`, and overview paragraph.
- **Right Column FAQ Box**: Floating white card (`.rw-faq-box`) with collapsible accordion questions. First FAQ open by default.
- **Right Column (Accordion / FAQ List)**:
  - 5 White rounded accordion items with dropdown arrow icons (`bi-chevron-down`):
    1. `Will I lose control?`
    2. `Can I get stuck in hypnosis?`
    3. `Will I reveal my secrets?`
    4. `Is hypnosis like sleep?`
    5. `Do I have to believe in it?`
  - Footer Link: `See all answers →` (Terracotta arrow link).

---

### Section 4: How I Can Help You (`rw-services-section`)
- **Header**: Eyebrow: `HOW I CAN HELP YOU`
- **6 Service Cards Grid**:
  - Card 1: `Anxiety & Stress` (Reduce worry, overthinking & panic).
  - Card 2: `Emotional Healing` (Heal past wounds & release emotional baggage).
  - Card 3: `Confidence & Self-Esteem` (Rewire self-doubt into self-worth).
  - Card 4: `Sleep & Insomnia` (Improve sleep naturally & wake up refreshed).
  - Card 5: `Habits & Addictions` (Break free from unhealthy habits).
  - Card 6: `Weight & Lifestyle` (Heal emotional eating & support healthy habits).
- **Card Aesthetics**: Crisp white background, rounded corners (`border-radius: 20px`), thin warm border (`#F3ECE4`), peachy circle icon container (`#FAF2EC`), terracotta outline icons.

---

### Section 5: How It Works (`rw-process-section`)
- **Header**: Eyebrow: `HOW IT WORKS`
- **4-Step Process Timeline**:
  - Step 1: `Connect` (We connect and understand your concerns).
  - Step 2: `Discover` (We explore the root cause and patterns).
  - Step 3: `Heal` (You relax deeply in a safe and guided state).
  - Step 4: `Transform` (We reprogram limiting beliefs and create lasting change).
- **Connector**: Horizontal dotted line with terracotta circular nodes connecting steps.

---

### Section 5: Your Guide On This Journey (About Kajal Banner)
- **Container**: Full-width soft warm beige banner (`max-width: 1440px`, `background: #FFF6EE`, `border-radius: 28px`, `box-shadow: 0 12px 35px rgba(0,0,0,0.02)`).
- **Grid Layout**: 3-Column ratio (`col-lg-3` left photo, `col-lg-5` center text content, `col-lg-4` right feature list).
- **Left Photo**:
  - Image (`images/kajal-about.png`): Right edge curved in a wide arch (`border-top-right-radius: 160px`, `border-bottom-right-radius: 160px`).
- **Center Content**:
  - Eyebrow: `HI, I'M KAJAL` (`11px`, letter-spacing `2px`, tracked muted tone `#9E8578`).
  - Heading: `Your guide on this journey.` (Playfair Display serif `34px`, color `#1C2A29`).
  - Body Text: `I'm a certified Clinical Hypnotherapist...`
  - Pill CTA Button: `Know My Story →` (Terracotta outline pill button `#E07A5F`, `border-radius: 50px`).
- **Right Features List**:
  - 3 Feature cards with circular white shadow badges and terracotta SVG icons:
    1. **Professional & Certified**: `Trained in clinical hypnotherapy with years of experience.`
    2. **Compassionate & Empathetic**: `A safe space to be heard, understood and supported.`
    3. **Results-Oriented**: `Helping you achieve long-lasting change, not quick fixes.`
- **Right Decor**:
  - Botanical Floral Branch SVG (`images/noun-floral-5095798.svg`): Positioned with `overflow: visible` on `.rw-guide-banner` (`right: -55px; bottom: -40px; width: 215px; transform: rotate(10deg); z-index: 10`) so the leaf branch extends past the right edge and bottom corner of the card container, matching reference mockup.

---

### Section 6: Testimonials (Kind Words From My Clients)
- **Container**: Soft warm beige banner (`max-width: 1440px`, `background: #FFF6EE`, `border-radius: 28px`, `padding: 44px 50px 48px 50px`).
- **Header**: Centered tracked eyebrow `KIND WORDS FROM MY CLIENTS` (`11px`, `letter-spacing: 2.5px`, color `#5A6B6C`).
- **Carousel Navigation**:
  - Circular white shadow navigation buttons (`.rw-carousel-nav-btn`) positioned on left (`left: -23px`) and right (`right: -23px`) edges with terracotta arrow icons (`#E07A5F`).
- **3 Testimonial Cards Layout**:
  - **Left Card (White)**: Background `#ffffff`, 5 terracotta stars (`#E07A5F`), quote by Priya S. (`Client since 2022`).
  - **Center Card (Deep Slate Teal Accent Card)**: Accent dark teal background (`#0C3834`), white quote text (`#ffffff`), 5 terracotta stars (`#E07A5F`), quote by Rahul M. (`Client since 2023`).
  - **Right Card (White)**: Background `#ffffff`, 5 terracotta stars (`#E07A5F`), quote by Ananya K. (`Client since 2021`).

---

### Section 7: Insights & Inspiration (`rw-blog-card`)
- **Grid Layout**: 4-Column card layout featuring 3 blog post cards and 1 booking CTA callout card.
- **Blog Cards**:
  1. `images/blog-overthinking.png`: Mindset - *Why Overthinking Keeps You Stuck (And How to Break Free)*.
  2. `images/blog-session.png`: Hypnotherapy - *What Really Happens During a Hypnotherapy Session?*.
  3. `images/blog-nervous.png`: Healing - *5 Simple Ways to Calm Your Nervous System Naturally*.
- **Booking Callout**: *Ready to start your healing journey?* with `Book Your Call Now →` button (`#0C3834`).d `#FAF2EC` + Title: `Ready to start your healing journey?` + Button: `Book Your Call Now →` (`#0F3B36`).

---

---

## About Page (`about.html`) Specifications
- **Navigation Bar**: Identical header navbar structure to `index.html`.
- **Section 1: Hero (`.rw-about-hero-bg`)**:
  - Soft warm beige card container (`#FFF6EE`, `border-radius: 32px`, `max-width: 1440px`, `margin-top: 50px` for clean separation below sticky navbar).
  - Left Col: `I'M KAJAL` pill badge (`#FBE8DB`), title `Your Guide to <span class="rw-accent-text">Healing,</span> Growth & Lasting Change.`, overview paragraph, `Book a Session →` (`.rw-btn.rw-btn-primary`) and `How It Works ▷` (`.rw-btn.rw-btn-secondary` with orange play icon wrap) buttons.
  - Right Col:
    - Uneven organic liquid portrait photo (`border-radius: 48% 52% 35% 65% / 58% 38% 62% 42%`) with background cream organic blob (`#F4ECE4`).
    - Top-left rotated leaf SVG (`images/noun-floral-5095798.svg`) curving gracefully behind the photo.
    - Top-right Glassmorphism Quote Badge (`top: 70px`, `right: -75px`, `max-width: 250px`, positioned to the side so Kajal's portrait photo remains completely clear and visible).
  - Bottom 4 Glassmorphism Stats Cards: 4 glass cards (`backdrop-filter: blur(16px)`, `200+ Clients Helped`, `8+ Years in Practice`, `98% Satisfaction Rate`, `100% Confidential & Safe Space`).
- **Section 2: My Story (`.rw-about-story`)**:
  - Left Col: `MY STORY` badge, `A Journey That Began With <span class="rw-accent-text">My Own Healing</span>` title, 3 narrative paragraphs, signature author block with avatar badge `K`, title, and handwritten cursive signature `Kajal`.
  - Right Col: 3-photo grid (1 large journal writing photo + 2 right-stacked cozy room & writing photos).
- **Section 3: My Philosophy Banner**:
  - Deep Slate Teal card (`#0C3834`, `border-radius: 28px`), `MY PHILOSOPHY` badge, `Therapy Is a Space to <span class="rw-accent-text">Be, Not Just Perform.</span>` title, left & right overlapping leaf SVGs (`images/noun-floral-5095798.svg`), 4 glassmorphism cards (`Non-Judgment`, `Collaboration`, `Evidence-Based`, `Your Pace`).
- **Section 4: Credentials**:
  - Left Col: `CREDENTIALS` badge, `Built on <span class="rw-accent-text">Solid Foundations</span>` title, subtext, `View Full Credentials →` button.
  - Right Col: Timeline list with terracotta line and circular dots.
- **Section 5: Therapeutic Modalities**:
  - 6 White Cards Grid: CBT, Somatic Therapy, Mindfulness-Based Therapy, Attachment-Based Therapy, Trauma-Informed Care, Narrative Therapy.
- **Section 6: Words From Those I've Helped (Testimonials)**:
  - Exact testimonial banner section copied from `index.html` featuring 3 cards (Card 1: Priya S., Card 2 Accent `#0C3834`: Rahul M., Card 3: Ananya K.) and circular left/right navigation arrow buttons.
- **Section 7: Ready to Begin Your Healing Journey? CTA Banner**:
  - Warm beige card (`#FFF6EE`, `border-radius: 28px`), `Ready to Begin Your <span class="rw-accent-text">Healing Journey?</span>` title, `Book a Session →` button, `Complete Intake Form` button.

---

## Blog Page (`blogs.php` & `blogs.html`) Specifications
- **Navigation Bar**: Identical header navbar structure to `index.html` (with `Blog` marked active).
- **Hero Section (`.rw-blog-hero`)**:
  - Title: `Blog & <span class="rw-accent-text">Insights</span>`.
  - Subtitle: `Thoughts, tools, and inspiration to help you understand your mind and create meaningful change.`.
- **Main White Card Container (`.rw-blog-main-card`)**:
  - `background: #ffffff`, `border-radius: 32px`, `padding: 48px`, `max-width: 1440px`.
  - **Category Filter Pills**: `All Posts` (Active `#0C3834`), `Anxiety`, `Mindfulness`, `Relationships`, `Self-Growth`, `Trauma`.
  - **Featured Article Hero Card**: Horizontal card with left cover photo, badge `MINDFULNESS`, label `⭐ FEATURED ARTICLE`, title `Calm is a skill you can practice`, excerpt, author badge `Kajal`, and `Read Article →` link.
  - **More Insights Header**: Title `More <br><span class="rw-accent-text">Insights</span>`.
  - **4 Category Columns Grid**:
    - Card 1 (Anxiety): Soft peach header `#FFF0E6`, icon 💡, title `Understanding anxiety loops`.
    - Card 2 (Relationships): Soft sage header `#EBF2EC`, icon 🌿, title `Emotional safety in relationships`.
    - Card 3 (Self-Growth): Soft lavender header `#F3EDF7`, icon ✨, title `Burnout is not a character flaw`.
    - Card 4 (Trauma): Soft mauve header `#F7EFEF`, icon 💜, title `Gentle pacing after overwhelm`.
  - **Newsletter Subscription Banner**: Soft apricot banner `#FFF5EE`, `Want more tools for your healing journey?` title, email input field, and `Subscribe` primary button.
- **Footer**: Identical footer structure to `index.html`.

---

### Section 10: Footer (`rw-footer`)
- **Background**: Deep dark slate teal `#082624`.
- **Columns**: Logo + Slogan + Social Icons, Quick Links, Resources, Get In Touch (Email, Phone, Location), Book a Session CTA.
- **Copyright Bar**: `© 2025 Rewire with Kajal. All rights reserved.`
