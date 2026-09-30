<?php
/**
 * Client portal auth. Keys are namespaced (client_*) and never touch the
 * admin's $_SESSION['logged_in'].
 */

require_once dirname(__DIR__, 2) . '/db-config.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

const PORTAL_IDLE_SECONDS = 7200;

function portalNoIndex() {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
}

function portalDeny($json) {
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Session expired. Please sign in again.']);
    } else {
        header('Location: ../customer-login.html');
    }
    exit;
}

/**
 * Start the session, enforce the idle timeout and return the client row.
 * $json = true for portal/api/* (401 JSON instead of a redirect).
 */
function requireClient($json = false) {
    portalNoIndex();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $id = (int) ($_SESSION['client_id'] ?? 0);
    if ($id < 1 || time() - (int) ($_SESSION['client_last_seen'] ?? 0) > PORTAL_IDLE_SECONDS) {
        unset($_SESSION['client_id'], $_SESSION['client_last_seen'], $_SESSION['client_csrf'], $_SESSION['client_sv']);
        portalDeny($json);
    }
    $stmt = getDbConnection()->prepare(
        'SELECT * FROM `clients` WHERE `id` = :id AND `archived_at` IS NULL AND `merged_into_id` IS NULL');
    $stmt->execute([':id' => $id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    // "Sign out of all devices" bumps session_version; a session adopts the
    // current value on first sight and is denied once it no longer matches.
    if ($client && !isset($_SESSION['client_sv'])) {
        $_SESSION['client_sv'] = (int) $client['session_version'];
    }
    if ($client && $_SESSION['client_sv'] !== (int) $client['session_version']) {
        $client = false;
    }
    if (!$client) {
        unset($_SESSION['client_id'], $_SESSION['client_last_seen'], $_SESSION['client_csrf'], $_SESSION['client_sv']);
        portalDeny($json);
    }
    $_SESSION['client_last_seen'] = time();
    return $client;
}

function portalCsrfToken() {
    if (empty($_SESSION['client_csrf'])) {
        $_SESSION['client_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['client_csrf'];
}

/** Accepts POST field `csrf` or header X-CSRF-Token. */
function portalCheckCsrf() {
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && !empty($_SESSION['client_csrf']) && hash_equals($_SESSION['client_csrf'], $sent);
}

/** For API endpoints: 403 JSON and exit unless the CSRF token is valid. */
function portalRequireCsrf() {
    if (!portalCheckCsrf()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid request token. Reload the page.']);
        exit;
    }
}

function e($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
