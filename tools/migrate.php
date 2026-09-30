<?php
/**
 * Comprehensive Idempotent Database Migration Tool for Production & Development.
 *
 * Safe to run repeatedly: every step checks for its own presence first, so
 * this never drops or rewrites existing data. Brings any older database up
 * to the latest schema without data loss.
 *
 * Execution options:
 *   1. Command Line:
 *        php tools/migrate.php
 *   2. Authenticated Admin via Web:
 *        Open https://your-domain.com/tools/migrate.php while logged in as admin
 *   3. Secret Key via Web (for automated deploy webhooks / CI):
 *        https://your-domain.com/tools/migrate.php?key=<INTAKE_ENCRYPTION_KEY>
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once dirname(__DIR__) . '/db-config.php';

    $allowed = false;
    // 1. Logged in administrator
    if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $allowed = true;
    }
    // 2. Development environment
    elseif (defined('APP_ENV') && APP_ENV === 'development') {
        $allowed = true;
    }
    // 3. Secret key parameter matching INTAKE_ENCRYPTION_KEY
    elseif (isset($_GET['key']) && defined('INTAKE_ENCRYPTION_KEY') && hash_equals(INTAKE_ENCRYPTION_KEY, (string) $_GET['key'])) {
        $allowed = true;
    }

    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("403 Forbidden: Access restricted to CLI, logged-in administrator, or valid ?key=... parameter.\n");
    }

    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once dirname(__DIR__) . '/db-config.php';
}

$startTime = microtime(true);
$db        = getDbConnection();
$applied   = [];
$skipped   = [];
$warnings  = [];

/** Check if table exists in active database schema. */
function tableExists(PDO $db, $table) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Check if column exists in table. */
function columnExists(PDO $db, $table, $column) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Declared column type for inspecting ENUM or datatype before ALTER. */
function columnType(PDO $db, $table, $column) {
    $stmt = $db->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn();
}

/** Check if index/key exists on table. */
function indexExists(PDO $db, $table, $index) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i
    ");
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Check if foreign key constraint exists on table. */
function foreignKeyExists(PDO $db, $table, $constraintName) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND CONSTRAINT_NAME = :c
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->execute([':t' => $table, ':c' => $constraintName]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Check if an ENUM column definition already contains a specific value. */
function enumHasValue(PDO $db, $table, $column, $value) {
    $stmt = $db->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    $type = $stmt->fetchColumn();
    if ($type === false || $type === null) {
        return false;
    }
    return strpos($type, "'" . $value . "'") !== false;
}

/** Add a foreign key safely without crashing if orphaned data is present. */
function addForeignKeySafely(PDO $db, $table, $constraint, $fkSql) {
    global $warnings;
    try {
        $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$fkSql}");
    } catch (PDOException $e) {
        $warnings[] = "Notice: FK `{$constraint}` on `{$table}` skipped: " . $e->getMessage();
    }
}

/** Record and execute a migration step conditionally. */
function step($label, $condition, callable $work) {
    global $db, $applied, $skipped;
    if (!$condition) {
        $skipped[] = $label;
        return;
    }
    $work($db);
    $applied[] = $label;
}

try {
    // -------------------------------------------------------------
    // 1. CORE TABLES CREATION (Fresh installs or missing tables)
    // -------------------------------------------------------------

    step("create `users` table",
        !tableExists($db, 'users'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `users` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(100) NOT NULL UNIQUE,
                    `password` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(255) NOT NULL UNIQUE,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `intake` table",
        !tableExists($db, 'intake'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `intake` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `email` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `patient-intake` table",
        !tableExists($db, 'patient-intake'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `patient-intake` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `first_name` VARCHAR(100) NOT NULL,
                    `last_name` VARCHAR(100) NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `phone` VARCHAR(50) NOT NULL,
                    `city` VARCHAR(100) NOT NULL,
                    `occupation` VARCHAR(100) NOT NULL,
                    `dob` DATE NOT NULL,
                    `concern` VARCHAR(100) NOT NULL,
                    `pref_consult` VARCHAR(50) NOT NULL,
                    `pref_date` DATE NOT NULL,
                    `pref_time` VARCHAR(50) NOT NULL,
                    `q1_1` VARCHAR(10) NOT NULL, `q1_2` VARCHAR(10) NOT NULL, `q1_3` VARCHAR(10) NOT NULL,
                    `q1_4` VARCHAR(10) NOT NULL, `q1_5` VARCHAR(10) NOT NULL, `q1_6` VARCHAR(10) NOT NULL,
                    `q1_7` VARCHAR(10) NOT NULL, `q1_8` VARCHAR(10) NOT NULL, `q1_9` VARCHAR(10) NOT NULL,
                    `q1_10` VARCHAR(10) NOT NULL, `q1_11` VARCHAR(10) NOT NULL, `q1_12` VARCHAR(10) NOT NULL,
                    `q1_13` VARCHAR(10) NOT NULL, `q1_14` VARCHAR(10) NOT NULL, `q1_15` VARCHAR(10) NOT NULL,
                    `q1_16` VARCHAR(10) NOT NULL, `q1_17` VARCHAR(10) NOT NULL, `q1_18` VARCHAR(10) NOT NULL,
                    `q2_1` VARCHAR(10) NOT NULL, `q2_2` VARCHAR(10) NOT NULL, `q2_3` VARCHAR(10) NOT NULL,
                    `q2_4` VARCHAR(10) NOT NULL, `q2_5` VARCHAR(10) NOT NULL, `q2_6` VARCHAR(10) NOT NULL,
                    `q2_7` VARCHAR(10) NOT NULL, `q2_8` VARCHAR(10) NOT NULL, `q2_9` VARCHAR(10) NOT NULL,
                    `q2_10` VARCHAR(10) NOT NULL, `q2_11` VARCHAR(10) NOT NULL, `q2_12` VARCHAR(10) NOT NULL,
                    `q2_13` VARCHAR(10) NOT NULL, `q2_14` VARCHAR(10) NOT NULL, `q2_15` VARCHAR(10) NOT NULL,
                    `q2_16` VARCHAR(10) NOT NULL, `q2_17` VARCHAR(10) NOT NULL, `q2_18` VARCHAR(10) NOT NULL,
                    `intake_link_id` INT DEFAULT NULL,
                    `client_id` INT DEFAULT NULL,
                    `form_version` INT NOT NULL DEFAULT 1,
                    `consent_given` TINYINT(1) NOT NULL DEFAULT 0,
                    `consent_at` DATETIME DEFAULT NULL,
                    `consent_version` INT NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `leads` table",
        !tableExists($db, 'leads'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `leads` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `country_code` VARCHAR(10) DEFAULT '+1',
                    `phone` VARCHAR(50) DEFAULT NULL,
                    `preferred_date` DATE DEFAULT NULL,
                    `preferred_time` TIME DEFAULT NULL,
                    `preference` VARCHAR(50) DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `source` VARCHAR(50) NOT NULL DEFAULT 'home',
                    `status` ENUM('new','contacted','confirmed','converted','rejected') NOT NULL DEFAULT 'new',
                    `client_id` INT DEFAULT NULL,
                    `assigned_staff_id` INT DEFAULT NULL,
                    `form_version_id` INT NOT NULL DEFAULT 1,
                    `possible_duplicate_of` INT DEFAULT NULL,
                    `is_existing_client` TINYINT(1) NOT NULL DEFAULT 0,
                    `first_viewed_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_status` (`status`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `lead_notes` table",
        !tableExists($db, 'lead_notes'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `lead_notes` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `lead_id` INT NOT NULL,
                    `user_id` INT DEFAULT NULL,
                    `content` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_lead` (`lead_id`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `clients` table",
        !tableExists($db, 'clients'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `clients` (
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
                    `status` ENUM('pending','review','active','inactive','completed') NOT NULL DEFAULT 'active',
                    `intake_data` LONGTEXT DEFAULT NULL,
                    `intake_form_version` INT DEFAULT NULL,
                    `intake_submitted_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `archived_at` DATETIME DEFAULT NULL,
                    `merged_into_id` INT DEFAULT NULL,
                    `pref_mode` VARCHAR(20) DEFAULT NULL,
                    `pref_times` VARCHAR(100) DEFAULT NULL,
                    `session_version` INT NOT NULL DEFAULT 0,
                    KEY `idx_status` (`status`),
                    KEY `idx_email` (`email`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `sessions` table",
        !tableExists($db, 'sessions'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `sessions` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT NOT NULL,
                    `start_time` DATETIME NOT NULL,
                    `end_time` DATETIME NOT NULL,
                    `session_type` ENUM('online','inperson') DEFAULT 'online',
                    `status` ENUM('pending','confirmed','rejected','completed','cancelled','no-show') NOT NULL DEFAULT 'pending',
                    `response_token` CHAR(64) DEFAULT NULL,
                    `responded_at` DATETIME DEFAULT NULL,
                    `video_link` VARCHAR(500) DEFAULT NULL,
                    `recurring_series_id` INT DEFAULT NULL,
                    `cancelled_reason` VARCHAR(500) DEFAULT NULL,
                    `rescheduled_count` INT NOT NULL DEFAULT 0,
                    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
                    `reminder30_sent` TINYINT(1) NOT NULL DEFAULT 0,
                    `notes` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_start` (`start_time`),
                    KEY `idx_series` (`recurring_series_id`),
                    KEY `idx_client_start` (`client_id`, `start_time`),
                    UNIQUE KEY `uniq_response_token` (`response_token`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `client_notes` table",
        !tableExists($db, 'client_notes'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_notes` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT NOT NULL,
                    `note_type` ENUM('session','general','clinical') DEFAULT 'general',
                    `user_id` INT DEFAULT NULL,
                    `note_kind` ENUM('session','administrative') NOT NULL DEFAULT 'session',
                    `corrects_note_id` INT DEFAULT NULL,
                    `session_id` INT DEFAULT NULL,
                    `content` TEXT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_client` (`client_id`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `client_fees` table",
        !tableExists($db, 'client_fees'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_fees` (
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
                    KEY `idx_client` (`client_id`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `client_documents` table",
        !tableExists($db, 'client_documents'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_documents` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT NOT NULL,
                    `original_name` VARCHAR(255) NOT NULL,
                    `stored_name` VARCHAR(80) NOT NULL,
                    `mime_type` VARCHAR(120) NOT NULL,
                    `size_bytes` INT NOT NULL DEFAULT 0,
                    `uploaded_by` INT DEFAULT NULL,
                    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `archived_at` DATETIME DEFAULT NULL,
                    `shared_with_client` TINYINT(1) NOT NULL DEFAULT 0,
                    `client_uploaded` TINYINT(1) NOT NULL DEFAULT 0,
                    UNIQUE KEY `uniq_stored` (`stored_name`),
                    KEY `idx_client` (`client_id`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `activity_log` table",
        !tableExists($db, 'activity_log'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `activity_log` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `action` VARCHAR(100) NOT NULL,
                    `description` TEXT NOT NULL,
                    `reference_type` VARCHAR(50) DEFAULT NULL,
                    `reference_id` INT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `blogs` table",
        !tableExists($db, 'blogs'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `blogs` (
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
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `settings` table",
        !tableExists($db, 'settings'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `settings` (
                    `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
                    `setting_value` TEXT DEFAULT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `form_questions` table",
        !tableExists($db, 'form_questions'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `form_questions` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `form_version` INT NOT NULL,
                    `field_id` VARCHAR(60) NOT NULL,
                    `section` VARCHAR(120) NOT NULL,
                    `label` VARCHAR(500) NOT NULL,
                    `field_type` ENUM('text','tel','date','textarea','select','yesno','checkbox') NOT NULL DEFAULT 'text',
                    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
                    `options` JSON DEFAULT NULL,
                    `reveal_field` VARCHAR(60) DEFAULT NULL,
                    `reveal_value` VARCHAR(120) DEFAULT NULL,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_version_field` (`form_version`, `field_id`),
                    KEY `idx_version` (`form_version`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `form_templates` table",
        !tableExists($db, 'form_templates'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `form_templates` (
                    `form_version` INT NOT NULL PRIMARY KEY,
                    `name` VARCHAR(120) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `intake_links` table",
        !tableExists($db, 'intake_links'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `intake_links` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `lead_id` INT NOT NULL,
                    `client_id` INT DEFAULT NULL,
                    `token` CHAR(64) NOT NULL,
                    `form_version` INT NOT NULL DEFAULT 1,
                    `status` ENUM('sent','opened','filled','submitted','expired') NOT NULL DEFAULT 'sent',
                    `expires_at` DATETIME NOT NULL,
                    `opened_at` DATETIME DEFAULT NULL,
                    `filled_at` DATETIME DEFAULT NULL,
                    `submitted_at` DATETIME DEFAULT NULL,
                    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
                    `draft_answers` JSON DEFAULT NULL,
                    `patient_intake_id` INT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_token` (`token`),
                    KEY `idx_lead` (`lead_id`),
                    KEY `idx_client` (`client_id`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `blocked_slots` table",
        !tableExists($db, 'blocked_slots'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `blocked_slots` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `slot_date` DATE NOT NULL,
                    `slot_time` VARCHAR(5) NOT NULL,
                    `reason` VARCHAR(255) DEFAULT NULL,
                    `created_by` INT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uniq_slot` (`slot_date`,`slot_time`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `holidays` table",
        !tableExists($db, 'holidays'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `holidays` (
                    `holiday_date` DATE NOT NULL PRIMARY KEY,
                    `reason` VARCHAR(255) DEFAULT NULL,
                    `created_by` INT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `client_otps` table",
        !tableExists($db, 'client_otps'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_otps` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT NOT NULL,
                    `code_hash` VARCHAR(255) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `used_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ip` VARCHAR(45) NOT NULL DEFAULT '',
                    INDEX `idx_client_created` (`client_id`, `created_at`),
                    INDEX `idx_ip_created` (`ip`, `created_at`)
                ) ENGINE=InnoDB
            ");
        }
    );

    step("create `client_payment_reports` table",
        !tableExists($db, 'client_payment_reports'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_payment_reports` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `client_id` INT NOT NULL,
                    `method` ENUM('upi','bank_transfer','cash') NOT NULL,
                    `amount` DECIMAL(10,2) NOT NULL,
                    `reference` VARCHAR(100) NOT NULL DEFAULT '',
                    `paid_on` DATE NOT NULL,
                    `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_client` (`client_id`)
                ) ENGINE=InnoDB
            ");
        }
    );

    // -------------------------------------------------------------
    // 2. COLUMN & ENUM ALTERATIONS (Bring existing tables up to date)
    // -------------------------------------------------------------

    // patient-intake columns
    step("patient-intake metadata columns (link_id, client_id, form_version, consent)",
        tableExists($db, 'patient-intake') && !columnExists($db, 'patient-intake', 'intake_link_id'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `patient-intake`
                ADD COLUMN `intake_link_id` INT DEFAULT NULL,
                ADD COLUMN `client_id` INT DEFAULT NULL,
                ADD COLUMN `form_version` INT NOT NULL DEFAULT 1,
                ADD COLUMN `consent_given` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `consent_at` DATETIME DEFAULT NULL,
                ADD COLUMN `consent_version` INT NOT NULL DEFAULT 1
            ");
        }
    );

    // leads columns & enums
    step("leads.source, country_code, duplicate & tracking columns",
        tableExists($db, 'leads') && !columnExists($db, 'leads', 'source'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `leads`
                ADD COLUMN `country_code` VARCHAR(10) DEFAULT '+1',
                ADD COLUMN `source` VARCHAR(50) NOT NULL DEFAULT 'home',
                ADD COLUMN `client_id` INT DEFAULT NULL,
                ADD COLUMN `assigned_staff_id` INT DEFAULT NULL,
                ADD COLUMN `form_version_id` INT NOT NULL DEFAULT 1,
                ADD COLUMN `possible_duplicate_of` INT DEFAULT NULL,
                ADD COLUMN `is_existing_client` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `first_viewed_at` DATETIME DEFAULT NULL
            ");
        }
    );

    step("add 'rejected' to `leads`.`status`",
        tableExists($db, 'leads') && !enumHasValue($db, 'leads', 'status', 'rejected'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `leads` MODIFY `status`
                ENUM('new','contacted','confirmed','converted','rejected')
                NOT NULL DEFAULT 'new'
            ");
        }
    );

    // clients columns & enums
    step("clients.intake_data, submitted_at, archived_at, merged_into_id",
        tableExists($db, 'clients') && !columnExists($db, 'clients', 'intake_data'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients`
                ADD COLUMN `lead_id` INT DEFAULT NULL,
                ADD COLUMN `patient_intake_id` INT DEFAULT NULL,
                ADD COLUMN `intake_data` LONGTEXT DEFAULT NULL,
                ADD COLUMN `intake_form_version` INT DEFAULT NULL,
                ADD COLUMN `intake_submitted_at` DATETIME DEFAULT NULL,
                ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
                ADD COLUMN `merged_into_id` INT DEFAULT NULL
            ");
        }
    );

    step("clients.pref_mode, pref_times, session_version",
        tableExists($db, 'clients') && !columnExists($db, 'clients', 'pref_mode'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients`
                ADD COLUMN `pref_mode` VARCHAR(20) DEFAULT NULL,
                ADD COLUMN `pref_times` VARCHAR(100) DEFAULT NULL,
                ADD COLUMN `session_version` INT NOT NULL DEFAULT 0
            ");
        }
    );

    step("expand `clients`.`status` ENUM ('pending','review','active','inactive','completed')",
        tableExists($db, 'clients') && (!enumHasValue($db, 'clients', 'status', 'review') || !enumHasValue($db, 'clients', 'status', 'completed')),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients` MODIFY `status`
                ENUM('pending','review','active','inactive','completed')
                NOT NULL DEFAULT 'active'
            ");
        }
    );

    // sessions columns & enums
    step("add 'rejected' to `sessions`.`status`",
        tableExists($db, 'sessions') && !enumHasValue($db, 'sessions', 'status', 'rejected'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions` MODIFY `status`
                ENUM('pending','confirmed','rejected','completed','cancelled','no-show')
                NOT NULL DEFAULT 'pending'
            ");
        }
    );

    step("sessions.response_token and responded_at",
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'response_token'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                ADD COLUMN `response_token` CHAR(64) DEFAULT NULL,
                ADD COLUMN `responded_at` DATETIME DEFAULT NULL
            ");
        }
    );

    step("sessions video, recurrence, reminder & tracking columns",
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'video_link'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                ADD COLUMN `video_link` VARCHAR(500) DEFAULT NULL,
                ADD COLUMN `recurring_series_id` INT DEFAULT NULL,
                ADD COLUMN `cancelled_reason` VARCHAR(500) DEFAULT NULL,
                ADD COLUMN `rescheduled_count` INT NOT NULL DEFAULT 0,
                ADD COLUMN `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0
            ");
        }
    );

    step("sessions.reminder30_sent",
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'reminder30_sent'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD COLUMN `reminder30_sent` TINYINT(1) NOT NULL DEFAULT 0");
        }
    );

    // client_notes columns
    step("client_notes.note_kind, corrects_note_id, session_id, user_id",
        tableExists($db, 'client_notes') && !columnExists($db, 'client_notes', 'note_kind'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `client_notes`
                ADD COLUMN `user_id` INT DEFAULT NULL,
                ADD COLUMN `note_kind` ENUM('session','administrative') NOT NULL DEFAULT 'session',
                ADD COLUMN `corrects_note_id` INT DEFAULT NULL,
                ADD COLUMN `session_id` INT DEFAULT NULL
            ");
        }
    );

    // client_fees columns
    step("client_fees.session_id, method, reference, fee_date",
        tableExists($db, 'client_fees') && !columnExists($db, 'client_fees', 'method'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `client_fees`
                ADD COLUMN `session_id` INT DEFAULT NULL,
                ADD COLUMN `method` ENUM('cash','upi','bank_transfer','other') NOT NULL DEFAULT 'cash',
                ADD COLUMN `reference` VARCHAR(255) DEFAULT NULL,
                ADD COLUMN `fee_date` DATE NOT NULL
            ");
        }
    );

    // client_documents columns
    step("client_documents.shared_with_client, client_uploaded, size_bytes, archived_at",
        tableExists($db, 'client_documents') && !columnExists($db, 'client_documents', 'shared_with_client'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `client_documents`
                ADD COLUMN `size_bytes` INT NOT NULL DEFAULT 0,
                ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
                ADD COLUMN `shared_with_client` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `client_uploaded` TINYINT(1) NOT NULL DEFAULT 0
            ");
        }
    );

    // intake_links columns
    step("intake_links.draft_answers, reminder_sent, patient_intake_id",
        tableExists($db, 'intake_links') && !columnExists($db, 'intake_links', 'draft_answers'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `intake_links`
                ADD COLUMN `draft_answers` JSON DEFAULT NULL,
                ADD COLUMN `patient_intake_id` INT DEFAULT NULL,
                ADD COLUMN `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0
            ");
        }
    );

    // -------------------------------------------------------------
    // 3. INDEXES (Ensure efficient querying & unique integrity)
    // -------------------------------------------------------------

    step("index `leads`.`idx_status`",
        tableExists($db, 'leads') && !indexExists($db, 'leads', 'idx_status'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD INDEX `idx_status` (`status`)");
        }
    );

    step("index `leads`.`idx_created`",
        tableExists($db, 'leads') && !indexExists($db, 'leads', 'idx_created'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD INDEX `idx_created` (`created_at`)");
        }
    );

    step("index `clients`.`idx_status`",
        tableExists($db, 'clients') && !indexExists($db, 'clients', 'idx_status'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `clients` ADD INDEX `idx_status` (`status`)");
        }
    );

    step("index `clients`.`idx_email`",
        tableExists($db, 'clients') && !indexExists($db, 'clients', 'idx_email'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `clients` ADD INDEX `idx_email` (`email`)");
        }
    );

    step("unique index `sessions`.`uniq_response_token`",
        tableExists($db, 'sessions') && columnExists($db, 'sessions', 'response_token') && !indexExists($db, 'sessions', 'uniq_response_token'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD UNIQUE KEY `uniq_response_token` (`response_token`)");
        }
    );

    step("index `sessions`.`idx_start`",
        tableExists($db, 'sessions') && !indexExists($db, 'sessions', 'idx_start'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_start` (`start_time`)");
        }
    );

    step("index `sessions`.`idx_series`",
        tableExists($db, 'sessions') && columnExists($db, 'sessions', 'recurring_series_id') && !indexExists($db, 'sessions', 'idx_series'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_series` (`recurring_series_id`)");
        }
    );

    step("index `sessions`.`idx_client_start`",
        tableExists($db, 'sessions') && !indexExists($db, 'sessions', 'idx_client_start'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_client_start` (`client_id`, `start_time`)");
        }
    );

    step("unique index `client_documents`.`uniq_stored`",
        tableExists($db, 'client_documents') && !indexExists($db, 'client_documents', 'uniq_stored'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `client_documents` ADD UNIQUE KEY `uniq_stored` (`stored_name`)");
        }
    );

    step("index `client_documents`.`idx_client`",
        tableExists($db, 'client_documents') && !indexExists($db, 'client_documents', 'idx_client'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `client_documents` ADD INDEX `idx_client` (`client_id`)");
        }
    );

    step("unique index `intake_links`.`uniq_token`",
        tableExists($db, 'intake_links') && !indexExists($db, 'intake_links', 'uniq_token'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `intake_links` ADD UNIQUE KEY `uniq_token` (`token`)");
        }
    );

    step("index `client_otps`.`idx_client_created`",
        tableExists($db, 'client_otps') && !indexExists($db, 'client_otps', 'idx_client_created'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `client_otps` ADD INDEX `idx_client_created` (`client_id`, `created_at`)");
        }
    );

    step("index `client_otps`.`idx_ip_created`",
        tableExists($db, 'client_otps') && !indexExists($db, 'client_otps', 'idx_ip_created'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `client_otps` ADD INDEX `idx_ip_created` (`ip`, `created_at`)");
        }
    );

    // -------------------------------------------------------------
    // 4. FOREIGN KEYS (Safe cascade & null constraints)
    // -------------------------------------------------------------

    step("foreign key `fk_leads_staff` on `leads`",
        tableExists($db, 'leads') && tableExists($db, 'users') && !foreignKeyExists($db, 'leads', 'fk_leads_staff'),
        function (PDO $db) {
            addForeignKeySafely($db, 'leads', 'fk_leads_staff', 'FOREIGN KEY (`assigned_staff_id`) REFERENCES `users`(`id`) ON DELETE SET NULL');
        }
    );

    step("foreign key `fk_lead_notes_lead` on `lead_notes`",
        tableExists($db, 'lead_notes') && tableExists($db, 'leads') && !foreignKeyExists($db, 'lead_notes', 'fk_lead_notes_lead'),
        function (PDO $db) {
            addForeignKeySafely($db, 'lead_notes', 'fk_lead_notes_lead', 'FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_lead_notes_user` on `lead_notes`",
        tableExists($db, 'lead_notes') && tableExists($db, 'users') && !foreignKeyExists($db, 'lead_notes', 'fk_lead_notes_user'),
        function (PDO $db) {
            addForeignKeySafely($db, 'lead_notes', 'fk_lead_notes_user', 'FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL');
        }
    );

    step("foreign key `fk_sessions_client` on `sessions`",
        tableExists($db, 'sessions') && tableExists($db, 'clients') && !foreignKeyExists($db, 'sessions', 'fk_sessions_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'sessions', 'fk_sessions_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_client_notes_client` on `client_notes`",
        tableExists($db, 'client_notes') && tableExists($db, 'clients') && !foreignKeyExists($db, 'client_notes', 'fk_client_notes_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'client_notes', 'fk_client_notes_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_client_fees_client` on `client_fees`",
        tableExists($db, 'client_fees') && tableExists($db, 'clients') && !foreignKeyExists($db, 'client_fees', 'fk_client_fees_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'client_fees', 'fk_client_fees_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_client_documents_client` on `client_documents`",
        tableExists($db, 'client_documents') && tableExists($db, 'clients') && !foreignKeyExists($db, 'client_documents', 'fk_client_documents_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'client_documents', 'fk_client_documents_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_client_documents_user` on `client_documents`",
        tableExists($db, 'client_documents') && tableExists($db, 'users') && !foreignKeyExists($db, 'client_documents', 'fk_client_documents_user'),
        function (PDO $db) {
            addForeignKeySafely($db, 'client_documents', 'fk_client_documents_user', 'FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL');
        }
    );

    step("foreign key `fk_intake_links_lead` on `intake_links`",
        tableExists($db, 'intake_links') && tableExists($db, 'leads') && !foreignKeyExists($db, 'intake_links', 'fk_intake_links_lead'),
        function (PDO $db) {
            addForeignKeySafely($db, 'intake_links', 'fk_intake_links_lead', 'FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_intake_links_client` on `intake_links`",
        tableExists($db, 'intake_links') && tableExists($db, 'clients') && !foreignKeyExists($db, 'intake_links', 'fk_intake_links_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'intake_links', 'fk_intake_links_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL');
        }
    );

    step("foreign key `fk_cpr_client` on `client_payment_reports`",
        tableExists($db, 'client_payment_reports') && tableExists($db, 'clients') && !foreignKeyExists($db, 'client_payment_reports', 'fk_cpr_client'),
        function (PDO $db) {
            addForeignKeySafely($db, 'client_payment_reports', 'fk_cpr_client', 'FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE');
        }
    );

    step("foreign key `fk_holidays_user` on `holidays`",
        tableExists($db, 'holidays') && tableExists($db, 'users') && !foreignKeyExists($db, 'holidays', 'fk_holidays_user'),
        function (PDO $db) {
            addForeignKeySafely($db, 'holidays', 'fk_holidays_user', 'FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL');
        }
    );

    // -------------------------------------------------------------
    // 5. DEFAULT SETTINGS SEEDING (INSERT IGNORE: Never overwrites existing values)
    // -------------------------------------------------------------

    $missingSettingsCount = 0;
    if (tableExists($db, 'settings')) {
        if (!function_exists('settingDefaults')) {
            require_once dirname(__DIR__) . '/includes/settings.php';
        }
        $defaults     = settingDefaults();
        $existingKeys = $db->query("SELECT `setting_key` FROM `settings`")->fetchAll(PDO::FETCH_COLUMN);
        $existingMap  = array_flip($existingKeys);
        foreach (array_keys($defaults) as $k) {
            if (!isset($existingMap[$k])) {
                $missingSettingsCount++;
            }
        }
    }

    step("seed missing default settings into `settings` ({$missingSettingsCount} missing)",
        $missingSettingsCount > 0,
        function (PDO $db) {
            $defaults = settingDefaults();
            $stmt = $db->prepare("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES (:k, :v)");
            foreach ($defaults as $k => $v) {
                $stmt->execute([':k' => $k, ':v' => $v]);
            }
        }
    );

    // -------------------------------------------------------------
    // 6. DEFAULT INTAKE FORM SEEDING (If form_questions is empty)
    // -------------------------------------------------------------

    $needFormSeed = false;
    if (tableExists($db, 'form_questions')) {
        $count = (int) $db->query("SELECT COUNT(*) FROM `form_questions`")->fetchColumn();
        if ($count === 0) {
            $needFormSeed = true;
        }
    }

    step("seed initial intake form questions into `form_questions`",
        $needFormSeed,
        function (PDO $db) {
            if (!function_exists('seedFormVersion')) {
                require_once dirname(__DIR__) . '/includes/form-builder.php';
            }
            if (function_exists('seedFormVersion')) {
                seedFormVersion($db, 1);
                seedFormVersion($db, 2);
            }
        }
    );

} catch (PDOException $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    echo "========================================\n";
    echo "DATABASE MIGRATION FAILED\n";
    echo "========================================\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Applied before failure (" . count($applied) . "):\n";
    foreach ($applied as $a) {
        echo "  + {$a}\n";
    }
    exit(1);
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "========================================\n";
echo "DATABASE MIGRATION REPORT\n";
echo "========================================\n";
echo "Status:   SUCCESS\n";
echo "Database: " . (defined('DB_NAME') ? DB_NAME : 'unknown') . "\n";
echo "Host:     " . (defined('DB_HOST') ? DB_HOST : 'unknown') . "\n";
echo "Time:     {$elapsed} ms\n\n";

echo "Applied (" . count($applied) . "):\n";
if ($applied) {
    foreach ($applied as $a) {
        echo "  [OK] {$a}\n";
    }
} else {
    echo "  (none - database schema is already completely up to date)\n";
}

echo "\nSkipped (" . count($skipped) . " already current):\n";
foreach ($skipped as $s) {
    echo "  [--] {$s}\n";
}

if (!empty($warnings)) {
    echo "\nWarnings (" . count($warnings) . "):\n";
    foreach ($warnings as $w) {
        echo "  [WARN] {$w}\n";
    }
}

echo "\nMigration finished successfully.\n";
