<?php
session_start();
require '../config.php';

if (!isset($_SESSION["email"]) || $_SESSION["role"] !== "tenant") {
    header("Location: ../login");
    exit();
}

$userId = $_SESSION["user_id"];
$role = $_SESSION["role"];
$email = $_SESSION["email"];

// Track "soft-deleted" message IDs in session
if (!isset($_SESSION["deleted_messages"])) {
    $_SESSION["deleted_messages"] = [];
}

// Fetch broadcast messages
$broadcastSql = "
    SELECT 
        bm.id, bm.title, bm.message, bm.created_at,
        CASE WHEN mr.user_id IS NOT NULL THEN 'Read' ELSE 'Unread' END AS status,
        'broadcast' AS message_type
    FROM broadcastmessages bm
    LEFT JOIN messagereads mr ON bm.id = mr.message_id AND mr.user_id = ?
    WHERE bm.target_role = ? OR bm.target_role = 'All'
";

// Fetch direct messages
$directSql = "
    SELECT 
        dm.id, dm.title, dm.message, dm.created_at,
        CASE WHEN udr.id IS NOT NULL THEN 'Read' ELSE 'Unread' END AS status,
        'direct' AS message_type
    FROM directmessages dm
    LEFT JOIN users u ON u.id = ?
    LEFT JOIN direct_message_reads udr ON dm.id = udr.message_id AND udr.user_id = ?
    WHERE dm.recipient_email = ?
";

// Execute both queries
$stmt1 = $conn->prepare($broadcastSql);
$stmt1->bind_param("is", $userId, $role);
$stmt1->execute();
$result1 = $stmt1->get_result();
$broadcasts = $result1->fetch_all(MYSQLI_ASSOC);
$stmt1->close();

$stmt2 = $conn->prepare($directSql);
$stmt2->bind_param("iis", $userId, $userId, $email);
$stmt2->execute();
$result2 = $stmt2->get_result();
$directs = $result2->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// Merge and sort
$allMessages = array_merge($broadcasts, $directs);
$allMessages = array_filter($allMessages, function ($msg) {
    return !in_array($msg['id'], $_SESSION["deleted_messages"]);
});
usort($allMessages, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
?>

<!DOCTYPE html>
<html>

<head>
    <title>Notifications - Chuluni CDC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        body {
            background-color: rgb(101, 102, 102);
            font-family: 'Segoe UI', sans-serif;
            padding: 30px;
        }

        .card {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s ease;
        }

        .card:hover {
            transform: translateY(-3px);
        }

        .read-card {
            background: linear-gradient(135deg, rgb(233, 240, 237), #cce3dc);
        }

        .unread-card {
            background: linear-gradient(135deg, #fff3cd, #ffeeba);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .message-type-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            background-color: #000000ff;
            color: white;
            border-radius: 8px;
            margin-left: 10px;
        }

        .message-preview {
            max-height: 80px;
            overflow-y: auto;
        }

        .message-card .card-body {
            padding-top: 0.75rem;
            padding-bottom: 0.5rem;
            flex-grow: 1;
        }

        .message-card .card-footer {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        .read-more {
            color: #0d6efd;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .status-badge {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 20px;
        }

        .badge-read {
            background-color: #05521aff;
            color: white;
        }

        .badge-unread {
            background-color: #bd0b1dff;
            color: #ffffffff;
        }

        .delete-btn {
            background-color: #8B0000;
            /* Dark red */
            color: #ffffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }

        .delete-btn:hover {
            background-color: #a10000;
            /* Slightly lighter on hover */
        }

        .search-box {
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

    <div class="container">
        <button onclick="history.back()" class="btn btn-dark mb-3 px-4 py-2 fw-bold">
            ⬅️ Back to Dashboard
        </button>
        <h3 class="text-light mb-4">🔔 All Your Notifications (Broadcasts & Direct Messages)</h3>

        <!-- Search box -->
        <input type="text" id="searchInput" class="form-control search-box" placeholder="Search by title or date">

        <div class="row" id="messageContainer">
            <?php foreach ($allMessages as $index => $msg): ?>
                <div class="col-md-6 col-lg-4 mb-4 message-card" data-index="<?= $index ?>">
                    <div class="card <?= $msg['status'] === 'Read' ? 'read-card' : 'unread-card' ?> shadow-sm h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">
                                <?= htmlspecialchars($msg['title']) ?>
                                <span class="message-type-badge"><?= $msg['message_type'] === 'direct' ? 'Direct Message' : 'Broadcast Message' ?></span>
                            </h5>
                            <div class="card-text message-preview" id="msg_<?= $msg['id'] ?>">
                                <?= nl2br(htmlspecialchars(substr($msg['message'], 0, 180))) ?>
                                <?php if (strlen($msg['message']) > 180): ?>
                                    <span class="read-more" onclick="expandMessage(<?= $msg['id'] ?>)">Read more</span>
                                    <div class="d-none full-message mt-2"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <small class="bg-dark text-light px-2 py-1 rounded">
                                <?= date("M j, Y, g:i a", strtotime($msg['created_at'])) ?>
                            </small>
                            <div class="d-flex gap-2 align-items-center">
                                <span class="status-badge <?= $msg['status'] === 'Read' ? 'badge-read' : 'badge-unread' ?>">
                                    <?= $msg['status'] ?>
                                </span>
                                <?php if ($msg['status'] === 'Read'): ?>
                                    <button class="delete-btn btn btn-sm">Clear</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination controls -->
        <nav>
            <ul class="pagination justify-content-center" id="pagination"></ul>
        </nav>
    </div>

    <script>
        const messagesPerPage = 20;
        let currentPage = 1;

        function paginate() {
            const cards = document.querySelectorAll('.message-card');
            const totalPages = Math.ceil(cards.length / messagesPerPage);

            cards.forEach((card, i) => {
                card.style.display = (i >= (currentPage - 1) * messagesPerPage && i < currentPage * messagesPerPage) ? 'block' : 'none';
            });

            const pagination = document.getElementById("pagination");
            pagination.innerHTML = "";

            for (let i = 1; i <= totalPages; i++) {
                const li = document.createElement("li");
                li.className = "page-item" + (i === currentPage ? " active" : "");
                li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                li.onclick = () => {
                    currentPage = i;
                    paginate();
                };
                pagination.appendChild(li);
            }
        }

        function expandMessage(id) {
            const container = document.getElementById("msg_" + id);
            const full = container.querySelector(".full-message");
            if (full) {
                full.classList.remove("d-none");
                container.querySelector(".read-more").remove();
            }
        }

        function deleteCard(index) {
            const card = document.querySelector(`.message-card[data-index='${index}']`);
            if (card) card.remove();
            paginate();
        }

        document.getElementById("searchInput").addEventListener("keyup", function() {
            const filter = this.value.toLowerCase();
            document.querySelectorAll(".message-card").forEach(card => {
                const title = card.querySelector(".card-title").innerText.toLowerCase();
                const date = card.querySelector("small").innerText.toLowerCase();
                card.style.display = (title.includes(filter) || date.includes(filter)) ? "block" : "none";
            });
        });

        window.onload = paginate;
    </script>

</body>

</html>