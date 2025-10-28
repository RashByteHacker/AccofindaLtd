<?php
session_start();
require '../config.php';

// ✅ Restrict only admins
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    die("Access denied. Admins only.");
}

// Handle Admin Response
$successMsg = $errorMsg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_id'])) {
    $respondId = intval($_POST['respond_id']);
    $adminResponse = trim($_POST['admin_response']);
    $status = $_POST['status'];

    $stmt = $conn->prepare("UPDATE support_messages SET admin_response = ?, status = ?, responded_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $adminResponse, $status, $respondId);

    if ($stmt->execute()) {
        $successMsg = "Response sent successfully!";
        header("Location: " . $_SERVER['PHP_SELF']); // reload clean to prevent resubmission
        exit();
    } else {
        $errorMsg = "Failed to send response.";
    }
    $stmt->close();
}

// Fetch support messages
$sql = "SELECT sm.*, u.full_name, u.email, u.phone_number 
        FROM support_messages sm
        JOIN users u ON sm.user_id = u.id
        ORDER BY sm.created_at DESC";
$result = $conn->query($sql);

// If detail view requested
$detail = null;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT sm.*, u.full_name, u.email, u.phone_number 
                            FROM support_messages sm
                            JOIN users u ON sm.user_id = u.id
                            WHERE sm.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Support - Accofinda</title>
    <link rel="icon" type="image/jpg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', sans-serif;
        }

        .navbar {
            background: linear-gradient(90deg, #212529, #343a40);
        }

        .status-badge {
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 8px;
            min-width: 90px;
            text-align: center;
            display: inline-block;
        }

        .status-pending {
            background: #ffc107;
            color: #212529;
        }

        .status-in_progress {
            background: #0dcaf0;
            color: #fff;
        }

        .status-resolved {
            background: #198754;
            color: #fff;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }
    </style>
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar navbar-dark px-3">
        <a class="navbar-brand d-flex align-items-center" href="admin_support.php">
            <img src="../Images/AccofindaLogo1.jpg" alt="Logo" width="35" height="35" class="me-2">
            <span>Admin Support Dashboard</span>
        </a>
        <div class="text-white">
            <i class="fa fa-user-shield me-1"></i> <?= htmlspecialchars($_SESSION['email']) ?> (Admin)
        </div>
    </nav>

    <div class="container my-4">
        <?php if ($successMsg): ?>
            <div class="alert alert-success"><?= $successMsg ?></div>
        <?php elseif ($errorMsg): ?>
            <div class="alert alert-danger"><?= $errorMsg ?></div>
        <?php endif; ?>

        <?php if (!$detail): ?>
            <h3 class="mb-3">All User Concerns</h3>

            <!-- Search box -->
            <input type="text" id="searchInput" class="form-control mb-3" placeholder="Search by ID, user, subject, or status...">

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover" id="concernTable">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="?id=<?= $row['id'] ?>" class="btn btn-link p-0">
                                        <?= $row['id'] ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['user_role']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['phone_number']) ?></td>
                                <td><?= htmlspecialchars($row['subject']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= $row['created_at'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- Detail view -->
            <div class="card shadow">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fa fa-user me-2"></i> <?= htmlspecialchars($detail['full_name']) ?>
                        (<?= htmlspecialchars($detail['user_role']) ?>)<br>
                        <small class="text-light"><?= htmlspecialchars($detail['email']) ?> | <?= htmlspecialchars($detail['phone_number']) ?></small>
                    </div>
                    <span class="status-badge status-<?= $detail['status'] ?>">
                        <?= ucfirst($detail['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <h5><i class="fa fa-tag me-2"></i><?= htmlspecialchars($detail['subject']) ?></h5>
                    <p><?= nl2br(htmlspecialchars($detail['message'])) ?></p>

                    <?php if ($detail['attachment']): ?>
                        <p><i class="fa fa-paperclip me-2"></i>
                            <a href="../uploads/support/<?= htmlspecialchars($detail['attachment']) ?>" target="_blank">View Attachment</a>
                        </p>
                    <?php endif; ?>

                    <?php if ($detail['admin_response']): ?>
                        <div class="alert alert-info">
                            <strong>Admin Response:</strong><br>
                            <?= nl2br(htmlspecialchars($detail['admin_response'])) ?><br>
                            <small><i class="fa fa-clock me-1"></i> <?= $detail['responded_at'] ?></small>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="mt-3">
                        <input type="hidden" name="respond_id" value="<?= $detail['id'] ?>">
                        <div class="mb-2">
                            <textarea name="admin_response" class="form-control" rows="3" placeholder="Write your response..." required></textarea>
                        </div>
                        <div class="mb-2">
                            <label>Status:</label>
                            <select name="status" class="form-select" required>
                                <option value="pending" <?= $detail['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="in_progress" <?= $detail['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="resolved" <?= $detail['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa fa-reply me-1"></i> Send Response
                        </button>
                        <a href="admin_support.php" class="btn btn-secondary btn-sm">Back to List</a>
                    </form>
                </div>
                <div class="card-footer text-muted">
                    <i class="fa fa-clock me-1"></i> Submitted: <?= $detail['created_at'] ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Table search filter
        document.getElementById("searchInput").addEventListener("keyup", function() {
            let value = this.value.toLowerCase();
            document.querySelectorAll("#concernTable tbody tr").forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(value) ? "" : "none";
            });
        });
    </script>

</body>

</html>