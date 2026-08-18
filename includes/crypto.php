<?php
/**
 * Encryption for the one column that warrants it: clients.intake_data.
 *
 * AES-256-GCM, so a tampered blob fails authentication rather than decrypting
 * into plausible garbage. A fresh IV per call: identical ciphertext for
 * identical input would leak that two clients gave the same answers, which on
 * a health questionnaire is itself a disclosure.
 *
 * The key is INTAKE_ENCRYPTION_KEY in db-config.php, which is deliberately
 * untracked. This protects against a database-only disclosure — a stolen dump,
 * a backup on the wrong disk — not against someone who can already read the
 * source.
 *
 * Lose the key and the data is gone. A database backup alone will not restore
 * it.
 */

require_once __DIR__ . '/../db-config.php';

const SENSITIVE_CIPHER  = 'aes-256-gcm';
const SENSITIVE_IV_LEN  = 12;   // GCM's standard nonce length
const SENSITIVE_TAG_LEN = 16;

function sensitiveKeyIsConfigured() {
    return defined('INTAKE_ENCRYPTION_KEY')
        && is_string(INTAKE_ENCRYPTION_KEY)
        && strlen(INTAKE_ENCRYPTION_KEY) >= 64;   // 32 bytes, hex-encoded
}

function sensitiveKey() {
    if (!sensitiveKeyIsConfigured()) {
        throw new RuntimeException(
            'INTAKE_ENCRYPTION_KEY is missing or too short. Run: php tools/generate-key.php'
        );
    }
    return hex2bin(substr(INTAKE_ENCRYPTION_KEY, 0, 64));
}

function encryptSensitive($plain) {
    $iv  = random_bytes(SENSITIVE_IV_LEN);
    $tag = '';

    $cipher = openssl_encrypt(
        (string) $plain, SENSITIVE_CIPHER, sensitiveKey(),
        OPENSSL_RAW_DATA, $iv, $tag, '', SENSITIVE_TAG_LEN
    );

    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }

    return base64_encode($iv . $tag . $cipher);
}

/** Null on any failure — wrong key, truncation, tampering, junk input. */
function decryptSensitive($blob) {
    if (!is_string($blob) || $blob === '') {
        return null;
    }

    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < SENSITIVE_IV_LEN + SENSITIVE_TAG_LEN) {
        return null;
    }

    $iv     = substr($raw, 0, SENSITIVE_IV_LEN);
    $tag    = substr($raw, SENSITIVE_IV_LEN, SENSITIVE_TAG_LEN);
    $cipher = substr($raw, SENSITIVE_IV_LEN + SENSITIVE_TAG_LEN);

    $plain = openssl_decrypt(
        $cipher, SENSITIVE_CIPHER, sensitiveKey(),
        OPENSSL_RAW_DATA, $iv, $tag
    );

    return $plain === false ? null : $plain;
}
