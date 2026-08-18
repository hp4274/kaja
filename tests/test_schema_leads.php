<?php

function leadsColumnType($column) {
    $stmt = testDb()->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = :c
    ");
    $stmt->execute([':c' => $column]);
    return $stmt->fetchColumn();
}

test('leads.status offers the full spec vocabulary', function () {
    $type = leadsColumnType('status');
    foreach (['new', 'contacted', 'confirmed', 'converted', 'rejected', 'spam'] as $value) {
        assertTrue(strpos($type, "'" . $value . "'") !== false, 'status enum should contain ' . $value);
    }
});

test('leads.status no longer offers the old vocabulary', function () {
    $type = leadsColumnType('status');
    assertTrue(strpos($type, "'accepted'") === false, 'accepted should be gone from the enum');
    assertTrue(strpos($type, "'declined'") === false, 'declined should be gone from the enum');
});

test('leads carries every field the spec lists', function () {
    foreach (['source', 'assigned_staff_id', 'form_version_id', 'reminder_sent',
              'possible_duplicate_of', 'first_viewed_at'] as $column) {
        assertTrue(leadsColumnType($column) !== false, 'leads should have a ' . $column . ' column');
    }
});

test('source_page is gone — renamed, not duplicated', function () {
    assertTrue(leadsColumnType('source_page') === false, 'source_page should have been renamed to source');
});
