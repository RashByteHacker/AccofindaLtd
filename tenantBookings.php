<?php
session_start();
require '../config.php';

// ✅ Restrict to landlords
if (!isset($_SESSION['id']) || strtolower($_SESSION['role']) !== 'landlord') {
    die("Access denied. Please log in as landlord.");
}

$landlordId = (int) $_SESSION['id'];

// ✅ Fetch pending bookings
$stmt = $conn->prepare("
    SELECT 
        br.booking_id, 
        br.unit_detail_id, 
        br.unit_code, 
        br.room_type, 
        br.amount, 
        br.status, 
        br.payment_status, 
        br.created_at,
        pud.status AS unit_status,
        u.full_name AS tenant_name, 
        u.phone_number AS tenant_phone
    FROM booking_requests br
    JOIN property_unit_details pud ON br.unit_detail_id = pud.unit_detail_id
    JOIN users u ON br.tenant_id = u.id
    WHERE br.owner_id = ? AND LOWER(br.status) = 'pending'
    ORDER BY br.created_at DESC
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$result = $stmt->get_result();
$pendingBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ✅ Fetch approved bookings
$stmt = $conn->prepare("
    SELECT 
        br.booking_id, 
        br.unit_detail_id, 
        br.unit_code, 
        br.room_type, 
        br.amount, 
        br.status, 
        br.payment_status, 
        br.created_at,
        pud.status AS unit_status,
        u.full_name AS tenant_name, 
        u.phone_number AS tenant_phone
    FROM booking_requests br
    JOIN property_unit_details pud ON br.unit_detail_id = pud.unit_detail_id
    JOIN users u ON br.tenant_id = u.id
    WHERE br.owner_id = ? AND LOWER(br.status) = 'approved'
    ORDER BY br.created_at DESC
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$result = $stmt->get_result();
$approvedBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Bookings</title>
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main {
            flex: 1;
            padding: 20px;
        }

        .navbar-brand {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            font-weight: bold;
        }

        .table th,
        .table td {
            vertical-align: middle;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .table-sm td,
        .table-sm th {
            padding: 0.3rem;
        }

        .footer {
            background-color: #343a40;
            color: #fff;
            text-align: center;
            padding: 10px 0;
            margin-top: auto;
        }

        .btn-action {
            font-size: 0.75rem;
            padding: 2px 6px;
        }

        .search-container {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .scrollable-table {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="landlord.php" class="btn btn-outline-light btn-sm d-flex align-items-center">&larr; Back</a>
            <span class="navbar-brand mx-auto">Manage Bookings</span>
            <div style="width:75px;"></div>
        </div>
    </nav>

    <main class="container">
        <div id="bookingMsg" class="alert text-center" style="display:none;"></div>

        <h5>Pending Bookings</h5>
        <div class="search-container mb-2">
            <input type="text" id="searchInputPending" class="form-control form-control-sm" placeholder="Search pending bookings...">
        </div>

        <div class="table-responsive scrollable-table">
            <table id="pendingTable" class="table table-striped table-bordered table-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Booking ID</th>
                        <th>Unit Code</th>
                        <th>Room Type</th>
                        <th>Amount</th>
                        <th>Unit Status</th>
                        <th>Tenant Name</th>
                        <th>Tenant Phone</th>
                        <th>Booking Status</th>
                        <th>Payment Status</th>
                        <th>Booked At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingBookings as $b): ?>
                        <tr id="bookingRow<?= $b['booking_id'] ?>" data-search="<?= strtolower($b['booking_id'] . ' ' . $b['unit_code'] . ' ' . $b['room_type'] . ' ' . $b['tenant_name'] . ' ' . $b['tenant_phone'] . ' ' . $b['status'] . ' ' . $b['payment_status']) ?>">
                            <td><?= htmlspecialchars($b['booking_id']) ?></td>
                            <td><?= htmlspecialchars($b['unit_code']) ?></td>
                            <td><?= htmlspecialchars($b['room_type']) ?></td>
                            <td><?= number_format($b['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($b['unit_status']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_name']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_phone']) ?></td>
                            <td><?= htmlspecialchars($b['status']) ?></td>
                            <td><?= htmlspecialchars($b['payment_status']) ?></td>
                            <td><?= date("M d, Y H:i", strtotime($b['created_at'])) ?></td>
                            <td>
                                <button class="btn btn-success btn-action approveBtn" data-id="<?= $b['booking_id'] ?>" data-unit="<?= $b['unit_detail_id'] ?>">Approve</button>
                                <button class="btn btn-danger btn-action rejectBtn" data-id="<?= $b['booking_id'] ?>" data-unit="<?= $b['unit_detail_id'] ?>">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h5 class="mt-4">Approved Bookings</h5>
        <div class="search-container mb-2">
            <input type="text" id="searchApproved" class="form-control form-control-sm" placeholder="Search by tenant, unit...">
        </div>

        <div class="table-responsive scrollable-table" style="max-height:400px; overflow-y:auto;">
            <table id="approvedTable" class="table table-striped table-bordered table-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Booking ID</th>
                        <th>Unit Code</th>
                        <th>Room Type</th>
                        <th>Amount</th>
                        <th>Unit Status</th>
                        <th>Tenant Name</th>
                        <th>Tenant Phone</th>
                        <th>Booking Status</th>
                        <th>Payment Status</th>
                        <th>Booked At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvedBookings as $b): ?>
                        <tr data-search="<?= htmlspecialchars(strtolower($b['booking_id'] . ' ' . $b['tenant_name'] . ' ' . $b['tenant_phone'] . ' ' . $b['unit_code'] . ' ' . $b['room_type'] . ' ' . $b['status'] . ' ' . $b['payment_status'])) ?>">
                            <td><?= htmlspecialchars($b['booking_id']) ?></td>
                            <td><?= htmlspecialchars($b['unit_code']) ?></td>
                            <td><?= htmlspecialchars($b['room_type']) ?></td>
                            <td><?= number_format($b['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($b['unit_status']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_name']) ?></td>
                            <td><?= htmlspecialchars($b['tenant_phone']) ?></td>
                            <td><?= htmlspecialchars($b['status']) ?></td>
                            <td><?= htmlspecialchars($b['payment_status']) ?></td>
                            <td><?= date("M d, Y H:i", strtotime($b['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <footer class="footer">&copy; <?= date("Y") ?> AccoFinda. All rights reserved.</footer>

    <script>
        function showMsg(message, success = true, duration = 2000) {
            const msg = $('#bookingMsg');
            msg.removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger');
            msg.text(message).fadeIn();
            setTimeout(() => {
                msg.fadeOut();
            }, duration);
        }

        // Approve/Reject buttons using delegation
        $('#pendingTable').on('click', '.approveBtn, .rejectBtn', function() {
            const bookingId = $(this).data('id');
            const unitId = $(this).data('unit');
            const action = $(this).hasClass('approveBtn') ? 'approve' : 'reject';
            const btn = $(this);
            btn.prop('disabled', true);

            $.post('landlordBookingAction', {
                booking_id: bookingId,
                unit_id: unitId,
                action: action
            }, function(res) {
                if (res.success) {
                    $('#bookingRow' + bookingId).fadeOut();
                    showMsg(res.message, true);
                } else {
                    showMsg(res.message, false, 3000);
                    btn.prop('disabled', false);
                }
            }, 'json').fail(function(xhr) {
                console.error(xhr.responseText);
                showMsg('Error processing request.', false, 3000);
                btn.prop('disabled', false);
            });
        });

        // Auto-search function
        function autoSearch(inputId, tableId) {
            const input = document.getElementById(inputId);
            input.addEventListener('input', () => {
                const query = input.value.toLowerCase();
                const rows = document.querySelectorAll(`#${tableId} tbody tr`);
                rows.forEach(row => {
                    const text = row.dataset.search || row.innerText.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
            });
        }

        // Initialize auto search for both tables
        autoSearch('searchInputPending', 'pendingTable');
        autoSearch('searchApproved', 'approvedTable');
    </script>
</body>

</html>