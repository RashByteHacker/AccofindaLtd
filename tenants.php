<?php
session_start();
require '../config.php';

// ✅ Access control: landlords only
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: login.php");
    exit();
}

$landlordId = $_SESSION['id'] ?? 0; // use landlord's ID instead of email

// ✅ Helper escape function
function e($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ✅ Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tenant_id'], $_POST['unit_detail_id'])) {
    $bookingId    = intval($_POST['delete_tenant_id']);
    $unitDetailId = intval($_POST['unit_detail_id']);

    // Delete booking (by landlord ID for safety)
    $stmt = $conn->prepare("DELETE FROM booking_requests WHERE booking_id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $bookingId, $landlordId);
    $stmt->execute();
    $stmt->close();

    // Mark unit as vacant
    $stmt2 = $conn->prepare("UPDATE property_unit_details SET status = 'Vacant' WHERE unit_detail_id = ?");
    $stmt2->bind_param("i", $unitDetailId);
    $stmt2->execute();
    $stmt2->close();

    // Success message
    $_SESSION['success_msg'] = "Tenant removed successfully and unit marked as vacant.";

    // Refresh page
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Fetch tenants with booking status = 'Approved'
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
        u.full_name AS tenant_name, 
        u.email AS tenant_email, 
        u.phone_number AS tenant_phone
    FROM booking_requests br
    JOIN users u ON br.tenant_id = u.id
    WHERE br.owner_id = ? 
      AND LOWER(br.status) = 'approved'
    ORDER BY br.created_at DESC
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$result = $stmt->get_result();
$tenants = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approved Tenants</title>
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f9fafb;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main.container {
            flex: 1 0 auto;
            padding-top: 80px;
            padding-bottom: 40px;
        }

        footer {
            flex-shrink: 0;
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 12px 10px;
            font-size: 0.9rem;
        }

        table thead {
            background-color: #343a40;
            color: #fff;
        }

        table tbody tr:hover {
            background-color: #e9ecef;
        }

        .search-container {
            margin-bottom: 15px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .scrollable-table {
            max-height: 500px;
            overflow-y: auto;
        }

        table {
            font-size: 0.875rem;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark fixed-top bg-dark">
        <div class="container-fluid">
            <a href="landlord" class="btn btn-outline-light btn-sm">
                <i class="fa fa-arrow-left"></i> Back
            </a>
            <span class="navbar-brand mx-auto fs-4 fw-bold text-center">
                <i class="fa fa-users"></i> Approved Tenants
            </span>
            <div style="width:75px;"></div>
        </div>
    </nav>

    <main class="container">
        <h2 class="mb-4 text-primary"><i class="fa fa-building-user"></i> Approved Tenants</h2>

        <!-- Success message -->
        <?php if (!empty($_SESSION['success_msg'])): ?>
            <div id="successAlert" class="alert alert-success text-center shadow-sm">
                <i class="fa fa-check-circle"></i> <?= e($_SESSION['success_msg']) ?>
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (!empty($tenants)): ?>
            <div class="search-container">
                <input type="text" id="tenantSearch" class="form-control form-control-sm w-50" placeholder="Search tenants by name, phone, email, unit, or room type">
                <div>
                    <button id="btnSearchTenant" class="btn btn-primary btn-sm me-2">Search</button>
                    <button id="btnResetTenant" class="btn btn-secondary btn-sm">Reset</button>
                </div>
            </div>

            <div class="table-responsive shadow-sm rounded scrollable-table">
                <table id="tenantTable" class="table table-hover align-middle bg-white mb-0">
                    <thead class="table-dark text-white">
                        <tr>
                            <th>#</th>
                            <th>Tenant Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Room Type</th>
                            <th>Unit Code</th>
                            <th>Amount</th>
                            <th>Payment Status</th>
                            <th>Booked At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $index => $t): ?>
                            <tr>
                                <th scope="row"><?= $index + 1 ?></th>
                                <td><?= e($t['tenant_name']) ?></td>
                                <td><a href="mailto:<?= e($t['tenant_email']) ?>"><?= e($t['tenant_email']) ?></a></td>
                                <td><?= e($t['tenant_phone']) ?></td>
                                <td><?= e($t['room_type']) ?></td>
                                <td><?= e($t['unit_code']) ?></td>
                                <td><?= number_format($t['amount'], 2) ?></td>
                                <td><?= e($t['payment_status']) ?></td>
                                <td><?= !empty($t['created_at']) ? date("M d, Y H:i", strtotime($t['created_at'])) : 'N/A' ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to remove this tenant?');">
                                        <input type="hidden" name="delete_tenant_id" value="<?= $t['booking_id'] ?>">
                                        <input type="hidden" name="unit_detail_id" value="<?= $t['unit_detail_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fa fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center shadow-sm"
                style="border: 1px solid black;">
                <i class="fa fa-info-circle"></i> No approved tenants are currently registered under your properties.
            </div>

        <?php endif; ?>
    </main>

    <footer>
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved
    </footer>

    <script>
        $(document).ready(function() {
            // Hide success alert after 5s
            setTimeout(() => {
                $('#successAlert').fadeOut('slow');
            }, 5000);

            // Search button
            $('#btnSearchTenant').on('click', function() {
                const query = $('#tenantSearch').val().toLowerCase();
                $('#tenantTable tbody tr').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(query) > -1);
                });
            });

            // Reset
            $('#btnResetTenant').on('click', function() {
                $('#tenantSearch').val('');
                $('#tenantTable tbody tr').show();
            });

            // Instant search
            $('#tenantSearch').on('keyup', function() {
                const query = $(this).val().toLowerCase();
                $('#tenantTable tbody tr').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(query) > -1);
                });
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>