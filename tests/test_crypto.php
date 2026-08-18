<?php
require_once __DIR__ . '/../includes/crypto.php';

test('a value round-trips', function () {
    $plain = 'Emergency contact: Anita Rao, +91 99000 11111';
    assertSame($plain, decryptSensitive(encryptSensitive($plain)), 'what goes in comes out');
});

test('the ciphertext does not contain the plaintext', function () {
    $blob = encryptSensitive('sleepwalking: yes');
    assertTrue(strpos(base64_decode($blob), 'sleepwalking') === false, 'nothing readable survives');
});

test('encrypting the same value twice gives different ciphertext', function () {
    // A fresh IV each time. Identical output would leak that two clients gave
    // identical answers, which for a health questionnaire is itself a fact.
    assertTrue(encryptSensitive('yes') !== encryptSensitive('yes'), 'IV is not reused');
});

test('a tampered blob decrypts to null rather than to garbage', function () {
    $blob = encryptSensitive('trust me');
    $raw  = base64_decode($blob);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
    assertSame(null, decryptSensitive(base64_encode($raw)), 'GCM rejects it');
});

test('rubbish input decrypts to null instead of throwing', function () {
    assertSame(null, decryptSensitive('not base64 at all !!!'), 'no exception escapes');
    assertSame(null, decryptSensitive(''), 'empty is null');
});

test('an empty string round-trips as an empty string', function () {
    assertSame('', decryptSensitive(encryptSensitive('')), 'empty is a value, not a failure');
});
