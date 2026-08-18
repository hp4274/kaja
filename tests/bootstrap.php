<?php
/**
 * Builds `kaja_db_test` from schema.sql and points the application's
 * getDbConnection() at it.
 *
 * schema.sql opens with DROP DATABASE IF EXISTS `kaja_db`. Every occurrence of
 * the database name is rewritten before executing, so a test run can never
 * drop the real database.
 */

define('DB_NAME', 'kaja_db_test');
require_once __DIR__ . '/../db-config.php';
require_once __DIR__ . '/lib.php';

function testDb() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = getDbConnection();
    }
    return $pdo;
}

function buildTestSchema() {
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    if ($sql === false) {
        fwrite(STDERR, "cannot read schema.sql\n");
        exit(1);
    }
    $sql = str_replace('`kaja_db`', '`kaja_db_test`', $sql);

    $dsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
    $root = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec($sql);
}

function resetTestTables(array $tables) {
    $db = testDb();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $db->exec('TRUNCATE TABLE `' . $t . '`');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** A lead row with sane defaults; pass overrides to vary one field at a time. */
function insertTestLead(array $overrides = []) {
    $db = testDb();
    $row = array_merge([
        'name'       => 'Test Person',
        'email'      => 'test@example.com',
        'phone'      => '9990001111',
        'message'    => 'I would like to book a session.',
        'source'     => 'appointment',
        'status'     => 'new',
        'created_at' => date('Y-m-d H:i:s'),
    ], $overrides);

    $stmt = $db->prepare("
        INSERT INTO `leads` (`name`,`email`,`phone`,`message`,`source`,`status`,`created_at`)
        VALUES (:name,:email,:phone,:message,:source,:status,:created_at)
    ");
    $stmt->execute([
        ':name' => $row['name'], ':email' => $row['email'], ':phone' => $row['phone'],
        ':message' => $row['message'], ':source' => $row['source'],
        ':status' => $row['status'], ':created_at' => $row['created_at'],
    ]);
    return (int) $db->lastInsertId();
}
