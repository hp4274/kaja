# Kaja Content & Structure Reference

> **CRITICAL AGENT INSTRUCTION**: Read this file at the start of any content generation, UI modification, or page restructuring task. Update this file whenever new pages, content models, or forms are added to the codebase.

---

## 1. Site Structure & Page Inventory

| File Path | Description / Role | Key Features |
|---|---|---|
| `index.html` | Primary Landing Page | Hero section, service highlights, lead form, patient testimonials, contact footer. |
| `about.html` | About Us & Practice Vision | Clinical background, team values, soft-modern visual aesthetics. |
| `appointment.html` | Appointment Booking Page | Interactive date/time picker, lead capture form, preference selection. |
| `intake-form.html` | Quick Intake Form | Lightweight inquiry & brief medical concern submission. |
| `patient-intake-form.html` | Complete Patient Medical Intake | Detailed multi-section form (Medical history, lifestyle, emergency contacts, consents). |
| `blogs.html` / `blogs.php` | Blog List Page | Featured articles, category filters, responsive grid layout. |
| `blog-detail.html` / `blog-detail.php` | Article Detail Page | Full article view, social share, author bio, related posts. |
| `login.html` | Admin Login | Secure credential entry for staff & administrators. |
| `dashboard.php` | Staff Admin Dashboard | Data tables for reviewing leads, patient intakes, and appointment requests. |

---

## 2. Form & Data Field Contracts

### A. Lead Acquisition Form (`leads` table)
- **Fields**: `name`, `email`, `country_code`, `phone`, `preferred_date`, `preferred_time`, `preference`, `message`, `source_page`.
- **Status Flags**: `new` (default), `accepted`, `converted`, `declined`.

### B. Patient Intake Form (`patient-intake` table)
- **Personal Info**: First name, Last name, Email, Phone, City, Occupation.
- **Medical Profile**: Primary health goals, existing conditions, current medications/supplements, allergies.
- **Lifestyle & Preferences**: Diet, sleep quality, stress levels, exercise routine.
- **Compliance & Consent**: Electronic agreement signature, HIPAA/Privacy consent acknowledgment.

---

## 3. Brand Tone & Messaging Guidelines
- **Voice**: Warm, clinical, modern, reassuring, and professional.
- **Terminology**: Use "Patient-Centered", "Holistic Wellness", "Personalized Care", "Streamlined Consultation".
- **Call-To-Actions (CTAs)**:
  - "Book Consultation"
  - "Start Intake Process"
  - "Explore Treatments"
  - "Schedule Your Visit"
