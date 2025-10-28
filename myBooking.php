<?php
session_start();
require '../config.php';

// ✅ Ensure tenant logged in
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    die("Please log in to view your bookings.");
}

// Handle booking cancellation in same file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $unit_id    = intval($_POST['unit_id']);

    $response = ["success" => false, "message" => "Error canceling booking."];

    if ($booking_id && $unit_id) {
        // ✅ Update booking status using tenant_id
        $stmt = $conn->prepare("UPDATE booking_requests SET status = 'Cancelled' WHERE booking_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $booking_id, $userId);
        if ($stmt->execute()) {
            // ✅ Free the unit
            $stmt2 = $conn->prepare("UPDATE property_unit_details SET status = 'Vacant' WHERE unit_detail_id = ?");
            $stmt2->bind_param("i", $unit_id);
            $stmt2->execute();
            $stmt2->close();

            $response = ["success" => true, "message" => "Booking cancelled successfully."];
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ✅ Fetch tenant's booked unit
$stmt = $conn->prepare("
    SELECT 
        br.booking_id, 
        br.unit_detail_id, 
        br.unit_code, 
        br.room_type, 
        br.status AS booking_status, 
        br.payment_status, 
        br.created_at, 
        br.amount,
        pud.status AS unit_status
    FROM booking_requests br
    JOIN property_unit_details pud 
        ON br.unit_detail_id = pud.unit_detail_id
    WHERE br.tenant_id = ? 
      AND br.status = 'Pending'
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result  = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Booking and Lease</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-size: 13px;
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
        }

        .footer {
            background-color: #000000ff;
            color: #fff;
            text-align: center;
            padding: 10px 0;
            margin-top: auto;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="tenant.php" class="btn btn-outline-light btn-sm d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <span class="navbar-brand mx-auto">My Booking and Lease</span>
            <div style="width:75px;"></div>
        </div>
    </nav>

    <main class="container">
        <?php if ($booking): ?>
            <div class="table-responsive mb-4">
                <table class="table table-bordered table-striped align-middle bg-white shadow-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Unit Code</th>
                            <th>Room Type</th>
                            <th>Amount</th>
                            <th>Booking Status</th>
                            <th>Unit Status</th>
                            <th>Payment Status</th>
                            <th>Booked At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($booking['unit_code']) ?></td>
                            <td><?= htmlspecialchars($booking['room_type']) ?></td>
                            <td><strong>KSh <?= number_format($booking['amount'], 2) ?></strong></td>
                            <td><?= htmlspecialchars($booking['booking_status']) ?></td>
                            <td><?= htmlspecialchars($booking['unit_status']) ?></td>
                            <td><?= htmlspecialchars($booking['payment_status']) ?></td>
                            <td><?= date("M d, Y H:i", strtotime($booking['created_at'])) ?></td>
                            <td>
                                <button class="btn btn-danger btn-sm" id="cancelBookingBtn"
                                    data-booking-id="<?= $booking['booking_id'] ?>"
                                    data-unit-id="<?= $booking['unit_detail_id'] ?>">
                                    Cancel
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="bookingMsg"></div>
        <?php else: ?>
            <div class="alert alert-info d-flex align-items-center justify-content-center p-4 shadow-sm rounded-3 text-light" style="background: #2e2f30ff; border-left: 6px solid #fdd90dff;">
                <i class="bi bi-calendar-x me-2 fs-4 text-light"></i>
                <span class="fw-semibold">You have no active bookings at the moment.</span>
            </div>
        <?php endif; ?>

    </main>

    <footer class="footer">
        &copy; <?= date("Y") ?> AccoFinda. All rights reserved.
    </footer>

    <script>
        $(document).ready(function() {
            $('#cancelBookingBtn').on('click', function() {
                if (!confirm('Are you sure you want to cancel this booking?')) return;

                var bookingId = $(this).data('booking-id');
                var unitId = $(this).data('unit-id');

                $.ajax({
                    url: '', // same page
                    method: 'POST',
                    data: {
                        cancel_booking: true,
                        booking_id: bookingId,
                        unit_id: unitId
                    },
                    dataType: 'json',
                    success: function(res) {
                        $('#bookingMsg').html(
                            '<div class="alert ' + (res.success ? 'alert-success' : 'alert-danger') + '">' +
                            res.message + '</div>'
                        );
                        if (res.success) {
                            $('#cancelBookingBtn').closest('tr').remove();
                        }
                    },
                    error: function() {
                        $('#bookingMsg').html('<div class="alert alert-danger">An error occurred. Try again.</div>');
                    }
                });
            });
        });
    </script>
</body>

</html>