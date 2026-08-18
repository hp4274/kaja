<?php
require_once __DIR__ . '/../includes/settings.php';

test('schema.sql seeds every setting the application falls back to', function () {
    // A fresh install missing a key is the kind of drift nobody notices until
    // an email goes out with an unrendered placeholder in it.
    $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');

    $start = strpos($sql, '-- Seed default settings.');
    $end   = strpos($sql, 'ON DUPLICATE KEY UPDATE', $start);
    assertTrue($start !== false && $end !== false, 'the seed block exists');

    $block   = substr($sql, $start, $end - $start);
    $missing = [];
    foreach (array_keys(settingDefaults()) as $key) {
        if (strpos($block, "('" . $key . "'") === false) {
            $missing[] = $key;
        }
    }

    assertSame([], $missing, 'schema.sql is missing: ' . implode(', ', $missing));
});

test('a freshly built database has every setting row', function () {
    // The test database is built from schema.sql, so this proves the seed
    // actually executes rather than merely containing the right text.
    $rows = testDb()->query('SELECT `setting_key` FROM `settings`')->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff(array_keys(settingDefaults()), $rows);
    assertSame([], array_values($missing), 'not seeded: ' . implode(', ', $missing));
});
