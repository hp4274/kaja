<?php
/**
 * Zero-dependency test library. No Composer in this project, so this is a
 * deliberately small harness: register tests with test(), assert with the
 * assert* helpers, run them all with tests/run.php.
 */

$GLOBALS['__tests'] = [];

function test($name, callable $fn) {
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

class AssertionFailed extends Exception {}

function assertTrue($cond, $message) {
    if ($cond !== true) {
        throw new AssertionFailed($message . ' — expected true, got ' . var_export($cond, true));
    }
}

function assertSame($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new AssertionFailed(
            $message . ' — expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assertContains($needle, array $haystack, $message) {
    if (!in_array($needle, $haystack, true)) {
        throw new AssertionFailed(
            $message . ' — ' . var_export($needle, true) . ' not in ' . json_encode($haystack)
        );
    }
}

function assertThrows(callable $fn, $message) {
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new AssertionFailed($message . ' — expected a throw, nothing was thrown');
}
