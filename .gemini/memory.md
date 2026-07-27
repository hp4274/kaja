# Kaja Project Memory & Session State

> **CRITICAL AGENT INSTRUCTION**:
> - **At Start of Chat/Session**: ALWAYS read `.gemini/memory.md`, `.gemini/content.md`, `.gemini/theme.md`, and `.gemini/skills.md` to restore full context, design system, and technical state.
> - **At Completion of Chat/Session**: ALWAYS update `.gemini/memory.md` with a summary of recent changes, new features added, active tasks, and updated project status.

---

## 1. Project Overview
- **Project Name**: Kaja Healthcare & Intake Portal / Rewire With Kajal
- **Database Name**: `kaja_db` (MySQL / MariaDB)
- **Tech Stack**: HTML5, Vanilla JavaScript, PHP 8+, MySQL, CSS3 (Soft-Modern Theme Architecture).
- **Core Purpose**: Patient intake management, lead acquisition, appointment booking, blog publishing, and admin dashboard operations.

---

## 2. Architecture & Data Flow
- **Database Schema (`schema.sql`)**:
  - `leads`: Lead acquisition from main landing & appointment forms. Status tracking (`new`, `accepted`, `converted`, `declined`).
  - `intake`: Short intake submission records.
  - `patient-intake`: Comprehensive clinical patient intake details (Personal Info, Medical History, Lifestyle, Consent).
  - `blogs`: Dynamic blog posts, tags, hero images, and author info.
  - `admin_users`: Admin login authentication and session security.
- **Backend Endpoints (`.php`)**:
  - `db-config.php`: Database connection initialization (PDO).
  - `submit-form.php`: Form handling for leads, short intakes, and full patient intakes.
  - `login-process.php` & `logout.php`: Session handling for admin authentication.
  - `dashboard.php`: Admin panel for reviewing leads, intakes, and appointments.
  - `blogs.php` & `blog-detail.php`: Dynamic blog listing and rendering.

---

## 3. Session Log & Memory History

### Log Entry: Initial Setup & Skills Integration
- **Date**: 2026-07-25
- **Actions Completed**:
  - Created `.gemini/memory.md` for session tracking & architecture context.
  - Created `.gemini/content.md` for site copy, pages inventory, and data structures.
  - Created `.gemini/theme.md` for design system tokens, color palettes, and UI rules.
  - Created `.gemini/skills.md` containing Master UI/UX Guidelines, section rhythms, animation principles, and design checklists.
  - Updated `AGENTS.md` in workspace root to mandate reading `.gemini/skills.md` along with `memory.md`, `content.md`, and `theme.md` at session start.
### Log Entry: Blog Page Full Redesign Matching Mockup
- **Date**: 2026-07-27
- **Actions Completed**:
  - Redesigned `blogs.php` and `blogs.html` to match reference mockup image.
  - Replaced navbar and footer with exact standardized `.rw-navbar` and `.rw-footer` from `index.html`.
  - Built white main card container (`.rw-blog-main-card`) with category filter pills, horizontal featured article card (`Calm is a skill you can practice`), "More Insights" 4-column card grid with pastel header tints (Anxiety 💡, Relationships 🌿, Self-Growth ✨, Trauma 💜), and newsletter subscription banner.
- **Files Modified**: `blogs.php`, `blogs.html`, `css/index-redesign.css`, `.gemini/ui.md`, `.gemini/memory.md`.
### Log Entry: How It Works Section Redesign Matching Mockup
- **Date**: 2026-07-27
- **Actions Completed**:
  - Redesigned `#how-it-works` section on `index.html` to match the exact mockup reference image.
  - Implemented an inline SVG sine wave connecting path (`.rw-process-svg-line`) with terracotta stroke (`#E07A5F`) and circular dot nodes.
  - Created 4 step items (`01 Connect`, `02 Discover`, `03 Heal`, `04 Transform`) featuring concentric multi-layer glowing white circular badges, terracotta outline vector icons (Users, Search, Daisy/Flower, Infinity), and bottom terracotta number badges.
  - Reduced the large vertical spacing gap above the "How It Works" section (adjusted `#services` bottom padding and `.rw-process-wave-section` top padding).
  - Fixed circle clipping issue by setting `overflow: visible` and adjusting top section padding.
  - Recalibrated SVG sine wave curve path (`0 0 1000 220`) to pass precisely through the center of every step circle.
- **Files Modified**: `index.html`, `css/index-redesign.css`, `.gemini/memory.md`.
### Log Entry: Appointment Page Full Redesign Matching Mockup
- **Date**: 2026-07-27
- **Actions Completed**:
  - Redesigned `appointment.html` to match reference mockup image.
  - Replaced legacy navbar and footer with standard `.rw-navbar` and `.rw-footer`.
  - Built hero section (`.rw-apt-hero-section`) with left eyebrow `🌿 BEGIN YOUR JOURNEY`, title `Book Your <span class="rw-accent-text rw-font-italic">Session.</span>`, subtext, pill button `Start Intake →`, and right dual overlapping photo grid with floating white badge `A safe space for you.`.
  - Built split 2-panel assessment card (`.rw-assessment-card`) with left peach privacy panel (`Your privacy matters.`, lock badge, floral art, 3 trust badges) and right 2-column form area with deep teal full-width `Submit →` button.
  - Built commitment quote banner (`.rw-quote-commitment-banner`) featuring quote text `Dedicated to helping you navigate life's complexities...`, left leaf SVG, and right candle/vase photo setup (`images/apt-quote-candle.png`).
  - Copied the exact footer structure (`.rw-footer`) from `index.html` into `appointment.html`.
  - Fixed navbar collision with the hero section by increasing `.rw-apt-hero-section` top padding to `140px` to clear the navbar.
  - Redesigned site navbar (`.rw-navbar`) into a floating capsule pill design with glassmorphism backdrop (`backdrop-filter: blur(20px)`, `background: rgba(255,255,255,0.78)`, `border-radius: 60px`, `box-shadow: 0 10px 35px rgba(15,59,54,0.07)`).
  - Increased navbar logo size (`.rw-logo img` height to `56px` / max `60px`).
  - Standardized `.rw-navbar` markup and fixed navigation link URLs (`Home` -> `index.html`, `How it Works` -> `index.html#how-it-works`, `About` -> `about.html`, `Blog` -> `blogs.php`, `Book Appointment` -> `appointment.html`) across all pages (`index.html`, `about.html`, `appointment.html`, `blogs.php`, `blogs.html`, `blog-detail.php`, `blog-detail.html`, `intake-form.html`, `patient-intake-form.html`).
  - Fixed footer column widths (`Get In Touch` expanded to `col-lg-3` so email `hello@rewirewithkajal.com` doesn't collide with `Book a Session`).
  - Created `.rw-footer-btn` styling (Terracotta pill button `#E07A5F` with clear white text) replacing white-on-white button blob.
- **Files Modified**: `index.html`, `about.html`, `appointment.html`, `blogs.php`, `blogs.html`, `css/index-redesign.css`, `.gemini/memory.md`.
- **Current Status**: Footer layout, text collision, column widths, and CTA button fixed across all pages.

---

## 4. Standard Workflow Rules
1. **Starting a Session**: Read `.gemini/memory.md`, `.gemini/content.md`, `.gemini/theme.md`, and `.gemini/skills.md`.
2. **Developing Code**: Follow design system in `.gemini/theme.md`, content specifications in `.gemini/content.md`, and UI/UX guidelines in `.gemini/skills.md`.
3. **Ending a Session**: Append notes under *Session Log & Memory History* in `.gemini/memory.md` summarizing work done and current state.



