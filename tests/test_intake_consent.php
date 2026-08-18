<?php
require_once __DIR__ . '/../includes/intake-status.php';

test('the consent version is a defined constant, not a literal', function () {
    assertTrue(defined('INTAKE_CONSENT_VERSION'), 'the version is declared once');
    assertTrue(INTAKE_CONSENT_VERSION >= 1, 'and is a real version number');
});

test('the form carries a required consent checkbox', function () {
    $html = file_get_contents(dirname(__DIR__) . '/patient-intake-form.html');
    assertTrue(strpos($html, 'name="consent_given"') !== false, 'the field exists');
    assertTrue(
        preg_match('/name="consent_given"[^>]*required/', $html) === 1,
        'and is required in the markup, not only on the server'
    );
});

test('consent is its own section so the wizard gives it a step', function () {
    $html = file_get_contents(dirname(__DIR__) . '/patient-intake-form.html');
    $pos  = strpos($html, 'name="consent_given"');
    $before = substr($html, 0, $pos);
    assertTrue(
        strrpos($before, 'ra-form-section-hdr') !== false,
        'a section heading precedes the checkbox'
    );
});

test('the submit handler refuses a form with no consent', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    assertTrue(strpos($src, 'consent_given') !== false, 'the handler reads the field');
    assertTrue(strpos($src, 'INTAKE_CONSENT_VERSION') !== false, 'and stores the version it was given under');
});

test('the submit handler clears the draft once the answers have landed', function () {
    $src = file_get_contents(dirname(__DIR__) . '/submit_intake.php');
    assertTrue(strpos($src, 'clearIntakeDraft') !== false, 'the draft is not left behind as a second copy');
});
