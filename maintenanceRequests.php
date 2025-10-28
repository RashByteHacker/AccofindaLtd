<?php
session_start();
require '../config.php';

// ✅ Ensure tenant is logged in
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'tenant') {
    header("Location: ../login.php");
    exit();
}

$tenant_email = $_SESSION['email'];

// ✅ Fetch tenant's property & unit from property_unit_details table
$stmt = $conn->prepare("
    SELECT pud.property_id, pud.unit_code 
    FROM property_unit_details pud
    INNER JOIN booking_requests br 
        ON br.unit_detail_id = pud.unit_detail_id
    INNER JOIN users u
        ON br.tenant_id = u.id
    WHERE u.email = ?
    LIMIT 1
");
$stmt->bind_param("s", $tenant_email);
$stmt->execute();
$stmt->bind_result($property_id, $unit_code);
$stmt->fetch();
$stmt->close();

$hasProperty = !empty($property_id) && !empty($unit_code);

// ✅ Fetch tenant details from users table
$stmt = $conn->prepare("SELECT full_name, phone_number FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $tenant_email);
$stmt->execute();
$stmt->bind_result($tenant_name, $tenant_phone);
$stmt->fetch();
$stmt->close();

// ✅ Handle new maintenance request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_request'])) {
    if (!$hasProperty) {
        $_SESSION['errorMsg'] = "You are not assigned to any property. You cannot raise a maintenance request.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $title = $_POST['title'];
    $description = $_POST['description'];
    $status = "Pending";
    $imagePath = NULL;

    // ✅ Handle file upload
    if (!empty($_FILES['image']['name'])) {
        $targetDir = "../uploads/maintenance/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $imagePath = $targetDir . time() . "_" . basename($_FILES["image"]["name"]);
        move_uploaded_file($_FILES["image"]["tmp_name"], $imagePath);
    }

    // ✅ Insert request
    $stmt = $conn->prepare("INSERT INTO maintenance_requests 
        (property_id, unit_code, tenant_email, tenant_name, tenant_phone, title, description, image, request_date, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
    $stmt->bind_param(
        "issssssss",
        $property_id,
        $unit_code,
        $tenant_email,
        $tenant_name,
        $tenant_phone,
        $title,
        $description,
        $imagePath,
        $status
    );
    $stmt->execute();
    $stmt->close();

    $_SESSION['successMsg'] = "Your maintenance request has been submitted successfully!";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Handle deletion of maintenance request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request_id'])) {
    $deleteId = intval($_POST['delete_request_id']);
    $stmt = $conn->prepare("DELETE FROM maintenance_requests WHERE request_id = ? AND tenant_email = ?");
    $stmt->bind_param("is", $deleteId, $tenant_email);
    $stmt->execute();
    $stmt->close();

    $_SESSION['successMsg'] = "Your maintenance request has been cancelled.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ✅ Fetch all previous maintenance requests by tenant
$requests = [];
$stmt = $conn->prepare("SELECT request_id, title, description, image, request_date, status 
                        FROM maintenance_requests 
                        WHERE tenant_email = ? ORDER BY request_date DESC");
$stmt->bind_param("s", $tenant_email);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

$successMsg = $_SESSION['successMsg'] ?? "";
unset($_SESSION['successMsg']);

$errorMsg = $_SESSION['errorMsg'] ?? "";
unset($_SESSION['errorMsg']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Maintenance Requests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: #a8aaacff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: medium;
        }

        .navbar {
            background: #000000ff;
        }

        .navbar-brand,
        .nav-link {
            color: #ffffffff !important;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .footer {
            margin-top: auto;
            background: #000000ff;
            color: white;
            text-align: center;
            padding: 10px;
        }

        .request-table th {
            background: #000000ff;
            color: #fff;
        }

        .status-pending {
            color: orange;
            font-weight: bold;
        }

        .status-completed {
            color: green;
            font-weight: bold;
        }

        .status-inprogress {
            color: blue;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <!-- ✅ Top Navbar -->
    <nav class="navbar navbar-expand-lg px-3">
        <a href="tenant.php" class="btn btn-light btn-sm me-3 btn-back">&larr; Back</a>
        <a class="navbar-brand" href="#">Maintenance Requests</a>
    </nav>

    <div class="container my-4">
        <!-- ✅ Success / Error Message -->
        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success" id="successMsg"><?= $successMsg ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger" id="errorMsg"><?= $errorMsg ?></div>
        <?php endif; ?>

        <!-- ✅ New Request Form -->
        <div class="card p-4 mb-4">
            <h4 class="mb-3">Raise a Maintenance Request</h4>
            <?php if ($hasProperty): ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="new_request" value="1">
                    <div class="mb-3">
                        <label class="form-label">Concern Title</label>
                        <input type="text" name="title" class="form-control" placeholder="Enter title of concern" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Describe the issue in detail..." required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Attach Image (optional)</label>
                        <input type="file" name="image" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">
                    You are not assigned to any property. You cannot raise a maintenance request.
                </div>
            <?php endif; ?>
        </div>

        <!-- ✅ Previous Requests -->
        <div class="card p-4">
            <h4 class="mb-3">My Previous Requests</h4>
            <table class="table table-bordered request-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Image</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($requests)): ?>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['title']) ?></td>
                                <td><?= htmlspecialchars($r['description']) ?></td>
                                <td>
                                    <?php if ($r['image']): ?>
                                        <a href="<?= $r['image'] ?>" target="_blank">View</a>
                                    <?php else: ?>
                                        No Image
                                    <?php endif; ?>
                                </td>
                                <td><?= date("M d, Y H:i", strtotime($r['request_date'])) ?></td>
                                <td class="status-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
                                <td>
                                    <?php if (strtolower($r['status']) === 'pending'): ?>
                                        <form method="post" style="display:inline;"
                                            onsubmit="return confirm('Are you sure you want to cancel this request?');">
                                            <input type="hidden" name="delete_request_id" value="<?= $r['request_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-danger">No action</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No requests found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ✅ Footer -->
    <div class="footer">
        &copy; <?= date("Y") ?> Accofinda
    </div>

    <script>
        // ✅ Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const msg = document.getElementById("successMsg");
            if (msg) msg.style.display = "none";
            const err = document.getElementById("errorMsg");
            if (err) err.style.display = "none";
        }, 5000);
    </script>
</body>

</html>