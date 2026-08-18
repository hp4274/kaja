<?php

function clientsCol($table, $column) {
    $stmt = testDb()->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return $stmt->fetchColumn();
}

test('clients carries the lifecycle and archive columns', function () {
    foreach (['updated_at', 'archived_at', 'merged_into_id'] as $c) {
        assertTrue(clientsCol('clients', $c) !== false, 'clients should have ' . $c);
    }
});

test('the client status vocabulary matches the spec', function () {
    $type = clientsCol('clients', 'status');
    foreach (['pending', 'review', 'active', 'inactive', 'completed'] as $v) {
        assertTrue(strpos($type, "'" . $v . "'") !== false, 'status should offer ' . $v);
    }
    assertTrue(strpos($type, "'discharged'") === false, 'discharged was renamed to completed');
});

test('notes record their author, kind and any entry they correct', function () {
    foreach (['user_id', 'note_kind', 'corrects_note_id'] as $c) {
        assertTrue(clientsCol('client_notes', $c) !== false, 'client_notes should have ' . $c);
    }
});

test('payments record how the money arrived', function () {
    foreach (['method', 'reference'] as $c) {
        assertTrue(clientsCol('client_fees', $c) !== false, 'client_fees should have ' . $c);
    }
});

test('client_documents exists and separates the original name from the stored path', function () {
    foreach (['client_id', 'original_name', 'stored_name', 'mime_type', 'size_bytes', 'uploaded_at'] as $c) {
        assertTrue(clientsCol('client_documents', $c) !== false, 'client_documents should have ' . $c);
    }
});
