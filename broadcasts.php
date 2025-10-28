<?php
session_start();
require '../config.php';
date_default_timezone_set('Africa/Nairobi');

// Allow only ProjectDirector and Admin
if (!isset($_SESSION["email"]) || !in_array($_SESSION["role"], ["admin", "manager", "landlord"])) {
    header("Location: ../login");
    exit();
}

$success = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

// Handle Broadcast Deletion
if (isset($_GET['delete'])) {
    $idToDelete = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM broadcastmessages WHERE id = ?");
    $stmt->bind_param("i", $idToDelete);
    $stmt->execute();
    $stmt->close();
    header("Location: broadcasts?success=" . urlencode("🗑️ Broadcast message deleted successfully."));
    exit();
}

// Handle Direct Message Deletion
if (isset($_GET['delete_direct'])) {
    $idToDelete = (int) $_GET['delete_direct'];
    $stmt = $conn->prepare("DELETE FROM directmessages WHERE id = ?");
    $stmt->bind_param("i", $idToDelete);
    $stmt->execute();
    $stmt->close();
    header("Location: broadcasts?success=" . urlencode("🗑️ Direct message deleted successfully."));
    exit();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $body        = trim($_POST['message'] ?? '');
    $messageType = trim($_POST['message_type'] ?? '');

    if ($messageType === 'broadcast') {
        $target = trim($_POST['target_role'] ?? '');
        if ($title === '' || $body === '' || $target === '') {
            header("Location: broadcasts?error=" . urlencode("❌ Please fill in all broadcast fields."));
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO broadcastmessages (title, message, target_role, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $title, $body, $target);
        $stmt->execute();
        $stmt->close();
        header("Location: broadcasts?success=" . urlencode("✅ Message broadcasted to '$target' successfully!"));
        exit();
    } elseif ($messageType === 'direct') {
        $recipient = trim($_POST['tenant_email'] ?? '');
        if ($title === '' || $body === '' || $recipient === '') {
            header("Location: broadcasts?error=" . urlencode("❌ Please fill in all direct message fields."));
            exit();
        }

        // Optional: Validate email format
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            header("Location: broadcasts?error=" . urlencode("❌ Invalid recipient email address."));
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO directmessages (recipient_email, title, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $recipient, $title, $body);
        $stmt->execute();
        $stmt->close();
        header("Location: broadcasts?success=" . urlencode("✅ Direct message sent successfully to Tenant."));
        exit();
    } else {
        header("Location: broadcasts?error=" . urlencode("❌ Invalid message type selected."));
        exit();
    }
}

// Fetch broadcast messages
$messages = [];
$result = $conn->query("SELECT id, title, message, target_role, created_at FROM broadcastmessages ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
}

// Fetch direct messages
$directMessages = [];
$query = "
    SELECT d.id, d.recipient_email, d.title, d.message, d.created_at, 
           u.phone_number AS phoneNo, u.full_name
    FROM directmessages d
    LEFT JOIN users u 
        ON d.recipient_email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
    ORDER BY d.created_at DESC
";
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $directMessages[] = $row;
    }
}

// Fetch tenants
$tenants = [];
$res = $conn->query("SELECT full_name, email, phone_number FROM users WHERE role = 'tenant' ORDER BY full_name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $tenants[] = $row;
    }
}

// Search direct messages
$searchTerm = trim($_GET['search_direct'] ?? '');
if ($searchTerm !== '') {
    $searchTermWildcard = '%' . $searchTerm . '%';
    $sql = "SELECT dm.*, u.full_name, u.phone_number 
            FROM directmessages dm
            LEFT JOIN users u 
                ON dm.recipient_email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
            WHERE u.full_name LIKE ? OR u.phone_number LIKE ?
            ORDER BY dm.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $searchTermWildcard, $searchTermWildcard);
} else {
    $sql = "SELECT dm.*, u.full_name, u.phone_number
            FROM directmessages dm
            LEFT JOIN users u 
                ON dm.recipient_email COLLATE utf8mb4_unicode_ci = u.email COLLATE utf8mb4_unicode_ci
            ORDER BY dm.created_at DESC";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();
$directMessages = $result->fetch_all(MYSQLI_ASSOC);

// Pre-fill from GET
$prefilled_title   = $_GET['title'] ?? '';
$prefilled_message = $_GET['message'] ?? '';

?>

<!DOCTYPE html>
<html>

<head>
    <title>Broadcast Message</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        body {
            background: #84888eff;
            font-family: 'Segoe UI', sans-serif;
        }

        .container {
            max-width: 900px;
            margin-top: 50px;
        }

        .card {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 30px;
        }

        .btn-group {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        table td {
            vertical-align: middle !important;
        }

        footer {
            text-align: center;
            padding: 15px;
            font-size: 13px;
            color: #fff;
            background-color: rgb(0, 0, 0);
        }
    </style>
</head>

<body>
    <!-- Top Navbar (fixed) -->
    <nav class="navbar navbar-dark bg-dark fixed-top py-3">
        <div class="container-fluid">
            <button type="button" class="btn btn-outline-light me-3" onclick="history.back()" aria-label="Go back">
                <i class="fa fa-arrow-left"></i> Back
            </button>
            <span class="navbar-brand mx-auto fs-4 fw-bold text-light text-center" style="position: absolute; left: 50%; transform: translateX(-50%);">
                📣Broadcast Centre
            </span>
        </div>
    </nav>
    <div class="container" style="padding-top: 120px; padding-bottom: 120px;">
        <div class="card p-4 shadow-sm" style="border: 1px solid #444;">
            <h3 class=" mb-4 text-primary">📣 Send a Message</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <div class="btn-group">
                    <a href="broadcasts" class="btn btn-warning">📨 Send Another Message</a>
                    <a href="javascript:history.back()" class="btn btn-secondary">🏠 Back to Dashboard</a>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$success): ?>
                <form method="POST">
                    <!-- 🔄 Message Type Selection -->
                    <div class="mb-3">
                        <label class="form-label">📝 Message Type</label>
                        <select name="message_type" id="messageType" class="form-select" onchange="toggleMessageType()" required>
                            <option value="">-- Select Message Type --</option>
                            <option value="broadcast">📢 Broadcast Message</option>
                            <option value="direct">📬 Direct Message</option>
                        </select>
                    </div>

                    <!-- 🎯 Target Role (Only for Broadcast) -->
                    <div id="broadcastTarget" class="mb-3" style="display:none;">
                        <label class="form-label">🎯 Target Role</label>
                        <select name="target_role" class="form-select">
                            <option value="">-- Select Role --</option>
                            <option value="All">🌍 All Users</option>
                            <option value="tenant">🎓 Tenants</option>
                            <option value="landlord">🤝 Landlord</option>
                            <option value="manager">👥 Managers</option>
                            <option value="admin">🛡️ Admins</option>
                        </select>
                    </div>

                    <!-- 👤 Direct Recipient (Only for Direct) -->
                    <div id="directTarget" class="mb-3" style="display:none;">
                        <label class="form-label">🔍 Search Tenant</label>
                        <input type="text" id="tenantSearch" class="form-control mb-2" placeholder="Search by PhoneNo or Name" autocomplete="off" />

                        <ul id="tenantSuggestions" class="list-group" style="max-height:150px; overflow-y:auto; display:none; cursor:pointer;"></ul>
                        <input type="hidden" name="tenant_email" id="selectedTenantEmail">
                    </div>

                    <!-- 📌 Message Title -->
                    <div class="mb-3">
                        <label class="form-label">📌 Message Title</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($prefilled_title) ?>" required>
                    </div>

                    <!-- ✉️ Message Body -->
                    <div class="mb-3">
                        <label class="form-label">✉️ Message Body</label>
                        <textarea name="message" class="form-control" rows="5" required><?= htmlspecialchars($prefilled_message) ?></textarea>
                    </div>

                    <button class="btn btn-primary">🚀 Send</button>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary ms-2">⬅️ Cancel</a>
                </form>
            <?php endif; ?>
        </div>

        <!-- Message History -->
        <div class="card mt-4 shadow-sm" style="border: 1px solid #444;">

            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">📜 Previous Broadcasts</h5>
                <input type="text" id="broadcastSearch" class="form-control form-control-sm w-50" placeholder="🔍 Search broadcasts...">
            </div>

            <div class="card-body p-3">
                <?php if (empty($messages)): ?>
                    <p class="text-muted">No messages broadcasted yet.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                        <table class="table table-sm table-bordered align-middle mb-0" id="broadcastTable">
                            <thead class="table-light text-center small sticky-top">
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Message Preview</th>
                                    <th>Target Role</th>
                                    <th>Sent</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php foreach ($messages as $index => $msg): ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($msg['title']) ?></td>
                                        <td>
                                            <?php
                                            $words = explode(' ', strip_tags($msg['message']));
                                            $preview = implode(' ', array_slice($words, 0, 5));
                                            echo htmlspecialchars($preview) . (count($words) > 5 ? '' : '');
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($msg['target_role']) ?></td>
                                        <td><?= date("M j, Y g:i A", strtotime($msg['created_at'])) ?></td>
                                        <td class="text-center">
                                            <a href="?delete=<?= $msg['id'] ?>"
                                                class="btn btn-sm"
                                                style="background-color: red; color: white; font-size: 0.8rem;"
                                                onclick="return confirm('Are you sure you want to delete this message?');">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Previous Direct Messages -->
        <div class="card mt-4 shadow-sm" style="border: 1px solid #444;">

            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">📥 Previous Direct Messages</h5>
                <!-- Search inside header -->
                <input type="text" id="directSearch"
                    class="form-control form-control-sm ms-3"
                    placeholder="Search PhoneNo or Name"
                    style="max-width: 250px;">
            </div>

            <div class="card-body p-3">
                <?php if (empty($directMessages)): ?>
                    <p class="text-muted">No direct messages sent yet.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                        <table id="directTable" class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light text-center small">
                                <tr>
                                    <th>#</th>
                                    <th>PhoneNo</th>
                                    <th>Name</th>
                                    <th>Title</th>
                                    <th>Message</th>
                                    <th>Sent</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php foreach ($directMessages as $index => $dm): ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($dm['phone_number']) ?></td>
                                        <td><?= htmlspecialchars($dm['full_name']) ?></td>
                                        <td><?= htmlspecialchars($dm['title']) ?></td>
                                        <td>
                                            <div style="max-height: 60px; overflow-y: auto;">
                                                <?php
                                                $words = explode(' ', strip_tags($dm['message']));
                                                $preview = implode(' ', array_slice($words, 0, 5));
                                                echo htmlspecialchars($preview) . (count($words) > 5 ? '' : '');
                                                ?>
                                            </div>
                                        </td>
                                        <td><?= date("M j, Y g:i A", strtotime($dm['created_at'])) ?></td>
                                        <td class="text-center">
                                            <a href="?delete_direct=<?= $dm['id'] ?>"
                                                class="btn btn-sm btn-danger"
                                                style="font-size: 0.8rem; color: white;"
                                                onclick="return confirm('Are you sure you want to delete this message?');">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Live Search Script -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById('broadcastSearch');
            const table = document.getElementById('broadcastTable');

            if (searchInput && table) {
                searchInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase();
                    const rows = table.querySelectorAll('tbody tr');

                    rows.forEach(row => {
                        const text = row.innerText.toLowerCase();
                        row.style.display = text.includes(query) ? '' : 'none';
                    });
                });
            }
        });
    </script>

    <script>
        const tenants = <?= json_encode($tenants) ?>;

        function toggleMessageType() {
            const type = document.getElementById("messageType").value;
            document.getElementById("broadcastTarget").style.display = type === "broadcast" ? "block" : "none";
            document.getElementById("directTarget").style.display = type === "direct" ? "block" : "none";
        }

        const input = document.getElementById("tenantSearch");
        const suggestionBox = document.getElementById("tenantSuggestions");
        const selectedEmail = document.getElementById("selectedTenantEmail");

        input.addEventListener("input", function() {
            const value = input.value.toLowerCase();
            suggestionBox.innerHTML = "";
            if (value === "") {
                suggestionBox.style.display = "none";
                return;
            }

            const matches = tenants.filter(t =>
                t.full_name.toLowerCase().includes(value) ||
                t.phone_number.toLowerCase().includes(value)
            ).slice(0, 5); // show top 5 results

            matches.forEach(t => {
                const li = document.createElement("li");
                li.className = "list-group-item list-group-item-action";
                li.textContent = `${t.full_name} (${t.phone_number})`;
                li.dataset.email = t.email;
                li.addEventListener("click", () => {
                    input.value = `${t.full_name} (${t.phone_number})`;
                    selectedEmail.value = t.email; // ✅ set hidden email field
                    suggestionBox.style.display = "none";
                });
                suggestionBox.appendChild(li);
            });

            suggestionBox.style.display = matches.length > 0 ? "block" : "none";
        });

        // Hide suggestion list when clicking outside
        document.addEventListener("click", function(event) {
            if (!input.contains(event.target) && !suggestionBox.contains(event.target)) {
                suggestionBox.style.display = "none";
            }
        });
    </script>
    <!-- 🔎 Auto Search Script -->
    <script>
        document.getElementById('directSearch').addEventListener('keyup', function() {
            let input = this.value.toLowerCase();
            let rows = document.querySelectorAll('#directTable tbody tr');
            rows.forEach(row => {
                let phone = row.cells[1].textContent.toLowerCase();
                let name = row.cells[2].textContent.toLowerCase();
                if (phone.includes(input) || name.includes(input)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-3 mt-0" style="padding-top:10px;">
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved.
    </footer>
</body>

</html>