<?php
require_once __DIR__ . '/../includes/lead-status.php';

test('no PHP source still writes the retired status values', function () {
    $root  = dirname(__DIR__);
    $files = [
        $root . '/admin/api/leads.php',
        $root . '/admin/pages/leads.php',
        $root . '/admin/pages/patient-intake.php',
        $root . '/admin/pages/intake.php',
        $root . '/submit-form.php',
    ];
    foreach ($files as $file) {
        $src = file_get_contents($file);
        assertTrue(strpos($src, "'accepted'") === false, basename($file) . " still mentions 'accepted'");
        assertTrue(strpos($src, "'declined'") === false, basename($file) . " still mentions 'declined'");
        assertTrue(strpos($src, 'source_page') === false, basename($file) . ' still mentions source_page');
    }
});

test('a status the pipeline forbids is refused before it reaches SQL', function () {
    // new -> converted skips confirm, which would leave a client-less converted lead.
    assertSame(false, leadCanTransition('new', 'converted'), 'guard must refuse the skip');
});

test('the short-intake insert records a possible duplicate', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit-form.php');

    // Anchor on the INSERT itself, not on the case label — the label appears
    // dozens of lines earlier and would make this assertion meaningless.
    $pos = strpos($src, "VALUES ('Valued Client'");
    assertTrue($pos !== false, 'the short-intake insert should still exist');

    $window = substr($src, max(0, $pos - 900), 1100);
    assertTrue(
        strpos($window, 'findPossibleDuplicate') !== false,
        'the short-intake path must run duplicate detection too'
    );
    assertTrue(
        strpos($window, 'possible_duplicate_of') !== false,
        'and must store what it found'
    );
});
