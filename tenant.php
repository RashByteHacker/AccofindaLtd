<?php
session_start();
require '../config.php';
include '../Shared/fetchNotification.php';
/**
 * ✅ Universal image URL helper
 */
if (!function_exists('getImageUrl')) {
    function getImageUrl($path)
    {
        if (empty($path)) {
            return '../assets/default-placeholder.jpeg';
        }

        $path = ltrim($path, '/');
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            return '../assets/default-placeholder.jpeg';
        }

        $fullPath = "../" . $path;
        return file_exists($fullPath) ? $fullPath : '../assets/default-placeholder.jpeg';
    }
}

/**
 * ✅ Escape output helper
 */
if (!function_exists('e')) {
    function e($v)
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

// ✅ Ensure tenant role & email
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'tenant' || empty($_SESSION['email'])) {
    header("Location: ../login.php");
    exit();
}

$tenantEmail = $_SESSION['email'];
$userId      = $_SESSION['user_id'] ?? 0;
$role        = $_SESSION['role'];

/**
 * ✅ Fetch tenant info
 */
$stmt = $conn->prepare("
    SELECT full_name, email, phone_number, profile_photo 
    FROM users 
    WHERE email = ?
");
$stmt->bind_param("s", $tenantEmail);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $tenantData       = $result->fetch_assoc();
    $tenantName       = $tenantData['full_name'] ?? 'Tenant Name';
    $tenantEmail      = $tenantData['email'] ?? $tenantEmail;
    $tenantPhone      = $tenantData['phone_number'] ?? 'N/A';
    $tenantProfilePic = getImageUrl($tenantData['profile_photo'] ?? null);
} else {
    $tenantName       = 'Tenant Name';
    $tenantEmail      = 'email@example.com';
    $tenantPhone      = 'N/A';
    $tenantProfilePic = getImageUrl(null);
}
$stmt->close();

/**
 * ✅ Fetch count of open maintenance requests
 */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS open_requests 
    FROM maintenance_requests 
    WHERE tenant_email = ? AND status = 'Open'
");
$stmt->bind_param("s", $tenantEmail);
$stmt->execute();
$openRequests = $stmt->get_result()->fetch_assoc()['open_requests'] ?? 0;
$stmt->close();

/**
 * ✅ Fetch unread broadcast messages
 */
$broadcastQuery = $conn->prepare("
    SELECT bm.id, bm.title, bm.message, bm.created_at
    FROM broadcastmessages bm
    WHERE (bm.target_role = ? OR bm.target_role = 'All')
      AND NOT EXISTS (
        SELECT 1 
        FROM messagereads mr 
        WHERE mr.user_id = ? 
          AND mr.message_id = bm.id
    )
    ORDER BY bm.created_at DESC
");
$broadcastQuery->bind_param("si", $role, $userId);
$broadcastQuery->execute();
$broadcastResult = $broadcastQuery->get_result();

/**
 * ✅ Fetch unread counts (broadcast + direct)
 */
$stmt = $conn->prepare("
    SELECT
        (
            SELECT COUNT(*)
            FROM broadcastmessages bm
            LEFT JOIN messagereads mr 
                ON bm.id = mr.message_id AND mr.user_id = ?
            WHERE (bm.target_role = ? OR bm.target_role = 'All') 
              AND mr.user_id IS NULL
        ) AS unread_broadcasts,
        (
            SELECT COUNT(*)
            FROM directmessages dm
            LEFT JOIN direct_message_reads dmr 
                ON dm.id = dmr.message_id AND dmr.user_id = ?
            WHERE dm.recipient_email = ? 
              AND dmr.user_id IS NULL
        ) AS unread_direct
");
$stmt->bind_param("isis", $userId, $role, $userId, $tenantEmail);
$stmt->execute();
$stmt->bind_result($unreadBroadcasts, $unreadDirect);
$stmt->fetch();
$stmt->close();

$unreadCount = ($unreadBroadcasts ?? 0) + ($unreadDirect ?? 0);

// ✅ Fetch Approved & Occupied booking
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
        p.title AS property_title,
        ud.status AS unit_status
    FROM booking_requests br
    LEFT JOIN property_unit_details ud ON br.unit_detail_id = ud.unit_detail_id
    LEFT JOIN properties p ON ud.property_id = p.property_id
    WHERE br.tenant_id = ?
      AND br.status = 'Approved'
      AND ud.status = 'Occupied'
    ORDER BY br.created_at DESC
    LIMIT 1
");
$stmt->bind_param("i", $userId); // ✅ use tenant_id instead of email
$stmt->execute();
$bookingResult = $stmt->get_result();

if ($bookingResult && $bookingResult->num_rows === 1) {
    $leaseInfo = $bookingResult->fetch_assoc();

    // ✅ Lease period: 1 year from created_at
    $leaseInfo['start_date'] = $leaseInfo['created_at'];
    $leaseInfo['end_date']   = date('Y-m-d H:i:s', strtotime($leaseInfo['created_at'] . ' +1 year'));
} else {
    $leaseInfo = [
        'property_title'  => 'N/A',
        'room_type'       => '',
        'unit_code'       => '',
        'amount'          => 0,
        'status'          => 'N/A',
        'payment_status'  => 'N/A',
        'created_at'      => null,
        'start_date'      => null,
        'end_date'        => null,
    ];
}
$stmt->close();

/**
 * ✅ Ensure recentActivities array
 */
$recentActivities = $recentActivities ?? [];
if (!empty($recentActivities)) {
    usort($recentActivities, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard - Accofinda</title>
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        /* Reset + Base */
        html,
        body {
            margin: 0;
            height: 100%;
            font-family: 'Segoe UI', sans-serif;
            background: #f6f6fb;
        }

        /* Dashboard wrapper (always side-by-side) */
        .dashboard {
            display: flex;
            min-height: 100vh;
            flex-direction: row;
            /* ✅ never stack */
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            /* ✅ fixed width */
            background: linear-gradient(180deg, #334047, #515652);
            color: #ffffff;
            padding: 30px 20px;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            font-size: 12px;
        }

        /* Sidebar links */
        .sidebar a {
            display: block;
            padding: 10px 20px;
            color: #ccc;
            text-decoration: none;
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: #000000;
            color: #fff;
            border-left: 4px solid #0d6efd;
        }

        /* Sidebar profile card */
        .sidebar-profile img.sidebar-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .profile-card {
            background: rgba(0, 0, 0, 1);
            border-radius: 10px;
            padding: 10px;
            font-size: 0.75rem;
            color: white;
            min-width: 200px;
            /* wider than default */
            max-width: 280px;
            /* optional, to prevent too wide */
            /* ensures it fills parent container */
        }

        /* Main content */
        .main {
            flex: 1;
            padding: 30px;

            flex-direction: column;
            background-color: #f8f9fa;
        }

        .avatar-circle {
            font-weight: 600;
            font-size: 1.1rem;
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        /* Footer */
        footer {
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 15px 10px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* Profile picture */
        .profile-pic {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #0d6efd;
        }

        /* Force row to behave like desktop always */
        .stats-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 15px;
        }

        .stat-col {
            flex: 1 0 25%;
            max-width: 25%;
        }

        .stat-card {
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
            color: #fff;
        }

        .stat-icon {
            font-size: 1.8rem;
            opacity: 0.85;
        }

        .stat-label {
            background-color: rgba(0, 0, 0, 0.7);
            color: #ffffff;
            padding: 0.1rem 0.4rem;
            font-size: 0.65rem;
            cursor: default;
            border-radius: 2px;
            border: red;
        }

        /* Shrink nicely on small screens instead of stacking */
        @media (max-width: 768px) {
            .stats-row {
                transform: scale(0.9);
                transform-origin: top left;
            }
        }

        @media (max-width: 480px) {
            .stats-row {
                transform: scale(0.8);
                transform-origin: top left;
            }
        }

        /* Broadcast scroll areas */
        .scrollable-broadcasts {
            max-height: 250px;
            font-size: 0.8rem;
            overflow-y: auto;
            padding-right: 10px;
            border: 1px solid #000;
            color: #000;
            border-radius: 8px;
            background-color: #fff;
        }

        .no-broadcasts-box {
            border: 1px solid #333;
            background-color: #fff;
            color: #666;
        }

        .broadcast-message-scroll {
            max-height: 3.5em;
            overflow-y: auto;
            padding-right: 6px;
            margin-bottom: 8px;
        }

        .broadcast-message-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .broadcast-message-scroll::-webkit-scrollbar-thumb {
            background-color: #aaa;
            border-radius: 2px;
        }

        /* Navbar full width */
        .navbar {
            width: 100%;
            white-space: nowrap;
            /* prevent wrapping */
            overflow: visible;
            /* allow dropdowns to escape navbar */
        }

        .navbar .container-fluid {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: nowrap;
            /* force single row always */
        }

        .navbar .btn {
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }

        .navbar .rounded-circle {
            width: 32px;
            height: 32px;
            min-width: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Force dropdown to show over main content */
        .navbar .dropdown-menu {
            position: absolute !important;
            /* force absolute positioning */
            top: 100%;
            /* below the button */
            right: 0;
            /* align right */
            z-index: 3000;
        }

        /* Disable Bootstrap collapse on small screens */
        @media (max-width: 768px) {
            .navbar-collapse {
                display: flex !important;
                flex-basis: auto !important;
            }
        }
    </style>


</head>


<body>

    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar position-relative">

            <!-- Profile Section -->
            <div class="sidebar-profile text-left p-3">
                <img src="../Images/AccofindaLogo1.jpg"
                    alt="Accofinda Logo"
                    class="mb-3 sidebar-logo"
                    width="40" height="40">

                <div class="profile-card p-2">
                    <h5 class="mb-1"><?php echo htmlspecialchars($tenantName); ?></h5>
                    <p class="mb-1">
                        <i class="fa fa-envelope"></i> <?php echo htmlspecialchars($tenantEmail); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fa fa-phone"></i> <?php echo htmlspecialchars($tenantPhone); ?>
                    </p>
                </div>
            </div>
            <nav>
                <a href="tenant" class="active"><i class="fa fa-chart-line me-2" style="color:#FFD700;"></i> Dashboard</a>
                <a href="myBooking"><i class="fa fa-file-contract me-2" style="color:#4CAF50;"></i> Booking & Lease</a>
                <a href="payments.php"><i class="fa fa-credit-card me-2" style="color: #3184ff;"></i> Payments</a>
                <a href="maintenanceRequests"><i class="fa fa-tools me-2" style="color:#DC143C;"></i> Maintenance Requests</a>
                <a href="../Shared/services"><i class="fa fa-microchip me-2" style="color: #fff;"></i> Service Providers</a>
                <a href="messages"><i class="fa fa-envelope me-2" style="color:#00CED1;"></i> Messages</a>
                <a href="notifications"><i class="fa fa-bell me-2" style="color: #fa3268;"></i> Notifications</a>
                <a href="profile.php"><i class="fa fa-user-cog me-2" style="color:#FF8C00;"></i> Account Settings</a>
                <a href="../Shared/updateProfile"><i class="fa fa-user-edit me-2" style="color:#198754;"></i> Update Profile</a>
                <a href="../Shared/support"><i class="fa fa-headset me-2" style="color:#1E90FF;"></i> Contact Support</a>
                <a href="../logout"
                    class="mt-3 d-block text-center"
                    style="background-color: #972e2e; color:#fff; padding:10px 15px; border-radius:8px; text-decoration:none; font-weight:500; max-width:230px">
                    <i class="fa fa-sign-out-alt me-2"></i> Logout
                </a>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="main" class="content flex-grow-1 d-flex flex-column" style="margin: 0; padding: 0;">
            <!-- Top navbar -->
            <nav class="navbar navbar-dark bg-secondary shadow-sm w-100 mb-2 p-0">
                <div class="container-fluid d-flex justify-content-between align-items-center px-3 py-2 flex-nowrap">

                    <!-- Left: Avatar + Welcome + Role -->
                    <div class="d-flex align-items-center flex-nowrap">
                        <div class="rounded-circle bg-light text-dark fw-bold d-flex align-items-center justify-content-center me-2"
                            style="width:32px; height:32px; min-width:32px;">
                            <?php
                            $parts = explode(' ', trim($tenantName));
                            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                            echo e($initials);
                            ?>
                        </div>

                        <div class="text-white small me-2">
                            <div>Welcome, <?= e($tenantName) ?>! 🌟</div>
                            <small class="text-light"><?= e(ucfirst($_SESSION['role'])) ?></small>
                        </div>

                        <span class="badge bg-success ms-2">Online</span>
                    </div>

                    <!-- Right: Brand + Messages Button + Hamburger -->
                    <div class="d-flex align-items-center gap-3 flex-nowrap">
                        <!-- Brand -->
                        <a class="navbar-brand fw-bold text-white me-2" href="#">Tenant Dashboard</a>

                        <!-- 📩 Messages Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-dark position-relative text-white"
                                type="button"
                                id="messageDropdown"
                                data-bs-toggle="dropdown"
                                data-bs-display="static"
                                aria-expanded="false"
                                onclick="markMessagesAsRead()">
                                <i class="fas fa-envelope"></i> Messages
                                <?php if ($unreadCount > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?= $unreadCount ?>
                                    </span>
                                <?php endif; ?>
                            </button>

                            <ul class="dropdown-menu shadow-lg p-2 dropdown-menu-end"
                                aria-labelledby="messageDropdown"
                                style="width:320px; max-height:270px; overflow-y:auto; z-index:3000;">
                                <?php if (empty($latestMessages)): ?>
                                    <li><span class="dropdown-item-text text-muted">No new messages</span></li>
                                <?php else: ?>
                                    <?php foreach ($latestMessages as $msg): ?>
                                        <li class="dropdown-item small">
                                            <div class="fw-semibold text-primary mb-1"><?= htmlspecialchars($msg['title']) ?></div>
                                            <div class="text-body small mb-1"><?= nl2br(htmlspecialchars(substr($msg['message'], 0, 70))) ?>...</div>
                                            <div class="text-muted small"><?= date('M j, Y • g:i A', strtotime($msg['created_at'])) ?></div>
                                        </li>
                                        <li>
                                            <hr class="dropdown-divider my-1">
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <!-- 🍔 Hamburger Menu -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-light" type="button" id="menuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-bars"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="menuDropdown">
                                <li>
                                    <a class="dropdown-item" href="../Shared/updateProfile">
                                        <i class="fa fa-user-edit me-2"></i> Update Profile
                                    </a>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="../logout">
                                        <i class="fa fa-sign-out-alt me-2"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>


            <!-- Page content -->
            <div class="p-4 flex-grow-1">
                <h6 class="mb-3"><i class="fa fa-tachometer-alt"></i> Tenant Dashboard</h6>

                <!-- Stats Row -->
                <div class="row g-4 mb-4">

                    <!-- Current Property -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card h-100" style="background: linear-gradient(135deg, #50575f, #6b7077); border-radius: 12px; padding: 1rem; color: white; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="badge bg-dark mb-1" style="font-size: 0.75rem;">Current Property</span>
                                    <h6 class="mb-0"><?= htmlspecialchars($leaseInfo['property_title'] ?? 'N/A') ?></h6>
                                    <small><?= htmlspecialchars($leaseInfo['room_type'] ?? '') ?> | <?= htmlspecialchars($leaseInfo['unit_code'] ?? '') ?></small>
                                </div>
                                <div class="stat-icon"><i class="fa fa-building fa-lg"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Lease Period -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card h-100" style="background: linear-gradient(135deg,#354539, #49624a); border-radius: 12px; padding: 1rem; color: white; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="badge bg-dark mb-1" style="font-size: 0.75rem;">Lease Period</span>
                                    <p class="mb-0 fw-semibold" style="font-size: 0.85rem;">
                                        <?php if (!empty($leaseInfo['start_date']) && !empty($leaseInfo['end_date'])): ?>
                                            <?= date("M d, Y", strtotime($leaseInfo['start_date'])) ?> - <?= date("M d, Y", strtotime($leaseInfo['end_date'])) ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="stat-icon"><i class="fa fa-calendar-alt fa-lg"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Rent Amount -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card h-100" style="background: linear-gradient(135deg, #524133, #6a503e); border-radius: 12px; padding: 1rem; color: white; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="badge bg-dark mb-1" style="font-size: 0.75rem;">Rent Amount</span>
                                    <h6 class="mb-0">Ksh <?= number_format(floatval($leaseInfo['amount'] ?? 0), 2) ?></h6>
                                    <small>Status: <?= htmlspecialchars($leaseInfo['payment_status'] ?? 'N/A') ?></small>
                                </div>
                                <div class="stat-icon"><i class="fa fa-dollar-sign fa-lg"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card h-100" style="background: linear-gradient(135deg,  #3e3353, #5a4a70); border-radius: 12px; padding: 1rem; color: white; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="badge bg-dark mb-1" style="font-size: 0.75rem;">Messages</span>
                                    <h6 class="mb-0"><?= $unreadMessages ?? 0 ?></h6>
                                </div>
                                <div class="stat-icon"><i class="fa fa-envelope fa-lg"></i></div>
                            </div>
                        </div>
                    </div>

                </div>


                <!-- Admin Broadcasts Section -->
                <div class="activities">
                    <h3 class="d-flex justify-content-between align-items-center">
                        📢 Broadcasts from Admins
                        <!-- You can add a button here if needed, for example: -->
                        <!-- <button class="btn btn-sm btn-outline-danger" id="clearBroadcastsBtn">Clear Broadcasts</button> -->
                    </h3>

                    <?php if ($broadcastResult->num_rows): ?>
                        <?php $scrollable = $broadcastResult->num_rows >= 1; ?>
                        <div class="<?= $scrollable ? 'scrollable-broadcasts' : '' ?>" style="margin-top: 5px;">
                            <?php while ($msg = $broadcastResult->fetch_assoc()): ?>
                                <div class="activity">
                                    <strong><?= htmlspecialchars($msg['title']) ?></strong>
                                    <div class="broadcast-message-scroll">
                                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                    </div>
                                    <div class="time">🕒 <?= date("F j, Y, g:i a", strtotime($msg['created_at'])) ?></div>
                                    <form method="POST" action="markRead">
                                        <input type="hidden" name="messageId" value="<?= $msg['id'] ?>">
                                        <button class="btn btn-sm btn-success">
                                            ✅ <span class="small">Mark as Read</span>
                                        </button>

                                    </form>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-broadcasts-box text-center p-4 rounded mt-3 w-100 mx-auto">
                            <h5 class="text-muted mb-2">📭 No Broadcasts Available</h5>
                            <p class="text-muted mb-0">No shared messages yet.Find past broadcasts in the notifications section</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Approved Properties Section -->
            <div class="approved-properties mt-4" style="margin-left:2rem; padding-right:2%; padding-bottom:750px;">

                <!-- Section Heading -->
                <h3 style="font-size:1.2rem; color:#fff; font-weight:bold; margin-bottom:0.8rem;">
                    <i class="fa fa-building"></i> Available & Approved Properties
                </h3>

                <!-- 🔍 Search Bar -->
                <div class="d-flex align-items-center mb-3" style="max-width:450px; gap:8px;">
                    <input type="text" id="propertySearch"
                        class="form-control form-control-sm bg-light text-dark"
                        style="border:1px solid #000;"
                        placeholder="Search by title, city, type, price...">
                </div>
                <?php
                $propStmt = $conn->prepare("
                    SELECT p.property_id, p.title, p.description, p.address, p.city, p.state, p.postal_code, 
               p.property_type, u.full_name AS landlord_name, p.image
                    FROM properties p
                    LEFT JOIN users u ON u.email = p.landlord_email
                         WHERE p.status = 'Approved'
                         ORDER BY p.created_at DESC
                                         ");
                $propStmt->execute();
                $propResult = $propStmt->get_result();
                ?>

                <?php if ($propResult->num_rows > 0): ?>
                    <div class="row g-3 property-section" id="propertyList" style="max-height:600px; overflow-y:auto;">
                        <?php while ($prop = $propResult->fetch_assoc()): ?>
                            <?php
                            // Get units
                            $unitsStmt = $conn->prepare("
                    SELECT pu.unit_id, pu.property_id, pu.room_type, pu.units_available, pu.price
                    FROM property_units pu
                    WHERE pu.property_id = ? AND pu.units_available > 0
                ");
                            $unitsStmt->bind_param('i', $prop['property_id']);
                            $unitsStmt->execute();
                            $unitsResult = $unitsStmt->get_result();
                            $units = $unitsResult->fetch_all(MYSQLI_ASSOC);
                            $unitsStmt->close();
                            ?>

                            <?php if (!empty($units)): ?>
                                <div class="col-md-6 col-lg-4 property-card"
                                    data-title="<?= strtolower($prop['title']) ?>"
                                    data-city="<?= strtolower($prop['city']) ?>"
                                    data-type="<?= strtolower($prop['property_type']) ?>">

                                    <div class="card h-100 shadow-sm position-relative"
                                        style="font-size:0.75rem; border-radius:10px; background-color:#1e1e1e; color:#fff; min-height:220px;">

                                        <!-- Location Icon (opens modal) -->
                                        <a href="javascript:void(0);"
                                            onclick="openMapModal('<?= htmlspecialchars($prop['address'] . ', ' . $prop['city'] . ', ' . $prop['state']) ?>')"
                                            class="position-absolute top-0 end-0 m-2 text-warning"
                                            style="z-index:10; font-size:1.2rem;">
                                            <i class="fa fa-map-marker-alt"></i>
                                        </a>

                                        <div class="row g-0">
                                            <div class="col-4">
                                                <?php
                                                $imgPath = $prop['image'] ?? '';
                                                if (!preg_match('/^https?:\/\//', $imgPath) && file_exists(__DIR__ . "/../" . $imgPath)) {
                                                    $imgPath = "../" . $imgPath;
                                                }
                                                if (empty($imgPath) || !file_exists(str_replace("../", __DIR__ . "/../", $imgPath))) {
                                                    $placeholderDir = __DIR__ . "/../Uploads/";
                                                    $placeholder = glob($placeholderDir . "placeholder.*");
                                                    $imgPath = !empty($placeholder) ? "../Uploads/" . basename($placeholder[0]) : "";
                                                }
                                                ?>
                                                <img src="<?= htmlspecialchars($imgPath) ?>"
                                                    class="img-fluid rounded-start"
                                                    alt="<?= htmlspecialchars($prop['title']) ?>"
                                                    style="height:100%; object-fit:cover;">
                                            </div>

                                            <div class="col-8">
                                                <div class="card-body p-2">
                                                    <h6 class="card-title mb-1">
                                                        <?= htmlspecialchars($prop['title']) ?>
                                                        <span class="badge bg-success" style="font-size:0.6rem;">Approved</span>
                                                    </h6>
                                                    <p class="mb-1 text-truncate"><?= htmlspecialchars($prop['description']) ?></p>
                                                    <p class="mb-1">
                                                        <small>📍 <?= htmlspecialchars($prop['address']) ?>, <?= htmlspecialchars($prop['city']) ?></small><br>
                                                        <small>🏢 <?= htmlspecialchars($prop['property_type']) ?> | 👤 <?= htmlspecialchars($prop['landlord_name']) ?></small>
                                                    </p>

                                                    <!-- Available Units -->
                                                    <div class="unit-list" style="max-height:140px; overflow-y:auto;">
                                                        <ul class="list-group list-group-flush mt-1" style="background:none">
                                                            <?php foreach ($units as $unit): ?>
                                                                <?php
                                                                $imgStmt = $conn->prepare("SELECT image_path FROM unit_images WHERE unit_id = ?");
                                                                $imgStmt->bind_param("i", $unit['unit_id']);
                                                                $imgStmt->execute();
                                                                $imgRes = $imgStmt->get_result();
                                                                $unitImages = [];
                                                                while ($row = $imgRes->fetch_assoc()) {
                                                                    $imgPath = $row['image_path'];
                                                                    if (!preg_match('/^https?:\/\//', $imgPath) && file_exists(__DIR__ . "/../" . $imgPath)) {
                                                                        $imgPath = "../" . $imgPath;
                                                                    }
                                                                    if (empty($imgPath) || !file_exists(str_replace("../", __DIR__ . "/../", $imgPath))) {
                                                                        $placeholderDir = __DIR__ . "/../Uploads/";
                                                                        $placeholder = glob($placeholderDir . "placeholder.*");
                                                                        $imgPath = !empty($placeholder) ? "../Uploads/" . basename($placeholder[0]) : "";
                                                                    }
                                                                    $unitImages[] = $imgPath;
                                                                }
                                                                $imgStmt->close();
                                                                $thumbImg = !empty($unitImages) ? $unitImages[0] : "../Uploads/placeholder.png";
                                                                ?>
                                                                <li class="list-group-item d-flex justify-content-between align-items-center p-1"
                                                                    style="background: #ded8d8ff; border-radius:5px;">
                                                                    <div class="d-flex align-items-center gap-2">
                                                                        <img src="<?= htmlspecialchars($thumbImg) ?>"
                                                                            alt="Unit Image"
                                                                            style="width:40px; height:40px; object-fit:cover; border-radius:3px; cursor:pointer;"
                                                                            onclick='openImagePopup(<?= json_encode($unitImages) ?>)'>
                                                                        <div>
                                                                            <strong><?= htmlspecialchars($unit['room_type']) ?></strong><br>
                                                                            <small>$<?= number_format($unit['price'], 2) ?> | Units: <?= htmlspecialchars($unit['units_available']) ?></small>
                                                                        </div>
                                                                    </div>
                                                                    <a href="bookUnit.php?property_id=<?= urlencode($unit['property_id']) ?>&unit_id=<?= urlencode($unit['unit_id']) ?>"
                                                                        class="btn btn-sm btn-primary"
                                                                        style="font-size:0.6rem; padding:0.15rem 0.3rem;"> Book </a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column align-items-center justify-content-center p-4"
                        style="background: linear-gradient(135deg, #2e427fff, #5265a0ff); border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); color: #fff; text-align:center;">
                        <i class="fa fa-home mb-2" style="font-size:2rem; color:#ffd700;"></i>
                        <h6 style="margin-bottom:0.5rem;">No Approved Properties Available</h6>
                        <p style="font-size:0.8rem; margin:0;">Please check back later - new listings are added regularly!</p>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Image Popup Modal -->
            <div id="imagePopup" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; 
                     background:rgba(0,0,0,0.8); justify-content:center; align-items:center; z-index:9999; flex-direction:column;">
                <button onclick="closeImagePopup()" style="position:absolute; top:10px; right:20px; background:#fff; border:none; padding:5px 10px; cursor:pointer;">X</button>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button id="prevBtn" onclick="prevImage()" style="background:#fff; border:none; padding:5px; cursor:pointer;">⬅</button>
                    <img id="popupImg" src="" alt="Preview" style="max-width:90%; max-height:90%; border-radius:5px;">
                    <button id="nextBtn" onclick="nextImage()" style="background:#fff; border:none; padding:5px; cursor:pointer;">➡</button>
                </div>
            </div>
            <!-- Location Modal -->
            <div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content" style="border-radius:15px; overflow:hidden;">
                        <div class="modal-header bg-dark text-white">
                            <h5 class="modal-title"><i class="fa fa-map-marker-alt"></i> Property Location</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-0" style="height:600px;">
                            <iframe id="mapFrame" src="" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer>
                &copy; <?= date('Y') ?> All Rights Reserved.Accofinda.
            </footer>
        </main>
    </div>

    <script>
        function markMessagesAsRead() {
            fetch('markDirectMessagesRead', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Optionally update badge count to 0 immediately
                        const badge = document.querySelector('#messageDropdown .badge');
                        if (badge) badge.textContent = '0';
                    }
                })
                .catch(err => console.error('Error marking messages as read:', err));
        }
    </script>
    <script>
        let popupImages = [];
        let currentIndex = 0;

        function openImagePopup(images) {
            popupImages = images;
            currentIndex = 0;
            if (popupImages.length > 0) {
                document.getElementById('popupImg').src = popupImages[currentIndex];
                document.getElementById('imagePopup').style.display = 'flex';
            }
        }

        function closeImagePopup() {
            document.getElementById('imagePopup').style.display = 'none';
        }

        function prevImage() {
            if (popupImages.length > 0) {
                currentIndex = (currentIndex - 1 + popupImages.length) % popupImages.length;
                document.getElementById('popupImg').src = popupImages[currentIndex];
            }
        }

        function nextImage() {
            if (popupImages.length > 0) {
                currentIndex = (currentIndex + 1) % popupImages.length;
                document.getElementById('popupImg').src = popupImages[currentIndex];
            }
        }
    </script>
    <script>
        document.getElementById("propertySearch").addEventListener("input", function() {
            let filter = this.value.toLowerCase();
            let properties = document.querySelectorAll("#propertyList .property-card");

            properties.forEach(card => {
                let title = (card.getAttribute("data-title") || "").toLowerCase();
                let city = (card.getAttribute("data-city") || "").toLowerCase();
                let type = (card.getAttribute("data-type") || "").toLowerCase();
                let price = (card.getAttribute("data-price") || "").toLowerCase();

                if (
                    title.includes(filter) ||
                    city.includes(filter) ||
                    type.includes(filter) ||
                    price.includes(filter)
                ) {
                    card.style.display = "";
                } else {
                    card.style.display = "none";
                }
            });
        });
    </script>
    <script>
        function openMapModal(address) {
            let mapUrl = "https://www.google.com/maps?q=" + encodeURIComponent(address) + "&output=embed";
            document.getElementById("mapFrame").src = mapUrl;
            var modal = new bootstrap.Modal(document.getElementById("mapModal"));
            modal.show();
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>