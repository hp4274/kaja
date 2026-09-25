<?php
/**
 * Public API: Admin Staff Authentication (POST)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once dirname(__DIR__) . '/db-config.php';

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter both username and password.']);
    exit;
}

$db = getDbConnection();

try {
    // Check by username or email
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :usr OR email = :email LIMIT 1");
    $stmt->execute([
        ':usr' => $username,
        ':email' => $username
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Password is correct, set session
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];

        echo json_encode(['success' => true, 'message' => 'Logged in successfully.']);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid username/email or password.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
