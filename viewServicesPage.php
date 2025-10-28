<?php
session_start();
require '../config.php';

// Access control: only admin, service provider, landlord, manager, tenant, client can view
$allowedRoles = ['admin', 'service provider', 'landlord', 'manager', 'tenant', 'client'];
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    header("Location: ../login.php");
    exit();
}

// Get provider ID from URL
$provider_id = intval($_GET['provider_id'] ?? 0);

// Fetch provider info
$stmt = $conn->prepare("SELECT id, full_name, email, service_type FROM users WHERE id=? AND role='service provider'");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$providerRes = $stmt->get_result();
$provider = $providerRes->fetch_assoc();
$stmt->close();

if (!$provider) {
    die("Service provider not found.");
}

// Fetch provider's services
$services = [];
$stmt = $conn->prepare("SELECT * FROM provider_services WHERE provider_id=? AND status='active'");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Services Offered by <?= htmlspecialchars($provider['full_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f0f2f5;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: Arial, sans-serif;
        }

        /* Navbar */
        .navbar {
            background: #000;
        }

        .navbar a,
        .navbar .navbar-brand {
            color: #fff !important;
        }

        /* Footer */
        footer {
            margin-top: auto;
            background: #000;
            color: #fff;
            text-align: center;
            padding: 15px;
        }

        /* Service Card */
        .service-card {
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            background: #000;
            color: #fff;
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
        }

        /* Card Image */
        .service-card img {
            height: 140px;
            object-fit: cover;
            width: 100%;
        }

        /* Badge for service mode */
        .service-card .badge {
            font-size: 0.7rem;
            padding: 0.25em 0.5em;
            font-weight: 500;
            position: absolute;
            top: 10px;
            right: 10px;
        }

        /* Card body */
        .service-card-body {
            padding: 10px 12px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Meta info button */
        .service-meta-btn {
            font-size: 0.7rem;
            background-color: #ffc107;
            color: #000;
            border: none;
            padding: 4px 6px;
            border-radius: 5px;
        }

        /* Buttons container */
        .service-card-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }

        /* Pay button */
        .btn-pay {
            background-color: #10531fff;
            border: none;
            flex: 1;
            color: white;
            margin-right: 5px;
        }

        .btn-pay:hover {
            background-color: #1aac39ff;
        }

        /* Report button */
        .btn-danger-bottom {
            background-color: #b02a37;
            border: none;
            flex: 1;
            color: white;
            margin-left: 5px;
        }

        .btn-danger-bottom:hover {
            background-color: #dc3545;
        }

        /* Responsive text */
        .service-card h6 {
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .service-card p {
            font-size: 0.8rem;
            margin-bottom: 5px;
        }
    </style>
</head>

<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg px-3 py-3">
        <a href="javascript:history.back()" class="btn btn-dark bg-secondary me-3"><i class="fa fa-arrow-left"></i> Back</a>
        <a class="navbar-brand mx-auto">Services of <?= htmlspecialchars($provider['full_name']) ?></a>
        <a href="../logout" class="btn btn-danger">Logout</a>
    </nav>

    <div class="container my-5">
        <div class="text-center mb-4">
            <h2>Services Offered by <?= htmlspecialchars($provider['full_name']) ?></h2>
            <p class="text-muted"><?= htmlspecialchars($provider['service_type']) ?></p>
        </div>

        <?php if (!empty($services)): ?>
            <div class="row g-4">
                <?php foreach ($services as $s):
                    $images = array_filter([$s['image_1'], $s['image_2'], $s['image_3']]);

                    // Map service mode to badge color
                    $badgeColor = match ($s['service_mode']) {
                        'At Provider Location' => 'primary',
                        'On-site' => 'success',
                        'Remote' => 'warning',
                        default => 'secondary',
                    };
                ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card service-card position-relative">
                            <?php if (!empty($images)): ?>
                                <img src="../uploads/<?= htmlspecialchars($images[0]) ?>" alt="<?= htmlspecialchars($s['title']) ?>">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/400x120?text=No+Image" alt="No Image">
                            <?php endif; ?>
                            <div class="service-card-body">
                                <div>
                                    <h6><?= htmlspecialchars($s['title']) ?></h6>
                                    <p><?= htmlspecialchars($s['description']) ?></p>
                                    <strong>Price: KES <?= number_format($s['price'], 2) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <button class="service-meta-btn">Last Updated: <?= date("d M Y", strtotime($s['updated_at'])) ?></button>
                                    <span class="badge bg-<?= $badgeColor ?>"><?= htmlspecialchars($s['service_mode']) ?></span>
                                </div>
                                <div class="service-card-buttons mt-2">
                                    <a href="requestService?service_id=<?= $s['id'] ?>" class="btn btn-pay btn-sm">
                                        <i class="fa fa-credit-card me-1"></i> Request / Pay
                                    </a>
                                    <a href="reportService?service_id=<?= $s['id'] ?>" class="btn btn-danger-bottom btn-sm">
                                        <i class="fa fa-exclamation-triangle me-1"></i> Report Service
                                    </a>
                                </div>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                This service provider has not listed any services yet.
            </div>
        <?php endif; ?>

    </div>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>
</body>

</html>