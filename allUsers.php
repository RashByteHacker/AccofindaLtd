<?php
session_start();
require '../config.php';

// ✅ Only Admin Access
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../index.php?error=AccessDenied");
    exit();
}

// ✅ Handle Manual Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['verify_user_id'])) {
    $verifyId = intval($_POST['verify_user_id']);

    $check = $conn->prepare("SELECT email_verified FROM users WHERE id = ?");
    $check->bind_param("i", $verifyId);
    $check->execute();
    $userData = $check->get_result()->fetch_assoc();
    $check->close();

    if ($userData && $userData['email_verified'] == 0) {
        $stmt = $conn->prepare("UPDATE users SET email_verified = 1, verification_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $verifyId);
        $stmt->execute();
        $stmt->close();

        // ✅ Store flash message in session instead of URL
        $_SESSION['success'] = "User verified successfully";
    } else {
        $_SESSION['error'] = "User is already verified";
    }

    // ✅ Redirect to avoid form re-submission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Handle Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_user_id'])) {
    $deleteId = intval($_POST['delete_user_id']);

    if ($deleteId == $_SESSION['id']) {
        $_SESSION['error'] = "You cannot delete your own account";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $deleteId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = "User deleted successfully";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Display message only once (then remove it)
$successMsg = $_SESSION['success'] ?? "";
$errorMsg   = $_SESSION['error']   ?? "";
unset($_SESSION['success'], $_SESSION['error']);

// ✅ Role Filtering
$roleFilter = $_GET['role'] ?? '';
if (!empty($roleFilter)) {
    $stmt = $conn->prepare("
        SELECT id, full_name, email, phone_number, role, city, email_verified, status 
        FROM users 
        WHERE role = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("s", $roleFilter);
} else {
    $stmt = $conn->prepare("
        SELECT id, full_name, email, phone_number, role, city, email_verified, status 
        FROM users 
        ORDER BY created_at DESC
    ");
}

$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ✅ Count Users by Role
$userRoleCounts = [];
$resultRole = $conn->query("SELECT role, COUNT(*) AS count FROM users GROUP BY role");
while ($row = $resultRole->fetch_assoc()) {
    $userRoleCounts[$row['role']] = $row['count'];
}

// ✅ Total Users
$totalUsers = count($users);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>All Users | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 16px;
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
        }

        /* Navbar */
        nav {
            background-color: #000;
            padding: 10px 20px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        nav a {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
        }

        /* Footer */
        footer {
            margin-top: auto;
            background-color: #000;
            color: #fff;
            text-align: center;
            padding: 15px;
        }

        /* Top Section */
        .top-section {
            background-color: #1f1f1f;
            color: #fff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 5px 5px 0 0;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .top-section .controls {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            align-items: center;
        }

        .top-section input {
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        .btn-show-all {
            background-color: #ac2b11;
            color: #fff;
            font-weight: 500;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-show-all:hover {
            background-color: #000;
            color: #fff;
        }

        /* Responsive Table Wrapper */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        /* ✅ Table Styling */
        table {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 13px;
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
            /* ✅ Fix column widths & remove extra empty space */
        }

        /* ✅ Make Actions column fit only its content */
        table th:last-child,
        table td:last-child {
            width: 210px;
            /* Adjust to best fit 3 buttons */
            text-align: center;
        }

        /* Header Styling */
        table thead th {
            background-color: #1f1f1f;
            color: #0dcaf0;
            border: 1px solid #444;
            text-align: center;
            padding: 8px;
            white-space: nowrap;
        }

        /* Small Buttons */
        .btn-sm {
            font-size: 0.75rem !important;
            padding: 2px 6px !important;
        }

        /* Body Cells */
        table tbody td {
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: middle;
            padding: 6px 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ✅ Zebra Striping */
        table tbody tr:nth-child(odd) td {
            background-color: #f2f2f2 !important;
        }

        table tbody tr:nth-child(even) td {
            background-color: #bdcad7ff !important;
        }

        /* Hover Effect */
        table tbody tr:hover td {
            background-color: #d6d8db !important;
            transition: 0.2s ease-in-out;
        }

        /* ✅ Ensure action buttons don't overflow */
        .action-btn {
            white-space: nowrap;
            display: inline-block;
        }


        /* Buttons */
        .btn-action {
            padding: 3px 8px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }

        .btn-edit {
            background-color: #0d6efd;
            color: #fff;
        }

        .btn-edit:hover {
            background-color: #0b5ed7;
        }

        .btn-verify {
            background-color: #ffc107;
            color: #000;
        }

        .btn-verify:hover {
            background-color: #e0a800;
        }

        .btn-delete {
            background-color: #dc3545;
            color: #fff;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }

        .action-btn {
            min-width: 58px;
            text-align: center;
            padding: 3px 6px;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        /* Alerts */
        .alert-success {
            display: none;
            margin-top: 10px;
        }

        .highlight {
            background-color: #fff3cd !important;
        }

        /* ✅ Mobile (≤768px) */
        @media (max-width: 768px) {
            .container {
                width: 100% !important;
                padding-left: 5px !important;
                padding-right: 5px !important;
            }

            table {
                font-size: 12px;
            }

            table thead th,
            table tbody td {
                padding: 4px;
                font-size: 12px;
            }

            .btn-action {
                padding: 2px 6px;
                font-size: 11px;
            }

            .top-section {
                flex-direction: column;
                align-items: stretch;
                padding: 10px;
            }

            .top-section .controls {
                flex-direction: column;
                gap: 5px;
                align-items: stretch;
            }

            .table-responsive {
                overflow-x: hidden;
            }
        }

        /* ✅ Extra Small Screens (≤480px) */
        @media (max-width: 480px) {
            table {
                font-size: 11px;
            }

            table thead th,
            table tbody td {
                padding: 3px;
            }

            .btn-action {
                font-size: 10px;
                padding: 2px 4px;
            }
        }
    </style>


</head>

<body>

    <!-- Navbar -->
    <nav>
        <a href="javascript:history.back();" class="btn btn-sm btn-dark">
            <i class="fa fa-arrow-left"></i> Back
        </a>
        <span style="text-align: center; flex-grow: 1;">Accofinda Admin Panel</span>
        <a href="../logout" class="btn btn-sm btn-danger">
            <i class="fa fa-sign-out-alt"></i> Logout
        </a>
    </nav>

    <div class="container-fluid my-4">

        <!-- Success Message -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success" id="successMsg"><?= htmlspecialchars($successMsg) ?></div>
            <script>
                setTimeout(() => {
                    const msg = document.getElementById("successMsg");
                    if (msg) msg.style.display = "none";
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- Top Section -->
        <div class="top-section">
            <h3>All Users</h3>
            <div class="controls">
                <input type="number" id="searchID" placeholder="Search by ID">
                <input type="text" id="searchInput" placeholder="Search by name, email or phone">
                <button class="btn btn-show-all" onclick="location.reload();">All Users (<?= $totalUsers ?>)</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>City</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTable">
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['id']) ?></td>
                            <td><?= htmlspecialchars($u['full_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($u['email'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($u['phone_number'] ?? 'N/A') ?></td>
                            <td>
                                <?php
                                $rawRole = $u['role'] ?? 'N/A';
                                $role = strtolower(trim($rawRole)); // Normalize

                                switch ($role) {
                                    case 'admin':
                                        $badgeClass = 'bg-danger text-white';
                                        break;
                                    case 'tenant':
                                        $badgeClass = 'bg-primary text-white';
                                        break;
                                    case 'serviceprovider':
                                    case 'service provider':
                                        $badgeClass = 'bg-success text-white';
                                        $role = 'Service Provider';
                                        break;
                                    case 'manager':
                                        $badgeClass = 'bg-info text-dark';
                                        break;
                                    case 'landlord':
                                        $badgeClass = 'bg-warning text-dark';
                                        break;
                                    default:
                                        $badgeClass = 'bg-secondary text-white';
                                        $role = 'N/A';
                                        break;
                                }
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= ucfirst($role) ?></span>
                            </td>
                            <td><?= htmlspecialchars($u['city'] ?? 'N/A') ?></td>
                            <td>
                                <div class="d-flex align-items-center flex-wrap gap-1">

                                    <!-- Edit Button -->
                                    <a href="editUser?id=<?= $u['id'] ?>" class="btn btn-primary btn-sm px-2 py-1 action-btn">
                                        <i class="fa fa-edit"></i> Edit
                                    </a>

                                    <!-- Email Verification -->
                                    <?php $emailVerified = $u['email_verified'] ?? null; ?>
                                    <?php if ($emailVerified == 1): ?>
                                        <span class="btn btn-success btn-sm px-2 py-1 action-btn">Verified</span>
                                    <?php elseif ($emailVerified == 0): ?>
                                        <form method="POST" class="d-inline-block m-0 p-0">
                                            <input type="hidden" name="verify_user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm px-2 py-1 action-btn">
                                                <i class="fa fa-check-circle"></i> Verify
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary d-flex align-items-center px-2 py-1 action-btn">
                                            <i class="fa fa-info-circle me-1"></i> Unknown
                                        </span>
                                    <?php endif; ?>

                                    <!-- Delete Button -->
                                    <form method="POST" class="d-inline-block m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                        <input type="hidden" name="delete_user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm px-2 py-1 action-btn">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination Controls -->
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0" id="pagination"></ul>
            </nav>
        </div>


    </div>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide success message
        window.addEventListener('DOMContentLoaded', () => {
            const msg = document.getElementById('successMsg');
            if (msg) {
                msg.style.display = 'block';
                setTimeout(() => msg.style.display = 'none', 3000);
            }
        });

        const rowsPerPage = 30;
        const tableRows = document.querySelectorAll('#usersTable tr');
        const pagination = document.getElementById('pagination');
        const searchInput = document.getElementById('searchInput');
        const searchID = document.getElementById('searchID');
        let currentPage = 1;
        let filteredRows = [...tableRows]; // start with all rows

        // Highlight helpers
        function highlightRow(row) {
            row.classList.add('highlight');
        }

        function removeHighlight(row) {
            row.classList.remove('highlight');
        }

        // Show rows for current page
        function displayTable(page) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            // Hide all first
            tableRows.forEach(r => r.style.display = 'none');

            // Show filtered + paginated rows
            filteredRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });
        }

        // Build pagination UI
        function setupPagination() {
            const pageCount = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            pagination.innerHTML = '';

            // First
            const first = document.createElement('li');
            first.className = 'page-item ' + (currentPage === 1 ? 'disabled' : '');
            first.innerHTML = `<a class="page-link" href="#">First</a>`;
            first.addEventListener('click', function(e) {
                e.preventDefault();
                if (currentPage !== 1) {
                    currentPage = 1;
                    displayTable(currentPage);
                    setupPagination();
                }
            });
            pagination.appendChild(first);

            // Prev
            const prev = document.createElement('li');
            prev.className = 'page-item ' + (currentPage === 1 ? 'disabled' : '');
            prev.innerHTML = `<a class="page-link" href="#">Previous</a>`;
            prev.addEventListener('click', function(e) {
                e.preventDefault();
                if (currentPage > 1) {
                    currentPage--;
                    displayTable(currentPage);
                    setupPagination();
                }
            });
            pagination.appendChild(prev);

            // Page numbers
            for (let i = 1; i <= pageCount; i++) {
                const li = document.createElement('li');
                li.className = 'page-item ' + (i === currentPage ? 'active' : '');
                li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                li.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentPage = i;
                    displayTable(currentPage);
                    setupPagination();
                });
                pagination.appendChild(li);
            }

            // Next
            const next = document.createElement('li');
            next.className = 'page-item ' + (currentPage === pageCount ? 'disabled' : '');
            next.innerHTML = `<a class="page-link" href="#">Next</a>`;
            next.addEventListener('click', function(e) {
                e.preventDefault();
                if (currentPage < pageCount) {
                    currentPage++;
                    displayTable(currentPage);
                    setupPagination();
                }
            });
            pagination.appendChild(next);

            // Last
            const last = document.createElement('li');
            last.className = 'page-item ' + (currentPage === pageCount ? 'disabled' : '');
            last.innerHTML = `<a class="page-link" href="#">Last</a>`;
            last.addEventListener('click', function(e) {
                e.preventDefault();
                if (currentPage !== pageCount) {
                    currentPage = pageCount;
                    displayTable(currentPage);
                    setupPagination();
                }
            });
            pagination.appendChild(last);
        }

        // Refresh pagination + reset to page 1
        function refreshPagination() {
            currentPage = 1;
            displayTable(currentPage);
            setupPagination();
        }

        // Search filters
        function filterRows() {
            const idFilter = searchID.value.trim();
            const textFilter = searchInput.value.toLowerCase();

            filteredRows = [...tableRows].filter(row => {
                const id = row.cells[0].innerText;
                const text = row.innerText.toLowerCase();

                const matches =
                    (!idFilter || id.includes(idFilter)) &&
                    (!textFilter || text.includes(textFilter));

                if (matches) {
                    highlightRow(row);
                } else {
                    removeHighlight(row);
                }
                return matches;
            });

            refreshPagination();
        }

        // Event listeners
        searchInput.addEventListener('input', filterRows);
        searchID.addEventListener('input', filterRows);

        // Init
        filterRows(); // sets filteredRows + pagination
    </script>


</body>

</html>