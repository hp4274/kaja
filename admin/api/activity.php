<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db-config.php';
$db = getDbConnection();

try {
    $stmt = $db->query("SELECT * FROM `activity_log` ORDER BY `created_at` DESC LIMIT 20");
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'activities' => $activities]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => publicError($e)]);
}
