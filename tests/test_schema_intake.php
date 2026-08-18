<?php

function intakeColumnType($table, $column) {
    $stmt = testDb()->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn();
}

test('intake_links.status offers the full lifecycle', function () {
    $type = intakeColumnType('intake_links', 'status');
    foreach (['sent', 'opened', 'filled', 'submitted', 'expired'] as $value) {
        assertTrue(strpos($type, "'" . $value . "'") !== false, 'status should contain ' . $value);
    }
});

test('intake_links carries the timing and draft columns', function () {
    foreach (['opened_at', 'filled_at', 'submitted_at', 'expires_at', 'draft_answers', 'reminder_sent'] as $c) {
        assertTrue(intakeColumnType('intake_links', $c) !== false, 'intake_links should have ' . $c);
    }
});

test('the reminder gate lives on intake_links, not on leads', function () {
    assertTrue(intakeColumnType('intake_links', 'reminder_sent') !== false, 'intake_links keeps the flag');
    assertTrue(intakeColumnType('leads', 'reminder_sent') === false, 'leads.reminder_sent was dropped');
});

test('patient-intake records consent with a timestamp and a text version', function () {
    foreach (['consent_given', 'consent_at', 'consent_version'] as $c) {
        assertTrue(intakeColumnType('patient-intake', $c) !== false, 'patient-intake should have ' . $c);
    }
});
