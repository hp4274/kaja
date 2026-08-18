<?php
/**
 * Test runner.  Usage:
 *   php tests/run.php              run every tests/test_*.php
 *   php tests/run.php lead_status  run only the files matching "lead_status"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Tests run from the command line only.');
}

require_once __DIR__ . '/bootstrap.php';

buildTestSchema();

$filter = isset($argv[1]) ? $argv[1] : '';
$files  = glob(__DIR__ . '/test_*.php');
sort($files);

foreach ($files as $file) {
    if ($filter !== '' && strpos(basename($file), $filter) === false) {
        continue;
    }
    require_once $file;
}

$passed = 0;
$failed = 0;

foreach ($GLOBALS['__tests'] as $t) {
    try {
        $t['fn']();
        $passed++;
        echo "  PASS  " . $t['name'] . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  " . $t['name'] . "\n";
        echo "        " . $e->getMessage() . "\n";
    }
}

echo "\n" . $passed . " passed, " . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
