<?php
session_start();
require '../config.php';

// ================== ADMIN ACCESS CHECK ==================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login");
    exit();
}
include '../Shared/fetchNotification.php';
// ================== ADMIN DETAILS ==================
$adminId    = $_SESSION['user_id'] ?? null;
$adminName  = $_SESSION['full_name'] ?? 'Admin User';
$adminEmail = $_SESSION['email'] ?? '';
$adminPhone = $_SESSION['phone_number'] ?? '';

if (empty($adminEmail) || empty($adminPhone)) {
    $stmt = $conn->prepare("SELECT full_name, email, phone_number FROM users WHERE id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $adminName  = $row['full_name'];
        $adminEmail = $row['email'];
        $adminPhone = $row['phone_number'];

        $_SESSION['full_name']     = $adminName;
        $_SESSION['email']         = $adminEmail;
        $_SESSION['phone_number']  = $adminPhone;
    }
    $stmt->close();
}

// ================== DASHBOARD COUNTS ==================
$userCount = $conn->query("SELECT COUNT(*) AS total_users FROM users")->fetch_assoc()['total_users'] ?? 0;

$roles = ['admin', 'landlord', 'tenant', 'manager', 'Service Provider'];
$userRoleCounts = [];
foreach ($roles as $role) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role = ?");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $userRoleCounts[$role] = $result->fetch_assoc()['total'] ?? 0;
    $stmt->close();
}

$propertyCount = $conn->query("SELECT COUNT(*) AS total_properties FROM properties")->fetch_assoc()['total_properties'] ?? 0;
$bookingCount  = $conn->query("SELECT COUNT(*) AS total_bookings FROM bookings")->fetch_assoc()['total_bookings'] ?? 0;
$messageCount  = $conn->query("SELECT COUNT(*) AS total_messages FROM broadcastmessages")->fetch_assoc()['total_messages'] ?? 0;

// ================== APPROVE / SUSPEND PROPERTY ==================
if (isset($_POST['action']) && isset($_POST['property_id'])) {
    $propertyId = intval($_POST['property_id']);
    $action     = $_POST['action'] === 'approve' ? 'approved' : 'suspended';

    // Get admin details from session
    $adminId   = $_SESSION['user_id'];
    $adminName = $_SESSION['full_name'];

    // Update property with who modified it and when
    $stmt = $conn->prepare("
        UPDATE properties 
        SET status = ?, 
            last_modified_by = ?, 
            last_modified_at = NOW()
        WHERE property_id = ?
    ");
    $stmt->bind_param("sii", $action, $adminId, $propertyId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['message'] = "Property #$propertyId has been $action by $adminName.";
    header("Location: adminDashboard.php");
    exit();
}

// ================== ADMIN PROPERTY MANAGEMENT ==================
$allPropertiesQuery = "
    SELECT p.property_id, 
           p.title, 
           p.status, 
           p.last_modified_by, 
           p.last_modified_at,
           u.full_name AS modified_by_name,
           u.email AS modified_by_email
    FROM properties p
    LEFT JOIN users u ON p.last_modified_by = u.id
    ORDER BY p.created_at DESC
";
$allPropertiesResult = $conn->query($allPropertiesQuery);

// ================== RECENT ACTIVITIES ==================
$recentActivitiesQuery = "
    SELECT 
        'user' AS type, 
        full_name AS title, 
        role, 
        city, 
        created_at AS date 
    FROM users

    UNION ALL

    SELECT 
        'property' AS type, 
        title, 
        NULL AS role, 
        NULL AS city, 
        created_at AS date 
    FROM properties

    UNION ALL

    SELECT 
        'booking' AS type, 
        (SELECT p.title FROM properties p WHERE p.property_id = b.property_id) AS title, 
        NULL AS role, 
        NULL AS city, 
        b.created_at AS date 
    FROM bookings b

    UNION ALL

    SELECT 
        'message' AS type, 
        CONCAT(title, ' (', target_role, ')') AS title, 
        NULL AS role, 
        NULL AS city, 
        created_at AS date 
    FROM broadcastmessages

    ORDER BY date DESC
    LIMIT 6
";
$recentActivitiesResult = $conn->query($recentActivitiesQuery);
$recentActivities = [];
while ($row = $recentActivitiesResult->fetch_assoc()) {
    $recentActivities[] = $row;
}

// ================== ALL PROPERTIES WITH EXTRA INFO ==================
$allProperties = [];
$query = "
    SELECT 
        p.*,
        u.full_name AS action_user_name,
        l.full_name AS landlord_name,
        l.email AS landlord_email,
        l.phone_number AS landlord_phone,
        (SELECT COUNT(*) FROM property_units pu WHERE pu.property_id = p.property_id) AS total_units
    FROM properties p
    LEFT JOIN users u ON u.email = p.last_modified_by
    LEFT JOIN users l ON l.email = p.landlord_email
    ORDER BY p.last_modified_at DESC
";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $allProperties[] = $row;
}

// ================== RECENTLY REGISTERED SERVICE PROVIDERS ==================
$recentProviders = [];
$sql = "SELECT id, full_name, email, phone_number, status, created_at 
        FROM users 
        WHERE LOWER(role) = 'service provider' 
        ORDER BY created_at DESC 
        LIMIT 10";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $recentProviders[] = $row;
    }
}
// ================== USER GROWTH DATA (BY CITY & ROLE) ==================
$growthData = [];
$stmt = $conn->prepare("
    SELECT DATE(created_at) AS reg_date,
           COALESCE(NULLIF(city, ''), 'Unknown') AS city,
           role,
           COUNT(*) AS user_count
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY reg_date, city, role
    ORDER BY reg_date ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $growthData[] = $row;
}
$stmt->close();

// Pass data to JS
echo "<script>var growthData = " . json_encode($growthData) . ";</script>";
// Urgent meetings (next 3 days) — uses its own array name
$urgentMeetingsRes = $conn->query("
    SELECT id, title, agenda, meeting_date, meeting_time, platform, meeting_link
    FROM meetings
    WHERE meeting_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY meeting_date ASC, meeting_time ASC
");
if ($urgentMeetingsRes) {
    while ($row = $urgentMeetingsRes->fetch_assoc()) {
        $datetime = $row['meeting_date'] . ' ' . $row['meeting_time'];
        $urgentMeetings[] = [
            'id'           => (int)$row['id'],
            'title'        => $row['title'],
            'agenda'       => $row['agenda'],
            'datetime'     => $datetime,
            'expired'      => (strtotime($datetime) < time()),
            'platform'     => $row['platform'],
            'meeting_link' => $row['meeting_link'] ?? ''
        ];
    }
}

// Broadcasts — own array name (no conflict)
$recentBroadcasts = [];
$broadRes = $conn->query("
    SELECT id, title, message, created_at
    FROM broadcastmessages
    ORDER BY created_at DESC
    LIMIT 5
");
if ($broadRes) {
    while ($row = $broadRes->fetch_assoc()) {
        $recentBroadcasts[] = $row;
    }
}
//total messages 
// Broadcasts
$result = $conn->query("SELECT COUNT(*) AS c FROM broadcastmessages");
$broadcastCount = $result ? $result->fetch_assoc()['c'] : 0;

// Direct Messages
$result = $conn->query("SELECT COUNT(*) AS c FROM directmessages");
$directCount = $result ? $result->fetch_assoc()['c'] : 0;

// Service Messages
$result = $conn->query("SELECT COUNT(*) AS c FROM service_messages");
$serviceMsgCount = $result ? $result->fetch_assoc()['c'] : 0;

// Contact Messages
$result = $conn->query("SELECT COUNT(*) AS c FROM contactmessages");
$contactCount = $result ? $result->fetch_assoc()['c'] : 0;
// Urgent Messages (unread from system_notifications)
$result = $conn->query("SELECT COUNT(*) AS c FROM system_notifications WHERE is_read = 0");
$urgentCount = $result ? $result->fetch_assoc()['c'] : 0;
// Total
$messageCount = $broadcastCount + $directCount + $serviceMsgCount + $contactCount + $urgentCount;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <title>Accofinda | Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap JS + Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
            font-size: 13px;
            overflow-x: hidden;
            /* prevent horizontal scroll */

        }

        .sidebar {
            background: linear-gradient(180deg, #2d373cff, #2c2d2dff);
            min-height: 100vh;
            color: #ffffff;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            width: 250px;
            flex-shrink: 0;
            /* lock width */
        }

        /* Main content */
        .main {
            flex: 1;
            padding: 30px;
            flex-direction: column;
            background-color: #f8f9fa;
        }

        .dashboard {
            display: flex;
            flex: 1;
            flex-direction: row;
            /* always row like desktop */
        }

        .profile-card {
            background: rgba(0, 0, 0, 1);
            border-radius: 10px;
            padding: 10px;
            font-size: 0.85rem;
            color: white;
        }

        .sidebar-profile {
            background-color: #444944ff;
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

        .sidebar-link {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 12px;
            transition: background 0.3s, padding-left 0.3s;
        }

        .sidebar-link:hover {
            background: rgba(0, 0, 0, 1);
            padding-left: 25px;
            border-left: 4px solid #0d6efd;
        }

        .table thead {
            background: #053405;
            color: white;
            font-size: 12px;
        }

        /* ✅ Stats row */
        .stats-row {
            display: flex;
            gap: 1rem;
            flex-wrap: nowrap;
            justify-content: space-between;
        }

        .stat-card {
            flex: 1 1 0;
            min-width: 0;
            /* ensures shrink */
            border-radius: 10px;
            padding: 1.2rem 0.8rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            padding-top: 3rem;
            transition: transform 0.2s ease-in-out;
            overflow: visible !important;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.3rem;
            opacity: 0.9;
        }

        .stat-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: black;
            color: white;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            border: none;
            cursor: default;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        /* Small screen tweaks */
        @media (max-width: 576px) {
            .stat-card .dropdown-menu {
                font-size: 0.6rem !important;
                /* smaller text */
                min-width: 120px !important;
                /* narrower menu */
                padding: 0.25rem !important;
                /* reduce padding */
            }

            .stat-card .dropdown .btn {
                font-size: 0.65rem !important;
                /* smaller toggle button */
                padding: 1px 4px !important;
            }
        }

        /* 🔑 Scaling on smaller screens */
        @media (max-width: 992px) {
            .stats-row {
                gap: 0.6rem;
            }

            .stat-card {
                padding: 1rem 0.5rem;
            }

            .stat-icon {
                font-size: 1.5rem;
            }

            .stat-btn {
                font-size: 0.7rem;
                padding: 2px 6px;
            }

            .stat-card h3 {
                font-size: 1rem;
            }
        }

        /* ✅ Properties & Recent Activities row */
        .properties-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            /* allow wrapping on very small screens */
            justify-content: space-between;
            align-items: stretch;
            /* stretch cards to equal height */
            margin-top: 1.5rem;
        }

        .properties-row>.card {
            flex: 1 1 48%;
            /* almost half width */
            min-width: 280px;
            /* prevent too small */
            display: flex;
            flex-direction: column;
            height: 100%;
            /* ensures equal height */
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            padding: 1rem;
            background: #fff;
        }

        .properties-row>.card .card-body {
            flex: 1 1 auto;
            /* make card body take remaining space */
            overflow-y: auto;
            /* scroll if content exceeds height */
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .properties-row {
                gap: 0.6rem;
            }

            .properties-row>.card {
                padding: 0.8rem;
                flex: 1 1 100%;
                /* full width on smaller screens */
            }
        }


        .avatar-circle {
            width: 40px;
            height: 40px;
            background-color: #ffffffff;
            color: black;
            font-weight: bold;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        /* Independent footer that scrolls with page */
        #page-footer {
            width: 100%;
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 15px 10px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }

        /* Small screen adjustments */
        @media (max-width: 576px) {
            #page-footer {
                padding: 12px 8px;
                font-size: 0.85rem;
            }
        }

        .sidebar-dropdown .collapse {
            width: 100%;
        }

        .sidebar-dropdown .collapse .bg-dark {
            padding: 0;
            margin: 0;
            border-radius: 0.25rem;
            width: 100%;
        }

        .sidebar-dropdown .collapse .bg-dark a.sidebar-link {
            display: block;
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
            margin: 0;
        }

        .sidebar-dropdown .collapse .bg-dark a.sidebar-link:hover {
            background-color: #000000ff;
            color: #f6913eff !important;
        }

        .sidebar-dropdown a[data-bs-toggle="collapse"] {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-dropdown a[data-bs-toggle="collapse"] .fa-caret-right {
            transition: transform 0.3s;
            margin-left: 0.25rem;
            font-size: 0.8rem;
        }

        .sidebar-dropdown a[data-bs-toggle="collapse"][aria-expanded="true"] .fa-caret-right {
            transform: rotate(90deg);
        }

        #propertySearch::placeholder {
            color: #ffffffff;
            opacity: 1;
        }

        .role-badge {
            display: inline-block;
            width: 18px;
            height: 18px;
            line-height: 16px;
            text-align: center;
            border-radius: 3px;
            font-size: 0.65rem;
            color: #fff;
            margin-left: 6px;
        }

        /* Layout wrapper */
        /* Section cards */
        .dashboard-sections {
            display: flex;
            flex-wrap: nowrap;
            /* force them on one row */
            gap: 15px;
            /* small spacing between */
        }

        .dashboard-sections section {
            flex: 1 1 0;
            /* allow equal growth */
            width: 50%;
            /* exactly half each */
            max-height: 280px;
            overflow: hidden;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        /* Scrollable lists */
        .scrollable-urgent,
        .scrollable-messages {
            max-height: 180px;
            overflow-y: auto;
            margin-top: 5px;
            padding-right: 5px;
        }

        /* Section headers (already dark) */
        .dashboard-sections section>div.bg-dark {
            border-bottom: 1px solid #333;
        }

        /* Content padding inside section + increased height */
        .dashboard-sections section>div:not(.bg-dark) {
            padding: 10px 15px;
            max-height: 350px;
            /* ✅ Increased height of content area */
            overflow-y: auto;
            /* ✅ Enables scrolling if content exceeds height */
        }

        /* Titles */
        .dashboard-sections h3 {
            font-size: 1rem;
            margin: 0;
        }

        /* ✅ Optional: add a minimum height so even empty sections look taller */
        .dashboard-sections section {
            min-height: 220px;
            /* You may adjust to 400px / 450px if needed */
        }


        .btn-agenda {
            font-size: 0.7rem;
            /* smaller font */
            padding: 2px 6px;
            /* tighter padding */
            border-radius: 4px;
            /* more rectangular, less rounded */
            line-height: 1.2;
            /* compact height */
        }

        .alert-box {
            background: #fff3cd;
            /* soft alert yellow */
            border: 1px solid #ffeeba;
            outline: 2px solid #000;
            /* black outline */
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            color: #a3821f;
            /* fixed warning text color */
            min-width: 550px;
            max-width: 90%;
            margin: 10px auto;
            padding: 12px 16px;
        }

        /* Text inside alert */
        .alert-box h6,
        .alert-box p {
            margin: 0;
        }

        /* ✅ Responsive for tablets and phones */
        @media (max-width: 768px) {
            .alert-box {
                min-width: unset;
                /* remove fixed width */
                width: 95%;
                /* fit within screen */
                font-size: 0.95rem;
                /* slightly smaller text */
                padding: 10px 14px;
            }
        }

        @media (max-width: 480px) {
            .alert-box {
                width: 100%;
                /* full width for small phones */
                border-radius: 8px;
                padding: 8px 12px;
                font-size: 0.9rem;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            }
        }
    </style>
</head>

<body>


    <div class="dashboard">

        <!-- Sidebar -->
        <aside class="col-md-2 sidebar p-0 d-flex flex-column">


            <!-- Profile Section -->
            <div class="sidebar-profile text-left p-3">
                <img src="../Images/AccofindaLogo1.jpg"
                    alt="Accofinda Logo"
                    class="mb-3 sidebar-logo"
                    width="40" height="40">

                <div class="profile-card p-2">
                    <h5 class="mb-1"><?php echo htmlspecialchars($adminName); ?></h5>
                    <p class="mb-1">
                        <i class="fa fa-envelope"></i> <?php echo htmlspecialchars($adminEmail); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fa fa-phone"></i> <?php echo htmlspecialchars($adminPhone); ?>
                    </p>
                </div>
            </div>
            <!-- Navigation Links -->
            <nav class="flex-grow-1">
                <a href="#" class="sidebar-link"><i class="fa fa-gauge" style="color: #4e73df;"></i> Dashboard</a>
                <!-- Manage Properties Dropdown -->
                <div class="sidebar-dropdown mb-2">
                    <a class="sidebar-link d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#propertySubmenu" role="button" aria-expanded="false" aria-controls="propertySubmenu">
                        <span><i class="fa fa-building me-2" style="color: #f6c23e;"></i> Manage Properties</span>
                        <i class="fa fa-caret-right text-white ms-1"></i>
                    </a>
                    <div class="collapse mt-1" id="propertySubmenu">
                        <div class="bg-dark rounded px-1 py-1" style="max-width: 210px;  margin-left: 1rem;">
                            <a href="../Shared/manageProperty" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-check-circle me-2" style="color: #1cc88a;"></i> Approvals/Suspensions
                            </a>
                            <a href="../Shared/propertiesListed" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-list-ul me-2" style="color: #ffffffff;"></i> View All Properties
                            </a>
                            <a href="agreementForm" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-file-contract me-2" style="color: #36b9cc;"></i> Agreement Form
                            </a>
                        </div>
                    </div>
                </div>
                <a href="scheduleMeetings" class="sidebar-link"><i class="fa fa-calendar-alt" style="color: #894ff4ff;"></i> Schedule Meeting</a>
                <div class="sidebar-dropdown mb-2">
                    <a class="sidebar-link d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#usersSubmenu" role="button" aria-expanded="false" aria-controls="usersSubmenu">
                        <span><i class="fa fa-users me-2" style="color: #14a8fdff;"></i>Manage Users</span>
                        <i class="fa fa-caret-right text-white ms-1"></i>
                    </a>
                    <div class="collapse mt-1" id="usersSubmenu">
                        <div class="bg-dark rounded px-1 py-1" style="max-width: 210px; margin-left: 1rem;">
                            <a href="allUsers" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-user-friends me-2" style="color: #ffffffff;"></i> All Users
                            </a>
                            <a href="smartCardMaker" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-id-card me-2" style="color: #bb3030ff;"></i> Smartcards
                            </a>
                        </div>
                    </div>
                </div>
                <!-- Broadcasts Dropdown -->
                <div class="sidebar-dropdown mb-2">
                    <a class="sidebar-link d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#broadcastSubmenu" role="button" aria-expanded="false" aria-controls="broadcastSubmenu">
                        <span><i class="fa fa-bullhorn me-2" style="color:#FF4500;"></i> Broadcasts</span>
                        <i class="fa fa-caret-right text-white ms-1"></i>
                    </a>
                    <div class="collapse mt-1" id="broadcastSubmenu">
                        <div class="bg-dark rounded px-1 py-1" style="max-width: 210px; margin-left: 1rem;">
                            <a href="../Shared/broadcasts" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-bullhorn me-2" style="color:#FF4500;"></i> Broadcasts
                            </a>
                            <a href="../Shared/directNotifications" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-bell me-2" style="color:#1E90FF;"></i> Direct Notification
                            </a>
                        </div>
                    </div>
                </div>

                <a href="allBooking" class="sidebar-link"><i class="fa fa-clipboard-list" style="color: #36b9cc;"></i> View Applications</a>
                <a href="#" class="sidebar-link"><i class="fa fa-calendar-check" style="color: #e83e8c;"></i> Bookings</a>
                <!-- Service Providers Dropdown -->
                <div class="sidebar-dropdown mb-2">
                    <a class="sidebar-link d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#serviceSubmenu" role="button" aria-expanded="false" aria-controls="serviceSubmenu">
                        <span><i class="fa fa-tools me-2" style="color: #fd7e14;"></i> Service Providers</span>
                        <i class="fa fa-caret-right text-white ms-1"></i>
                    </a>
                    <div class="collapse mt-1" id="serviceSubmenu">
                        <div class="bg-dark rounded px-1 py-1" style="max-width: 210px; margin-left: 1rem;">
                            <a href="../Shared/services" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-pen-nib me-2" style="color: #ffee01ff;"></i> Service Provider
                            </a>
                            <a href="verifyService" class="sidebar-link small d-flex align-items-center py-1">
                                <i class="fa fa-check-circle me-2" style="color: #ffffffff;"></i> Verify Service
                            </a>
                        </div>
                    </div>
                </div>

                <a href="messages" class="sidebar-link"><i class="fa fa-comments" style="color: #20c997;"></i>Service Messages</a>
                <a href="#" class="sidebar-link"><i class="fa fa-money-bill" style="color: #28a745;"></i> Transactions</a>
                <a href="adminSupport" class="sidebar-link"><i class="fa fa-headset" style="color: #17a2b8;"></i> Support</a>
                <a href="#" class="sidebar-link"><i class="fa fa-chart-line" style="color: #ffffffff;"></i> Analytics</a>
                <a href="serviceReports" class="sidebar-link"><i class="fa fa-chart-pie" style="color: #ffc107;"></i> Reports</a>
                <a href="../Shared/updateProfile" class="sidebar-link"><i class="fa fa-user-edit me-2" style="color:#198754;"></i> Update Profile</a>
                <a href="../Shared/createUser" class="sidebar-link"><i class="fa fa-user-plus" style="color: #ffffffff;"></i> Create User</a>
                <a href="../viewEmails" class="sidebar-link">
                    <i style="color: #007bff;"></i> 📧 Emails
                </a>
                <a href="../logout"
                    class="mt-3 d-block text-center"
                    style="background-color: #972e2eff; color:#fff; max-width: 200px;padding:10px 10px; border-radius:8px; text-decoration:none; font-weight:500;">
                    <i class="fa fa-sign-out-alt me-2"></i> Logout
                </a>
            </nav>

        </aside>
        <!-- Main Content Area -->
        <main class="main" class="content flex-grow-1 d-flex flex-column" style="margin: 0; padding: 0;">

            <!-- Top Navbar -->
            <nav class="navbar navbar-expand-lg navbar-dark w-100"
                style="margin:0; padding:10px 1rem; border-radius:0; background: linear-gradient(180deg, #46566aff, #4a586eff);">

                <div class="d-flex align-items-center">
                    <!-- User Initials Avatar -->
                    <div class="avatar-circle me-2">
                        <?php
                        $parts = explode(' ', trim($adminName));
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                        echo $initials;
                        ?>
                    </div>
                    <div>
                        <div class="fw-bold text-white">
                            Welcome, <?php echo htmlspecialchars($adminName); ?>
                        </div>
                        <small class="text-light">
                            <?php echo ucfirst(htmlspecialchars($_SESSION['role'])); ?>
                        </small>
                    </div>
                </div>

                <div class="ms-auto d-flex align-items-center">
                    <!-- Online Button -->
                    <button class="btn btn-success btn-sm me-5">
                        Online
                    </button>

                    <!-- Simple Dropdown Menu -->
                    <div class="dropdown">
                        <button class="btn bg-dark text-light btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-bars"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="../Shared/updateProfile">
                                    <i class="fa fa-user-edit"></i> Update Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger" href="../logout">
                                    <i class="fa fa-sign-out-alt"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </div>

                </div>
            </nav>

            <!-- Page Content -->
            <div class="p-4">
                <!-- Stats Overview -->
                <div class="row g-3 mb-4 text-center">

                    <!-- Total Users Card -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card text-white"
                            style="background: #524133ff; position: relative; padding-top: 2.5rem; border-radius: 0.6rem; cursor: pointer;"
                            onclick="window.location='allUsers'">

                            <!-- Dropdown -->
                            <div class="dropdown"
                                style="position: absolute; top: 8px; left: 8px;"
                                onclick="event.stopPropagation();"> <!-- prevents dropdown click from triggering card redirect -->
                                <button class="btn btn-sm btn-dark dropdown-toggle py-1 px-2"
                                    type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                    style="font-size: 0.85rem; line-height: 1;">
                                    Total Users
                                </button>
                                <ul class="dropdown-menu dropdown-menu-dark p-1" style="font-size: 0.7rem; min-width: 150px; border-radius: 0.4rem;">
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='allUsers?role=admin'">
                                        Admins <span class="role-badge" style="background: #c70909ff;"><?= $userRoleCounts['admin'] ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='allUsers?role=landlord'">
                                        Landlords <span class="role-badge" style="background: #a06008ff;"><?= $userRoleCounts['landlord'] ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='allUsers?role=tenant'">
                                        Tenants <span class="role-badge" style="background: #058a7dff;"><?= $userRoleCounts['tenant'] ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='allUsers?role=Service+Provider'">
                                        Service Providers <span class="role-badge" style="background: #9c478bff;"><?= $userRoleCounts['Service Provider'] ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='allUsers?role=manager'">
                                        Managers <span class="role-badge" style="background: #237f18ff;"><?= $userRoleCounts['manager'] ?? 0 ?></span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Main Content -->
                            <i class="fa fa-users stat-icon" style="font-size: 2.3rem; margin-bottom: 0.3rem;"></i>
                            <h3 class="mt-2"><?= $userCount ?></h3>
                        </div>
                    </div>

                    <!-- Properties Card -->
                    <div class="col-6 col-md-3">
                        <a href="../Shared/propertiesListed" style="text-decoration: none;">
                            <div class="stat-card text-white"
                                style="background: #3e3353ff; position: relative; padding-top: 2.5rem; cursor: pointer;">
                                <button class="stat-btn">Properties Listed</button>
                                <i class="fa fa-building stat-icon"></i>
                                <h3 class="mt-2"><?= $propertyCount ?></h3>
                            </div>
                        </a>
                    </div>

                    <!-- Bookings Card -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card text-white"
                            style="background: #354539ff; position: relative; padding-top: 2.5rem;">
                            <button class="stat-btn">Bookings</button>
                            <i class="fa fa-calendar-check stat-icon"></i>
                            <h3 class="mt-2"><?= $bookingCount ?></h3>
                        </div>
                    </div>

                    <!-- Messages Card -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card text-white"
                            style="background: #50575fff; position: relative; padding-top: 2.5rem; border-radius: 0.6rem;">

                            <!-- Dropdown -->
                            <div class="dropdown" style="position: absolute; top: 8px; left: 8px;">
                                <button class="btn btn-sm btn-dark dropdown-toggle py-1 px-2"
                                    type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                    style="font-size: 0.8rem; line-height: 1;">
                                    Messages
                                </button>
                                <ul class="dropdown-menu dropdown-menu-dark p-1" style="font-size: 0.7rem; min-width: 160px; border-radius: 0.4rem;">
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='../Shared/broadcasts'">
                                        Broadcasts <span class="role-badge" style="background: #6b186dff;"><?= $broadcastCount ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='../Shared/broadcasts'">
                                        Direct Messages <span class="role-badge" style="background: #13599fff;"><?= $directCount ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='messages'">
                                        Service Messages <span class="role-badge" style="background: #1a755aff;"><?= $serviceMsgCount ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='../viewEmails'">
                                        Contact Messages <span class="role-badge" style="background: #98791bff;"><?= $contactCount ?? 0 ?></span>
                                    </li>
                                    <li class="dropdown-item py-1 px-2 d-flex justify-content-between align-items-center"
                                        onclick="window.location='../Shared/directNotifications'">
                                        Urgent Messages <span class="role-badge" style="background: #8d111dff;"><?= $urgentCount ?? 0 ?></span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Main Content -->
                            <i class="fa fa-envelope stat-icon"></i>
                            <h3 class="mt-2"><?= $messageCount ?></h3>
                        </div>
                    </div>


                </div>
                <!-- Dashboard Sections Wrapper -->
                <div class="dashboard-sections d-flex flex-wrap gap-3 mt-3">

                    <!-- Urgent Meetings (Next 3 Days) -->
                    <section class="urgent-meetings border rounded">
                        <!-- Dark Header -->
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark text-white rounded-top">
                            <h3 class="fs-6 m-0">📌 Meetings</h3>
                            <button class="btn btn-sm btn-outline-light py-0 px-2"
                                onclick="clearMissedUrgentMeetings()">🧹 Clear All</button>
                        </div>

                        <?php if (!empty($urgentMeetings)): ?>
                            <?php $scrollableUrgent = count($urgentMeetings) > 3; ?>
                            <div class="<?= $scrollableUrgent ? 'scrollable-urgent' : '' ?> overflow-auto px-2">
                                <?php foreach ($urgentMeetings as $m): ?>
                                    <?php
                                    $itemId = 'urgent_' . md5($m['id'] . $m['title'] . $m['datetime']);
                                    $human = date("F j, Y g:i A", strtotime($m['datetime']));
                                    $isExpired = $m['expired'];
                                    ?>
                                    <div class="mb-2 p-2 border rounded bg-light small <?= $isExpired ? 'border-danger' : '' ?>"
                                        data-id="<?= $itemId ?>" data-expired="<?= $isExpired ? '1' : '0' ?>">

                                        <!-- Meeting Title + Agenda Button -->
                                        <div class="fw-bold d-flex justify-content-between align-items-center">
                                            📅 <?= htmlspecialchars($m['title']) ?>
                                            <button class="btn btn-dark btn-agenda"
                                                data-bs-toggle="collapse" data-bs-target="#agenda<?= $m['id'] ?>">
                                                📂 Agenda
                                            </button>

                                        </div>

                                        <!-- Meeting Info -->
                                        <small>
                                            <?php if ($isExpired): ?>
                                                <span class="text-danger fw-bold">Meeting passed!</span> (was <?= $human ?>)
                                            <?php else: ?>
                                                <?= $human ?>
                                                <?php if ($m['platform'] !== 'In-Person' && !empty($m['meeting_link'])): ?>
                                                    <a class="btn btn-sm btn-success ms-2 py-0 px-2" target="_blank"
                                                        href="<?= htmlspecialchars($m['meeting_link']) ?>">Join</a>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary ms-2">In-Person</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </small>

                                        <!-- Date Badge -->
                                        <div class="mt-1">
                                            <span class="badge bg-dark small"><?= date("M j", strtotime($m['datetime'])) ?></span>
                                        </div>

                                        <!-- Collapsible Agenda -->
                                        <div id="agenda<?= $m['id'] ?>" class="collapse mt-2">
                                            <div class="p-2 border rounded bg-white">
                                                <strong>Agenda:</strong>
                                                <p class="mb-0"><?= nl2br(htmlspecialchars($m['agenda'])) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-activities-box alert-box text-center p-3 rounded small mx-auto">
                                <h6 class="mb-1">📭 No Urgent Meetings</h6>
                                <p class="mb-0">Nothing in the next 3 days.</p>
                            </div>

                        <?php endif; ?>
                    </section>

                    <!-- Recent Broadcast Messages -->
                    <section class="messages border rounded">
                        <!-- Dark Header -->
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-dark text-white rounded-top">
                            <h3 class="fs-6 m-0">📣 Broadcasts</h3>
                            <button class="btn btn-sm btn-outline-light py-0 px-2"
                                onclick="clearBroadcasts()">🧹 Clear All</button>
                        </div>

                        <?php if (empty($recentBroadcasts)): ?>
                            <div class="no-broadcasts-box alert-box text-center p-3 rounded small mx-auto">
                                <h6 class="text-muted mb-1">📭 No Broadcasts</h6>
                                <p class="text-muted mb-0">Nothing shared by admin.</p>
                            </div>
                        <?php else: ?>
                            <?php $scrollableMsgs = count($recentBroadcasts) > 3; ?>
                            <div class="<?= $scrollableMsgs ? 'scrollable-messages' : '' ?> overflow-auto px-2">
                                <?php foreach ($recentBroadcasts as $msg): ?>
                                    <div class="message-item mb-2 p-2 border rounded bg-light small" data-id="<?= $msg['id'] ?>">
                                        <strong><?= htmlspecialchars($msg['title']) ?></strong>
                                        <p class="mb-1"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                                        <div class="time text-muted small">
                                            <span class="badge bg-dark small">🕒 <?= date("M j, Y g:i a", strtotime($msg['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                </div>

                <!-- Properties & Recent Activities Row -->
                <div class="row mb-4">

                    <!-- Latest Properties Table (Left) -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header text-white"
                                style="font-size: 0.85rem; background: linear-gradient(180deg, #1f2937, #374151);">
                                <i class="fa fa-building"></i> Latest Properties
                            </div>

                            <div class="card-body p-0" style="font-size: 0.75rem; max-height: 300px; overflow-y: auto;">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-dark sticky-top" style="font-size: 0.75rem;">
                                        <tr>
                                            <th>Title</th>
                                            <th>Owner Name</th>
                                            <th>Phone</th>
                                            <th>County</th>
                                            <th>Status</th>
                                            <th>Availability</th>
                                        </tr>
                                    </thead>
                                    <tbody style="font-size: 0.80rem;">
                                        <?php
                                        // Fetch latest properties with owner details
                                        $sql = "SELECT p.title, u.full_name AS owner_name, u.phone_number, 
                                   p.county, p.status, p.availability_status
                            FROM properties p
                            JOIN users u ON p.landlord_email = u.email
                            ORDER BY p.property_id DESC";
                                        $result = $conn->query($sql);

                                        if ($result && $result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                // Color mapping for status
                                                $statusColor = match (strtolower($row['status'])) {
                                                    'pending' => 'warning',
                                                    'approved' => 'success',
                                                    'suspended' => 'danger',
                                                    default => 'secondary'
                                                };

                                                // Color mapping for availability status
                                                $availabilityColor = match (strtolower($row['availability_status'])) {
                                                    'available' => 'success',
                                                    'booked' => 'warning',
                                                    'occupied' => 'danger',
                                                    'under maintainance' => 'secondary',
                                                    default => 'secondary'
                                                };
                                        ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['title'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($row['owner_name'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($row['phone_number'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($row['county'] ?? '') ?></td>
                                                    <td><span class="badge bg-<?= $statusColor ?>"><?= htmlspecialchars($row['status'] ?? '') ?></span></td>
                                                    <td><span class="badge bg-<?= $availabilityColor ?>"><?= htmlspecialchars($row['availability_status'] ?? '') ?></span></td>
                                                </tr>
                                        <?php
                                            }
                                        } else {
                                            echo '<tr><td colspan="6" class="text-center text-muted">No properties found</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>


                    <!-- Recent Activities (Right) -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-dark text-white">
                                <i class="fa fa-clock"></i> Recent Activities
                            </div>
                            <div class="card-body p-0" style="max-height: 240px; overflow-y: auto;">
                                <ul class="list-group list-group-flush small"> <!-- smaller text -->
                                    <?php if (!empty($recentActivities)): ?>
                                        <?php foreach (array_slice($recentActivities, 0, 4) as $activity): ?>
                                            <li class="list-group-item py-2">
                                                <?php if ($activity['type'] === 'user'): ?>
                                                    <i class="fa fa-user-plus text-success"></i>
                                                    New user <strong><?= htmlspecialchars($activity['title']) ?></strong> registered.
                                                    <br>
                                                    <small>
                                                        Role: <strong><?= htmlspecialchars($activity['role'] ?? '#') ?></strong> |
                                                        City: <em><?= htmlspecialchars($activity['city'] ?? '#') ?></em>
                                                    </small>

                                                <?php elseif ($activity['type'] === 'property'): ?>
                                                    <i class="fa fa-home text-primary"></i>
                                                    Property <strong><?= htmlspecialchars($activity['title']) ?></strong> added.

                                                <?php elseif ($activity['type'] === 'booking'): ?>
                                                    <i class="fa fa-calendar-check text-warning"></i>
                                                    Booking made for <strong><?= htmlspecialchars($activity['title']) ?></strong>.

                                                <?php elseif ($activity['type'] === 'message'): ?>
                                                    <i class="fa fa-comments" style="color: #6f42c1;"></i> <!-- purple icon -->
                                                    New message: <strong><?= htmlspecialchars($activity['title']) ?></strong>.
                                                <?php endif; ?>

                                                <br>
                                                <small class="badge bg-dark text-white mt-1 px-2 py-1" style="font-size: 0.65rem;">
                                                    <i class="fa fa-calendar-alt me-1"></i>
                                                    <?= htmlspecialchars($activity['date']) ?>
                                                </small>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="list-group-item">No recent activities.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>


                <div class="section-card" style="max-height: 600px; overflow-y: auto; padding-right: 5px;">
                    <h4>🏠 Manage All Properties</h4>
                    <!-- 🔎 Search bar -->
                    <div class="mb-3">
                        <input type="text" id="propertySearch"
                            class="form-control form-control-sm bg-dark text-light border border-dark"
                            placeholder="Search properties...">
                    </div>

                    <?php if (empty($allProperties)): ?>
                        <p>No properties found.</p>
                    <?php else: ?>
                        <!-- Cards Grid -->
                        <div class="row row-cols-1 row-cols-md-2 g-3" id="propertyList">
                            <?php foreach ($allProperties as $property): ?>
                                <?php
                                $title       = htmlspecialchars($property['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
                                $description = htmlspecialchars($property['description'] ?? 'No description available.', ENT_QUOTES, 'UTF-8');
                                $address     = htmlspecialchars($property['address'] ?? '', ENT_QUOTES, 'UTF-8');
                                $city        = htmlspecialchars($property['city'] ?? '', ENT_QUOTES, 'UTF-8');
                                $state       = htmlspecialchars($property['state'] ?? '', ENT_QUOTES, 'UTF-8');
                                $postal_code = htmlspecialchars($property['postal_code'] ?? '', ENT_QUOTES, 'UTF-8');
                                $createdAt   = !empty($property['last_modified_at']) ? date("M d, Y", strtotime($property['last_modified_at'])) : 'Unknown date';
                                $property_id = (int)($property['property_id'] ?? 0);

                                $availability_status = strtolower(trim($property['availability_status'] ?? 'unknown'));
                                $availabilityClass = match ($availability_status) {
                                    'available' => 'bg-success',
                                    'occupied'  => 'bg-danger',
                                    'booked'    => 'bg-warning text-dark',
                                    'under maintenance' => 'bg-secondary',
                                    default     => 'bg-dark'
                                };

                                $approval_status = ucfirst(strtolower(trim($property['status'] ?? 'Pending')));
                                $approvalBadgeClass = match (strtolower($approval_status)) {
                                    'approved'  => 'bg-success',
                                    'pending'   => 'bg-warning text-dark',
                                    'suspended' => 'bg-danger',
                                    default     => 'bg-secondary'
                                };

                                $editor_name = "N/A";
                                if (!empty($property['last_modified_by'])) {
                                    $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                                    $stmt->bind_param("i", $property['last_modified_by']);
                                    $stmt->execute();
                                    $stmt->bind_result($full_name);
                                    if ($stmt->fetch()) {
                                        $editor_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') . " [" . $createdAt . "] ";
                                    }
                                    $stmt->close();
                                }

                                $landlord_name  = htmlspecialchars($property['landlord_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                                $landlord_email = htmlspecialchars($property['landlord_email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                                $landlord_phone = htmlspecialchars($property['landlord_phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                                $total_units    = (int)($property['total_units'] ?? 0);

                                // Google Maps query URL
                                $mapQuery = urlencode($address . ' ' . $city . ' ' . $state . ' ' . $postal_code);
                                ?>
                                <div class="col property-card">
                                    <div class="card h-100" style="background-color: #222; color: #fff; border-radius: 10px; min-height: 250px; position: relative;">

                                        <!-- 📍 Location Button (opens modal) -->
                                        <button type="button"
                                            class="btn btn-sm bg-warning"
                                            style="position: absolute; top: 8px; right: 8px; font-size: 0.75rem; padding: 2px 6px;"
                                            data-bs-toggle="modal"
                                            data-bs-target="#mapModal"
                                            data-map="<?= $mapQuery ?>">
                                            Location
                                        </button>

                                        <div class="card-body d-flex flex-column gap-2">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <?= $title ?>
                                                    <span class="badge <?= $approvalBadgeClass ?> px-2 py-1" style="font-size:0.75rem;">
                                                        <?= $approval_status ?>
                                                    </span>
                                                </h5>
                                                <p style="margin: 0 0 5px 0; font-size: 0.9rem;"><?= $description ?></p>
                                                <p style="margin: 0; font-size: 0.85rem;">
                                                    <strong>📍 Location:</strong> <?= $address ?>, <?= $city ?>, <?= $state ?> <?= $postal_code ?><br>
                                                    <strong>👤 Landlord:</strong> <?= $landlord_name ?> | <?= $landlord_email ?> | <?= $landlord_phone ?><br>
                                                    <strong>🏢 Units:</strong> <?= $total_units ?><br>
                                                    <strong>📝 Last Action By:</strong> <?= $editor_name ?>
                                                </p>
                                            </div>
                                        </div>

                                        <!-- ✅ Footer with small text buttons -->
                                        <div class="card-footer d-flex justify-content-between align-items-center">
                                            <span class="badge <?= $availabilityClass ?>"><?= ucfirst($availability_status) ?></span>
                                            <div class="d-flex gap-1">
                                                <a href="../Shared/editProperty?property_id=<?= $property_id ?>"
                                                    class="btn btn-sm btn-info" style="font-size: 0.75rem; padding: 2px 6px;">Edit</a>
                                                <a href="../Shared/propertyUnitsDetail?property_id=<?= $property_id ?>"
                                                    class="btn btn-sm btn-primary" style="font-size: 0.75rem; padding: 2px 6px;">Update</a>
                                                <form action="../Shared/deleteProperty" method="POST"
                                                    onsubmit="return confirm('Delete this property? This action is permanent.')" style="margin:0;">
                                                    <input type="hidden" name="property_id" value="<?= $property_id ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"
                                                        style="font-size: 0.75rem; padding: 2px 6px;">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 🌍 Modal for Map -->
                <div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content bg-dark text-white">
                            <div class="modal-header">
                                <h5 class="modal-title">Property Location</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0">
                                <iframe id="mapFrame" src="" width="100%" height="450" style="border:0;" allowfullscreen loading="lazy"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Recently Registered Users + User Growth Trend -->
                <div class="row mb-4">

                    <!-- Recently Registered Service Providers (Left) -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-dark text-white" style="font-size: 0.95rem;">
                                <i class="fa fa-users"></i> Recently Registered Service Providers
                            </div>
                            <!-- Reduced font size -->
                            <div class="card-body p-0" style="font-size: 0.75rem; max-height: 300px; overflow-y: auto;">
                                <table class="table table-hover table-bordered mb-0" style="font-size: 0.75rem;">
                                    <thead class="table-secondary" style="font-size: 0.72rem;">
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recentProviders)): ?>
                                            <?php foreach ($recentProviders as $prov): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($prov['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($prov['email']) ?></td>
                                                    <td><?= htmlspecialchars($prov['phone_number']) ?></td>
                                                    <td>
                                                        <?php
                                                        $status = strtolower($prov['status'] ?? '');
                                                        if ($status === 'active') echo '<span class="badge bg-success">Active</span>';
                                                        elseif ($status === 'inactive') echo '<span class="badge bg-secondary">Inactive</span>';
                                                        else echo '<span class="badge bg-warning text-dark">' . htmlspecialchars($prov['status']) . '</span>';
                                                        ?>
                                                    </td>
                                                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($prov['created_at']))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted">No recent service providers found</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- User Growth Trend (Right) -->
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center" style="padding: 0.4rem 0.75rem; font-size: 0.9rem;">
                                <span><i class="fa fa-chart-line"></i> User Growth Trend</span>
                                <select class="form-select form-select-sm w-auto" id="growthDuration" style="font-size: 0.8rem; padding: 0.15rem 0.5rem;">
                                    <option value="7">Last 7 Days</option>
                                    <option value="30" selected>Last 30 Days</option>
                                    <option value="365">Last Year</option>
                                </select>
                            </div>
                            <div class="card-body p-2" style="height: 200px;">
                                <canvas id="userGrowthChart" style="height: 160px;"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    </div>
    <!-- Footer -->
    <footer id="page-footer">
        &copy; <?= date('Y') ?> Accofinda.Rashid Developer
    </footer>

    <!-- user Chart.js Script -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const ctx = document.getElementById("userGrowthChart").getContext("2d");

            // Process data (city + role)
            const grouped = {};
            growthData.forEach(row => {
                const roleLabel = row.role ? row.role.charAt(0).toUpperCase() + row.role.slice(1) : "Unknown Role";
                const cityLabel = row.city && row.city.trim() !== "" ? row.city : "Unknown City";
                const key = `${cityLabel} (${roleLabel})`; // clearer designation
                if (!grouped[key]) grouped[key] = {};
                grouped[key][row.reg_date] = row.user_count;
            });

            // All dates (x-axis)
            const labels = [...new Set(growthData.map(r => r.reg_date))];

            // Convert grouped data to datasets
            const datasets = Object.keys(grouped).map((key, i) => {
                return {
                    label: key,
                    data: labels.map(date => grouped[key][date] || 0),
                    borderColor: `hsl(${i * 50}, 70%, 45%)`,
                    backgroundColor: `hsl(${i * 50}, 70%, 65%)`,
                    fill: false,
                    tension: 0.3
                };
            });

            // Chart.js line chart
            new Chart(ctx, {
                type: "line",
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: "right",
                            align: "start", // pushes legend far right
                            labels: {
                                font: {
                                    size: 9
                                },
                                boxWidth: 10,
                                padding: 8
                            }
                        },
                        title: {
                            display: true,
                            text: "User Growth by City & Role"
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: "Date"
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: "Users"
                            },
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    </script>
    <!-- Chart.js Script for Revenue -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const ctxRevenue = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(ctxRevenue, {
                type: 'bar',
                data: {
                    labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
                    datasets: [{
                        label: 'Revenue ($)',
                        data: [1200, 1500, 1800, 2000],
                        backgroundColor: 'rgba(40, 167, 69, 0.8)',
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: '#333'
                            }
                        },
                        y: {
                            ticks: {
                                color: '#333'
                            },
                            beginAtZero: true
                        }
                    }
                }
            });

            // Update chart when dropdown changes
            document.getElementById('revenueDuration').addEventListener('change', function() {
                let days = parseInt(this.value);
                let labels = Array.from({
                    length: days / 7
                }, (_, i) => "Week " + (i + 1));
                let data = Array.from({
                    length: days / 7
                }, () => Math.floor(Math.random() * 2500) + 1000);

                revenueChart.data.labels = labels;
                revenueChart.data.datasets[0].data = data;
                revenueChart.update();
            });
        });
    </script>
    <!-- Search Filter Script -->
    <script>
        document.getElementById("propertySearch").addEventListener("keyup", function() {
            let query = this.value.toLowerCase().trim();
            document.querySelectorAll("#propertyList .property-card").forEach(card => {
                let text = card.innerText.toLowerCase();
                // Auto reset: if query empty, show all
                card.style.display = query === "" || text.includes(query) ? "" : "none";
            });
        });
    </script>
    <!-- JS to load map dynamically -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var mapModal = document.getElementById('mapModal');
            mapModal.addEventListener('show.bs.modal', function(event) {
                var button = event.relatedTarget;
                var mapQuery = button.getAttribute('data-map');
                var iframe = document.getElementById('mapFrame');
                iframe.src = "https://www.google.com/maps?q=" + mapQuery + "&output=embed";
            });
        });
    </script>
    <script>
        function clearMissedUrgentMeetings() {
            const list = document.getElementById('urgentMeetingsList');
            if (!list) return;

            const items = list.querySelectorAll('.missed-item-urgent');

            items.forEach(item => {
                const id = item.getAttribute('data-id');
                if (id) {
                    localStorage.setItem('cleared_' + id, '1'); // mark cleared
                    item.remove();
                }
            });

            if (list.children.length === 0) {
                list.remove();
                const p = document.createElement('p');
                p.className = 'text-muted';
                p.id = 'noUrgentMeetingsText';
                p.innerText = "No urgent meetings in the next 3 days.";
                document.querySelector(".urgent-meetings").appendChild(p);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const list = document.getElementById('urgentMeetingsList');
            if (!list) return;

            const items = list.querySelectorAll('li');
            items.forEach(item => {
                const id = item.getAttribute('data-id');
                if (localStorage.getItem('cleared_' + id) === '1') {
                    item.remove();
                }
            });

            if (list.children.length === 0) {
                list.remove();
                const p = document.createElement('p');
                p.className = 'text-muted';
                p.id = 'noUrgentMeetingsText';
                p.innerText = "No urgent meetings in the next 3 days.";
                document.querySelector(".urgent-meetings").appendChild(p);
            }
        });
    </script>
    <script>
        function showNoBroadcasts(container) {
            // Prevent duplicate fallback
            if (container.querySelector('.no-broadcasts-box')) return;

            const emptyBox = document.createElement('div');
            emptyBox.className = "no-broadcasts-box text-center p-3 rounded small";
            emptyBox.innerHTML = `
            <h6 class="text-muted mb-1">📭 No Broadcasts</h6>
            <p class="text-muted mb-0">Nothing shared by admin.</p>
        `;
            const header = container.querySelector('.d-flex');
            header.insertAdjacentElement('afterend', emptyBox);
        }

        function clearBroadcasts() {
            const container = document.querySelector('.messages');
            if (!container) return;

            const list = container.querySelectorAll('.message-item');
            if (!list.length) return;

            // Remove broadcasts & store cleared status
            list.forEach((msg) => {
                const id = msg.dataset.id;
                localStorage.setItem('cleared_broadcast_' + id, '1');
                msg.remove();
            });

            // If none left, show "No Broadcasts"
            if (!container.querySelector('.message-item')) {
                showNoBroadcasts(container);
            }
        }

        // Hide cleared broadcasts on page load
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.querySelector('.messages');
            if (!container) return;

            const msgs = container.querySelectorAll('.message-item');
            msgs.forEach((msg) => {
                const id = msg.dataset.id;
                if (localStorage.getItem('cleared_broadcast_' + id) === '1') {
                    msg.remove();
                }
            });

            // If none to show on load
            if (!container.querySelector('.message-item')) {
                showNoBroadcasts(container);
            }
        });
    </script>


</body>

</html>