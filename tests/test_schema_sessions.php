<?php

function sessionsCol($table, $column) {
    $stmt = testDb()->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn();
}

test('sessions stores a real datetime range', function () {
    assertTrue(strpos((string) sessionsCol('sessions', 'start_time'), 'datetime') !== false, 'start_time');
    assertTrue(strpos((string) sessionsCol('sessions', 'end_time'), 'datetime') !== false, 'end_time');
});

test('the session status vocabulary matches the spec', function () {
    $type = sessionsCol('sessions', 'status');
    foreach (['pending', 'confirmed', 'completed', 'cancelled', 'no-show'] as $v) {
        assertTrue(strpos($type, "'" . $v . "'") !== false, 'status should offer ' . $v);
    }
    assertTrue(strpos($type, "'scheduled'") === false, 'scheduled became confirmed');
});

test('sessions carries the new record fields', function () {
    foreach (['video_link', 'recurring_series_id', 'cancelled_reason', 'rescheduled_count', 'updated_at'] as $c) {
        assertTrue(sessionsCol('sessions', $c) !== false, 'sessions should have ' . $c);
    }
});

test('a note can be tied to the session it is about', function () {
    assertTrue(sessionsCol('client_notes', 'session_id') !== false, 'client_notes.session_id');
});
