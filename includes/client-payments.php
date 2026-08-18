<?php
/**
 * The client payment ledger.
 *
 * Manual entries only: there is no gateway and no auto-invoicing, so this
 * records what the therapist was actually paid rather than what was billed.
 * Amounts are handled as strings up to the point they reach a DECIMAL column —
 * money that has been through a float is money that no longer adds up.
 */

require_once __DIR__ . '/../db-config.php';

function clientPaymentMethods() {
    return ['cash', 'upi', 'bank_transfer', 'other'];
}

function clientPaymentMethodLabel($method) {
    $labels = [
        'cash'          => 'Cash',
        'upi'           => 'UPI',
        'bank_transfer' => 'Bank transfer',
        'other'         => 'Other',
    ];
    return isset($labels[$method]) ? $labels[$method] : 'Unknown';
}

function addClientPayment(PDO $db, $clientId, $amount, $method, $date, $reference = '') {
    if (!in_array($method, clientPaymentMethods(), true)) {
        // Checked here rather than left to the enum: MySQL would coerce an
        // unknown value to '' and the ledger would claim it was cash.
        throw new InvalidArgumentException('Unknown payment method: ' . $method);
    }
    if (!is_numeric($amount) || (float) $amount <= 0) {
        throw new InvalidArgumentException('A payment must be a positive amount.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
        throw new InvalidArgumentException('A payment needs a date.');
    }

    $db->prepare('
        INSERT INTO `client_fees` (`client_id`,`amount`,`method`,`reference`,`fee_date`,`status`,`description`)
        VALUES (:c,:a,:m,:r,:d,"paid",:desc)
    ')->execute([
        ':c'    => (int) $clientId,
        ':a'    => $amount,
        ':m'    => $method,
        ':r'    => mb_substr((string) $reference, 0, 255),
        ':d'    => $date,
        ':desc' => clientPaymentMethodLabel($method) . ' payment',
    ]);

    return (int) $db->lastInsertId();
}

function clientPayments(PDO $db, $clientId) {
    $stmt = $db->prepare('
        SELECT `id`, `amount`, `method`, `reference`, `fee_date`, `status`, `created_at`
        FROM `client_fees`
        WHERE `client_id` = :c
        ORDER BY `fee_date` DESC, `id` DESC
    ');
    $stmt->execute([':c' => (int) $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Summed by the database, which owns the DECIMAL type these are stored in. */
function clientPaymentTotal(PDO $db, $clientId) {
    $stmt = $db->prepare('SELECT COALESCE(SUM(`amount`), 0) FROM `client_fees` WHERE `client_id` = :c');
    $stmt->execute([':c' => (int) $clientId]);
    return $stmt->fetchColumn();
}
