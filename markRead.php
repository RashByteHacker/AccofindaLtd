<?php
session_start();
require '../config.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login");
    exit();
}

$userId = $_SESSION["user_id"];
$messageId = intval($_POST["messageId"] ?? 0);

if ($messageId > 0) {
    $stmt = $conn->prepare("INSERT IGNORE INTO messagereads (user_id, message_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $userId, $messageId);
    $stmt->execute();
    $stmt->close();
}

// Redirect back to the dashboard after marking as read
header("Location: ../Tenants/tenant");
exit();
