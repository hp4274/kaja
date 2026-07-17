-- Drop Database if exists to ensure clean schema rebuild
DROP DATABASE IF EXISTS `kaja_db`;
CREATE DATABASE `kaja_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kaja_db`;

-- 1. Leads Table (from index.html & appointment.html forms)
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
    `source_page` VARCHAR(50) NOT NULL,
    `status` ENUM('new','accepted','converted','declined') DEFAULT 'new',
    `client_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Short Intakes Table (Intake)
CREATE TABLE IF NOT EXISTS `intake` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Patient Intakes Table (Patient-Intake)
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
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Users Table (Admin / Therapist)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 5. Clients Table (converted from leads or patient intakes)
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
    `status` ENUM('active','inactive','discharged') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. Sessions Table
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `session_date` DATE NOT NULL,
    `session_time` TIME NOT NULL,
    `duration_minutes` INT DEFAULT 60,
    `session_type` ENUM('online','inperson') DEFAULT 'online',
    `status` ENUM('scheduled','completed','cancelled','no-show') DEFAULT 'scheduled',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. Client Notes Table
CREATE TABLE IF NOT EXISTS `client_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `note_type` ENUM('session','general','clinical') DEFAULT 'general',
    `content` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Client Fees Table
CREATE TABLE IF NOT EXISTS `client_fees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT NOT NULL,
    `session_id` INT DEFAULT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('paid','pending','waived') DEFAULT 'pending',
    `fee_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. Activity Log Table
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 10. Blogs Table
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
