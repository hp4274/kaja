<?php

test('no PHP source still reads the retired session columns', function () {
    // The form still POSTS session_date and session_time, because a date input
    // plus a time input is the right pair to show. What must be gone is any
    // code treating them as columns.
    $root  = dirname(__DIR__);
    $files = [
        '/includes/scheduling.php', '/includes/client-repo.php',
        '/admin/api/sessions.php', '/admin/pages/sessions.php',
        '/admin/pages/dashboard.php', '/admin/pages/client-profile.php',
        '/admin/pages/reports.php',
    ];
    foreach ($files as $f) {
        $src = file_get_contents($root . $f);
        foreach (['`session_date`', '`session_time`', '`duration_minutes`',
                  's.session_date', 's.session_time', 's.duration_minutes'] as $needle) {
            assertTrue(strpos($src, $needle) === false,
                basename($f) . ' still reads ' . $needle);
        }
    }
});

test('the retired columns are gone from the table', function () {
    foreach (['session_date', 'session_time', 'duration_minutes'] as $c) {
        assertTrue(sessionsCol('sessions', $c) === false, $c . ' should have been dropped');
    }
});

test('nothing still writes the retired session status', function () {
    $root  = dirname(__DIR__);
    foreach (['/admin/api/sessions.php', '/admin/pages/sessions.php',
              '/admin/pages/client-profile.php', '/admin/pages/dashboard.php'] as $f) {
        $src = file_get_contents($root . $f);
        assertTrue(strpos($src, "'scheduled'") === false, basename($f) . " still uses 'scheduled'");
    }
});
