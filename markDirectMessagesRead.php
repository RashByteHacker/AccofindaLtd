<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// Mark all unread direct messages as read by inserting into direct_message_reads
// Only insert if not exists (use INSERT IGNORE or equivalent)

$sql = "
    INSERT IGNORE INTO direct_message_reads (message_id, user_id)
    SELECT dm.id, ? FROM directmessages dm
    LEFT JOIN direct_message_reads dmr ON dm.id = dmr.message_id AND dmr.user_id = ?
    WHERE dm.recipient_email = ? AND dmr.user_id IS NULL
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iis', $userId, $userId, $_SESSION['email']);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $success]);
