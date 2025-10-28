<?php
// Shared/markNotificationRead.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// adjust path if your config file lives elsewhere
require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id']);
    exit;
}

if (!isset($_SESSION['id']) || !$_SESSION['id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
    exit;
}

$notificationId = intval($_POST['id']);
$userId = intval($_SESSION['id']);

if ($notificationId <= 0 || $userId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
    exit;
}

// make sure $conn exists
if (!isset($conn)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection not found']);
    exit;
}

// Insert or update read record
$stmt = $conn->prepare("
    INSERT INTO notification_reads (notification_id, user_id, read_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE read_at = NOW()
");
if (!$stmt) {
    error_log("markNotificationRead prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("ii", $notificationId, $userId);

if ($stmt->execute()) {
    echo json_encode(['status' => 'ok']);
    exit;
} else {
    error_log("markNotificationRead execute failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $stmt->error]);
    exit;
}
