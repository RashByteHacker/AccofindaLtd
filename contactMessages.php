<?php
session_start();
require 'config.php';

// ✅ Access Control: Admin only
if (!isset($_SESSION["email"]) || strtolower($_SESSION["role"]) !== "admin") {
    header("Location: index.php?error=AccessDenied");
    exit();
}

// ✅ Handle deletion via AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $idToDelete = intval($_POST['delete_id']);

    // Fetch message details for logging
    $stmtFetch = $conn->prepare("SELECT email, subject FROM contactmessages WHERE id = ?");
    $stmtFetch->bind_param("i", $idToDelete);
    $stmtFetch->execute();
    $stmtFetch->bind_result($email, $subject);
    $stmtFetch->fetch();
    $stmtFetch->close();

    // Log deletion
    $log = $conn->prepare("
        INSERT INTO deleted_contact_logs (deleted_by, role, message_id, message_subject, message_email, deleted_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $log->bind_param("ssiss", $_SESSION["email"], $_SESSION["role"], $idToDelete, $subject, $email);
    $log->execute();
    $log->close();

    // Delete the message
    $stmt = $conn->prepare("DELETE FROM contactmessages WHERE id = ?");
    $stmt->bind_param("i", $idToDelete);
    $stmt->execute();
    $stmt->close();

    exit(); // ✅ AJAX response success
}

// ✅ Pagination
$perPage = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

$total = $conn->query("SELECT COUNT(*) as count FROM contactmessages")->fetch_assoc()['count'];
$totalPages = ceil($total / $perPage);

$result = $conn->prepare("SELECT * FROM contactmessages ORDER BY submitted_at DESC LIMIT ?, ?");
$result->bind_param("ii", $offset, $perPage);
$result->execute();
$messages = $result->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../Images/favicon.png" type="image/png">
    <title>Contact Messages | Accofinda Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #e0e0e0;
            padding-top: 40px;
            font-size: 13px;
        }

        .table thead th {
            background-color: #1f1f1f;
            color: #0dcaf0;
        }

        .container {
            max-width: 1100px;
        }

        .message-box {
            background-color: #1d1d1d;
            border-left: 4px solid #0dcaf0;
            padding: 10px;
            white-space: pre-wrap;
            font-size: 13px;
            max-height: 100px;
            overflow-y: auto;
        }
    </style>
</head>

<body>

    <div class="container">
        <h2 class="mb-4 text-info text-center">📬 Contact Messages</h2>

        <div id="alert-container"></div>

        <?php if ($messages->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-dark table-bordered table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Sender</th>
                            <th>Email</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Submitted At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $messages->fetch_assoc()): ?>
                            <tr id="row-<?= $row['id'] ?>">
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><a href="mailto:<?= htmlspecialchars($row['email']) ?>" class="text-info"><?= htmlspecialchars($row['email']) ?></a></td>
                                <td><?= htmlspecialchars($row['subject']) ?></td>
                                <td>
                                    <div class="message-box"><?= htmlspecialchars($row['message']) ?></div>
                                </td>
                                <td><?= date('M d, Y H:i', strtotime($row['submitted_at'])) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal" onclick="setDeleteId(<?= $row['id'] ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- ✅ Pagination -->
            <nav class="d-flex justify-content-center mt-4">
                <ul class="pagination pagination-sm">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>

            <!-- ✅ Delete Confirmation Modal -->
            <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-dark text-light border border-danger">
                        <div class="modal-header">
                            <h5 class="modal-title">Confirm Deletion</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            Are you sure you want to permanently delete this message?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete</button>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="alert alert-warning text-center">No messages have been received yet.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentDeleteId = null;

        function setDeleteId(id) {
            currentDeleteId = id;
        }

        function confirmDelete() {
            if (!currentDeleteId) return;

            fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        delete_id: currentDeleteId
                    })
                })
                .then(res => res.ok ? res.text() : Promise.reject("Failed"))
                .then(() => {
                    const row = document.getElementById("row-" + currentDeleteId);
                    if (row) row.remove();

                    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal'));
                    modal.hide();

                    showTempAlert("✅ Message deleted successfully", "success");
                })
                .catch(() => {
                    showTempAlert("❌ Failed to delete message", "danger");
                });
        }

        function showTempAlert(msg, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} text-center`;
            alert.textContent = msg;
            document.getElementById('alert-container').appendChild(alert);
            setTimeout(() => alert.remove(), 4000);
        }
    </script>
</body>

</html>