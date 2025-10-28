<?php
session_start();
require '../config.php';

// ---------------------------
// Role & Login Checks
// ---------------------------
if (!isset($_SESSION['id'], $_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$currentUserId = $_SESSION['id'];
$role = $_SESSION['role'];
$adminView = false;

// ---------------------------
// Determine conversation
// ---------------------------
$providerId = intval($_GET['provider_id'] ?? 0);

if ($role === 'admin' && isset($_GET['conversation'])) {
    $ids = explode("_", $_GET['conversation']); // format: userId_providerId
    $currentUserId = intval($ids[0]);
    $providerId = intval($ids[1]);
    $adminView = true;
}

// ---------------------------
// Handle sending a message
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message']) && isset($_POST['provider_id'])) {
    $sendToId = intval($_POST['provider_id']);
    $msg = trim($_POST['message']);

    if ($sendToId && $msg !== '') {
        $stmt = $conn->prepare("INSERT INTO service_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $currentUserId, $sendToId, $msg);
        $stmt->execute();
        $stmt->close();
    }

    // PRG to prevent form resubmission
    header("Location: serviceChats?provider_id=" . $sendToId);
    exit();
}

// ---------------------------
// Fetch provider list
// ---------------------------
$providersList = [];
if ($role !== 'admin') {
    $sql = "SELECT u.id, u.full_name, COUNT(m.id) AS unread
            FROM users u
            LEFT JOIN service_messages m 
                ON m.sender_id = u.id 
               AND m.receiver_id = ? 
               AND m.is_read = 0
            WHERE u.role = 'service provider'
            GROUP BY u.id
            ORDER BY u.full_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $providersList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---------------------------
// Fetch messages for conversation
// ---------------------------
$messages = [];
if ($providerId) {
    $stmt = $conn->prepare("
        SELECT m.*, s.full_name AS sender_name, r.full_name AS receiver_name
        FROM service_messages m
        JOIN users s ON m.sender_id = s.id
        JOIN users r ON m.receiver_id = r.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("iiii", $currentUserId, $providerId, $providerId, $currentUserId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Mark messages as read immediately
    if (!$adminView) {
        $stmt = $conn->prepare("UPDATE service_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $stmt->bind_param("ii", $providerId, $currentUserId);
        $stmt->execute();
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Service Chats</title>
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
            background: #000;
            color: #fff;
        }

        .container-flex {
            display: flex;
            min-width: 820px;
            margin: 20px auto;
            gap: 20px;
        }

        .chat-sidebar {
            width: 250px;
            background: #fff;
            border-radius: 8px;
            padding: 10px;
            max-height: 600px;
            overflow-y: auto;
        }

        .chat-sidebar a {
            display: flex;
            justify-content: space-between;
            padding: 8px;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 5px;
            color: #000;
        }

        .chat-sidebar a.active {
            background: #0d6efd;
            color: #fff;
        }

        .chat-box-container {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 8px;
            max-height: 600px;
        }

        .chat-box {
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

        .message.user {
            background: #d1e7dd;
            align-self: flex-end;
        }

        .message.provider {
            background: #f8d7da;
            align-self: flex-start;
        }

        .message.admin {
            background: #e2e3e5;
            align-self: center;
            text-align: center;
            font-style: italic;
        }

        .chat-input {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            padding: 10px;
            border-top: 1px solid #ccc;
        }

        .chat-input textarea {
            flex-grow: 1;
            resize: none;
            border-radius: 5px;
            padding: 8px;
        }

        .chat-input button {
            border-radius: 5px;
        }

        .delete-btn {
            position: absolute;
            top: 2px;
            right: 5px;
            color: red;
            font-weight: bold;
            text-decoration: none;
        }

        /* MOBILE ADJUSTMENTS */
        @media (max-width: 768px) {
            .container-flex {
                flex-direction: column;
                padding: 10px;
            }

            .chat-sidebar {
                width: 100%;
                max-height: 80px;
                display: flex;
                overflow-x: auto;
                overflow-y: hidden;
                gap: 5px;
                padding: 5px;
                border-radius: 8px;
                margin-bottom: 10px;
            }

            .chat-sidebar a {
                flex: 0 0 auto;
                margin-bottom: 0;
                padding: 8px 12px;
            }

            .chat-box-container {
                width: 100%;
            }

            .chat-box {
                max-height: 50vh;
            }

            .chat-input {
                margin-top: 5px;
            }
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <a href="services" class="btn btn-success btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
            <span><?= $adminView ? "Admin View: Conversation" : "Chat with Provider" ?></span>
            <div style="width:60px;"></div>
        </div>
    </nav>

    <div class="container-flex">

        <!-- Sidebar -->
        <?php if (!$adminView): ?>
            <div class="chat-sidebar">
                <h6>Chats</h6>
                <?php if (!empty($providersList)): ?>
                    <?php foreach ($providersList as $prov): ?>
                        <a href="?provider_id=<?= $prov['id'] ?>" class="<?= ($prov['id'] == $providerId) ? 'active' : '' ?>">
                            <?= htmlspecialchars($prov['full_name']) ?>
                            <?php if ($prov['unread'] > 0): ?>
                                <span class="badge bg-danger"><?= $prov['unread'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">No providers to chat with yet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Chat Box -->
        <div class="chat-box-container">

            <div class="chat-box" id="chatBox">
                <?php if ($providerId && !empty($messages)): ?>
                    <?php foreach ($messages as $msg):
                        $cls = $adminView ? 'admin' : (($msg['sender_id'] == $_SESSION['id']) ? 'user' : 'provider');
                    ?>
                        <div class="message <?= $cls ?>">
                            <strong><?= htmlspecialchars($msg['sender_name']) ?>:</strong> <?= htmlspecialchars($msg['message']) ?>
                            <br><small class="text-muted"><?= date("M d, H:i", strtotime($msg['created_at'])) ?></small>
                            <?php if (!$adminView && $cls === 'user'): ?>
                                <a href="?provider_id=<?= $providerId ?>&delete_msg=<?= $msg['id'] ?>" class="delete-btn" title="Delete">×</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($providerId): ?>
                    <p class="text-muted text-center">No messages yet. Start the conversation below.</p>
                <?php else: ?>
                    <p class="text-muted text-center">Select a provider from the sidebar to start chatting.</p>
                <?php endif; ?>
            </div>

            <?php if (!$adminView && $providerId): ?>
                <form class="chat-input" method="POST">
                    <textarea name="message" rows="1" placeholder="Type your message..." required></textarea>
                    <input type="hidden" name="provider_id" value="<?= $providerId ?>">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send</button>
                </form>
            <?php elseif ($adminView): ?>
                <div class="text-center mt-2 text-muted">Admin view: Read-only conversation log</div>
            <?php endif; ?>

        </div>

    </div>

    <script>
        const chatBox = document.getElementById('chatBox');
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
    </script>
</body>

</html>