<?php
session_start();
require '../config.php';

// ---------------------------
// Role & Login Checks
// ---------------------------
if (!isset($_SESSION['id'], $_SESSION['role']) || strtolower($_SESSION['role']) !== 'service provider') {
    header("Location: ../login.php");
    exit();
}

$providerId = $_SESSION['id'];

// ---------------------------
// Delete message (only own messages)
// ---------------------------
if (isset($_GET['delete_msg'])) {
    $msgId = intval($_GET['delete_msg']);
    $stmt = $conn->prepare("DELETE FROM service_messages WHERE id = ? AND sender_id = ?");
    $stmt->bind_param("ii", $msgId, $providerId);
    $stmt->execute();
    $stmt->close();
    header("Location: providerMessages.php" . (isset($_GET['user']) ? "?user=" . intval($_GET['user']) : ""));
    exit();
}

// ---------------------------
// Send message
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['user_id'])) {
    $msg = trim($_POST['message']);
    $userId = intval($_POST['user_id']);
    if (!empty($msg)) {
        $stmt = $conn->prepare("INSERT INTO service_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $providerId, $userId, $msg);
        $stmt->execute();
        $stmt->close();
    }
    // PRG to prevent resubmission
    header("Location: providerMessages.php?user=" . $userId);
    exit();
}

// ---------------------------
// Get all users who messaged provider
// ---------------------------
$users = [];
$result = $conn->query("
    SELECT u.id, u.full_name, COUNT(m.id) AS unread_count
    FROM service_messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = $providerId
    GROUP BY u.id
    ORDER BY MAX(m.created_at) DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// ---------------------------
// Get conversation if user selected
// ---------------------------
$conversation = [];
$chatUser = null;
if (isset($_GET['user'])) {
    $chatUser = intval($_GET['user']);
    $stmt = $conn->prepare("
        SELECT m.*, s.full_name AS sender_name, r.full_name AS receiver_name
        FROM service_messages m
        JOIN users s ON m.sender_id = s.id
        JOIN users r ON m.receiver_id = r.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("iiii", $chatUser, $providerId, $providerId, $chatUser);
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Mark user's messages as read
    $conn->query("UPDATE service_messages SET is_read = 1 WHERE sender_id = $chatUser AND receiver_id = $providerId");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Provider Messages</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: "Times New Roman", serif;
            background: #f5f5f5;
            margin: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .navbar {
            background: #000 !important;
            color: #fff;
        }

        .container-flex {
            display: flex;
            max-width: 1200px;
            margin: 20px auto;
            gap: 20px;
        }

        .users-list {
            width: 250px;
            background: #fff;
            border-radius: 8px;
            padding: 10px;
            max-height: 600px;
            overflow-y: auto;
        }

        .users-list .user-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .users-list .user-item:hover {
            background: #f5f5f5;
        }

        .badge-unread {
            background: red;
            color: #fff;
            font-size: 0.7rem;
            border-radius: 50%;
            padding: 3px 6px;
        }

        .chat-box {
            flex-grow: 1;
            background: #fff;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            max-height: 600px;
        }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            padding: 15px;
        }

        .message {
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 15px;
            max-width: 70%;
            word-wrap: break-word;
            font-size: 0.9rem;
            position: relative;
        }

        .message.provider {
            background: #d1e7dd;
            align-self: flex-end;
        }

        .message.user {
            background: #f8d7da;
            align-self: flex-start;
        }

        .delete-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 0.75rem;
            color: red;
            cursor: pointer;
        }

        .chat-input {
            display: flex;
            gap: 10px;
            padding: 10px 15px;
            border-top: 1px solid #ccc;
            align-items: center;
        }

        .chat-input textarea {
            flex-grow: 1;
            resize: none;
            border-radius: 5px;
            padding: 10px;
            font-size: 1rem;
            height: 50px;
            /* standard height */
            line-height: 1.4;
        }

        .chat-input button {
            border-radius: 5px;
            padding: 10px 16px;
            /* standard button size */
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .container-flex {
                flex-direction: column;
            }

            .users-list {
                width: 100%;
                max-height: 200px;
                display: flex;
                overflow-x: auto;
            }

            .users-list .user-item {
                flex: 0 0 auto;
                margin-right: 10px;
            }
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark px-3 py-3">
        <div class="d-flex justify-content-between align-items-center w-100">
            <span class="text-white fw-bold">Provider Messages</span>
            <a href="serviceProvider" class="btn btn-success btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
        </div>
    </nav>

    <div class="container-flex">
        <!-- Users List -->
        <div class="users-list">
            <?php if (!empty($users)): ?>
                <?php foreach ($users as $u): ?>
                    <a href="?user=<?= $u['id'] ?>" class="text-decoration-none text-dark">
                        <div class="user-item">
                            <span><?= htmlspecialchars($u['full_name']) ?></span>
                            <?php if ($u['unread_count'] > 0): ?>
                                <span class="badge-unread"><?= $u['unread_count'] ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted p-2">No messages yet.</div>
            <?php endif; ?>
        </div>

        <!-- Chat Box -->
        <div class="chat-box">
            <?php if ($chatUser): ?>
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($conversation as $msg):
                        $cls = ($msg['sender_id'] == $providerId) ? 'provider' : 'user';
                    ?>
                        <div class="message <?= $cls ?>">
                            <strong><?= htmlspecialchars($msg['sender_name']) ?>:</strong> <?= htmlspecialchars($msg['message']) ?>
                            <br><small class="text-muted"><?= date("M d, H:i", strtotime($msg['created_at'])) ?></small>
                            <?php if ($cls === 'provider'): ?>
                                <a href="?user=<?= $chatUser ?>&delete_msg=<?= $msg['id'] ?>" class="delete-btn" title="Delete">×</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form class="chat-input" method="POST">
                    <textarea name="message" placeholder="Type your message..." required></textarea>
                    <input type="hidden" name="user_id" value="<?= $chatUser ?>">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send</button>
                </form>
            <?php else: ?>
                <div class="flex-grow-1 d-flex justify-content-center align-items-center text-muted">
                    Select a user to start conversation
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;
    </script>

</body>

</html>