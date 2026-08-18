<?php
require_once __DIR__ . '/../includes/lead-repo.php';

function seedRepoLeads() {
    resetTestTables(['leads']);
    return [
        'anita'  => insertTestLead(['name'=>'Anita Rao',  'email'=>'anita@example.com', 'phone'=>'9001',
                                    'status'=>'new',       'source'=>'home',        'created_at'=>'2026-08-01 09:00:00']),
        'bhavik' => insertTestLead(['name'=>'Bhavik Shah','email'=>'bhavik@example.com','phone'=>'9002',
                                    'status'=>'contacted', 'source'=>'appointment', 'created_at'=>'2026-08-10 09:00:00']),
        'chirag' => insertTestLead(['name'=>'Chirag Nair','email'=>'chirag@example.com','phone'=>'9003',
                                    'status'=>'spam',      'source'=>'home',        'created_at'=>'2026-08-15 09:00:00']),
    ];
}

test('no filters returns everything, newest first', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), []);
    assertSame(3, count($rows), 'all three leads come back');
    assertSame('Chirag Nair', $rows[0]['name'], 'default sort is newest first');
});

test('search matches name, email and phone', function () {
    seedRepoLeads();
    assertSame(1, count(fetchLeads(testDb(), ['q' => 'anita'])), 'name match');
    assertSame(1, count(fetchLeads(testDb(), ['q' => 'bhavik@example'])), 'email match');
    assertSame(1, count(fetchLeads(testDb(), ['q' => '9003'])), 'phone match');
});

test('search is not SQL injectable', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), ['q' => "' OR 1=1 --"]);
    assertSame(0, count($rows), 'the quote is data, not syntax');
});

test('status filter narrows to one status', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), ['status' => 'contacted']);
    assertSame(1, count($rows), 'one contacted lead');
    assertSame('Bhavik Shah', $rows[0]['name'], 'and it is the right one');
});

test('an unknown status filter is ignored rather than returning nothing', function () {
    seedRepoLeads();
    assertSame(3, count(fetchLeads(testDb(), ['status' => 'bogus'])), 'garbage filter falls back to all');
});

test('source filter narrows to one source', function () {
    seedRepoLeads();
    assertSame(2, count(fetchLeads(testDb(), ['source' => 'home'])), 'two home leads');
});

test('date range is inclusive on both ends', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), ['date_from' => '2026-08-10', 'date_to' => '2026-08-15']);
    assertSame(2, count($rows), 'the 10th and the 15th are both inside the range');
});

test('sort by status ascending orders by pipeline position, not alphabet', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), ['sort' => 'status', 'dir' => 'asc']);
    assertSame('new',       $rows[0]['status'], 'new comes first');
    assertSame('contacted', $rows[1]['status'], 'then contacted');
    assertSame('spam',      $rows[2]['status'], 'terminal states come last');
});

test('sort direction is whitelisted, not interpolated', function () {
    seedRepoLeads();
    $rows = fetchLeads(testDb(), ['sort' => 'date', 'dir' => 'ASC; DROP TABLE leads']);
    assertSame(3, count($rows), 'a junk direction degrades to the default instead of executing');
});

test('status counts cover every status plus a total', function () {
    seedRepoLeads();
    $counts = leadStatusCounts(testDb());
    assertSame(1, $counts['new'], 'one new');
    assertSame(1, $counts['contacted'], 'one contacted');
    assertSame(0, $counts['converted'], 'no converted');
    assertSame(3, $counts['all'], 'three in total');
});

test('sources list is distinct and sorted', function () {
    seedRepoLeads();
    assertSame(['appointment', 'home'], leadSources(testDb()), 'distinct sources, alphabetical');
});

test('markLeadViewed stamps once and never again', function () {
    $ids = seedRepoLeads();
    markLeadViewed(testDb(), $ids['anita']);
    $first = fetchLead(testDb(), $ids['anita'])['first_viewed_at'];
    assertTrue($first !== null, 'first view is stamped');

    markLeadViewed(testDb(), $ids['anita']);
    $second = fetchLead(testDb(), $ids['anita'])['first_viewed_at'];
    assertSame($first, $second, 'a second view must not move the stamp');
});

test('fetchLead returns null for an id that does not exist', function () {
    seedRepoLeads();
    assertSame(null, fetchLead(testDb(), 999999), 'missing lead is null, not false');
});
