<?php

test('test database is a separate database from the live one', function () {
    $name = testDb()->query('SELECT DATABASE()')->fetchColumn();
    assertSame('kaja_db_test', $name, 'tests must not run against kaja_db');
});

test('schema.sql created the leads table in the test database', function () {
    $count = testDb()->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
    ")->fetchColumn();
    assertSame(1, (int) $count, 'leads table should exist after buildTestSchema()');
});

test('insertTestLead round-trips a row', function () {
    resetTestTables(['leads']);
    $id  = insertTestLead(['name' => 'Round Trip']);
    $row = testDb()->query("SELECT * FROM `leads` WHERE `id`=" . (int) $id)->fetch();
    assertSame('Round Trip', $row['name'], 'inserted name should come back');
});
