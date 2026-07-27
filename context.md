# Project Context & Architecture Reference (`context.md`)

> **Kaja / Rewire With Kajal Healthcare & Intake Portal**
> Technical context document describing system architecture, data models, endpoints, tech stack, and site inventory.

---

## 1. Executive Summary & Tech Stack

- **Project Name**: Rewire With Kajal / Kaja Intake & Booking Portal
- **Core Domain**: Clinical Hypnotherapy, Patient Intake Management, Lead Acquisition, Appointment Scheduling & Educational Blog Publishing.
- **Tech Stack**:
  - **Frontend**: HTML5, Vanilla JavaScript, CSS3 (Soft-Modern Palette & Responsive Typography), Bootstrap 5.3 icons & utilities.
  - **Backend**: PHP 8+ (PDO for MySQL/MariaDB database access).
  - **Database**: `kaja_db` (MySQL/MariaDB).
  - **Theme System**: Soft-Modern Organic Palette (`#FAF7F2` Cream background, `#E07A5F` Terracotta accent, `#0F3B36` Deep Slate Teal).

---

## 2. Directory & Site Structure Inventory

| File Path | Description | Functional Role |
|---|---|---|
| `index.html` | Primary Landing Page | Full landing page including Hero, FAQ, Services, Process, About, Testimonials, Blogs, and CTA Banner. |
| `about.html` | Practice Vision & Bio | Comprehensive background story, clinical philosophy, and certifications. |
| `appointment.html` | Booking Page | Interactive date/time picker, preference selection, and lead capture form. |
| `intake-form.html` | Quick Intake | Lightweight concern submission form. |
| `patient-intake-form.html` | Comprehensive Medical Intake | Detailed patient intake (Medical history, lifestyle, emergency contacts, consents). |
| `blogs.php` / `blogs.html` | Article Listing | Categorized wellness articles with search & tag filtering. |
| `blog-detail.php` | Article Detail Page | Full blog article view with related posts and share widgets. |
| `login.html` | Admin Authentication | Secure staff login panel. |
| `dashboard.php` | Staff Admin Dashboard | Data tables for reviewing leads, appointment requests, and patient intake submissions. |
| `css/index-redesign.css` | Primary Stylesheet | Global theme tokens, typography specs, layout rules, and component styles. |

---

## 3. Database Schema (`schema.sql`)

### A. Table `leads`
- `id` (INT, Primary Key, Auto Increment)
- `name` (VARCHAR 255)
- `email` (VARCHAR 255)
- `country_code` (VARCHAR 10)
- `phone` (VARCHAR 50)
- `preferred_date` (DATE)
- `preferred_time` (VARCHAR 50)
- `preference` (VARCHAR 100)
- `message` (TEXT)
- `source_page` (VARCHAR 100)
- `status` (`new`, `accepted`, `converted`, `declined`)
- `created_at` (TIMESTAMP)

### B. Table `patient-intake`
- `id` (INT, Primary Key, Auto Increment)
- `first_name`, `last_name`, `email`, `phone`, `city`, `occupation`
- `primary_goal`, `health_conditions`, `medications`, `allergies`
- `diet_quality`, `sleep_quality`, `stress_level`, `exercise_routine`
- `signature_consent` (BOOLEAN), `hipaa_acknowledgment` (BOOLEAN)
- `created_at` (TIMESTAMP)

### C. Table `blogs`
- `id`, `title`, `slug`, `category`, `excerpt`, `content`, `hero_image`, `author_name`, `published_at`

---

## 4. Brand Tone & Core Design Rules

- **Voice**: Empathetic, clinical, modern, reassuring, and professional.
- **Typography**:
  - Headings: `Playfair Display`, serif (Weights: 500, 600, 700).
  - Body: `Inter` / `Plus Jakarta Sans`, sans-serif (Weights: 300, 400, 500).
- **Core Color Tokens**:
  - Primary Accent: Terracotta `#E07A5F`
  - Primary CTA / Dark Teal: `#0F3B36`
  - Dark Slate Text: `#1C2A29`
  - Soft Neutral Background: `#FAF7F2`
  - Card Background: `#FFFFFF` / `#FFFDFB`
