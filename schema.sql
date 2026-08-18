-- Drop Database if exists to ensure clean schema rebuild
DROP DATABASE IF EXISTS `kaja_db`;
CREATE DATABASE `kaja_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kaja_db`;

-- Tables are created in dependency order, so a foreign key never points at a
-- table that does not exist yet. That is why `users` comes before `leads`,
-- and `clients` before everything that hangs off it.
--
--   1  intake             short contact-form messages
--   2  patient-intake     the questionnaire archive (legacy; see clients.intake_data)
--   3  users              admin logins
--   4  leads              public form submissions
--   5  lead_notes         append-only notes on a lead
--   6  clients            the people in treatment
--   7  sessions           appointments
--   8  client_notes       append-only clinical and administrative notes
--   9  client_fees        the manual payment ledger
--  10  client_documents   metadata only; the files live outside the project
--  11  activity_log       the audit trail every module writes to
--  12  blogs              public site content
--  13  settings           key/value configuration
--  14  form_questions     the editable intake question set, per version
--  15  intake_links       tokenised, single-use intake invitations

-- 1. Short Intakes Table (Intake)
CREATE TABLE IF NOT EXISTS `intake` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Patient Intakes Table (Patient-Intake)
CREATE TABLE IF NOT EXISTS `patient-intake` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    -- Personal Information
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `occupation` VARCHAR(100) NOT NULL,
    `dob` DATE NOT NULL,
    `concern` VARCHAR(100) NOT NULL,
    
    -- Preferences
    `pref_consult` VARCHAR(50) NOT NULL,
    `pref_date` DATE NOT NULL,
    `pref_time` VARCHAR(50) NOT NULL,
    
    -- Questionnaire 1 (Q1 - Q18)
    `q1_1` VARCHAR(10) NOT NULL,
    `q1_2` VARCHAR(10) NOT NULL,
    `q1_3` VARCHAR(10) NOT NULL,
    `q1_4` VARCHAR(10) NOT NULL,
    `q1_5` VARCHAR(10) NOT NULL,
    `q1_6` VARCHAR(10) NOT NULL,
    `q1_7` VARCHAR(10) NOT NULL,
    `q1_8` VARCHAR(10) NOT NULL,
    `q1_9` VARCHAR(10) NOT NULL,
    `q1_10` VARCHAR(10) NOT NULL,
    `q1_11` VARCHAR(10) NOT NULL,
    `q1_12` VARCHAR(10) NOT NULL,
    `q1_13` VARCHAR(10) NOT NULL,
    `q1_14` VARCHAR(10) NOT NULL,
    `q1_15` VARCHAR(10) NOT NULL,
    `q1_16` VARCHAR(10) NOT NULL,
    `q1_17` VARCHAR(10) NOT NULL,
    `q1_18` VARCHAR(10) NOT NULL,
    
    -- Questionnaire 2 (Q1 - Q18)
    `q2_1` VARCHAR(10) NOT NULL,
    `q2_2` VARCHAR(10) NOT NULL,
    `q2_3` VARCHAR(10) NOT NULL,
    `q2_4` VARCHAR(10) NOT NULL,
    `q2_5` VARCHAR(10) NOT NULL,
    `q2_6` VARCHAR(10) NOT NULL,
    `q2_7` VARCHAR(10) NOT NULL,
    `q2_8` VARCHAR(10) NOT NULL,
    `q2_9` VARCHAR(10) NOT NULL,
    `q2_10` VARCHAR(10) NOT NULL,
    `q2_11` VARCHAR(10) NOT NULL,
    `q2_12` VARCHAR(10) NOT NULL,
    `q2_13` VARCHAR(10) NOT NULL,
    `q2_14` VARCHAR(10) NOT NULL,
    `q2_15` VARCHAR(10) NOT NULL,
    `q2_16` VARCHAR(10) NOT NULL,
    `q2_17` VARCHAR(10) NOT NULL,
    `q2_18` VARCHAR(10) NOT NULL,

    -- Which intake link produced this, which client it belongs to, and which
    -- version of the questionnaire the answers were given against.
    `intake_link_id` INT DEFAULT NULL,
    `client_id` INT DEFAULT NULL,
    `form_version` INT NOT NULL DEFAULT 1,

    -- Consent as shown, not as assumed: the version records which wording the
    -- person actually agreed to, so a later edit cannot rewrite what they saw.
    `consent_given` TINYINT(1) NOT NULL DEFAULT 0,
    `consent_at` DATETIME DEFAULT NULL,
    `consent_version` INT NOT NULL DEFAULT 1,

    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Users Table (Admin / Therapist)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Leads Table (from index.html & appointment.html forms)
-- Defined after `users` because assigned_staff_id references it.
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `country_code` VARCHAR(10) DEFAULT '+1',
    `phone` VARCHAR(50) DEFAULT NULL,
    `preferred_date` DATE DEFAULT NULL,
    `preferred_time` TIME DEFAULT NULL,
    `preference` VARCHAR(50) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    -- Which public form produced this lead: 'home', 'appointment', 'intake'.
    `source` VARCHAR(50) NOT NULL,
    -- new -> contacted -> confirmed -> converted, with rejected/spam as
    -- terminal side-exits reachable from any state. Only Confirm advances a
    -- lead automatically; every other move is a manual admin action.
    `status` ENUM('new','contacted','confirmed','converted','rejected','spam') NOT NULL DEFAULT 'new',
    `client_id` INT DEFAULT NULL,
    -- No staff table exists; this is here so attribution has somewhere to go
    -- the day a second user is added.
    `assigned_staff_id` INT DEFAULT NULL,
    -- Pinned at submit time. The detail drawer renders the answers against
    -- this version's field map, never against the live form.
    `form_version_id` INT NOT NULL DEFAULT 1,
    -- Set at submit time when the same email/phone already exists. Advisory
    -- only: the submission is never blocked, the admin just sees a banner.
    `possible_duplicate_of` INT DEFAULT NULL,
    `is_existing_client` TINYINT(1) NOT NULL DEFAULT 0,
    -- Stamped the first time the detail drawer is opened.
    `first_viewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_leads_staff`
        FOREIGN KEY (`assigned_staff_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Lead Notes Table
-- Free-text thread on a lead, attributed to the acting user. user_id is
-- nullable so a note written by a background job reads as "System" rather
-- than pretending a person wrote it.
CREATE TABLE IF NOT EXISTS `lead_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `user_id` INT DEFAULT NULL,
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_lead` (`lead_id`),
    CONSTRAINT `fk_lead_notes_lead`
        FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lead_notes_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. Clients Table (converted from leads or patient intakes)
CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT DEFAULT NULL,
    `patient_intake_id` INT DEFAULT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `occupation` VARCHAR(100) DEFAULT NULL,
    `dob` DATE DEFAULT NULL,
    `concern` VARCHAR(100) DEFAULT NULL,
    -- 'pending'   = created when a lead was confirmed, intake not yet returned.
    -- 'review'    = intake submitted, awaiting a human look before bookable.
    -- 'completed' = treatment finished; the record stays and they can return.
    `status` ENUM('pending','review','active','inactive','completed') NOT NULL DEFAULT 'active',
    -- The intake answers, AES-256-GCM encrypted. Written and read only through
    -- includes/intake-data.php, keyed by question id from intake_form_version.
    `intake_data` LONGTEXT DEFAULT NULL,
    `intake_form_version` INT DEFAULT NULL,
    `intake_submitted_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Soft delete. There is no hard delete: a client row anchors sessions,
    -- notes, documents and payments, and removing it would orphan a history
    -- that has to stay explicable.
    `archived_at` DATETIME DEFAULT NULL,
    -- Set when this row was merged INTO another, so the loser's history stays
    -- traceable rather than becoming a dead-end archived record.
    `merged_into_id` INT DEFAULT NULL
) ENGINE=InnoDB;

-- 7. Sessions Table
-- start_time/end_time are real DATETIMEs, not a DATE plus a TIME plus a
-- duration. Overlap becomes a range comparison; deriving the end from a
-- duration made it string arithmetic that could not cross midnight.
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `session_type` ENUM('online','inperson') DEFAULT 'online',
    -- pending -> confirmed -> completed, with cancelled and no-show as exits.
    -- no-show is deliberately not a flavour of cancelled: no advance notice,
    -- different follow-up, and its own line on the dashboard.
    `status` ENUM('pending','confirmed','completed','cancelled','no-show') NOT NULL DEFAULT 'pending',
    -- One static practice room, copied in at booking so the record keeps the
    -- link it was actually sent with.
    `video_link` VARCHAR(500) DEFAULT NULL,
    -- Groups the rows generated from one recurrence. Real rows, not a rule:
    -- editing a single occurrence is then an ordinary update.
    `recurring_series_id` INT DEFAULT NULL,
    `cancelled_reason` VARCHAR(500) DEFAULT NULL,
    -- A cheap signal that a slot keeps moving, without full history logging.
    `rescheduled_count` INT NOT NULL DEFAULT 0,
    -- Set once by the reminder pass so a session is never chased twice.
    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_start` (`start_time`),
    KEY `idx_series` (`recurring_series_id`),
    KEY `idx_client_start` (`client_id`, `start_time`),
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Client Notes Table
CREATE TABLE IF NOT EXISTS `client_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `note_type` ENUM('session','general','clinical') DEFAULT 'general',
    `user_id` INT DEFAULT NULL,
    -- 'session' notes are clinical; 'administrative' is everything else.
    `note_kind` ENUM('session','administrative') NOT NULL DEFAULT 'session',
    -- A correction is a NEW note pointing at the one it corrects. Nothing is
    -- ever overwritten, so the original stays readable beside the fix.
    `corrects_note_id` INT DEFAULT NULL,
    -- Nullable: a note about a specific session says which; a general or
    -- administrative note is about the client, not an appointment.
    `session_id` INT DEFAULT NULL,
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. Client Fees Table
CREATE TABLE IF NOT EXISTS `client_fees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `session_id` INT DEFAULT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('paid','pending','waived') DEFAULT 'pending',
    `method` ENUM('cash','upi','bank_transfer','other') NOT NULL DEFAULT 'cash',
    `reference` VARCHAR(255) DEFAULT NULL,
    `fee_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. Client Documents Table
-- The file itself lives outside the project at document_storage_path. Only its
-- metadata is here, and stored_name is generated: an uploaded filename is
-- attacker-controlled and must never become a path.
CREATE TABLE IF NOT EXISTS `client_documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(80) NOT NULL,
    `mime_type` VARCHAR(120) NOT NULL,
    `size_bytes` INT NOT NULL,
    `uploaded_by` INT DEFAULT NULL,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `archived_at` DATETIME DEFAULT NULL,
    UNIQUE KEY `uniq_stored` (`stored_name`),
    KEY `idx_client` (`client_id`),
    CONSTRAINT `fk_client_documents_client`
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_client_documents_user`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 11. Activity Log Table
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 12. Blogs Table
CREATE TABLE IF NOT EXISTS `blogs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `excerpt` TEXT NOT NULL,
    `content` LONGTEXT NOT NULL,
    `category` VARCHAR(50) NOT NULL,
    `read_time` INT DEFAULT 5,
    `cover_image` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('draft', 'published') DEFAULT 'draft',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 13. Settings Table
-- Upstream of every module. Written via setSetting(), which uses
-- INSERT ... ON DUPLICATE KEY UPDATE so new keys never need a migration.
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 14. Form Questions Table
-- The editable question set, seeded from includes/intake-schema.php the first
-- time a version is opened in the admin. Versions are append-only: publishing
-- edits mints N+1 and leaves earlier versions alone, because a link already
-- sent and an answer already given were both against a specific set of
-- questions.
CREATE TABLE IF NOT EXISTS `form_questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `form_version` INT NOT NULL,
    `field_id` VARCHAR(60) NOT NULL,
    `section` VARCHAR(120) NOT NULL,
    `label` VARCHAR(500) NOT NULL,
    `field_type` ENUM('text','tel','date','textarea','select','yesno','checkbox') NOT NULL DEFAULT 'text',
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `options` JSON DEFAULT NULL,
    -- A question that only appears once another was answered a certain way.
    `reveal_field` VARCHAR(60) DEFAULT NULL,
    `reveal_value` VARCHAR(120) DEFAULT NULL,
    -- Gaps of ten, so a question can be moved between two others without
    -- renumbering the whole set.
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_version_field` (`form_version`, `field_id`),
    KEY `idx_version` (`form_version`)
) ENGINE=InnoDB;

-- 15. Intake Links Table (tokenised, single-use intake invitations)
-- expires_at and form_version are PINNED at send time: changing the matching
-- setting later must not alter links already sitting in someone's inbox.
-- client_id is nullable because the short-intake path knows only an email
-- address; the client row is created when the questionnaire comes back.
CREATE TABLE IF NOT EXISTS `intake_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `client_id` INT DEFAULT NULL,
    `token` CHAR(64) NOT NULL,
    `form_version` INT NOT NULL DEFAULT 1,
    `status` ENUM('sent','opened','filled','submitted','expired') NOT NULL DEFAULT 'sent',
    `expires_at` DATETIME NOT NULL,
    `opened_at` DATETIME DEFAULT NULL,
    -- Stamped by the JS beacon on the first keystroke. It separates "opened
    -- the link and walked away" from "started answering and got interrupted",
    -- which need different follow-ups.
    `filled_at` DATETIME DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
    -- Partial answers, autosaved so a long questionnaire can be left and
    -- resumed. Working state, not a record: cleared on successful submit.
    `draft_answers` JSON DEFAULT NULL,
    `patient_intake_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_lead` (`lead_id`),
    KEY `idx_client` (`client_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_intake_links_lead`
        FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_intake_links_client`
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Seed default settings.
-- Generated from settingDefaults() in includes/settings.php, which is the
-- authority. tests/test_settings_seed.php asserts the two stay in step, because
-- a fresh install silently missing a key is the kind of drift nobody notices
-- until an email goes out with an unrendered placeholder in it.
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('intake_token_expiry_days', '14'),
    ('intake_form_version', '2'),
    ('admin_reminder_hours', '48'),
    ('default_session_duration', '60'),
    ('buffer_minutes', '0'),
    ('min_notice_hours', '24'),
    ('max_advance_days', '60'),
    ('practice_name', 'Rewire With Kajal'),
    ('practice_email', 'hello@rewirewithkajal.com'),
    ('notify_lead_confirmed_subject', 'Complete your intake form - {{practice_name}}'),
    ('notify_lead_confirmed_body', 'Hello {{name}},\n\nThank you for getting in touch. Please take a few moments to complete your intake questionnaire using your personal link below:\n\n{{intake_link}}\n\nThis link is unique to you, so please do not forward it. It stays active until {{expires}}.\n\nBest regards,\n{{practice_name}}'),
    ('auto_confirm_sessions', '0'),
    ('practice_video_link', ''),
    ('session_reminder_hours', '24'),
    ('practice_timezone', 'Asia/Kolkata'),
    ('notify_session_confirmed_subject', 'Your session on {{session_time}} - {{practice_name}}'),
    ('notify_session_confirmed_body', 'Hello {{client_name}},\n\nYour session is confirmed for {{session_time}}.\n\nFormat: {{session_type}}\nJoining link: {{video_link}}\n\nIf you need to change or cancel it, just reply to this email.\n\nBest regards,\n{{practice_name}}'),
    ('notify_session_cancelled_subject', 'Your session on {{session_time}} has been cancelled'),
    ('notify_session_cancelled_body', 'Hello {{client_name}},\n\nYour session on {{session_time}} has been cancelled.\n\n{{cancel_reason}}\n\nReply to this email and we will find another time.\n\nBest regards,\n{{practice_name}}'),
    ('notify_session_reminder_subject', 'Reminder: your session on {{session_time}}'),
    ('notify_session_reminder_body', 'Hello {{client_name}},\n\nThis is a reminder of your session on {{session_time}}.\n\nFormat: {{session_type}}\nJoining link: {{video_link}}\n\nBest regards,\n{{practice_name}}'),
    ('upload_max_mb', '10'),
    ('upload_allowed_types', 'pdf,jpg,jpeg,png,doc,docx'),
    ('document_storage_path', ''),
    ('site_base_url', '')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- Seed default admin user (Username: admin, Password: admin123)
INSERT INTO `users` (`username`, `password`, `email`)
VALUES ('admin', '$2y$10$kMAlSbqecZQA5DxrPyg4C.3JLHKE/aCxmuFsbvw.h.4aZL/3CQizi', 'admin@rewire.com')
ON DUPLICATE KEY UPDATE `password`=VALUES(`password`);

-- Seed existing blog posts
INSERT INTO `blogs` (`title`, `slug`, `excerpt`, `content`, `category`, `read_time`, `cover_image`, `status`, `created_at`) VALUES
('Calm is a skill you can practice', 'calm-is-a-skill-you-can-practice',
 'Small daily practices—breath, boundaries, and compassionate self-talk—add up to a steadier nervous system.',
 '<p>Calm is not a personality trait you are born with — it is something your nervous system learns through repetition. When life feels loud, your body is asking for evidence that it can soften. Small practices, done kindly and consistently, become that evidence.</p><p>Start with something undramatic: three slow breaths before you check your phone, a glass of water when you notice irritation, or naming one thing that is okay right now. You are not trying to erase difficulty; you are widening your capacity to stay present inside it.</p><p>If you would like support tailoring these ideas to your story, you are welcome to <a href=\"appointment.html\">book a session</a>.</p>',
 'Mindfulness', 5,
 'https://images.unsplash.com/photo-1506126613408-eca07ce68773?auto=format&fit=crop&w=1200&q=80',
 'published', '2026-04-02 10:00:00'),

('Understanding anxiety loops', 'understanding-anxiety-loops',
 'Notice the trigger, the thought, and the body response—then choose a gentler next step.',
 '<p>Anxiety often operates in a loop: a trigger sparks a thought, the thought produces a body sensation, and the sensation feeds back into the thought. Understanding this cycle is the first step toward interrupting it.</p><p>Next time you feel anxious, try pausing and asking: What triggered this? What story am I telling myself? Where do I feel it in my body? Even a brief pause introduces choice into what otherwise feels automatic.</p><p>You don''t need to eliminate anxiety — just widen the space between the trigger and your response.</p>',
 'Anxiety', 5, NULL, 'published', '2026-03-18 10:00:00'),

('Emotional safety in relationships', 'emotional-safety-in-relationships',
 'Repair starts with clear, kind communication—and room for both people to be imperfect.',
 '<p>Emotional safety is the foundation of every healthy relationship. It means both people can express feelings without fear of punishment, dismissal, or contempt.</p><p>Building safety doesn''t require perfection — it requires repair. When ruptures happen (and they will), the willingness to return, listen, and take responsibility is what builds trust over time.</p><p>Start small: validate before you problem-solve, and ask \"What do you need right now?\" more often than \"Why are you upset?\"</p>',
 'Relationships', 6, NULL, 'published', '2026-03-05 10:00:00'),

('Burnout is not a character flaw', 'burnout-is-not-a-character-flaw',
 'Your energy is information. Listening early helps you reset before you hit empty.',
 '<p>Burnout is not a sign that you are weak or lazy. It is your nervous system telling you that the demands on your energy have exceeded your capacity to recover.</p><p>Instead of pushing through, try treating fatigue as data. What is draining you? What would replenish you? Sometimes the answer is rest; sometimes it is a boundary; sometimes it is simply permission to not perform for a while.</p><p>Recovery is not earned through more effort. It is allowed.</p>',
 'Self-Growth', 4, NULL, 'published', '2026-02-22 10:00:00'),

('Gentle pacing after overwhelm', 'gentle-pacing-after-overwhelm',
 'Healing rarely moves in a straight line. Here is how to honor your window of tolerance.',
 '<p>After a period of overwhelm — whether from trauma, grief, or prolonged stress — your system needs gentleness, not urgency. Healing is not linear, and pushing too fast can re-activate the very patterns you are trying to release.</p><p>The concept of a \"window of tolerance\" helps here: it is the zone where you can feel emotion without shutting down or becoming flooded. Staying within it, even if progress feels slow, is actually faster in the long run.</p><p>Pace yourself. Rest is not the opposite of progress — it is part of it.</p>',
 'Trauma', 7, NULL, 'published', '2026-02-08 10:00:00');
