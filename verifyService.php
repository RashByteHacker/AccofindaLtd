<?php
session_start();
require '../config.php';

// Flash message system
$message = '';
$messageType = '';

// ---------------------------
// Admin login check
// ---------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php?error=not_logged_in");
    exit();
}

// Admin is logged in, get admin ID from user_id
$currentAdminId = $_SESSION['user_id'] ?? null;

if (!$currentAdminId) {
    header("Location: ../login.php?error=invalid_session");
    exit();
}

// ---------------------------
// Handle approve/suspend/delete actions
// ---------------------------
if (isset($_GET['id'], $_GET['action'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    $stmt = null;

    switch ($action) {
        case 'approve':
            $stmt = $conn->prepare("
                UPDATE users 
                SET serviceprovider_status = 'approved',
                    approved_by_id = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("ii", $currentAdminId, $id);
                $msg = "approved successfully.";
                $type = "success";
            }
            break;

        case 'suspend':
            $stmt = $conn->prepare("UPDATE users SET serviceprovider_status = 'suspended' WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $msg = "suspended successfully.";
                $type = "danger";
            }
            break;

        case 'delete':
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $msg = "deleted successfully.";
                $type = "warning";
            }
            break;
    }

    if ($stmt) {
        if (!$stmt->execute()) {
            die("Database error: " . $stmt->error);
        }
        $_SESSION['flash_message'] = "Provider ID $id $msg";
        $_SESSION['flash_type'] = $type;
        $stmt->close();
    }

    header("Location: verifyService.php");
    exit();
}

// ---------------------------
// Retrieve flash messages
// ---------------------------
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ---------------------------
// Fetch all service providers
// ---------------------------
$search = $_GET['search'] ?? '';
$providers = [];
$approvedProviders = [];

if ($search) {
    $like = "%$search%";
    $stmt = $conn->prepare("
        SELECT u.*, a.full_name AS approved_by_name
        FROM users u
        LEFT JOIN users a ON u.approved_by_id = a.id
        WHERE u.role = 'service provider'
          AND (u.full_name LIKE ? OR u.email LIKE ? OR u.service_type LIKE ?)
    ");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("
        SELECT u.*, a.full_name AS approved_by_name
        FROM users u
        LEFT JOIN users a ON u.approved_by_id = a.id
        WHERE u.role = 'service provider'
    ");
}

while ($row = $result->fetch_assoc()) {
    if ($row['serviceprovider_status'] === 'approved') {
        $approvedProviders[] = $row;
    } else {
        $providers[] = $row;
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Service Approval</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            background: #000000ff;
        }

        .navbar-brand {
            color: #fff !important;
            margin: 0 auto;
            font-weight: bold;
        }

        .back-btn {
            color: #fff;
            text-decoration: none;
        }

        .table thead {
            background: #000;
            color: #fff;
        }

        footer {
            margin-top: auto;
            background: #000000ff;
            color: #fff;
            text-align: center;
            padding: 12px;
        }

        .alert {
            position: fixed;
            top: 70px;
            right: 20px;
            min-width: 250px;
            z-index: 9999;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a href="adminDashboard" class="btn btn-success btn-sm d-flex align-items-center"
                style="height: 36px; padding: 0 12px; border-radius: 6px;">
                <i class="fa fa-arrow-left me-1"></i> Back
            </a>
            <span class="navbar-brand mb-0 h1 text-center flex-grow-1">Service Approval</span>
            <div style="width: 90px;"></div>
        </div>
    </nav>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="container py-4 flex-grow-1">

        <!-- Search Bar -->
        <form method="GET" class="d-flex mb-4 search-box align-items-center" onsubmit="return false;">
            <input type="text" id="providerSearch" name="search" value="<?= htmlspecialchars($search) ?>"
                class="form-control" placeholder="Search provider by name, email, or type..." style="height: 36px;">
        </form>

        <!-- Pending Providers -->
        <div class="card shadow mb-4">
            <div class="card-header bg-dark text-white fw-bold" style="font-size:0.9rem;">Pending Service Providers</div>
            <div class="card-body p-0">
                <div style="max-height:250px; overflow-y:auto;">
                    <table class="table table-hover mb-0" style="font-size:0.8rem;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Service Type</th>
                                <th>Phone</th>
                                <th>City</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($providers) > 0): ?>
                                <?php foreach ($providers as $p): ?>
                                    <tr>
                                        <td><?= $p['id'] ?></td>
                                        <td><?= htmlspecialchars($p['full_name']) ?></td>
                                        <td><?= htmlspecialchars($p['email']) ?></td>
                                        <td><?= htmlspecialchars($p['service_type'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['phone_number'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['city'] ?? '-') ?></td>
                                        <td><span class="badge bg-warning"><?= $p['serviceprovider_status'] ?: 'pending' ?></span></td>
                                        <td>
                                            <a href="verifyService.php?id=<?= $p['id'] ?>&action=approve"
                                                class="btn btn-success btn-sm mb-1"
                                                style="padding: 0.15rem 0.4rem; font-size:0.7rem; height:auto;">
                                                <i class="fa fa-check"></i> Approve
                                            </a>

                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No pending providers found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Approved Providers -->
        <div class="card shadow">
            <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center" style="font-size:0.9rem;">
                <span>Approved Service Providers</span>
                <input type="text" id="approvedSearch" class="form-control form-control-sm ms-2"
                    placeholder="Search..." style="max-width: 220px; font-size:0.8rem;">
            </div>
            <div class="card-body p-0">
                <!-- ✅ Removed scroll wrapper -->
                <table class="table table-striped mb-0" style="font-size:0.8rem;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Service Type</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Approved At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="approvedTable">
                        <?php if (count($approvedProviders) > 0): ?>
                            <?php foreach ($approvedProviders as $a): ?>
                                <tr>
                                    <td><?= $a['id'] ?></td>
                                    <td><?= htmlspecialchars($a['full_name']) ?></td>
                                    <td><?= htmlspecialchars($a['email']) ?></td>
                                    <td><?= htmlspecialchars($a['service_type'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($a['phone_number'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($a['city'] ?? '-') ?></td>
                                    <td><span class="badge bg-success">Approved</span></td>
                                    <td><?= htmlspecialchars($a['approved_by_name'] ?? '-') ?></td>
                                    <td><?= !empty($a['approved_at']) ? date("M d, Y H:i", strtotime($a['approved_at'])) : '-' ?></td>
                                    <td>
                                        <a href="verifyService.php?id=<?= $a['id'] ?>&action=suspend"
                                            class="btn btn-danger btn-sm mb-1"
                                            style="padding: 0.15rem 0.4rem; font-size:0.7rem; height:auto;">
                                            <i class="fa fa-ban"></i> Suspend
                                        </a>
                                        <a href="verifyService.php?id=<?= $a['id'] ?>&action=delete"
                                            class="btn btn-warning btn-sm mb-1"
                                            style="padding: 0.15rem 0.4rem; font-size:0.7rem; height:auto;">
                                            <i class="fa fa-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">No approved providers yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination inside card body aligned left -->
                <nav>
                    <ul id="approvedPagination" class="pagination pagination-sm justify-content-start my-2 ms-2"></ul>
                </nav>
            </div>
        </div>

    </div>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All Rights Reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const rowsPerPage = 10;
        const approvedTable = document.getElementById("approvedTable");
        const approvedRows = approvedTable.querySelectorAll("tr");
        const approvedPagination = document.getElementById("approvedPagination");
        let currentPage = 1;
        let filteredRows = [...approvedRows];

        function displayApproved(page) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            approvedRows.forEach(r => r.style.display = "none");
            filteredRows.forEach((row, idx) => {
                if (idx >= start && idx < end) row.style.display = "";
            });
        }

        function setupApprovedPagination() {
            const pageCount = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            approvedPagination.innerHTML = "";

            // Prev
            const prev = document.createElement("li");
            prev.className = "page-item " + (currentPage === 1 ? "disabled" : "");
            prev.innerHTML = `<a class="page-link" href="#">Previous</a>`;
            prev.onclick = e => {
                e.preventDefault();
                if (currentPage > 1) {
                    currentPage--;
                    displayApproved(currentPage);
                    setupApprovedPagination();
                }
            };
            approvedPagination.appendChild(prev);

            // Page numbers
            for (let i = 1; i <= pageCount; i++) {
                const li = document.createElement("li");
                li.className = "page-item " + (i === currentPage ? "active" : "");
                li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                li.onclick = e => {
                    e.preventDefault();
                    currentPage = i;
                    displayApproved(currentPage);
                    setupApprovedPagination();
                };
                approvedPagination.appendChild(li);
            }

            // Next
            const next = document.createElement("li");
            next.className = "page-item " + (currentPage === pageCount ? "disabled" : "");
            next.innerHTML = `<a class="page-link" href="#">Next</a>`;
            next.onclick = e => {
                e.preventDefault();
                if (currentPage < pageCount) {
                    currentPage++;
                    displayApproved(currentPage);
                    setupApprovedPagination();
                }
            };
            approvedPagination.appendChild(next);
        }

        // Search filter
        document.getElementById("approvedSearch").addEventListener("keyup", function() {
            const q = this.value.toLowerCase();
            filteredRows = [...approvedRows].filter(r => r.innerText.toLowerCase().includes(q));
            currentPage = 1;
            displayApproved(currentPage);
            setupApprovedPagination();
        });

        // Init
        displayApproved(currentPage);
        setupApprovedPagination();

        // Auto-hide alert
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) alert.classList.remove('show');
        }, 5000);
    </script>

</body>

</html>