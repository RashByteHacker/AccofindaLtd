<?php
session_start();
require '../config.php';

// Restrict access to logged-in users (customers)
if (!isset($_SESSION['email'])) {
    die("Access denied. Please login first.");
}

$userEmail = $_SESSION['email'];
$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'Customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provider_id = $_POST['provider_id'] ?? null;
    $provider_email = $_POST['provider_email'] ?? '';
    $service_type = $_POST['service_type'] ?? '';
    $service_title = $_POST['service_title'] ?? '';
    $description = $_POST['description'] ?? '';
    $price_range = $_POST['price_range'] ?? '';
    $availability_days = $_POST['availability_days'] ?? '';
    $availability_hours = $_POST['availability_hours'] ?? '';
    $location = $_POST['location'] ?? '';
    $created_at = date('Y-m-d H:i:s');
    $updated_at = date('Y-m-d H:i:s');
    $status = 'pending';
    $rating = 0;

    // Insert into services table
    $stmt = $conn->prepare("INSERT INTO services 
        (user_email, provider_id, service_type, service_title, description, price_range, availability_days, availability_hours, location, rating, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssssssdsss", $userEmail, $provider_id, $service_type, $service_title, $description, $price_range, $availability_days, $availability_hours, $location, $rating, $status, $created_at, $updated_at);

    if ($stmt->execute()) {
        $successMsg = "Service request submitted successfully!";
    } else {
        $errorMsg = "Error submitting service: " . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Service</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #84878aff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .navbar-brand {
            font-weight: 600;
        }

        .form-section {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            max-width: 600px;
        }

        .form-control,
        .form-select {
            font-size: 0.875rem;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            transition: all 0.3s;

        }

        .form-control:focus,
        .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 5px rgba(13, 110, 253, 0.3);
            outline: none;
        }

        .btn-submit {
            font-size: 0.75rem;
            padding: 0 12px;
            height: 25px;
        }

        footer {
            background: #212529;
            color: #eee;
            text-align: center;
            padding: 10px 7px;
            font-size: 0.85rem;
            margin-top: auto;
        }
    </style>
</head>

<body>

    <!-- Top Nav -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <!-- Back Button on Left -->
            <a href="javascript:history.back()" class="btn btn-outline-light btn-sm">
                <i class="fa fa-arrow-left me-1"></i> Back
            </a>

            <!-- Center Brand / Page Title -->
            <span class="navbar-brand mx-auto text-center">
                Service Form
            </span>

            <!-- User Info on Right -->
            <div class="d-flex align-items-center text-white">
                <i class="fa fa-user-circle me-2"></i> <?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userRole) ?>)
            </div>
        </div>
    </nav>


    <!-- Main Content -->
    <div class="container my-5 flex-grow-1">
        <div class="row justify-content-center">
            <div class="col-md-8 form-section">
                <h4 class="mb-4 text-center"><i class="fa fa-plus-circle me-2"></i> Request a New Service</h4>

                <?php if (!empty($successMsg)): ?>
                    <div class="alert alert-success"><?= $successMsg ?></div>
                <?php elseif (!empty($errorMsg)): ?>
                    <div class="alert alert-danger"><?= $errorMsg ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="provider_id" value="<?= htmlspecialchars($_GET['provider_id'] ?? '') ?>">
                    <input type="hidden" name="provider_email" value="<?= htmlspecialchars($_GET['provider_email'] ?? '') ?>">
                    <input type="hidden" name="service_type" value="<?= htmlspecialchars($_GET['service_type'] ?? '') ?>">

                    <div class="mb-3">
                        <label class="form-label">Service Title</label>
                        <input type="text" class="form-control form-control-sm" name="service_title" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control form-control-sm" name="description" rows="2"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price Range / Enter Price</label>
                        <input type="text" class="form-control form-control-sm" name="price_range" placeholder="ksh ">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label">Availability Days</label>
                            <select class="form-select form-select-sm" name="availability_days">
                                <option value="">Select</option>
                                <option value="Mon–Fri">Mon–Fri</option>
                                <option value="Mon–Sun">Mon–Sun</option>
                                <option value="Weekends">Weekends</option>
                            </select>
                        </div>
                        <div class="col">
                            <label class="form-label">Availability Hours</label>
                            <select class="form-select form-select-sm" name="availability_hours">
                                <option value="">Select</option>
                                <option value="9am–5pm">9am–5pm</option>
                                <option value="10am–6pm">10am–6pm</option>
                                <option value="Custom">Custom</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input type="text" class="form-control form-control-sm" name="location" placeholder="City or Area">
                    </div>

                    <div class="d-flex justify-content-start mt-3">
                        <button type="submit" class="btn btn-success btn-submit px-4" style="height: 35px; font-size: 0.75rem; border-radius: 6px;">
                            <i class="fa fa-paper-plane me-1"></i>Request/Pay
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        &copy; <?= date('Y') ?> Accofinda. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>