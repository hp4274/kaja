<?php
/**
 * Idempotent schema migration for the tokenized-intake spine.
 *
 * Safe to run repeatedly: every step checks for its own presence first, so
 * this never drops or rewrites existing data. Run it once against an existing
 * kaja_db. (schema.sql already contains all of this for fresh installs.)
 *
 *   CLI:     php migrate.php
 *   Browser: /Kaja/migrate.php  (requires an admin session)
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    session_start();
    if (empty($_SESSION['logged_in'])) {
        http_response_code(403);
        exit('Forbidden. Log in to the admin panel first, or run this from the command line.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/db-config.php';

$db = getDbConnection();
$applied = [];
$skipped = [];

function tableExists(PDO $db, $table) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $db, $table, $column) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * True when a named index is already on the table.
 * ADD INDEX IF NOT EXISTS is not portable across the MySQL/MariaDB builds this
 * ships on, so the presence check happens here instead.
 */
function indexExists(PDO $db, $table, $index) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i
    ");
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** True when an ENUM column already offers $value, so we can skip the ALTER. */
function enumHasValue(PDO $db, $table, $column, $value) {
    $stmt = $db->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    $type = $stmt->fetchColumn();
    if ($type === false) {
        return false;
    }
    return strpos($type, "'" . $value . "'") !== false;
}

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
    // ---- 1. settings -----------------------------------------------------
    step('create table `settings`', !tableExists($db, 'settings'), function (PDO $db) {
        $db->exec("
            CREATE TABLE `settings` (
                `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
                `setting_value` TEXT DEFAULT NULL,
                `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    // ---- 2. intake_links -------------------------------------------------
    // client_id is NULLable on purpose: the short-intake path knows only an
    // email address, so no client row exists until the form comes back.
    step('create table `intake_links`', !tableExists($db, 'intake_links'), function (PDO $db) {
        $db->exec("
            CREATE TABLE `intake_links` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `lead_id`           INT NOT NULL,
                `client_id`         INT DEFAULT NULL,
                `token`             CHAR(64) NOT NULL,
                `form_version`      INT NOT NULL DEFAULT 1,
                `status`            ENUM('sent','opened','submitted','expired') NOT NULL DEFAULT 'sent',
                `expires_at`        DATETIME NOT NULL,
                `opened_at`         DATETIME DEFAULT NULL,
                `submitted_at`      DATETIME DEFAULT NULL,
                `reminder_sent`     TINYINT(1) NOT NULL DEFAULT 0,
                `patient_intake_id` INT DEFAULT NULL,
                `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_token` (`token`),
                KEY `idx_lead`   (`lead_id`),
                KEY `idx_client` (`client_id`),
                KEY `idx_status` (`status`),
                CONSTRAINT `fk_intake_links_lead`
                    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_intake_links_client`
                    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    });

    // ---- 3. leads: spam status + duplicate flags -------------------------
    step("add 'spam' to `leads`.`status`", !enumHasValue($db, 'leads', 'status', 'spam'), function (PDO $db) {
        $db->exec("
            ALTER TABLE `leads`
            MODIFY COLUMN `status`
            ENUM('new','accepted','converted','declined','spam') NOT NULL DEFAULT 'new'
        ");
    });

    step('add `leads`.`possible_duplicate_of`', !columnExists($db, 'leads', 'possible_duplicate_of'), function (PDO $db) {
        $db->exec("ALTER TABLE `leads` ADD COLUMN `possible_duplicate_of` INT DEFAULT NULL AFTER `client_id`");
    });

    step('add `leads`.`is_existing_client`', !columnExists($db, 'leads', 'is_existing_client'), function (PDO $db) {
        $db->exec("ALTER TABLE `leads` ADD COLUMN `is_existing_client` TINYINT(1) NOT NULL DEFAULT 0 AFTER `possible_duplicate_of`");
    });

    // ---- 4. clients: pending status --------------------------------------
    // A client is created at lead-confirm time, before any intake data exists.
    step("add 'pending' to `clients`.`status`", !enumHasValue($db, 'clients', 'status', 'pending'), function (PDO $db) {
        $db->exec("
            ALTER TABLE `clients`
            MODIFY COLUMN `status`
            ENUM('pending','active','inactive','discharged') NOT NULL DEFAULT 'active'
        ");
    });

    // ---- 5. patient-intake: link back to the token and client ------------
    step('add `patient-intake`.`intake_link_id`', !columnExists($db, 'patient-intake', 'intake_link_id'), function (PDO $db) {
        $db->exec("ALTER TABLE `patient-intake` ADD COLUMN `intake_link_id` INT DEFAULT NULL");
    });

    step('add `patient-intake`.`client_id`', !columnExists($db, 'patient-intake', 'client_id'), function (PDO $db) {
        $db->exec("ALTER TABLE `patient-intake` ADD COLUMN `client_id` INT DEFAULT NULL");
    });

    step('add `patient-intake`.`form_version`', !columnExists($db, 'patient-intake', 'form_version'), function (PDO $db) {
        $db->exec("ALTER TABLE `patient-intake` ADD COLUMN `form_version` INT NOT NULL DEFAULT 1");
    });

    // ---- 6. seed settings ------------------------------------------------
    // INSERT IGNORE so an operator's edited values are never overwritten.
    require_once __DIR__ . '/includes/settings.php';
    $seed = $db->prepare("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES (:k, :v)");
    $seeded = 0;
    foreach (settingDefaults() as $key => $value) {
        $seed->execute([':k' => $key, ':v' => $value]);
        $seeded += $seed->rowCount();
    }
    if ($seeded > 0) {
        $applied[] = "seed {$seeded} default setting(s)";
    } else {
        $skipped[] = 'seed settings (all keys already present)';
    }

    // ---- 7. lead module: field list --------------------------------------

    step('leads.source (renamed from source_page)',
        tableExists($db, 'leads') && columnExists($db, 'leads', 'source_page'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` CHANGE `source_page` `source` VARCHAR(50) NOT NULL");
        }
    );

    step('leads.assigned_staff_id',
        tableExists($db, 'leads') && !columnExists($db, 'leads', 'assigned_staff_id'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD COLUMN `assigned_staff_id` INT DEFAULT NULL AFTER `client_id`");
            $db->exec("
                ALTER TABLE `leads`
                ADD CONSTRAINT `fk_leads_staff`
                FOREIGN KEY (`assigned_staff_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ");
        }
    );

    step('leads.form_version_id',
        tableExists($db, 'leads') && !columnExists($db, 'leads', 'form_version_id'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD COLUMN `form_version_id` INT NOT NULL DEFAULT 1 AFTER `assigned_staff_id`");
        }
    );

    // NOTE: leads.reminder_sent was added here originally. The intake module
    // moved the reminder gate onto intake_links, where a resent link earns its
    // own reminder, so the column is dropped further down. Re-adding it here
    // would make this migration ping-pong forever.

    step('leads.first_viewed_at',
        tableExists($db, 'leads') && !columnExists($db, 'leads', 'first_viewed_at'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD COLUMN `first_viewed_at` DATETIME DEFAULT NULL AFTER `reminder_sent`");
        }
    );

    step('leads indexes on status and created_at',
        tableExists($db, 'leads') && !indexExists($db, 'leads', 'idx_status'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `leads` ADD INDEX `idx_status` (`status`)");
            if (!indexExists($db, 'leads', 'idx_created')) {
                $db->exec("ALTER TABLE `leads` ADD INDEX `idx_created` (`created_at`)");
            }
        }
    );

    step('lead_notes table',
        !tableExists($db, 'lead_notes'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `lead_notes` (
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
                ) ENGINE=InnoDB
            ");
        }
    );

    // ---- 8. lead module: status vocabulary --------------------------------
    // Three steps, in this order. The first widens the enum so both
    // vocabularies are legal at once; the UPDATEs then move the rows; the
    // third narrows it. Doing it as one ALTER would coerce every 'accepted'
    // row to '' on the way through.

    step('leads.status widened to hold both vocabularies',
        tableExists($db, 'leads') && !enumHasValue($db, 'leads', 'status', 'confirmed'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `leads` MODIFY `status`
                ENUM('new','contacted','confirmed','converted','rejected','spam','accepted','declined')
                NOT NULL DEFAULT 'new'
            ");
        }
    );

    step('leads.status rows moved to the new vocabulary',
        tableExists($db, 'leads') && enumHasValue($db, 'leads', 'status', 'accepted'),
        function (PDO $db) {
            $db->exec("UPDATE `leads` SET `status`='confirmed' WHERE `status`='accepted'");
            $db->exec("UPDATE `leads` SET `status`='rejected'  WHERE `status`='declined'");
            // A lead that already produced a client is converted, whatever it said.
            $db->exec("UPDATE `leads` SET `status`='converted' WHERE `client_id` IS NOT NULL AND `client_id` > 0");
        }
    );

    step('leads.status narrowed to the new vocabulary only',
        tableExists($db, 'leads') && enumHasValue($db, 'leads', 'status', 'accepted'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `leads` MODIFY `status`
                ENUM('new','contacted','confirmed','converted','rejected','spam')
                NOT NULL DEFAULT 'new'
            ");
        }
    );

    // ---- 9. intake module ------------------------------------------------

    step("intake_links.status gains 'filled'",
        tableExists($db, 'intake_links') && !enumHasValue($db, 'intake_links', 'status', 'filled'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `intake_links` MODIFY `status`
                ENUM('sent','opened','filled','submitted','expired')
                NOT NULL DEFAULT 'sent'
            ");
        }
    );

    step('intake_links.filled_at',
        tableExists($db, 'intake_links') && !columnExists($db, 'intake_links', 'filled_at'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `intake_links` ADD COLUMN `filled_at` DATETIME DEFAULT NULL AFTER `opened_at`");
        }
    );

    step('intake_links.draft_answers',
        tableExists($db, 'intake_links') && !columnExists($db, 'intake_links', 'draft_answers'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `intake_links` ADD COLUMN `draft_answers` JSON DEFAULT NULL AFTER `reminder_sent`");
        }
    );

    step('leads.reminder_sent dropped (gate moved to intake_links)',
        tableExists($db, 'leads') && columnExists($db, 'leads', 'reminder_sent'),
        function (PDO $db) {
            // Carry any flag already set across, so a lead reminded under the
            // old per-lead scheme is not chased again under the per-link one.
            $db->exec("
                UPDATE `intake_links` il
                JOIN `leads` l ON l.`id` = il.`lead_id`
                SET il.`reminder_sent` = 1
                WHERE l.`reminder_sent` = 1
            ");
            $db->exec("ALTER TABLE `leads` DROP COLUMN `reminder_sent`");
        }
    );

    step('patient-intake consent columns',
        tableExists($db, 'patient-intake') && !columnExists($db, 'patient-intake', 'consent_given'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `patient-intake`
                ADD COLUMN `consent_given` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `consent_at` DATETIME DEFAULT NULL,
                ADD COLUMN `consent_version` INT NOT NULL DEFAULT 1
            ");
        }
    );

    // ---- 10. patient intake ----------------------------------------------

    step("clients.status gains 'review'",
        tableExists($db, 'clients') && !enumHasValue($db, 'clients', 'status', 'review'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients` MODIFY `status`
                ENUM('pending','review','active','inactive','discharged')
                NOT NULL DEFAULT 'active'
            ");
        }
    );

    step('clients intake columns',
        tableExists($db, 'clients') && !columnExists($db, 'clients', 'intake_data'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients`
                ADD COLUMN `intake_data` LONGTEXT DEFAULT NULL,
                ADD COLUMN `intake_form_version` INT DEFAULT NULL,
                ADD COLUMN `intake_submitted_at` DATETIME DEFAULT NULL
            ");
        }
    );

    // ---- 11. clients module ----------------------------------------------

    step('clients.status: discharged becomes completed',
        tableExists($db, 'clients') && !enumHasValue($db, 'clients', 'status', 'completed'),
        function (PDO $db) {
            // Widen, move the rows, then narrow -- the same three-step dance the
            // lead vocabulary needed. One ALTER would coerce every 'discharged'
            // row to '' on the way through.
            $db->exec("
                ALTER TABLE `clients` MODIFY `status`
                ENUM('pending','review','active','inactive','discharged','completed')
                NOT NULL DEFAULT 'active'
            ");
            $db->exec("UPDATE `clients` SET `status`='completed' WHERE `status`='discharged'");
            $db->exec("
                ALTER TABLE `clients` MODIFY `status`
                ENUM('pending','review','active','inactive','completed')
                NOT NULL DEFAULT 'active'
            ");
        }
    );

    step('clients archive and merge columns',
        tableExists($db, 'clients') && !columnExists($db, 'clients', 'archived_at'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `clients`
                ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
                ADD COLUMN `merged_into_id` INT DEFAULT NULL
            ");
        }
    );

    step('client_notes author, kind and correction columns',
        tableExists($db, 'client_notes') && !columnExists($db, 'client_notes', 'user_id'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `client_notes`
                ADD COLUMN `user_id` INT DEFAULT NULL,
                ADD COLUMN `note_kind` ENUM('session','administrative') NOT NULL DEFAULT 'session',
                ADD COLUMN `corrects_note_id` INT DEFAULT NULL
            ");
        }
    );

    step('client_fees method and reference',
        tableExists($db, 'client_fees') && !columnExists($db, 'client_fees', 'method'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `client_fees`
                ADD COLUMN `method` ENUM('cash','upi','bank_transfer','other') NOT NULL DEFAULT 'cash',
                ADD COLUMN `reference` VARCHAR(255) DEFAULT NULL
            ");
        }
    );

    step('client_documents table',
        !tableExists($db, 'client_documents'),
        function (PDO $db) {
            $db->exec("
                CREATE TABLE `client_documents` (
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
                ) ENGINE=InnoDB
            ");
        }
    );

    // ---- 12. sessions module ---------------------------------------------

    step('sessions.start_time and end_time',
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'start_time'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                ADD COLUMN `start_time` DATETIME NULL AFTER `client_id`,
                ADD COLUMN `end_time` DATETIME NULL AFTER `start_time`
            ");
            // Backfill from the columns being retired. duration_minutes may be
            // null on old rows; 60 was the historical default.
            $db->exec("
                UPDATE `sessions`
                SET `start_time` = TIMESTAMP(`session_date`, `session_time`),
                    `end_time`   = TIMESTAMP(`session_date`, `session_time`)
                                 + INTERVAL COALESCE(`duration_minutes`, 60) MINUTE
                WHERE `start_time` IS NULL
            ");
            $db->exec("ALTER TABLE `sessions` MODIFY `start_time` DATETIME NOT NULL");
            $db->exec("ALTER TABLE `sessions` MODIFY `end_time` DATETIME NOT NULL");
        }
    );

    step('sessions record fields',
        tableExists($db, 'sessions') && !columnExists($db, 'sessions', 'rescheduled_count'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                ADD COLUMN `video_link` VARCHAR(500) DEFAULT NULL,
                ADD COLUMN `recurring_series_id` INT DEFAULT NULL,
                ADD COLUMN `cancelled_reason` VARCHAR(500) DEFAULT NULL,
                ADD COLUMN `rescheduled_count` INT NOT NULL DEFAULT 0,
                ADD COLUMN `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ");
        }
    );

    step('sessions indexes for range and series lookups',
        tableExists($db, 'sessions') && !indexExists($db, 'sessions', 'idx_start'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_start` (`start_time`)");
            if (!indexExists($db, 'sessions', 'idx_series')) {
                $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_series` (`recurring_series_id`)");
            }
            if (!indexExists($db, 'sessions', 'idx_client_start')) {
                $db->exec("ALTER TABLE `sessions` ADD INDEX `idx_client_start` (`client_id`, `start_time`)");
            }
        }
    );

    step('sessions.status: scheduled becomes confirmed',
        tableExists($db, 'sessions') && !enumHasValue($db, 'sessions', 'status', 'confirmed'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions` MODIFY `status`
                ENUM('scheduled','pending','confirmed','completed','cancelled','no-show')
                NOT NULL DEFAULT 'pending'
            ");
            // Existing rows were already treated as locked in, so they become
            // confirmed. Demoting them to pending would silently un-confirm
            // real appointments people are expecting to attend.
            $db->exec("UPDATE `sessions` SET `status`='confirmed' WHERE `status`='scheduled'");
            $db->exec("
                ALTER TABLE `sessions` MODIFY `status`
                ENUM('pending','confirmed','completed','cancelled','no-show')
                NOT NULL DEFAULT 'pending'
            ");
        }
    );

    // Dropped last, once every reader has moved onto the range. Keeping them
    // through the earlier steps meant nothing broke mid-migration.
    step('sessions: drop the retired date, time and duration columns',
        tableExists($db, 'sessions') && columnExists($db, 'sessions', 'session_date'),
        function (PDO $db) {
            $db->exec("
                ALTER TABLE `sessions`
                DROP COLUMN `session_date`,
                DROP COLUMN `session_time`,
                DROP COLUMN `duration_minutes`
            ");
        }
    );

    step('client_notes.session_id',
        tableExists($db, 'client_notes') && !columnExists($db, 'client_notes', 'session_id'),
        function (PDO $db) {
            $db->exec("ALTER TABLE `client_notes` ADD COLUMN `session_id` INT DEFAULT NULL");
        }
    );

    // ---- 13. form module -------------------------------------------------

    step('form_questions table',
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

} catch (PDOException $e) {
    http_response_code(500);
    echo "MIGRATION FAILED\n" . $e->getMessage() . "\n";
    echo "\nApplied before failure:\n";
    foreach ($applied as $a) { echo "  + {$a}\n"; }
    exit(1);
}

echo "Migration complete.\n\n";
echo "Applied (" . count($applied) . "):\n";
foreach ($applied as $a) { echo "  + {$a}\n"; }
if (!$applied) { echo "  (nothing - schema already current)\n"; }
echo "\nSkipped (" . count($skipped) . "):\n";
foreach ($skipped as $s) { echo "  . {$s}\n"; }
