<?php
require_once __DIR__ . '/../includes/lead-repo.php';

test('pending actions are new leads past the aging threshold, oldest first', function () {
    resetTestTables(['leads']);
    $old   = insertTestLead(['email'=>'old@example.com',   'status'=>'new',       'created_at'=>'2026-08-01 09:00:00']);
    $older = insertTestLead(['email'=>'older@example.com', 'status'=>'new',       'created_at'=>'2026-07-01 09:00:00']);
    insertTestLead(['email'=>'fresh@example.com', 'status'=>'new',       'created_at'=>date('Y-m-d H:i:s')]);
    insertTestLead(['email'=>'moved@example.com', 'status'=>'contacted', 'created_at'=>'2026-07-01 09:00:00']);

    $pending = dashboardPendingLeads(testDb());
    $ids     = array_map('intval', array_column($pending, 'id'));

    assertSame([$older, $old], $ids, 'only untouched new leads, oldest first');
});

test('spam and rejected never count as new leads', function () {
    resetTestTables(['leads']);
    insertTestLead(['email'=>'a@example.com', 'status'=>'new']);
    insertTestLead(['email'=>'b@example.com', 'status'=>'spam']);
    insertTestLead(['email'=>'c@example.com', 'status'=>'rejected']);

    $counts = leadStatusCounts(testDb());
    assertSame(1, $counts['new'], 'only the genuinely new lead counts');
});

test('a spam lead left sitting is never a pending action', function () {
    resetTestTables(['leads']);
    insertTestLead(['email'=>'spam@example.com', 'status'=>'spam', 'created_at'=>'2026-07-01 09:00:00']);
    assertSame(0, count(dashboardPendingLeads(testDb())), 'spam is decided, not pending');
});
