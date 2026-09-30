<?php
require_once dirname(__DIR__) . '/includes/auth.php';
requireClient(true);
portalRequireCsrf();
session_destroy();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'redirect' => '../../customer-login.html']);
