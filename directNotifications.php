<?php
session_start();
require '../config.php';

// ✅ Allow only admin/manager
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager', 'landlord'])) {
    header("Location: ../login.php");
    exit();
}

$response = null;

// --- Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_type = $_POST['target_type'];
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);

    if (empty($title) || empty($message)) {
        $_SESSION['flash'] = ["type" => "error", "msg" => "Title and message are required!"];
    } elseif ($target_type === 'user') {
        $userId = intval($_POST['user_id']);
        if ($userId) {
            $stmt = $conn->prepare("INSERT INTO system_notifications (user_id, title, message) VALUES (?,?,?)");
            $stmt->bind_param("iss", $userId, $title, $message);
            $stmt->execute();
            $_SESSION['flash'] = ["type" => "success", "msg" => "Notification sent to user ✅"];
        } else {
            $_SESSION['flash'] = ["type" => "error", "msg" => "Please select a user!"];
        }
    } elseif ($target_type === 'role') {
        $role = trim($_POST['role']);
        if ($role) {
            $stmt = $conn->prepare("INSERT INTO system_notifications (role, title, message) VALUES (?,?,?)");
            $stmt->bind_param("sss", $role, $title, $message);
            $stmt->execute();
            $_SESSION['flash'] = ["type" => "success", "msg" => "Notification sent to all $role(s) ✅"];
        } else {
            $_SESSION['flash'] = ["type" => "error", "msg" => "Please select a role!"];
        }
    } else {
        $_SESSION['flash'] = ["type" => "error", "msg" => "Invalid target type!"];
    }

    // ✅ Redirect to avoid resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- Fetch users for dropdown
$users = $conn->query("SELECT id, full_name, role FROM users ORDER BY full_name ASC");
$roles = ['landlord', 'tenant', 'admin', 'manager', 'service provider'];
// Pagination setup
$limit = 10; // notifications per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Fetch total number of notifications
$totalResult = $conn->query("SELECT COUNT(*) AS total FROM system_notifications");
$totalRow = $totalResult->fetch_assoc();
$totalNotifications = $totalRow['total'];
$totalPages = ceil($totalNotifications / $limit);

// Fetch notifications for current page
$sent = $conn->query("
    SELECT sn.id, sn.title, sn.message, sn.role, sn.user_id, sn.created_at, u.full_name
    FROM system_notifications sn
    LEFT JOIN users u ON sn.user_id = u.id
    ORDER BY sn.created_at DESC
    LIMIT $limit OFFSET $offset
");
// Pagination setup
$limit = 10; // notifications per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Total notifications
$totalResult = $conn->query("SELECT COUNT(*) AS total FROM system_notifications");
$totalRow = $totalResult->fetch_assoc();
$totalNotifications = $totalRow['total'];
$totalPages = ceil($totalNotifications / $limit);

// Fetch notifications for current page
$sent = $conn->query("
    SELECT sn.id, sn.title, sn.message, sn.role, sn.user_id, sn.created_at, u.full_name
    FROM system_notifications sn
    LEFT JOIN users u ON sn.user_id = u.id
    ORDER BY sn.created_at DESC
    LIMIT $limit OFFSET $offset
");


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Send Notification</title>
    <meta charset="UTF-8">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f9;
        }

        /* --- Top Navigation --- */
        .navbar {
            background: #000000ff;
            color: #fff;
            padding: 14px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        .navbar h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .navbar a {
            color: #fff;
            margin-left: 20px;
            text-decoration: none;
            font-size: 14px;
        }

        .navbar a:hover {
            text-decoration: underline;
        }

        /* --- Form Section --- */
        .form-card {
            max-width: 550px;
            margin: 40px auto;
            background: #c7c7c7ff;
            /* softer than pure white */
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
            border-top: 5px solid #095f25ff;
            /* accent border */
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #0d6efd;
            font-size: 20px;
        }

        label {
            font-weight: 600;
            font-size: 14px;
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            color: #333;
        }

        label .icon {
            margin-right: 5px;
        }

        select,
        input,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 14px;
            background: #fafafa;
            transition: border 0.2s, background 0.2s;
        }

        select:focus,
        input:focus,
        textarea:focus {
            outline: none;
            border: 1px solid #0d6efd;
            background: #fff;
        }

        textarea {
            resize: none;
        }

        /* Buttons */
        button,
        .btn {
            margin-top: 20px;
            background: #0d6efd;
            color: #fff;
            font-weight: 600;
            padding: 8px 18px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
            width: auto;
            display: inline-block;
        }

        button:hover,
        .btn:hover {
            background: #0b5ed7;
        }

        /* --- Popup Modal --- */
        .popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .popup-content {
            background: #fff;
            padding: 25px 35px;
            border-radius: 12px;
            text-align: center;
            max-width: 420px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
            animation: popIn 0.3s ease;
        }

        .popup-content.success {
            border-top: 6px solid #28a745;
        }

        .popup-content.error {
            border-top: 6px solid #dc3545;
        }

        .popup-content h3 {
            margin: 0 0 12px;
            color: #333;
            font-size: 18px;
        }

        .popup-content p {
            margin: 0 0 20px;
            font-size: 14px;
            color: #555;
        }

        .popup-content button {
            background: #0d6efd;
            padding: 10px 20px;
            border: none;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .popup-content button:hover {
            background: #0b5ed7;
        }

        @keyframes popIn {
            from {
                transform: scale(0.85);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Delete button */
        .delete-btn {
            background: #dc3545;
            border: none;
            color: #fff;
            font-size: 12px;
            padding: 5px 8px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .delete-btn:hover {
            background: #a71d2a;
        }

        /* --- Sent Notifications Section --- */
        /* Container for previous notifications */
        .sent-card {
            margin-top: 30px;
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }

        /* Scrollable table wrapper after 10 rows */
        .scrollable-table {
            max-height: 400px;
            /* ≈10 rows */
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        /* Keep headers fixed while scrolling */
        .scrollable-table table {
            border-collapse: collapse;
            width: 100%;
        }

        .scrollable-table thead th {
            position: sticky;
            top: 0;
            background: #007bff;
            color: #fff;
            z-index: 2;
        }

        .sent-card h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #0d6efd;
            font-size: 20px;
        }

        .sent-card input {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 12px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 14px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        #notificationsTable {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        #notificationsTable th,
        #notificationsTable td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        #notificationsTable th {
            background: #000000ff;
            color: #fff;
            position: sticky;
            top: 0;
        }

        #notificationsTable tr:nth-child(even) {
            background: #f9f9f9;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #fff;
            opacity: 0;
            pointer-events: none;
            transition: opacity .4s ease;
            z-index: 100000;
        }

        .toast.show {
            opacity: 1;
            pointer-events: auto;
        }

        .toast.success {
            background: #198754;
        }

        /* Bootstrap green */
        .toast.error {
            background: #dc3545;
        }

        /* Bootstrap red */
        #formSection {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.4s ease;
            opacity: 0;
        }

        #formSection.show {
            max-height: 800px;
            /* adjust if your form is taller */
            opacity: 1;
        }

        .sent-card {
            margin-top: 0;
            padding-top: 10px;
        }


        /* Search input */
        .search-input {
            width: 150px;
            height: 40px;
            padding: 0 12px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 14px;
            box-sizing: border-box;
            line-height: 40px;
            /* ✅ aligns text vertically */
        }

        /* Button */
        .btn-cta {
            background: #0d6efd;
            color: #fff;
            height: 40px;
            /* ✅ same height as input */
            padding: 0 16px;
            /* side padding only */
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            border: none;
            display: flex;
            /* ✅ centers text */
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease-in-out;
        }

        .pagination-card {
            display: flex;
            align-items: center;
            gap: 4px;
            /* buttons closer together */
            padding: 6px 8px;
            background: #ffffffff;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(255, 255, 255, 1);
            margin-bottom: 15px;
            float: left;
            /* aligns left of table */
            font-family: 'Segoe UI', sans-serif;
        }

        .pagination-card .page-btn {
            background: #0037ffff;
            color: #fff;
            padding: 4px 8px;
            /* smaller padding */
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
            /* smaller font */
            transition: background 0.2s, transform 0.1s;
        }

        .pagination-card .page-btn:hover:not([disabled]) {
            background: #0b5ed7;
            transform: translateY(-1px);
            /* subtle hover effect */
        }

        .pagination-card .page-btn[disabled] {
            opacity: 0.5;
            pointer-events: none;
            cursor: default;
        }

        .pagination-card .current-page {
            font-weight: 600;
            font-size: 12px;
            color: #333;
            margin: 0 6px;
            white-space: nowrap;
        }
    </style>

    <script>
        // Toggle user/role box
        function toggleTarget(type) {
            document.getElementById('userBox').style.display = (type === 'user') ? 'block' : 'none';
            document.getElementById('roleBox').style.display = (type === 'role') ? 'block' : 'none';
        }

        // Filter users in dropdown
        function filterUsers() {
            let input = document.getElementById('userSearch').value.toLowerCase();
            let options = document.getElementById('userDropdown').options;
            for (let i = 0; i < options.length; i++) {
                let text = options[i].text.toLowerCase();
                options[i].style.display = text.includes(input) ? "" : "none";
            }
        }

        function showPopup(type, message) {
            const popup = document.getElementById('popup');
            const content = popup.querySelector('.popup-content');
            const msgBox = document.getElementById('popupMsg');
            const titleBox = document.getElementById('popupTitle');

            // set title text depending on type
            titleBox.textContent = type === "success" ? "✅ Success" : "❌ Error";

            // add styling class
            content.className = "popup-content " + type;

            // set message
            msgBox.textContent = message;

            // show popup
            popup.style.display = 'flex';
        }

        function closePopup() {
            document.getElementById('popup').style.display = 'none';
        }

        function filterNotifications() {
            const input = document.getElementById("searchNotifications").value.toLowerCase();
            const rows = document.querySelectorAll("#notificationsTable tbody tr");
            rows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(input) ? "" : "none";
            });
        }
    </script>
    <script>
        function showToast(toastId) {
            const toast = document.getElementById(toastId);
            if (!toast) return;
            toast.classList.add("show");
            setTimeout(() => toast.classList.remove("show"), 4000);
        }

        function deleteNotification(id) {
            if (!confirm("Are you sure you want to delete this notification?")) return;

            fetch('../Shared/deleteNotification', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        // remove row from table
                        const row = document.getElementById('row-' + id);
                        if (row) row.remove();

                        // show success toast
                        showToast('deleteSuccessToast');
                    } else {
                        console.error(data.message);
                        showToast('deleteErrorToast');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('deleteErrorToast');
                });
        }
    </script>

</head>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const btn = document.getElementById("showFormBtn");
        const formSection = document.getElementById("formSection");

        if (!btn || !formSection) return; // prevent errors if not found

        btn.addEventListener("click", function() {
            if (!formSection.classList.contains("show")) {
                formSection.classList.add("show");
                btn.textContent = "✖ Close Form";
                btn.style.background = "#dc3545"; // red
            } else {
                formSection.classList.remove("show");
                btn.textContent = "➕ Create New Notification";
                btn.style.background = "#0d6efd"; // blue
            }
        });
    });
</script>

<?php if (isset($_SESSION['flash'])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            showPopup("<?= $_SESSION['flash']['type'] ?>", "<?= htmlspecialchars($_SESSION['flash']['msg']) ?>");
        });
    </script>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<body>
    <!-- Navbar -->
    <div class="navbar">
        <h1>Admin Dashboard</h1>
        <div>
            <a href="logout">Logout</a>
        </div>
    </div>

    <?php
    // --- Fetch sent notifications
    $sent = $conn->query("
    SELECT sn.id, sn.title, sn.message, sn.role, sn.user_id, sn.created_at, u.full_name
    FROM system_notifications sn
    LEFT JOIN users u ON sn.user_id = u.id
    ORDER BY sn.created_at DESC
");
    ?>
    <!-- Previous Notifications Section -->
    <div class="sent-card">
        <h2>📨 All Notifications</h2>

        <!-- Search + CTA Row -->
        <div class="search-cta-row">
            <input type="text" id="searchNotifications"
                placeholder="🔎 Search notifications..."
                onkeyup="filterNotifications()"
                class="search-input">

            <button id="showFormBtn" class="btn btn-cta">
                ➕ Create New Notification
            </button>
        </div>

        <!-- Notifications Table -->
        <div class="table-wrapper">
            <table id="notificationsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Target</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Sent At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sent && $sent->num_rows > 0): ?>
                        <?php while ($n = $sent->fetch_assoc()): ?>
                            <tr id="row-<?= $n['id'] ?>">
                                <td><?= $n['id'] ?></td>
                                <td>
                                    <?php if ($n['user_id']): ?>
                                        👤 <?= htmlspecialchars($n['full_name']) ?> (User)
                                    <?php elseif ($n['role']): ?>
                                        👥 <?= ucfirst($n['role']) ?>
                                    <?php else: ?>
                                        🌍 All
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($n['title']) ?></td>
                                <td><?= htmlspecialchars($n['message']) ?></td>
                                <td><?= date("M d, Y H:i", strtotime($n['created_at'])) ?></td>
                                <td>
                                    <button class="delete-btn" onclick="deleteNotification(<?= $n['id'] ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">No notifications sent yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages >= 1): ?>
            <div class="pagination-card">
                <a href="?page=1" class="page-btn" <?= ($page == 1 ? 'disabled' : '') ?>>⏮ First</a>
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn" <?= ($page == 1 ? 'disabled' : '') ?>>⬅ Prev</a>
                <span class="current-page">Page <?= $page ?> / <?= $totalPages ?></span>
                <a href="?page=<?= min($totalPages, $page + 1) ?>" class="page-btn" <?= ($page == $totalPages ? 'disabled' : '') ?>>Next ➡</a>
                <a href="?page=<?= $totalPages ?>" class="page-btn" <?= ($page == $totalPages ? 'disabled' : '') ?>>Last ⏭</a>
            </div>
        <?php endif; ?>

    </div>

    <!-- Form Container (Initially Hidden) -->
    <div id="formSection" class="form-card">
        <h2>🚨 Send Urgent Notification</h2>
        <form method="post">
            <!-- Target Type -->
            <label><span class="icon">🎯</span> Target Type:</label>
            <select name="target_type" onchange="toggleTarget(this.value)" required>
                <option value="">Select Target Type</option>
                <option value="user">Specific User</option>
                <option value="role">Specific Role</option>
            </select>

            <!-- User Selection -->
            <div id="userBox" style="display:none;">
                <label><span class="icon">👤</span> User:</label>
                <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="🔎 Search user...">
                <select name="user_id" id="userDropdown" size="5">
                    <option value="">Select User</option>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= $u['role'] ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Role Selection -->
            <div id="roleBox" style="display:none;">
                <label><span class="icon">👥</span> Role:</label>
                <select name="role">
                    <option value="">Select Role</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Title -->
            <label><span class="icon">📝</span> Title:</label>
            <input type="text" name="title" required>

            <!-- Message -->
            <label><span class="icon">💬</span> Message:</label>
            <textarea name="message" rows="4" required></textarea>

            <!-- Submit -->
            <div style="text-align:left; margin-top:15px;">
                <button type="submit" class="btn-submit">Send Notification</button>
            </div>
        </form>
    </div>
    <!-- Popup Modal -->
    <div id="popup" class="popup">
        <div class="popup-content">
            <h3 id="popupTitle"></h3>
            <p id="popupMsg"></p>
            <button onclick="closePopup()">OK</button>
        </div>
    </div>

    <?php if ($response): ?>
        <script>
            showPopup("<?= $response['type'] ?>", "<?= htmlspecialchars($response['msg']) ?>");
        </script>
    <?php endif; ?>
    <!-- Toasts for delete -->
    <div id="deleteSuccessToast" class="toast success">✅ Notification deleted successfully.</div>
    <div id="deleteErrorToast" class="toast error">❌ Failed to delete notification.</div>

</body>

</html>