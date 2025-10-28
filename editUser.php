<?php
include("../config.php");
session_start();

// ✅ Check admin access
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../signin");
    exit();
}

// ✅ Validate User ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['errorMessage'] = "Invalid User ID.";
    header("Location: users.php");
    exit();
}
$userId = intval($_GET['id']);

// ✅ Fetch existing user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $_SESSION['errorMessage'] = "User not found.";
    header("Location: users.php");
    exit();
}

// ✅ Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone_number']);
    $gender = trim($_POST['gender']);
    $city = trim($_POST['city']);
    $role = trim($_POST['role']);
    $status = trim($_POST['status']);
    $password = trim($_POST['password']);

    if (!empty($password)) {
        $password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET full_name=?, username=?, email=?, phone_number=?, gender=?, city=?, role=?, status=?, password=?, updated_at=NOW() WHERE id=?";
        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param("sssssssssi", $full_name, $username, $email, $phone, $gender, $city, $role, $status, $password, $userId);
    } else {
        $sql = "UPDATE users SET full_name=?, username=?, email=?, phone_number=?, gender=?, city=?, role=?, status=?, updated_at=NOW() WHERE id=?";
        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param("ssssssssi", $full_name, $username, $email, $phone, $gender, $city, $role, $status, $userId);
    }

    if ($stmt2->execute()) {
        $_SESSION['successMessage'] = "✅ User updated successfully!";
        // Prevent resubmission
        header("Location: editUser.php?id=$userId");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .form-container {
            max-width: 750px;
            margin: 30px auto;
        }

        .form-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-top: 20px;
            margin-bottom: 10px;
            border-left: 4px solid #0d6efd;
            padding-left: 10px;
        }

        .btn-custom {
            padding: 7px 16px;
            font-size: 15px;
        }

        .fade-out {
            transition: opacity 0.5s ease-in-out;
        }

        .fade-out.hide {
            opacity: 0;
        }
    </style>
</head>

<body>

    <div class="container form-container">
        <h3 class="mb-4">✏️ Edit User (ID: <?= htmlspecialchars($user['id'] ?? '', ENT_QUOTES); ?>)</h3>

        <!-- ✅ Success Alert -->
        <?php if (!empty($_SESSION['successMessage'])): ?>
            <div id="successAlert" class="alert alert-success fade-out">
                <?= $_SESSION['successMessage'];
                unset($_SESSION['successMessage']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="card p-4 shadow-sm">

            <!-- 🔹 Personal Information -->
            <div class="form-section-title">Personal Information</div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control"
                        value="<?= htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                        value="<?= htmlspecialchars(!empty($user['username']) ? $user['username'] : 'N/A', ENT_QUOTES); ?>">
                </div>
            </div>

            <!-- 🔹 Contact Information -->
            <div class="form-section-title">Contact Information</div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES); ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" class="form-control"
                        value="<?= htmlspecialchars($user['phone_number'] ?? '', ENT_QUOTES); ?>">
                </div>
            </div>

            <!-- 🔹 Account Settings -->
            <div class="form-section-title">Account Settings</div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        <?php
                        $roles = ['admin', 'tenant', 'serviceprovider', 'manager', 'landlord'];
                        $currentRole = strtolower(str_replace(' ', '', $user['role'] ?? '')); // normalize

                        foreach ($roles as $r) {
                            $selected = ($currentRole === $r) ? "selected" : "";
                            $displayName = ucwords(str_replace('serviceprovider', 'Service Provider', $r));
                            echo "<option value='$r' $selected>$displayName</option>";
                        }

                        ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= ($user['status'] === 'active') ? 'selected' : '' ?>>active</option>
                        <option value="inactive" <?= ($user['status'] === 'inactive') ? 'selected' : '' ?>>inactive</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select...</option>
                        <option value="Male" <?= ($user['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= ($user['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
            </div>

            <!-- 🔹 Password -->
            <div class="form-section-title">Password</div>
            <label class="form-label">(Leave blank to keep current password)</label>
            <input type="password" name="password" class="form-control mb-3">

            <!-- 🔹 Submit Buttons -->
            <div class="mt-4">
                <button type="submit" class="btn btn-primary btn-custom">💾 Save Changes</button>
                <a href="allUsers" class="btn btn-secondary btn-custom">← Back</a>
            </div>
        </form>
    </div>

    <script>
        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            let alertBox = document.getElementById('successAlert');
            if (alertBox) alertBox.classList.add('hide');
        }, 5000);
    </script>

</body>

</html>