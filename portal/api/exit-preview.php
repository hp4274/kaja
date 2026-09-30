<?php
/**
 * Portal API: Exit Admin Preview Mode.
 *
 * Clears client session keys while keeping admin logged_in session intact,
 * and redirects back to the Admin Dashboard.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$clientId = (int) ($_SESSION['admin_impersonating']['client_id'] ?? $_SESSION['client_id'] ?? 0);

// Clear portal-specific session keys
unset(
    $_SESSION['client_id'],
    $_SESSION['client_last_seen'],
    $_SESSION['client_csrf'],
    $_SESSION['client_sv'],
    $_SESSION['admin_impersonating']
);

$target = $clientId > 0
    ? '../../admin/index.php?page=client-profile&id=' . $clientId
    : '../../admin/index.php?page=clients';

header('Location: ' . $target);
exit;
