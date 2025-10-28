<?php
session_start();
require '../config.php';

// Access control: only landlords
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: ../login");
    exit();
}
include '../Shared/fetchNotification.php';
$landlordEmail = $_SESSION['email']; // Email is the unique ID

// --- Helper function for safe output
function e($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Fetch landlord details from DB
$stmt = $conn->prepare("SELECT full_name, email, phone_number FROM users WHERE email = ?");
$stmt->bind_param("s", $landlordEmail);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
if ($res) {
    $landlordName = $res['full_name'] ?? 'Landlord';
    $landlordPhone = $res['phone_number'] ?? '';
} else {
    $landlordName = $_SESSION['full_name'] ?? 'Landlord';
    $landlordPhone = $_SESSION['phone_number'] ?? '';
}
$stmt->close();

// --- 1) Stats
$totalProperties = 0;
$activeTenants = 0;
$pendingApplications = 0;
$monthlyIncome = 0.00;

// ✅ Landlord ID from session
$landlordId = $_SESSION['id'] ?? 0;

// --- Total properties (specific landlord)
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM properties WHERE landlord_id = ?");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$totalProperties = $res ? (int)$res['cnt'] : 0;
$stmt->close();

// --- Pending applications (specific landlord using landlord_id)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt 
    FROM applications a
    JOIN properties p ON a.property_id = p.property_id
    WHERE p.landlord_id = ? AND LOWER(a.status) = 'pending'
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$pendingApplications = $res ? (int)$res['cnt'] : 0;
$stmt->close();

// --- Monthly income (occupied units * price for this landlord)
$stmt = $conn->prepare("
    SELECT IFNULL(SUM(pud.occupied_count * pu.price), 0) AS total
    FROM property_units pu
    JOIN properties p ON pu.property_id = p.property_id
    JOIN (
        SELECT property_unit_id, COUNT(*) AS occupied_count
        FROM property_unit_details
        WHERE LOWER(status) = 'occupied'
        GROUP BY property_unit_id
    ) pud ON pud.property_unit_id = pu.unit_id
    WHERE p.landlord_id = ?
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$monthlyIncome = $res ? (float)$res['total'] : 0;
$stmt->close();

// --- Fetch properties with units and unit details
$properties = [];
$stmt = $conn->prepare("SELECT * FROM properties WHERE landlord_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$result = $stmt->get_result();

while ($property = $result->fetch_assoc()) {
    $property_id = $property['property_id'];

    // Fetch property units
    $units_stmt = $conn->prepare("SELECT * FROM property_units WHERE property_id = ?");
    $units_stmt->bind_param("i", $property_id);
    $units_stmt->execute();
    $units_result = $units_stmt->get_result();

    $units = [];
    while ($unit = $units_result->fetch_assoc()) {
        $unit_id = $unit['unit_id'] ?? 0;

        // Fetch unit details for this unit
        $details_stmt = $conn->prepare("SELECT * FROM property_unit_details WHERE property_unit_id = ?");
        $details_stmt->bind_param("i", $unit_id);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();

        $unit_details = [];
        while ($detail = $details_result->fetch_assoc()) {
            $unit_details[] = $detail;
        }
        $details_stmt->close();

        $unit['unit_details'] = $unit_details;
        $units[] = $unit;
    }
    $units_stmt->close();

    $property['units'] = $units;
    $properties[] = $property;
}
$stmt->close();

// Fetch Pending + Active Bookings
$stmt = $conn->prepare("
    SELECT br.booking_id, br.unit_detail_id, br.unit_code, br.room_type, 
           br.amount, br.status, br.payment_status, br.created_at,
           pud.status AS unit_status,
           u.full_name AS tenant_name, u.phone_number AS tenant_phone
    FROM booking_requests br
    JOIN property_unit_details pud ON br.unit_detail_id = pud.unit_detail_id
    JOIN users u ON br.tenant_id = u.id
    WHERE br.owner_id = ? AND LOWER(br.status) IN ('pending', 'active')
    ORDER BY br.created_at DESC
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$result = $stmt->get_result();
$pendingBookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ✅ Fetch Active Tenants (specific landlord, status = approved)
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT u.id) AS total
    FROM booking_requests br
    JOIN users u ON br.tenant_id = u.id
    WHERE br.status = 'approved' AND br.owner_id = ?
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$activeTenants = $res['total'] ?? 0;
$stmt->close();



// Fetch Pending Applications (status = pending)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM booking_requests
    WHERE status = 'pending' AND owner_id = ?
");
$stmt->bind_param("i", $landlordId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$pendingApplications = $res['total'] ?? 0;
$stmt->close();

?>


<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <title>Accofinda • Landlord Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f9;
            font-size: 12px;
        }

        .main {
            flex: 1;
            padding: 30px 0 30px 8px;
            /* ✅ remove right padding, small left gap only */
            flex-direction: column;
            background-color: #f8f9fa;
        }

        .dashboard {
            display: flex;
            flex: 1;
        }

        .navbar {
            width: 100% !important;
            /* ✅ full width */
            margin-right: 0 !important;
            padding-right: 0.5rem !important;
            /* keep only internal padding */
        }

        .sidebar {
            background: linear-gradient(180deg, #3b4042ff, #515652ff);
            min-height: 100vh;
            color: #ffffff;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .sidebar a {
            color: white;
            display: flex;
            padding: 12px 18px;
            text-decoration: none;
        }

        .sidebar a:hover {
            background: rgba(0, 0, 0, 1);
        }

        /* ---------- Stat Cards ---------- */
        .stat-card {
            border-radius: 10px;
            padding: 15px;
            color: white;
            height: 130px;
            /* Uniform height */
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            /* prevent flex shrinking issues */
            margin-right: 0;
            /* flush with right edge */
        }

        .stat-card h3,
        .stat-card h6 {
            margin: 5px 0 0 0;
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.9;
            flex-shrink: 0;
            /* icon never shrinks */
        }

        .stat-card button {
            white-space: nowrap;
            /* prevent wrapping */
        }

        /* Inner row inside card: no wrapping */
        .stat-card .d-flex.align-items-center.justify-content-between {
            flex-wrap: nowrap;
        }

        /* Left content (button + h3/h6) flexible */
        .stat-card>.d-flex>div:first-child {
            flex: 1;
            min-width: 0;
            /* allow truncation if needed */
        }

        /* Small gap ONLY from sidebar + top nav */
        .stats-row {
            padding-left: 8px;
            /* sidenav gap */
            padding-right: 0;
            /* flush right */
            padding-top: 8px;
            /* top nav gap */
        }

        /* ---------- Property Cards ---------- */
        .property-card {
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
            margin-left: 8px;
            /* small sidenav spacing */
            margin-right: 0;
            /* flush with right edge */
            width: calc(100% - 8px);
            /* stretch to right */
        }

        /* Images inside property cards */
        .property-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
        }

        /* Mobile fix: stack properly without shrinking */
        @media (max-width: 576px) {
            .property-card {
                width: 100%;
                margin-left: 0;
                /* full width on small screens */
            }

            .property-card .card-body {
                flex-direction: column !important;
            }
        }

        /* ---------- Misc ---------- */
        .small-muted {
            color: #ffffffff;
            font-size: 1.1rem;
        }

        .actions .btn {
            padding: .35rem .6rem;
        }

        .profile-card {
            background: rgba(0, 0, 0, 1);
            border-radius: 10px;
            padding: 10px;
            font-size: 0.75rem;
            color: white;
        }

        .profile-card i {
            color: #ffffffff;
            margin-right: 5px;
        }

        .profile-card h5 {
            font-weight: bold;
            font-size: 1rem;
        }

        footer {
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 15px 10px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* -------------------- RESPONSIVE ADJUSTMENTS -------------------- */
        @media (max-width: 575px) {

            /* 2 cards per row */
            .col-6 {
                flex: 0 0 48%;
                max-width: 48%;
            }

            .stat-card {
                margin-bottom: 10px;
                /* gap between rows */
            }

            .property-card {
                width: 100%;
            }
        }
    </style>

</head>

<body>


    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="col-md-2 sidebar">
            <!-- Profile Section -->
            <div class="sidebar-profile text-left p-3">
                <img src="../Images/AccofindaLogo1.jpg"
                    alt="Accofinda Logo"
                    class="mb-3 sidebar-logo"
                    width="40" height="40">

                <div class="profile-card p-2">
                    <h5 class="mb-1"><?php echo e($landlordName); ?></h5>
                    <p class="mb-1">
                        <i class="fa fa-envelope"></i> <?php echo e($landlordEmail); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fa fa-phone"></i> <?php echo e($landlordPhone); ?>
                    </p>
                </div>
            </div>

            <a href="landlord"><i class="fa fa-chart-line me-2" style="color:#FFD700;"></i> Dashboard</a>
            <a href="../Shared/addProperty"><i class="fa fa-plus me-2" style="color:#4CAF50;"></i> Add New Property</a>
            <a href="../Shared/propertyUnitsDetail"><i class="fa fa-edit me-2" style="color: #ffffffff;"></i> Update Units</a>
            <a href="propertyView"><i class="fa fa-home me-2" style="color:#00BFFF;"></i> My Properties</a>
            <a href="tenants"><i class="fa fa-users me-2" style="color:#FF69B4;"></i> Tenants</a>
            <a href="../Shared/broadcasts"> <i class="fa fa-bullhorn me-2" style="color:#FF4500;"></i> Broadcasts</a>
            <a href="tenantBookings"><i class="fa fa-file-alt me-2" style="color:#FFA500;"></i> Applications</a>
            <a href="payment"><i class="fa fa-credit-card me-2" style="color: #005effff;"></i> Payments</a>
            <a href="messages"><i class="fa fa-envelope me-2" style="color:#00CED1;"></i> Messages</a>
            <a href="maintenanceRequest"><i class="fa fa-tools me-2" style="color:#DC143C;"></i> Maintenance Requests</a>
            <a href="reports"><i class="fa fa-chart-pie me-2" style="color:#3CB371;"></i> Reports</a>
            <a href="../Shared/services" class="sidebar-link"><i class="fa fa-tools" style="color: #fd7e14;"></i> Service Providers</a>
            <a href="../Shared/updateProfile"><i class="fa fa-user-edit me-2" style="color:#198754;"></i> Update Profile</a>
            <a href="../Shared/support"><i class="fa fa-headset me-2" style="color:#1E90FF;"></i> Contact Support</a>
            <a href="../logout"
                class="mt-3 d-block text-center"
                style="background-color: #972e2eff; color:#fff; max-width: 200px;padding:10px 10px; border-radius:8px; text-decoration:none; font-weight:500;">
                <i class="fa fa-sign-out-alt me-2"></i> Logout
            </a>
        </aside>

        <!-- Main area -->
        <main class="main" class="content flex-grow-1 d-flex flex-column" style="margin: 0; padding: 0;">
            <!-- Top navbar (starts next to sidebar) -->
            <nav class="navbar navbar-expand-lg navbar-dark bg-secondary w-100" style="margin:0; padding:0.5rem 1rem;">
                <div class="d-flex align-items-center">
                    <div class="avatar-circle bg-dark text-white d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;border-radius:50%;font-weight:600;">
                        <?php
                        $parts = explode(' ', trim($landlordName));
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                        echo e($initials);
                        ?>
                    </div>
                    <div class="text-white">
                        <div class="fw-bold">Welcome, <?php echo e($landlordName); ?></div>
                        <small class="text-light"><?php echo e(ucfirst($_SESSION['role'])); ?></small>
                    </div>
                </div>

                <div class="ms-auto d-flex align-items-center">
                    <button class="btn btn-success btn-sm me-2"><i></i> Online</button>

                    <div class="dropdown">
                        <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="dropdown">
                            <i class="fa fa-bars"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../Shared/updateProfile"><i class="fa fa-user-edit me-2"></i> Edit Profile</a></li>
                            <li><a class="dropdown-item text-danger" href="../logout"><i class="fa fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>

            <!-- Page content -->

            <!-- Stats -->
            <div class="row g-3 mb-4 stats-row">
                <!-- Card 1 -->
                <!-- Card 1: Total Properties -->
                <div class="col-6 col-md-3 px-1">
                    <a href="propertyView.php" class="text-decoration-none">
                        <div class="stat-card position-relative"
                            style="background: linear-gradient(135deg, #524133, #6a503e); padding: 1rem; border-radius: 8px; color: white; cursor: pointer;">

                            <!-- Top-left label -->
                            <button type="button" class="btn btn-sm position-absolute top-0 start-0 m-2"
                                style="background-color: black; color: white; font-size: 0.7rem; cursor: default;" disabled>
                                Total Properties
                            </button>

                            <!-- Content -->
                            <div class="d-flex align-items-center justify-content-between mt-4">
                                <h3><?php echo e($totalProperties); ?></h3>
                                <div class="stat-icon"><i class="fa fa-building fa-lg"></i></div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Card 2: Active Tenants -->
                <div class="col-6 col-md-3 px-1">
                    <a href="tenants.php" class="text-decoration-none">
                        <div class="stat-card position-relative"
                            style="background: linear-gradient(135deg, #3e3353, #5a4a70); padding: 1rem; border-radius: 8px; color: white; cursor: pointer;">

                            <button type="button" class="btn btn-sm position-absolute top-0 start-0 m-2"
                                style="background-color: black; color: white; font-size: 0.7rem; cursor: default;" disabled>
                                Active Tenants
                            </button>

                            <div class="d-flex align-items-center justify-content-between mt-4">
                                <h3><?php echo $activeTenants; ?></h3>
                                <div class="stat-icon"><i class="fa fa-users fa-lg"></i></div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Card 3: Pending Applications -->
                <div class="col-6 col-md-3 px-1">
                    <a href="tenantBookings.php" class="text-decoration-none">
                        <div class="stat-card position-relative"
                            style="background: linear-gradient(135deg, #354539, #49624a); padding: 1rem; border-radius: 8px; color: white; cursor: pointer;">

                            <button type="button" class="btn btn-sm position-absolute top-0 start-0 m-2"
                                style="background-color: black; color: white; font-size: 0.7rem; cursor: default;" disabled>
                                Pending Applications
                            </button>

                            <div class="d-flex align-items-center justify-content-between mt-4">
                                <h3><?php echo $pendingApplications; ?></h3>
                                <div class="stat-icon"><i class="fa fa-file-alt fa-lg"></i></div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Card 4 -->
                <div class="col-6 col-md-3 px-1">
                    <div class="stat-card position-relative" style="background: linear-gradient(135deg, #50575f, #6b7077); padding: 1rem; border-radius: 8px; color: white;">

                        <button type="button" class="btn btn-sm position-absolute top-0 start-0 m-2"
                            style="background-color: black; color: white; font-size: 0.7rem; cursor: default;" disabled>
                            Monthly Income
                        </button>

                        <div class="d-flex align-items-center justify-content-between mt-4">
                            <div>
                                <h6 id="monthlyIncomeValue" style="visibility: hidden;">
                                    <?= 'Ksh ' . number_format($monthlyIncome, 2); ?>
                                </h6>
                                <button id="toggleIncomeBtn" type="button" class="btn btn-sm mt-1 px-2"
                                    style="background-color: black; color: white; font-size: 0.75rem; border: none;">
                                    Show
                                </button>
                            </div>
                            <div class="stat-icon"><i class="fa fa-shilling-sign fa-lg"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Properties Section -->
            <div class="section-card ms-1 me-1"> <!-- added small margins like stat cards -->
                <h4>🏠 Your Available Properties</h4>

                <?php if (empty($properties)): ?>
                    <div class="alert alert-warning text-center shadow-sm"
                        style="border: 1px solid;">
                        <i class="fa fa-info-circle"></i> No Properties Found.
                    </div>
                <?php else: ?>
                    <?php foreach ($properties as $property): ?>
                        <?php
                        $title         = e($property['title'] ?? 'Untitled');
                        $description   = e($property['description'] ?? 'No description available.');
                        $address       = e($property['address'] ?? '');
                        $city          = e($property['city'] ?? '');
                        $state         = e($property['state'] ?? '');
                        $postal_code   = e($property['postal_code'] ?? '');
                        $createdAt     = !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'Unknown date';
                        $property_id   = (int)($property['property_id'] ?? 0);

                        // Availability badge
                        $availability_status = strtolower(trim($property['availability_status'] ?? 'unknown'));
                        $availabilityClass = match ($availability_status) {
                            'available' => 'bg-success',
                            'occupied'  => 'bg-danger',
                            'booked'    => 'bg-warning text-dark',
                            'under maintenance' => 'bg-secondary',
                            default     => 'bg-dark'
                        };

                        // Approval badge
                        $approval_status = ucfirst(strtolower(trim($property['status'] ?? 'Pending')));
                        $approvalBadgeClass = match (strtolower($approval_status)) {
                            'approved'  => 'bg-success',
                            'pending'   => 'bg-warning text-dark',
                            'suspended' => 'bg-danger',
                            default     => 'bg-secondary'
                        };

                        // Unit summary
                        $statusCounts = [];
                        foreach ($property['units'] ?? [] as $unit) {
                            $roomType = $unit['room_type'] ?? 'Unknown';
                            if (!isset($statusCounts[$roomType])) {
                                $statusCounts[$roomType] = ['occupied' => 0, 'booked' => 0, 'vacant' => 0];
                            }
                            foreach ($unit['unit_details'] ?? [] as $detail) {
                                $status = strtolower($detail['status'] ?? 'vacant');
                                if (isset($statusCounts[$roomType][$status])) {
                                    $statusCounts[$roomType][$status]++;
                                }
                            }
                        }
                        ?>

                        <!-- Responsive Property Card -->
                        <div class="card mb-3 property-card position-relative" style="background-color: #000; color: #fff; border-radius: 10px;">
                            <div class="card-body row g-3">

                                <!-- Left column -->
                                <div class="col-12 col-md-6">
                                    <h5 class="card-title mb-2 d-flex align-items-center flex-wrap">
                                        <?= $title ?>
                                        <span class="badge <?= $approvalBadgeClass ?> ms-2"
                                            style="font-size: 0.8rem; padding: 0.4em 0.7em; border-radius: 0.4rem;">
                                            <?= $approval_status ?>
                                        </span>
                                    </h5>

                                    <p class="card-text mb-2"><?= $description ?></p>

                                    <p class="card-text">
                                        <strong>📍 Location:</strong> <?= $address ?>, <?= $city ?>, <?= $state ?> <?= $postal_code ?><br>
                                        <strong>🕒 Added on:</strong> <?= $createdAt ?><br>
                                    </p>

                                    <?php if (!empty($property['units'])): ?>
                                        <div class="units-list" style="max-height: 250px; overflow-y: auto;">
                                            <h6>Units and Status:</h6>
                                            <table class="table table-dark table-striped table-sm" style="font-size: 0.85rem;">
                                                <thead>
                                                    <tr>
                                                        <th>Room Type</th>
                                                        <th>Units Available</th>
                                                        <th>Status</th>
                                                        <th>Price (Ksh)</th>
                                                        <th>Electricity Service</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($property['units'] as $unit): ?>
                                                        <tr>
                                                            <td><?= e($unit['room_type'] ?? 'N/A') ?></td>
                                                            <td><?= intval($unit['units_available'] ?? 0) ?></td>
                                                            <td><?= e($unit['status'] ?? 'Available') ?></td>
                                                            <td><?= number_format(floatval($unit['price'] ?? 0), 2) ?></td>
                                                            <td><?= e($unit['electricity_service'] ?? 'N/A') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p>No units available for this property.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Right column -->
                                <div class="col-12 col-md-6 p-3 text-light" style="background-color: #48544E; border-radius: 8px;">
                                    <h6 class="text-center bg-dark text-white py-2 rounded">Unit Details</h6>

                                    <?php if (!empty($property['units'])): ?>
                                        <div class="table-responsive" style="max-height: 160px; overflow-y: auto;">
                                            <table class="table table-dark table-striped table-sm text-center align-middle" style="font-size: 0.85rem;">
                                                <thead class="table-secondary text-dark sticky-top">
                                                    <tr>
                                                        <th>Room Type</th>
                                                        <th>Room ID</th>
                                                        <th>Room Code</th>
                                                        <th>Status</th>
                                                        <th>Last Updated</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($property['units'] as $unit): ?>
                                                        <?php foreach ($unit['unit_details'] as $detail): ?>
                                                            <tr>
                                                                <td><?= e($unit['room_type'] ?? 'N/A') ?></td>
                                                                <td><?= e($detail['unit_detail_id'] ?? 'N/A') ?></td>
                                                                <td><?= e($detail['unit_code'] ?? 'N/A') ?></td>
                                                                <td><?= e(ucfirst($detail['status'] ?? 'N/A')) ?></td>
                                                                <td><?= !empty($detail['last_updated']) ? date("M d, Y", strtotime($detail['last_updated'])) : 'N/A' ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <h6 class="mt-3 text-center bg-dark text-white py-2 rounded">Room Type Summary</h6>
                                        <ul class="list-unstyled ps-3">
                                            <?php foreach ($statusCounts as $roomType => $counts): ?>
                                                <li>
                                                    <strong><?= e($roomType) ?></strong>:
                                                    Occupied = <?= $counts['occupied'] ?? 0 ?>,
                                                    Booked = <?= $counts['booked'] ?? 0 ?>,
                                                    Vacant = <?= $counts['vacant'] ?? 0 ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-center">No detailed unit data available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Bottom Row -->
                            <div class="d-flex justify-content-between align-items-center mt-3 px-3 pb-3 flex-wrap gap-2">
                                <span class="badge <?= $availabilityClass ?>" style="font-size: 0.85rem; padding: 0.5em 0.75em; border-radius: 0.5rem;">
                                    <?= ucfirst($availability_status) ?>
                                </span>

                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="../Shared/editProperty?property_id=<?= $property_id ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                                    <a href="../Shared/propertyUnitsDetail?property_id=<?= $property_id ?>" class="btn btn-sm btn-success">🔄 Update</a>
                                    <form action="../Shared/deleteProperty" method="POST" onsubmit="return confirm('Are you sure you want to delete this property? This action cannot be undone.')">
                                        <input type="hidden" name="property_id" value="<?= $property_id ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <!-- Pending Bookings -->
            <div class="mt-5">
                <div class="card-header bg-dark text-white" style="padding: 1.0rem 1rem;">
                    <h5 class="mb-2">Pending Bookings</h5>
                </div>
                <?php if (count($pendingBookings) === 0): ?>
                    <div class="alert alert-secondary">No pending bookings at the moment.</div>
                <?php else: ?>
                    <div class="table-responsive scrollable-table">
                        <table id="pendingTable" class="table table-striped table-bordered table-sm align-middle">
                            <thead class="table-primary">
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
                                    <tr id="bookingRow<?= $b['booking_id'] ?>">
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
                <?php endif; ?>

            </div>

        </main>
    </div>

    <script>
        const incomeEl = document.getElementById('monthlyIncomeValue');
        const toggleBtn = document.getElementById('toggleIncomeBtn');

        toggleBtn.addEventListener('click', () => {
            if (incomeEl.style.visibility === 'hidden') {
                incomeEl.style.visibility = 'visible';
                toggleBtn.textContent = 'Hide';
            } else {
                incomeEl.style.visibility = 'hidden';
                toggleBtn.textContent = 'Show';
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Footer -->
    <footer>
        &copy; <?= date('Y') ?>All Rights Reserved. Accofinda
    </footer>
</body>

</html>