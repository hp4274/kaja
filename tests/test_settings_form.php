<?php
require_once __DIR__ . '/../includes/settings.php';

test('the notification template defaults exist', function () {
    $defaults = settingDefaults();
    assertTrue(isset($defaults['notify_lead_confirmed_subject']), 'subject default');
    assertTrue(isset($defaults['notify_lead_confirmed_body']), 'body default');
});

test('the default body uses both placeholders', function () {
    $body = settingDefaults()['notify_lead_confirmed_body'];
    assertTrue(strpos($body, '{{name}}') !== false, '{{name}} is used');
    assertTrue(strpos($body, '{{intake_link}}') !== false, '{{intake_link}} is used');
});

test('rendering substitutes every known placeholder', function () {
    $out = renderNotificationTemplate(
        'Hello {{name}}, your link: {{intake_link}}',
        ['name' => 'Anita', 'intake_link' => 'https://example.com/x']
    );
    assertSame('Hello Anita, your link: https://example.com/x', $out, 'both substituted');
});

test('an unknown placeholder is left alone rather than blanked', function () {
    $out = renderNotificationTemplate('Hi {{name}}, {{mystery}}', ['name' => 'Anita']);
    assertTrue(strpos($out, '{{mystery}}') !== false, 'a typo stays visible instead of vanishing');
});

test('a placeholder value cannot smuggle another placeholder', function () {
    // Substituting one value must not create a token a later pass expands.
    $out = renderNotificationTemplate(
        '{{name}} {{intake_link}}',
        ['name' => '{{intake_link}}', 'intake_link' => 'REAL']
    );
    assertSame('{{intake_link}} REAL', $out, 'each placeholder is replaced exactly once');
});

test('the settings page only writes keys it whitelists', function () {
    $src = file_get_contents(dirname(__DIR__) . '/admin/pages/settings.php');
    assertTrue(strpos($src, '$editable') !== false, 'there is a whitelist');
    assertTrue(
        strpos($src, 'foreach ($_POST as') === false,
        'and the form never loops the raw POST into setSetting()'
    );
});
