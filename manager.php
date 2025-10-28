<?php
session_start();
require '../config.php';

// ================== MANAGER ACCESS CHECK ==================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

// ================== MANAGER DETAILS ==================
$managerId    = $_SESSION['user_id'] ?? null;
$managerName  = $_SESSION['full_name'] ?? 'Manager User';
$managerEmail = $_SESSION['email'] ?? '';
$managerPhone = $_SESSION['phone_number'] ?? '';

if (empty($managerEmail) || empty($managerPhone)) {
    $stmt = $conn->prepare("SELECT full_name, email, phone_number FROM users WHERE id = ?");
    $stmt->bind_param("i", $managerId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $managerName  = $row['full_name'];
        $managerEmail = $row['email'];
        $managerPhone = $row['phone_number'];

        $_SESSION['full_name']     = $managerName;
        $_SESSION['email']         = $managerEmail;
        $_SESSION['phone_number']  = $managerPhone;
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
    $action = $_POST['action'] === 'approve' ? 'approved' : 'suspended';

    // Get manager details from session
    $managerId = $_SESSION['user_id'];
    $managerName = $_SESSION['full_name'];

    // Update property with who modified it and when
    $stmt = $conn->prepare("
        UPDATE properties 
        SET status = ?, 
            last_modified_by = ?, 
            last_modified_at = NOW()
        WHERE property_id = ?
    ");
    $stmt->bind_param("sii", $action, $managerId, $propertyId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['message'] = "Property #$propertyId has been $action by $managerName.";
    header("Location: managerDashboard.php");
    exit();
}

// ================== MANAGER PROPERTY MANAGEMENT ==================
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
    SELECT 'user' AS type, full_name AS title, created_at AS date FROM users
    UNION ALL
    SELECT 'property', title, created_at FROM properties
    UNION ALL
    SELECT 'booking', (SELECT p.title FROM properties p WHERE p.property_id = b.property_id), b.created_at FROM bookings b
    UNION ALL
    SELECT 'message', CONCAT(title, ' (', target_role, ')'), created_at FROM broadcastmessages
    ORDER BY date DESC
    LIMIT 6
";
$recentActivitiesResult = $conn->query($recentActivitiesQuery);
$recentActivities = [];
while ($row = $recentActivitiesResult->fetch_assoc()) {
    $recentActivities[] = $row;
}

// ================== FETCH ALL PROPERTIES WITH EXTRA INFO ==================
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
    LEFT JOIN users u ON u.id = p.last_modified_by
    LEFT JOIN users l ON l.email = p.landlord_email
    ORDER BY p.last_modified_at DESC
";

$result = $conn->query($query);

while ($row = $result->fetch_assoc()) {
    $allProperties[] = $row;
}

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
            background-color: #ffffffff;
            font-family: 'Segoe UI', sans-serif;
            font-size: 13px;
        }

        .sidebar {
            background: linear-gradient(180deg, #334047ff, #515652ff);
            min-height: 100vh;
            color: #ffffff;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);

        }

        .profile-card {
            background: rgba(0, 0, 0, 1);
            /* Slight transparent overlay */
            border-radius: 10px;
            padding: 10px;
            font-size: 0.85rem;
            color: white;
        }

        .sidebar-profile {
            background-color: #444944ff;
            /* Sidebar background */
            color: white;
        }

        .profile-card i {
            color: #ffffffff;
            /* Gold icons */
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
        }

        .table thead {
            background: #053405;
            color: white;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            /* Needed for absolute positioning of the button */
            padding-top: 3rem;
            /* Space for the top button */
            transition: transform 0.2s ease-in-out;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 5px;
            opacity: 0.9;
        }

        /* New black top-left button */
        .stat-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: black;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.96rem;
            border: none;
            cursor: default;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .avatar-circle {
            width: 40px;
            height: 40px;
            background-color: #000000ff;
            color: white;
            font-weight: bold;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        footer {
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 15px 10px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* Submenu container */
        .sidebar-dropdown .collapse {
            width: 100%;
            /* take full sidebar width */
        }

        /* Submenu box */
        .sidebar-dropdown .collapse .bg-dark {
            padding: 0;
            /* remove extra padding */
            margin: 0;
            /* remove any left margin */
            border-radius: 0.25rem;
            width: 100%;
            /* stay inside sidebar */
        }

        /* Submenu links aligned with main sidebar items */
        .sidebar-dropdown .collapse .bg-dark a.sidebar-link {
            display: block;
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
            /* same as main sidebar links */
            margin: 0;
        }

        /* Hover effect */
        .sidebar-dropdown .collapse .bg-dark a.sidebar-link:hover {
            background-color: #000000ff;
            color: #f6c23e !important;
        }

        /* Make the main toggle a flex container */
        .sidebar-dropdown a[data-bs-toggle="collapse"] {
            display: flex;
            align-items: center;
            justify-content: space-between;
            /* text left, caret right */
        }

        /* Arrow right after the text */
        .sidebar-dropdown a[data-bs-toggle="collapse"] .fa-caret-right {
            transition: transform 0.3s;
            margin-left: 0.25rem;
            /* optional space from text */
            font-size: 0.8rem;
        }

        /* Rotate arrow when expanded */
        .sidebar-dropdown a[data-bs-toggle="collapse"][aria-expanded="true"] .fa-caret-right {
            transform: rotate(90deg);
        }

        #propertySearch::placeholder {
            color: #ffffffff;
            /* light gray */
            opacity: 1;
            /* ensure it's fully visible */
        }
    </style>
</head>

<body>

    <div class="container-fluid">
        <div class="row">

            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0 d-flex flex-column">


                <!-- Profile Section -->
                <div class="sidebar-profile text-left p-3">
                    <img src="../Images/AccofindaLogo1.jpg"
                        alt="Accofinda Logo"
                        class="mb-3 sidebar-logo"
                        width="40" height="40">

                    <div class="profile-card p-2">
                        <h5 class="mb-1"><?php echo htmlspecialchars($managerName); ?></h5>
                        <p class="mb-1">
                            <i class="fa fa-envelope"></i> <?php echo htmlspecialchars($managerEmail); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fa fa-phone"></i> <?php echo htmlspecialchars($managerPhone); ?>
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
                                <a href="../Shared/allProperty" class="sidebar-link small d-flex align-items-center py-1">
                                    <i class="fa fa-list-ul me-2" style="color: #ffffffff;"></i> View All Properties
                                </a>
                            </div>
                        </div>
                    </div>

                    <a href="../Shared/broadcasts" class="sidebar-link"><i class=" fa fa-bullhorn me-2" style="color:#FF4500;"></i> Broadcasts</a>
                    <a href="#" class="sidebar-link"><i class="fa fa-calendar-check" style="color: #e83e8c;"></i> Bookings</a>
                    <a href="../Shared/services" class="sidebar-link"><i class="fa fa-tools" style="color: #fd7e14;"></i> Service Providers</a>
                    <a href="#" class="sidebar-link"><i class="fa fa-comments" style="color: #20c997;"></i> Messages</a>
                    <a href="#" class="sidebar-link"><i class="fa fa-headset" style="color: #17a2b8;"></i> Support</a>
                    <a href="#" class="sidebar-link"><i class="fa fa-chart-line" style="color: #ffffffff;"></i> Analytics</a>
                    <a href="#" class="sidebar-link"><i class="fa fa-chart-pie" style="color: #ffc107;"></i> Reports</a>
                    <a href="../Shared/updateProfile" class="sidebar-link"><i class="fa fa-user-edit me-2" style="color:#198754;"></i> Update Profile</a>
                    <div class="mt-3 pt-3">
                        <a href="../logout"
                            class="mt-3 d-block text-center"
                            style="background-color: #972e2eff; color:#fff; max-width: 200px;padding:10px 10px; border-radius:8px; text-decoration:none; font-weight:500;">
                            <i class="fa fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </nav>

            </div>
            <!-- Main Content Area -->
            <div class="col-md-10 p-0">

                <!-- Top Navbar -->
                <nav class="navbar navbar-expand-lg navbar-dark bg-secondary w-100" style="margin:0; padding:10 1rem; border-radius:0;">
                    <div class="d-flex align-items-center">
                        <!-- User Initials Avatar -->
                        <div class="avatar-circle me-2">
                            <?php
                            $parts = explode(' ', trim($managerName));
                            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                            echo $initials;
                            ?>
                        </div>
                        <div>
                            <div class="fw-bold text-white">
                                Welcome, <?php echo htmlspecialchars($managerName); ?>
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
                            <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
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
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card text-center text-white"
                                style="background: linear-gradient(135deg, #293b73ff, #09257aff); 
                position: relative; padding-top: 2.5rem;">

                                <!-- Compact Dropdown -->
                                <div class="dropdown" style="position: absolute; top: 8px; left: 8px;">
                                    <button class="btn btn-sm btn-dark dropdown-toggle py-2 px-3"
                                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                        style="font-size: 0.85rem; line-height: 1.2;">
                                        Total Users
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-dark p-1" style="font-size: 0.7rem; min-width: 150px;">
                                        <li class="dropdown-item py-1 px-2">Admins: <?= $userRoleCounts0['admin'] ?? 0 ?></li>
                                        <li class="dropdown-item py-1 px-2">Landlords: <?= $userRoleCounts0['landlord'] ?? 0 ?></li>
                                        <li class="dropdown-item py-1 px-2">Tenants: <?= $userRoleCounts0['tenant'] ?? 0 ?></li>
                                        <li class="dropdown-item py-1 px-2">Managers: <?= $userRoleCounts0['manager'] ?? 0 ?></li>
                                        <li class="dropdown-item py-1 px-2">Service Providers: <?= $userRoleCounts0['Service Provider'] ?? 0 ?></li>
                                    </ul>
                                </div>

                                <!-- Main Card Content -->
                                <i class="fa fa-users stat-icon"></i>
                                <h3 class="mt-2"><?= $userCount ?></h3>
                            </div>
                        </div>


                        <div class="col-md-3">
                            <div class="stat-card text-center text-white" style="background: linear-gradient(135deg, #000000ff, #a281b1ff);position: relative; padding-top: 2.5rem;">
                                <button class="stat-btn">Properties Listed</button>
                                <i class="fa fa-building stat-icon"></i>
                                <h3 class="mt-2"><?= $propertyCount ?></h3>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="stat-card text-center text-white" style="background: linear-gradient(135deg, #1a1919ff, #00d3baff);position: relative; padding-top: 2.5rem;">
                                <button class="stat-btn">Bookings</button>
                                <i class="fa fa-calendar-check stat-icon"></i>
                                <h3 class="mt-2"><?= $bookingCount ?></h3>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="stat-card text-center text-white" style="background: linear-gradient(135deg, #5a4f4bff, #30492bff);position: relative; padding-top: 2.5rem;">
                                <button class="stat-btn">Messages</button>
                                <i class="fa fa-envelope stat-icon"></i>
                                <h3 class="mt-2"><?= $messageCount ?></h3>
                            </div>
                        </div>
                    </div>

                    <!-- Properties & Recent Activities Row -->
                    <div class="row mb-4">

                        <!-- Latest Properties Table (Left) -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-secondary text-white">
                                    <i class="fa fa-building"></i> Latest Properties
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-striped table-hover mb-0">
                                        <thead class="table-dark sticky-top">
                                            <tr>
                                                <th>Title</th>
                                                <th>Owner Name</th>
                                                <th>Phone</th>
                                                <th>County</th>
                                                <th>Status</th>
                                                <th>Availability</th>
                                            </tr>
                                        </thead>
                                        <tbody>
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
                                <div class="card-header bg-secondary text-white">
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
                                                        <i class="fa fa-calendar-alt me-1"></i><?= htmlspecialchars($activity['date']) ?>
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
                                            $editor_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') . " (" . $createdAt . ")";
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

                        <!-- Recently Registered Users (Left) -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-dark text-white">
                                    <i class="fa fa-users"></i> Recently Registered Users
                                </div>
                                <!-- Scrollable if more than 4 records -->
                                <div class="card-body p-0" style="font-size: 0.85rem; max-height: 300px; overflow-y: auto;">
                                    <table class="table table-hover table-bordered mb-0">
                                        <thead class="table-secondary">
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Role</th>
                                                <th>Joined</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($recentUsers)): ?>
                                                <?php foreach (array_slice($recentUsers, 0, 10) as $user): // fetch up to 10, scroll if needed 
                                                ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($user['name']) ?></td>
                                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                                        <td><?= htmlspecialchars($user['phone']) ?></td>
                                                        <td>
                                                            <?php if (strtolower($user['role']) === 'tenant'): ?>
                                                                <span class="badge bg-info">Tenant</span>
                                                            <?php elseif (strtolower($user['role']) === 'landlord'): ?>
                                                                <span class="badge bg-warning text-dark">Landlord</span>
                                                            <?php elseif (strtolower($user['role']) === 'service provider'): ?>
                                                                <span class="badge bg-success">Service Provider</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary"><?= htmlspecialchars($user['role']) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($user['joined']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">No recent users found</td>
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
            </div>
        </div>
    </div>
    </div>
    <!-- Chart.js Script -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const ctx = document.getElementById('userGrowthChart').getContext('2d');
            const userGrowthChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ["Day 1", "Day 2", "Day 3", "Day 4", "Day 5", "Day 6", "Day 7"],
                    datasets: [{
                        label: 'New Users',
                        data: [5, 9, 7, 12, 8, 14, 10],
                        borderColor: '#ffffff',
                        backgroundColor: 'rgba(255, 255, 255, 0.3)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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

            // Change chart data based on dropdown selection
            document.getElementById('growthDuration').addEventListener('change', function() {
                let days = parseInt(this.value);
                let labels = Array.from({
                    length: days
                }, (_, i) => "Day " + (i + 1));
                let data = Array.from({
                    length: days
                }, () => Math.floor(Math.random() * 20) + 1);

                userGrowthChart.data.labels = labels;
                userGrowthChart.data.datasets[0].data = data;
                userGrowthChart.update();
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
            let query = this.value.toLowerCase();
            document.querySelectorAll("#propertyList .property-card").forEach(card => {
                let text = card.innerText.toLowerCase();
                card.style.display = text.includes(query) ? "" : "none";
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
    <!-- Footer -->
    <footer>
        &copy; <?= date('Y') ?> Accofinda.Rashid Developer
    </footer>
</body>

</html>