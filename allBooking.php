<?php
session_start();
require '../config.php';

// ✅ Restrict access to admins only
if (!isset($_SESSION['id']) || strtolower($_SESSION['role']) !== 'admin') {
    die("Access denied. Admins only.");
}

$successMsg = "";

// ================== DELETE HANDLER ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking_id'])) {
    $deleteId = intval($_POST['delete_booking_id']);
    $stmt = $conn->prepare("DELETE FROM booking_requests WHERE booking_id = ?");
    $stmt->bind_param("i", $deleteId);
    if ($stmt->execute()) {
        $successMsg = "✅ Booking deleted successfully!";
    } else {
        $successMsg = "❌ Error deleting booking.";
    }
    $stmt->close();
}

// ================== FETCH PENDING BOOKINGS ==================
$stmt = $conn->prepare("
    SELECT 
        br.booking_id, br.unit_detail_id, br.unit_code, br.room_type, br.amount, 
        br.status AS booking_status, br.payment_status, br.created_at,
        t.full_name AS tenant_name, t.phone_number AS tenant_phone,
        pud.status AS unit_status,
        l.full_name AS landlord_name, l.phone_number AS landlord_phone
    FROM booking_requests br
    JOIN users t ON br.tenant_id = t.id
    JOIN property_unit_details pud ON br.unit_detail_id = pud.unit_detail_id
    JOIN properties p ON pud.property_id = p.property_id
    JOIN users l ON p.landlord_id = l.id
    WHERE LOWER(br.status) = 'pending'
    ORDER BY br.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$pendingBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ================== FETCH PREVIOUS BOOKINGS ==================
$stmt2 = $conn->prepare("
    SELECT 
        br.booking_id, br.unit_detail_id, br.unit_code, br.room_type, br.amount, 
        br.status AS booking_status, br.payment_status, br.created_at,
        t.full_name AS tenant_name, t.phone_number AS tenant_phone,
        pud.status AS unit_status,
        l.full_name AS landlord_name, l.phone_number AS landlord_phone
    FROM booking_requests br
    JOIN users t ON br.tenant_id = t.id
    JOIN property_unit_details pud ON br.unit_detail_id = pud.unit_detail_id
    JOIN properties p ON pud.property_id = p.property_id
    JOIN users l ON p.landlord_id = l.id
    WHERE LOWER(br.status) != 'pending'
    ORDER BY br.created_at DESC
");
$stmt2->execute();
$result2 = $stmt2->get_result();
$previousBookings = $result2->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bookings - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        main {
            flex: 1;
            padding: 20px;
        }

        .navbar-brand {
            font-weight: bold;
        }

        .footer {
            background-color: #343a40;
            color: #fff;
            text-align: center;
            padding: 10px 0;
            margin-top: auto;
        }

        .badge-status {
            font-size: 0.8rem;
        }

        .search-container {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
            max-width: 500px;
        }

        .table-sm td,
        .table-sm th {
            padding: 0.3rem;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .scrollable-table {
            max-height: 400px;
            overflow-y: auto;
        }

        .fade-out {
            animation: fadeOut 1s ease forwards;
            animation-delay: 4s;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="adminDashboard.php" class="btn btn-outline-light btn-sm">Back</a>
            <span class="navbar-brand mx-auto">Bookings</span>
            <div style="width:75px;"></div>
        </div>
    </nav>

    <main class="container-fluid my-4">

        <!-- Success Message -->
        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success fade-out"><?= $successMsg ?></div>
        <?php endif; ?>

        <!-- Pending Bookings -->
        <h5>Pending Bookings</h5>
        <div class="search-container mb-2">
            <input type="text" id="searchPending" class="form-control form-control-sm"
                placeholder="Search by tenant, unit, landlord...">
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-sm align-middle" id="pendingTable">
                <thead class="table-dark">
                    <tr>
                        <th>Booking ID</th>
                        <th>Unit Code</th>
                        <th>Room Type</th>
                        <th>Amount (KSh)</th>
                        <th>Unit Status</th>
                        <th>Tenant Name</th>
                        <th>Tenant Phone</th>
                        <th>Landlord Name</th>
                        <th>Landlord Phone</th>
                        <th>Booking Status</th>
                        <th>Payment Status</th>
                        <th>Booked At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingBookings as $b): ?>
                        <tr data-search="<?= htmlspecialchars(strtolower($b['tenant_name'] . ' ' . $b['tenant_phone'] . ' ' . $b['unit_code'] . ' ' . $b['landlord_name'] . ' ' . $b['landlord_phone'])) ?>">
                            <td><?= htmlspecialchars($b['booking_id']) ?></td>
                            <td><?= htmlspecialchars($b['unit_code']) ?></td>
                            <td><?= htmlspecialchars($b['room_type']) ?></td>
                            <td><?= number_format($b['amount'], 2) ?></td>
                            <td>
                                <?php if ($b['unit_status'] === 'Vacant'): ?>
                                    <span class="badge bg-success badge-status">Vacant</span>
                                <?php elseif ($b['unit_status'] === 'Booked'): ?>
                                    <span class="badge bg-primary badge-status">Booked</span>
                                <?php else: ?>
                                    <span class="badge bg-danger badge-status"><?= htmlspecialchars($b['unit_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($b['tenant_name']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_phone']) ?></td>
                            <td><?= htmlspecialchars($b['landlord_name']) ?></td>
                            <td><?= htmlspecialchars($b['landlord_phone']) ?></td>
                            <td><span class="badge bg-warning text-dark badge-status"><?= htmlspecialchars($b['booking_status']) ?></span></td>
                            <td><span class="badge bg-info text-dark badge-status"><?= htmlspecialchars($b['payment_status']) ?></span></td>
                            <td><?= date("M d, Y H:i", strtotime($b['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination container -->
        <nav>
            <ul class="pagination pagination-sm mt-2" id="pendingPagination"></ul>
        </nav>

        <!-- Previous Bookings -->
        <h5 class="mt-4">Previous Bookings</h5>
        <div class="search-container mb-2">
            <input type="text" id="searchPrevious" class="form-control form-control-sm"
                placeholder="Search by tenant, unit, landlord...">
        </div>
        <div class="table-responsive scrollable-table">
            <table class="table table-striped table-bordered table-sm align-middle" id="previousTable">
                <thead class="table-dark">
                    <tr>
                        <th>Booking ID</th>
                        <th>Unit Code</th>
                        <th>Room Type</th>
                        <th>Amount (KSh)</th>
                        <th>Unit Status</th>
                        <th>Tenant Name</th>
                        <th>Tenant Phone</th>
                        <th>Landlord Name</th>
                        <th>Landlord Phone</th>
                        <th>Booking Status</th>
                        <th>Payment Status</th>
                        <th>Booked At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previousBookings as $b): ?>
                        <tr data-search="<?= htmlspecialchars(strtolower($b['tenant_name'] . ' ' . $b['tenant_phone'] . ' ' . $b['unit_code'] . ' ' . $b['landlord_name'] . ' ' . $b['landlord_phone'])) ?>">
                            <td><?= htmlspecialchars($b['booking_id']) ?></td>
                            <td><?= htmlspecialchars($b['unit_code']) ?></td>
                            <td><?= htmlspecialchars($b['room_type']) ?></td>
                            <td><?= number_format($b['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($b['unit_status']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_name']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_phone']) ?></td>
                            <td><?= htmlspecialchars($b['landlord_name']) ?></td>
                            <td><?= htmlspecialchars($b['landlord_phone']) ?></td>
                            <td><?= htmlspecialchars($b['booking_status']) ?></td>
                            <td><?= htmlspecialchars($b['payment_status']) ?></td>
                            <td><?= date("M d, Y H:i", strtotime($b['created_at'])) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Are you sure you want to delete this booking?');">
                                    <input type="hidden" name="delete_booking_id" value="<?= htmlspecialchars($b['booking_id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination container -->
        <nav>
            <ul class="pagination pagination-sm mt-2" id="previousPagination"></ul>
        </nav>

    </main>

    <footer class="footer">&copy; <?= date("Y") ?> AccoFinda. All rights reserved.</footer>

    <script>
        function setupTableSearch(inputSelector, tableSelector, searchBtnSelector, resetBtnSelector) {
            const input = $(inputSelector);
            const rows = $(tableSelector + ' tbody tr');
            $(searchBtnSelector).on('click', function() {
                const val = input.val().toLowerCase();
                rows.each(function() {
                    $(this).toggle($(this).attr("data-search").indexOf(val) > -1);
                });
            });
            $(resetBtnSelector).on('click', function() {
                input.val('');
                rows.show();
            });
        }
        setupTableSearch('#searchPending', '#pendingTable', '#btnSearchPending', '#btnResetPending');
        setupTableSearch('#searchPrevious', '#previousTable', '#btnSearchPrevious', '#btnResetPrevious');
    </script>
    <script>
        function setupTableSearch(inputSelector, tableSelector, searchBtnSelector, resetBtnSelector) {
            const input = $(inputSelector);
            const rows = $(tableSelector + ' tbody tr');
            $(searchBtnSelector).on('click', function() {
                const val = input.val().toLowerCase();
                rows.each(function() {
                    $(this).toggle($(this).attr("data-search").indexOf(val) > -1);
                });
            });
            $(resetBtnSelector).on('click', function() {
                input.val('');
                rows.show();
            });
        }

        function setupPagination(tableSelector, paginationSelector, rowsPerPage = 10) {
            const table = document.querySelector(tableSelector);
            if (!table) return;

            const tbody = table.querySelector("tbody");
            const rows = tbody.querySelectorAll("tr");
            const pagination = document.querySelector(paginationSelector);
            let currentPage = 1;

            function displayTable(page) {
                const start = (page - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                rows.forEach((row, index) => {
                    row.style.display = (index >= start && index < end) ? "" : "none";
                });
            }

            function setup() {
                let pageCount = Math.ceil(rows.length / rowsPerPage);
                if (pageCount === 0) pageCount = 1;

                pagination.innerHTML = "";

                // --- First Button
                const firstLi = document.createElement("li");
                firstLi.className = "page-item " + (currentPage === 1 ? "disabled" : "");
                firstLi.innerHTML = `<a class="page-link" href="#">First</a>`;
                firstLi.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (currentPage !== 1) {
                        currentPage = 1;
                        displayTable(currentPage);
                        setup();
                    }
                });
                pagination.appendChild(firstLi);

                // --- Previous Button
                const prevLi = document.createElement("li");
                prevLi.className = "page-item " + (currentPage === 1 ? "disabled" : "");
                prevLi.innerHTML = `<a class="page-link" href="#">Previous</a>`;
                prevLi.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (currentPage > 1) {
                        currentPage--;
                        displayTable(currentPage);
                        setup();
                    }
                });
                pagination.appendChild(prevLi);

                // --- Page Numbers
                for (let i = 1; i <= pageCount; i++) {
                    const li = document.createElement("li");
                    li.className = "page-item " + (i === currentPage ? "active" : "");
                    li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                    li.addEventListener("click", function(e) {
                        e.preventDefault();
                        currentPage = i;
                        displayTable(currentPage);
                        setup();
                    });
                    pagination.appendChild(li);
                }

                // --- Next Button
                const nextLi = document.createElement("li");
                nextLi.className = "page-item " + (currentPage === pageCount ? "disabled" : "");
                nextLi.innerHTML = `<a class="page-link" href="#">Next</a>`;
                nextLi.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (currentPage < pageCount) {
                        currentPage++;
                        displayTable(currentPage);
                        setup();
                    }
                });
                pagination.appendChild(nextLi);

                // --- Last Button
                const lastLi = document.createElement("li");
                lastLi.className = "page-item " + (currentPage === pageCount ? "disabled" : "");
                lastLi.innerHTML = `<a class="page-link" href="#">Last</a>`;
                lastLi.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (currentPage !== pageCount) {
                        currentPage = pageCount;
                        displayTable(currentPage);
                        setup();
                    }
                });
                pagination.appendChild(lastLi);
            }

            displayTable(currentPage);
            setup();
        }


        // ✅ Initialize both tables
        setupTableSearch('#searchPending', '#pendingTable', '#btnSearchPending', '#btnResetPending');
        setupTableSearch('#searchPrevious', '#previousTable', '#btnSearchPrevious', '#btnResetPrevious');
        setupPagination('#pendingTable', '#pendingPagination', 10);
        setupPagination('#previousTable', '#previousPagination', 10);
    </script>
    <script>
        // ✅ Auto-search in table
        document.getElementById("searchPending").addEventListener("keyup", function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll("#pendingTable tbody tr");

            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? "" : "none";
            });
        });
        // ✅ Auto-search in Previous Bookings table
        document.getElementById("searchPrevious").addEventListener("keyup", function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll("#previousTable tbody tr");

            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? "" : "none";
            });
        });
    </script>

</body>

</html>