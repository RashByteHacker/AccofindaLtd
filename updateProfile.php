<?php
session_start();
require '../config.php';

// Redirect if not logged in
if (!isset($_SESSION['email'])) {
    header('Location: ../login.php');
    exit();
}

$email = $_SESSION['email'];

// Fetch user details
$stmt = $conn->prepare("SELECT full_name, username, phone_number, gender, date_of_birth, city, address_line1, address_line2, bio, role, service_type 
                        FROM users 
                        WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Handle form submission (POST-Redirect-GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name    = trim($_POST['full_name']);
    $username     = !empty($_POST['username']) ? trim($_POST['username']) : $user['username']; // fallback to existing username
    $phone_number = trim($_POST['phone_number']);
    $gender       = trim($_POST['gender']);
    $dob          = trim($_POST['date_of_birth']);
    $city         = trim($_POST['city']);
    $address1     = trim($_POST['address_line1']);
    $address2     = trim($_POST['address_line2']);
    $bio          = trim($_POST['bio']);
    $service_type = isset($_POST['service_type']) ? trim($_POST['service_type']) : $user['service_type'];
    $password     = $_POST['password'];

    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users 
            SET full_name=?, username=?, phone_number=?, gender=?, date_of_birth=?, city=?, address_line1=?, address_line2=?, bio=?, service_type=?, password=? 
            WHERE email=?");
        $stmt->bind_param("ssssssssssss", $full_name, $username, $phone_number, $gender, $dob, $city, $address1, $address2, $bio, $service_type, $hashed_password, $email);
    } else {
        $stmt = $conn->prepare("UPDATE users 
            SET full_name=?, username=?, phone_number=?, gender=?, date_of_birth=?, city=?, address_line1=?, address_line2=?, bio=?, service_type=? 
            WHERE email=?");
        $stmt->bind_param("sssssssssss", $full_name, $username, $phone_number, $gender, $dob, $city, $address1, $address2, $bio, $service_type, $email);
    }
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid resubmission
    header("Location: updateProfile?success=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <script src="https://kit.fontawesome.com/a2d04b05a4.js" crossorigin="anonymous"></script>
    <style>
        .fade-out {
            transition: opacity 1s ease-out;
            opacity: 0;
        }

        body {
            background: linear-gradient(135deg, #484e4fff, #272e2fff);
            margin: auto;

        }

        .container {
            max-width: 650px;
        }
    </style>
</head>

<body class="bg-light">

    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <button type="button" class="btn btn-outline-light me-3" onclick="history.back()">
                <i class="fa fa-arrow-left"></i> Back
            </button>
            <a class="navbar-brand fw-bold text-white" href="#">
                <i class="fa-solid fa-user-gear"></i> Update Profile
            </a>
        </div>
    </nav>

    <div class="container mt-5">
        <?php if (isset($_GET['success'])): ?>
            <div id="successMsg" class="alert alert-success text-center">
                ✅ Profile updated successfully!
            </div>
        <?php endif; ?>

        <div class="card shadow-lg border-0 rounded-3">
            <div class="card-body p-4">
                <h3 class="text-center text-white fw-bold mb-4 py-2 px-3 rounded shadow-sm" style="background-color: #3e444eff;">
                    <i class="fas fa-user-edit me-2"></i> Edit Your Profile
                </h3>

                <form method="POST" action="">
                    <!-- Personal Info Section -->
                    <div class="p-3 mb-4 rounded bg-light border">
                        <h5 class="text-white bg-dark p-2 rounded">
                            <i class="fas fa-id-card me-2"></i> Personal Information
                        </h5>
                        <div class="row mt-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control"
                                    value="<?= htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control"
                                    value="<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES) ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone_number" class="form-control"
                                    value="<?= htmlspecialchars($user['phone_number'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">Select</option>
                                    <option value="Male" <?= ($user['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($user['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Location Section -->
                    <div class="p-3 mb-4 rounded bg-light border">
                        <h5 class="text-white bg-dark p-2 rounded">
                            <i class="fas fa-map-marker-alt me-2"></i> Location Details
                        </h5>
                        <div class="row mt-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control"
                                    value="<?= htmlspecialchars($user['date_of_birth'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" name="city" class="form-control"
                                    value="<?= htmlspecialchars($user['city'] ?? '', ENT_QUOTES) ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="address_line1" class="form-control"
                                value="<?= htmlspecialchars($user['address_line1'] ?? '', ENT_QUOTES) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="address_line2" class="form-control"
                                value="<?= htmlspecialchars($user['address_line2'] ?? '', ENT_QUOTES) ?>">
                        </div>
                    </div>

                    <!-- Service Type (For Service Providers Only) -->
                    <?php if (strtolower($user['role']) === 'service provider'): ?>
                        <div class="p-3 mb-4 rounded bg-light border">
                            <h5 class="text-white bg-dark p-2 rounded">
                                <i class="fas fa-tools me-2"></i> Service Category
                            </h5>
                            <div class="mb-3 mt-3">
                                <label class="form-label">Select Service Type</label>
                                <select name="service_type" class="form-select" required>
                                    <option value="">-- Select a Service --</option>
                                    <option value="Repairs" <?= ($user['service_type'] ?? '') === 'Repairs' ? 'selected' : '' ?>>Repairs</option>
                                    <option value="Plumbing" <?= ($user['service_type'] ?? '') === 'Plumbing' ? 'selected' : '' ?>>Plumbing</option>
                                    <option value="Electrical" <?= ($user['service_type'] ?? '') === 'Electrical' ? 'selected' : '' ?>>Electrical</option>
                                    <option value="Cleaning" <?= ($user['service_type'] ?? '') === 'Cleaning' ? 'selected' : '' ?>>Cleaning</option>
                                    <option value="Laundry" <?= ($user['service_type'] ?? '') === 'Laundry' ? 'selected' : '' ?>>Laundry</option>
                                    <option value="Wi-Fi Setup" <?= ($user['service_type'] ?? '') === 'Wi-Fi Setup' ? 'selected' : '' ?>>Wi-Fi Setup</option>
                                    <option value="Movers" <?= ($user['service_type'] ?? '') === 'Movers' ? 'selected' : '' ?>>Movers</option>
                                    <option value="Furniture/Fittings" <?= ($user['service_type'] ?? '') === 'Furniture/Fittings' ? 'selected' : '' ?>>Furniture/Fittings</option>
                                    <option value="Gas Refill" <?= ($user['service_type'] ?? '') === 'Gas Refill' ? 'selected' : '' ?>>Gas Refill</option>
                                    <option value="Transport Services" <?= ($user['service_type'] ?? '') === 'Transport Services' ? 'selected' : '' ?>>Transport Services</option>
                                    <option value="Garbage Pickup" <?= ($user['service_type'] ?? '') === 'Garbage Pickup' ? 'selected' : '' ?>>Garbage Pickup</option>
                                    <option value="Security" <?= ($user['service_type'] ?? '') === 'Security' ? 'selected' : '' ?>>Security</option>
                                    <option value="Saloon & Barber Sevices" <?= ($user['service_type'] ?? '') === 'Saloon & Barber Sevices' ? 'selected' : '' ?>>Saloon & Barber Sevices</option>
                                    <option value="Fumigation Services" <?= ($user['service_type'] ?? '') === 'Fumigation Services' ? 'selected' : '' ?>>Fumigation Services</option>
                                    <option value="Pedicure & Manicure" <?= ($user['service_type'] ?? '') === 'Pedicure & Manicure' ? 'selected' : '' ?>>Pedicure & Manicure</option>
                                    <option value="Painters" <?= ($user['service_type'] ?? '') === 'Painters' ? 'selected' : '' ?>>Painters</option>
                                    <option value="Photography/Videography" <?= ($user['service_type'] ?? '') === 'Photography/Videography' ? 'selected' : '' ?>>Photography/Videography</option>
                                    <option value="Catering" <?= ($user['service_type'] ?? '') === 'Catering' ? 'selected' : '' ?>>Catering</option>
                                    <option value="Healthcare" <?= ($user['service_type'] ?? '') === 'Healthcare' ? 'selected' : '' ?>>Healthcare</option>
                                    <option value="Touring" <?= ($user['service_type'] ?? '') === 'Touring' ? 'selected' : '' ?>>Touring</option>
                                    <option value="Gymnastics" <?= ($user['service_type'] ?? '') === 'Gymnastics' ? 'selected' : '' ?>>Gymnastics</option>
                                    <option value="Landscaping" <?= ($user['service_type'] ?? '') === 'Landscaping' ? 'selected' : '' ?>>Landscaping</option>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>



                    <!-- About Me Section -->
                    <div class="p-3 mb-4 rounded bg-light border">
                        <h5 class="text-white bg-dark p-2 rounded">
                            <i class="fas fa-info-circle me-2"></i> About Me
                        </h5>
                        <div class="mb-3 mt-3">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-control" rows="3"><?= htmlspecialchars($user['bio'] ?? '', ENT_QUOTES) ?></textarea>
                        </div>
                    </div>

                    <!-- Security Section -->
                    <div class="p-3 mb-4 rounded bg-light border">
                        <h5 class="text-white bg-dark p-2 rounded">
                            <i class="fas fa-lock me-2"></i> Security Settings
                        </h5>
                        <div class="mb-3 mt-3">
                            <label class="form-label">New Password (optional)</label>
                            <input type="password" name="password" class="form-control"
                                placeholder="Leave blank to keep current password">
                        </div>
                    </div>

                    <!-- Submit Section -->
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary px-4 py-2">
                            <i class="fas fa-save me-2"></i> Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="text-center text-white mt-5 mb-3">
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script>
        // Auto-hide success message
        setTimeout(() => {
            const msg = document.getElementById('successMsg');
            if (msg) {
                msg.classList.add('fade-out');
                setTimeout(() => msg.remove(), 1000);
            }
        }, 3000);
    </script>
</body>

</html>