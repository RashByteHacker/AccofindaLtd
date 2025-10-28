<?php
session_start();
require '../config.php';
include '../Shared/fetchNotification.php';
// Restrict access to service provider only
if (!isset($_SESSION['email']) || strtolower($_SESSION['role']) !== 'service provider') {
    die("Access denied. Service Providers only.");
}

$providerEmail = $_SESSION['email'] ?? '';

// Fetch provider details including status
$stmt = $conn->prepare("SELECT id, full_name, email, phone_number, service_type, serviceprovider_status 
                        FROM users 
                        WHERE email = ?");
$stmt->bind_param("s", $providerEmail);
$stmt->execute();
$result = $stmt->get_result();
$provider = $result->fetch_assoc();
$stmt->close();

if (!$provider) {
    die("Provider not found.");
}

$providerId       = $provider['id'];
$providerName     = $provider['full_name'] ?? 'Service Provider';
$providerPhone    = $provider['phone_number'] ?? 'N/A';
$providerCategory = $provider['service_type'] ?? 'General Services';
$providerStatus   = $provider['serviceprovider_status'] ?? 'Pending';

// Dashboard stats
$newRequests = 0;
$topClients = 0;
$paymentsDue = 0;
$completedJobs = 0;

$sql = "SELECT COUNT(*) FROM services WHERE provider_id = ? AND status = 'pending'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $providerId);
$stmt->execute();
$stmt->bind_result($newRequests);
$stmt->fetch();
$stmt->close();

$sql = "SELECT COUNT(*) FROM services WHERE provider_id = ? AND status = 'completed'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $providerId);
$stmt->execute();
$stmt->bind_result($completedJobs);
$stmt->fetch();
$stmt->close();

// Provider’s services
$myServices = [];
$sql = "SELECT service_title, description FROM services WHERE provider_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $providerId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $myServices[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Provider - Accofinda</title>
    <link rel="icon" type="image/jpg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        /* Reset + Base */
        html,
        body {
            height: 100%;
            margin: 0;
            /* ✅ stops left/right scroll */
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            /* stick full height */
        }

        /* Dashboard wrapper (always side-by-side) */
        .dashboard {
            display: flex;
            flex: 1;
            /* take remaining height after navbar */
            min-height: 100dvh;
            /* ensures full screen height */
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #334047, #515652);
            color: #ffffff;
            padding: 30px 10px;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            font-size: 12px;
            display: flex;
            flex-direction: column;
            height: 1750px;
            /* allow flex parent to control */
            min-height: 100%;
            /* always stretch inside dashboard */
        }

        /* Sidebar links */
        .sidebar a {
            display: block;
            padding: 10px 20px;
            color: #ccc;
            text-decoration: none;
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
            margin-top: 8px;
            /* small gap between items */
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: #000000;
            color: #fff;
            border-left: 4px solid #0d6efd;
        }

        /* Gap between profile card and nav items */
        .sidebar-profile {
            margin-bottom: 20px;
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
            max-width: 280px;
        }

        /* Main content */
        .main {
            flex: 1;
            padding: 30px;
            flex-direction: column;
            display: flex;
        }

        /* Avatar */
        .avatar-circle {
            font-weight: 600;
            font-size: 1.1rem;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Profile picture */
        .profile-pic {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #0d6efd;
        }

        /* Navbar */
        .navbar {
            width: 100%;
            white-space: nowrap;
        }

        .navbar .container-fluid {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: nowrap;
        }

        .navbar .dropdown-menu {
            position: absolute !important;
            z-index: 3000;
        }

        /* Cards */
        .dashboard-cards .stat-card {
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            color: #fff;
        }

        .bg-requests {
            background: linear-gradient(135deg, #50575f, #6b7077);
        }

        .bg-clients {
            background: linear-gradient(135deg, #354539, #49624a);
        }

        .bg-payments {
            background: linear-gradient(135deg, #524133, #6a503e);
        }

        .bg-completed {
            background: linear-gradient(135deg, #3e3353, #5a4a70);
        }

        /* Footer fixed at bottom */
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #212529;
            color: #fff;
            text-align: center;
            padding: 12px 8px;
            font-size: 0.85rem;
            z-index: 999;
            /* keeps it above other elements */
        }
    </style>
</head>

<body>

    <div class="dashboard d-flex min-dvh-100">
        <!-- Sidebar -->
        <aside class="sidebar ">

            <div class="sidebar-profile text-left">
                <img src="../Images/AccofindaLogo1.jpg" alt="Logo" class="mb-3" width="40" height="40">
                <div class="profile-card">
                    <h6 class="mb-1"><?= htmlspecialchars($providerName) ?></h6>
                    <p class="mb-1"><i class="fa fa-envelope"></i> <?= htmlspecialchars($providerEmail) ?></p>
                    <p class="mb-0"><i class="fa fa-phone"></i> <?= htmlspecialchars($providerPhone) ?></p>
                </div>
            </div>
            <nav>
                <a href="#" class="active"><i class="fa fa-chart-line me-2" style="color:#FFD700;"></i> Dashboard</a>
                <a href="myServices"><i class="fa fa-briefcase me-2" style="color:#FFFFFF;"></i> My Services</a>
                <a href="#"><i class="fa fa-toolbox me-2" style="color:#4CAF50;"></i> Service Requests</a>
                <a href="#"><i class="fa fa-users me-2" style="color:#FF69B4;"></i> My Clients</a>
                <a href="#"><i class="fa fa-credit-card me-2" style="color:#8A2BE2;"></i> Payments</a>
                <a href="providerMessages"><i class="fa fa-envelope me-2" style="color:#00CED1;"></i> Messages</a>
                <a href="../Shared/services"><i class="fa fa-tools me-2" style="color:#fd7e14;"></i> Service Providers</a>
                <a href="../Shared/updateProfile"><i class="fa fa-user-edit me-2" style="color:#198754;"></i> Update Profile</a>
                <a href="../Shared/support"><i class="fa fa-headset me-2" style="color:#1E90FF;"></i> Contact Support</a>
                <a href="../logout" class="mt-3 d-block text-center"
                    style="background:#972e2e;color:#fff;padding:10px 15px;border-radius:8px;text-decoration:none;font-weight:500;">
                    <i class="fa fa-sign-out-alt me-2"></i> Logout
                </a>
            </nav>
        </aside>

        <!-- Main content -->
        <main class="main" class="content flex-grow-1 d-flex flex-column" style="margin: 0; padding: 0;">
            <!-- Top navbar -->
            <nav class="navbar navbar-dark bg-secondary shadow-sm w-100 mb-2">
                <div class="container-fluid px-3 py-2">
                    <!-- Left: Avatar + Welcome -->
                    <div class="d-flex align-items-center flex-nowrap">
                        <div class="avatar-circle bg-light text-dark me-2">
                            <?php
                            $parts = explode(' ', trim($providerName));
                            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                            echo $initials;
                            ?>
                        </div>
                        <div class="text-white small me-2">
                            <div>Welcome, <?= htmlspecialchars($providerName) ?>! 🌟</div>
                            <div class="d-flex align-items-center gap-2">
                                <small class="text-light"><?= ucfirst($_SESSION['role']) ?></small>
                                <!-- ✅ Service Type as Small Dark Button -->
                                <button class="btn btn-sm btn-dark px-2 py-1">
                                    <?= htmlspecialchars($providerCategory) ?>
                                </button>
                            </div>
                        </div>
                        <span class="badge bg-success ms-2">Online</span>
                    </div>

                    <!-- Center: Balance -->
                    <div class="mx-auto">
                        <button class="btn btn-sm btn-danger fw-bold px-2 py-1 me-2">
                            <i class="fa fa-wallet me-1"></i>
                            Balance: $<?= number_format(250.75, 2) // Replace with $providerBalance 
                                        ?>
                        </button>
                    </div>

                    <!-- Right: Menu -->
                    <div class="ms-auto">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-dark" type="button" data-bs-toggle="dropdown">
                                <i class="fa fa-bars"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="../Shared/updateProfile">
                                        <i class="fa fa-user-edit me-2"></i> Update Profile
                                    </a>
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


            <!-- Dashboard cards -->
            <div class="container my-3 dashboard-cards">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-requests">
                            <h6>New Requests</h6>
                            <p><?= $newRequests ?> Pending</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-clients">
                            <h6>Top Clients</h6>
                            <p><?= $topClients ?> Active</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-payments">
                            <h6>Payments Due</h6>
                            <p>$<?= number_format($paymentsDue, 2) ?> Pending</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-completed">
                            <h6>Completed Jobs</h6>
                            <p><?= $completedJobs ?> Done</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Alert -->
            <div class="container mb-3">
                <?php if (strtolower($providerStatus) === 'pending'): ?>
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="fa fa-clock me-2"></i>
                        <div>
                            Your account is <strong>Pending Approval</strong>. You will not be visible to clients until approved.
                        </div>
                    </div>
                <?php elseif (strtolower($providerStatus) === 'suspended'): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fa fa-ban me-2"></i>
                        <div>
                            Your account has been <strong>Suspended</strong>.
                            You are invisible to clients until Unsuspended.
                            Please contact <a href="../Shared/support" class="text-decoration-underline text-light fw-bold">Support</a> for assistance.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- My Services -->
            <div class="container mb-4">
                <h5 class="mb-3">My Services Overview</h5>
                <div class="row g-3">
                    <?php if ($myServices): foreach ($myServices as $srv): ?>
                            <div class="col-md-4">
                                <div class="card p-3 h-100">
                                    <h6><i class="fa fa-tools"></i> <?= htmlspecialchars($srv['service_title']) ?></h6>
                                    <p class="small text-muted"><?= htmlspecialchars($srv['description']) ?></p>
                                </div>
                            </div>
                        <?php endforeach;
                    else: ?>
                        <p class="text-muted">No services registered yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <footer>
                &copy; <?= date('Y') ?> Accofinda. All Rights Reserved.
            </footer>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>