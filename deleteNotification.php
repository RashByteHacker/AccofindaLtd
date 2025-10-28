<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);

    // Delete related reads first
    $conn->query("DELETE FROM notification_reads WHERE notification_id = $id");

    // Delete from notifications
    $stmt = $conn->prepare("DELETE FROM system_notifications WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Invalid request"]);
