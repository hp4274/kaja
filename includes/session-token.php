<?php
/**
 * One-time accept/reject links for a session that is waiting on the client.
 *
 * The token is 64 random hex characters stored on the row, the same shape as
 * intake tokens. It is single use: the response UPDATE only matches while
 * `responded_at` is NULL, so a second click (or a mail scanner replaying the
 * link) changes nothing. A reschedule issues a fresh token and clears
 * `responded_at`.
 */

require_once __DIR__ . '/intake-token.php';

/** Issue (or replace) the token for a session. Call outside any transaction. */
function issueSessionToken(PDO $db, $sessionId) {
    $token = bin2hex(random_bytes(32));
    $db->prepare('UPDATE `sessions` SET `response_token` = :t, `responded_at` = NULL WHERE `id` = :id')
       ->execute([':t' => $token, ':id' => (int) $sessionId]);
    return $token;
}

function sessionResponseUrl($token, $choice = '') {
    return siteBaseUrl() . 'session-response.php?token=' . urlencode($token)
         . ($choice !== '' ? '&r=' . $choice : '');
}

/** The session row (with client name) for a token, or null. */
function findSessionByToken(PDO $db, $token) {
    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT s.*, c.`first_name`, c.`last_name`
        FROM `sessions` s JOIN `clients` c ON c.`id` = s.`client_id`
        WHERE s.`response_token` = :t LIMIT 1
    ');
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 'open' | 'answered' | 'closed' (cancelled/past/finished). */
function sessionTokenState(array $row) {
    if (!in_array($row['status'], ['pending', 'rejected', 'confirmed'], true)
        || strtotime($row['start_time']) < time()) {
        return 'closed';
    }
    return ($row['responded_at'] !== null || $row['status'] !== 'pending') ? 'answered' : 'open';
}

/**
 * Record the client's answer. Returns true only for the request that actually
 * flipped the row, so the caller mails and logs exactly once.
 */
function respondToSession(PDO $db, $token, $accept) {
    $stmt = $db->prepare('
        UPDATE `sessions` SET `status` = :s, `responded_at` = NOW()
        WHERE `response_token` = :t AND `responded_at` IS NULL
          AND `status` = "pending" AND `start_time` > NOW()
    ');
    $stmt->execute([':s' => $accept ? 'confirmed' : 'rejected', ':t' => $token]);
    return $stmt->rowCount() === 1;
}
