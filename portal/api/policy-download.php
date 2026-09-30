<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/policies.php';
requireClient(true);

$name = basename((string) ($_GET['f'] ?? ''));
if (!isset(portalPolicies()[$name])) {
    http_response_code(404);
    exit('Not found.');
}
$path = dirname(__DIR__, 2) . '/storage/policies/' . $name;
header('Content-Type: ' . PORTAL_POLICY_EXT[strtolower(pathinfo($name, PATHINFO_EXTENSION))]);
header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $name) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
