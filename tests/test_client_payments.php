<?php
require_once __DIR__ . '/../includes/client-payments.php';

function payClient() {
    resetTestTables(['client_fees', 'clients', 'leads']);
    $leadId = insertTestLead(['status' => 'confirmed']);
    testDb()->prepare('INSERT INTO `clients` (`lead_id`,`first_name`,`last_name`,`email`,`status`)
                       VALUES (:l,"Anita","Rao","a@example.com","active")')->execute([':l' => $leadId]);
    return (int) testDb()->lastInsertId();
}

test('a payment records amount, method and reference', function () {
    $c = payClient();
    addClientPayment(testDb(), $c, '1500.00', 'upi', '2026-08-18', 'UPI ref 8811');

    $rows = clientPayments(testDb(), $c);
    assertSame(1, count($rows), 'one entry');
    assertSame('upi', $rows[0]['method'], 'method stored');
    assertSame('UPI ref 8811', $rows[0]['reference'], 'reference stored');
});

test('the running total is the sum of the ledger', function () {
    $c = payClient();
    addClientPayment(testDb(), $c, '1500.00', 'cash', '2026-08-01', '');
    addClientPayment(testDb(), $c, '500.50',  'upi',  '2026-08-05', '');
    assertSame('2000.50', number_format((float) clientPaymentTotal(testDb(), $c), 2, '.', ''), 'summed');
});

test('an empty ledger totals zero rather than null', function () {
    $c = payClient();
    assertSame(0.0, (float) clientPaymentTotal(testDb(), $c), 'zero, not null');
});

test('a zero or negative amount is refused', function () {
    $c = payClient();
    assertThrows(function () use ($c) { addClientPayment(testDb(), $c, '0', 'cash', '2026-08-01', ''); },
        'zero is not a payment');
    assertThrows(function () use ($c) { addClientPayment(testDb(), $c, '-50', 'cash', '2026-08-01', ''); },
        'a negative is a refund, which this ledger does not model');
});

test('an unknown method is refused rather than silently stored as cash', function () {
    $c = payClient();
    assertThrows(function () use ($c) {
        addClientPayment(testDb(), $c, '100', 'bitcoin', '2026-08-01', '');
    }, 'MySQL would coerce it to an empty string, so it is checked first');
});

test('a missing or malformed date is refused', function () {
    $c = payClient();
    assertThrows(function () use ($c) { addClientPayment(testDb(), $c, '100', 'cash', '', ''); },
        'a payment with no date cannot be reconciled');
    assertThrows(function () use ($c) { addClientPayment(testDb(), $c, '100', 'cash', '18/08/2026', ''); },
        'and a non-ISO date would be stored wrong');
});

test('the ledger is newest first', function () {
    $c = payClient();
    addClientPayment(testDb(), $c, '100', 'cash', '2026-08-01', 'older');
    addClientPayment(testDb(), $c, '200', 'cash', '2026-08-10', 'newer');
    assertSame('newer', clientPayments(testDb(), $c)[0]['reference'], 'most recent first');
});

test('decimal amounts survive the round trip exactly', function () {
    $c = payClient();
    addClientPayment(testDb(), $c, '1234.56', 'bank_transfer', '2026-08-01', '');
    assertSame('1234.56', clientPayments(testDb(), $c)[0]['amount'], 'no float rounding');
});
