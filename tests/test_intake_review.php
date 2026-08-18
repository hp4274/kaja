<?php
require_once __DIR__ . '/../includes/intake-data.php';

test('clients awaiting review are listed oldest first', function () {
    resetTestTables(['clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);

    foreach ([['Older', '2026-08-01 09:00:00'], ['Newer', '2026-08-10 09:00:00']] as $c) {
        testDb()->prepare('
            INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`,`intake_submitted_at`)
            VALUES (:l, :fn, "Test", "t@example.com", "review", :at)
        ')->execute([':l' => $leadId, ':fn' => $c[0], ':at' => $c[1]]);
    }

    $rows = clientsAwaitingReview(testDb());
    assertSame(2, count($rows), 'both are waiting');
    assertSame('Older', $rows[0]['first_name'], 'the longest wait comes first');
});

test('an active client is not awaiting review', function () {
    resetTestTables(['clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
        VALUES (:l, "Done", "Test", "d@example.com", "active")
    ')->execute([':l' => $leadId]);

    assertSame(0, count(clientsAwaitingReview(testDb())), 'already reviewed');
});

test('marking reviewed only moves a client that is actually at review', function () {
    resetTestTables(['clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('
        INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
        VALUES (:l, "Already", "Active", "a@example.com", "active")
    ')->execute([':l' => $leadId]);
    $id = (int) testDb()->lastInsertId();

    // The same statement the API runs. A second click on a stale page must not
    // log a review that never happened.
    $stmt = testDb()->prepare("UPDATE `clients` SET `status`='active' WHERE `id`=:id AND `status`='review'");
    $stmt->execute([':id' => $id]);
    assertSame(0, $stmt->rowCount(), 'nothing moved, so nothing is logged');
});
