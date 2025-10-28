<?php
session_start();
require '../config.php';

// ✅ Access control: only service providers
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'service provider') {
    header("Location: ../login.php");
    exit();
}

$provider_id = $_SESSION['id'] ?? 0;

// ✅ Success message via GET (for PRG pattern)
$successMsg = $_GET['success'] ?? "";

// --- Get provider's service_type for category
$category = '';
$stmtCat = $conn->prepare("SELECT service_type FROM users WHERE id=?");
$stmtCat->bind_param("i", $provider_id);
$stmtCat->execute();
$resCat = $stmtCat->get_result();
if ($rowCat = $resCat->fetch_assoc()) {
    $category = $rowCat['service_type'] ?? '';
}
$stmtCat->close();

// Handle form submission for adding service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $title       = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $price       = $_POST['price'] ?? '';
    $currency    = $_POST['currency'] ?? 'KES';
    $service_mode = $_POST['service_mode'] ?? 'At Provider Location';

    // Handle image uploads (up to 3)
    $image_1 = $image_2 = $image_3 = null;
    if (!empty($_FILES['images']['name'][0])) {
        for ($i = 0; $i < 3; $i++) {
            if (!empty($_FILES['images']['name'][$i])) {
                $name = $_FILES['images']['name'][$i];
                $tmp  = $_FILES['images']['tmp_name'][$i];
                $ext  = pathinfo($name, PATHINFO_EXTENSION);
                $newName = uniqid() . '.' . $ext;
                $dest = '../Uploads/ServiceImages/' . $newName; // ✅ fixed path

                if (move_uploaded_file($tmp, $dest)) {
                    if ($i === 0) $image_1 = $newName;
                    if ($i === 1) $image_2 = $newName;
                    if ($i === 2) $image_3 = $newName;
                }
            }
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO provider_services 
        (provider_id, title, description, price, currency, category, service_mode, image_1, image_2, image_3, featured, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', NOW(), NOW())
    ");
    $stmt->bind_param(
        "issdssssss",
        $provider_id,
        $title,
        $description,
        $price,
        $currency,
        $category,
        $service_mode,
        $image_1,
        $image_2,
        $image_3
    );
    if ($stmt->execute()) {
        $stmt->close();
        // ✅ Redirect to avoid duplicate submission
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode("Service added successfully!"));
        exit();
    }
    $stmt->close();
}

// Handle inline deletion
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM provider_services WHERE id=? AND provider_id=?");
    $stmt->bind_param("ii", $del_id, $provider_id);
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode("Service deleted successfully!"));
        exit();
    }
    $stmt->close();
}

// Fetch services for this provider
$services = [];
$res = $conn->prepare("SELECT * FROM provider_services WHERE provider_id = ?");
$res->bind_param("i", $provider_id);
$res->execute();
$result = $res->get_result();
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}
$res->close();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Services - Provider Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            background: #1a1a1a;
        }

        .navbar a,
        .navbar .navbar-brand {
            color: #fff !important;
        }

        .container {
            max-width: 900px;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        input,
        textarea,
        select {
            border-radius: 8px;
        }

        img.preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 8px;
        }

        .service-card {
            background-color: #1e1e1e;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .service-card img {
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }

        .input-icon {
            position: relative;
        }

        .input-icon i {
            position: absolute;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 0.9rem;
        }

        .input-icon input,
        .input-icon textarea,
        .input-icon select {
            padding-left: 25px;
        }

        .input-icon .fa-briefcase {
            color: #007bff;
        }

        .input-icon .fa-money-bill-wave {
            color: #28a745;
        }

        .input-icon .fa-align-left {
            color: #17a2b8;
        }

        .input-icon .fa-location-dot {
            color: #ff0000ff;
        }

        .input-icon .fa-upload {
            color: #6f42c1;
        }

        .card .section-title {
            background-color: #f0f8ff;
            padding: 8px 12px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            color: #007bff;
            font-weight: 600;
            font-size: 1rem;
        }

        .service-card .badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.75rem;
        }

        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            border-left: 4px solid #007bff;
        }

        footer {
            margin-top: auto;
            background: #1a1a1a;
            color: #fff;
            text-align: center;
            padding: 15px;
        }

        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
        }

        .btn-success:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        form .card .col-md-6.input-icon,
        form .card .col-12.input-icon {
            margin-bottom: 12px;
        }

        .alert-success {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 9999;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg px-3 py-3">
        <a href="javascript:history.back()" class="btn btn-dark bg-secondary me-3"><i class="fa fa-arrow-left"></i> Back</a>
        <a class="navbar-brand mx-auto">My Services</a>
        <a href="../logout" class="btn btn-danger">Logout</a>
    </nav>

    <div class="container my-4">

        <!-- Success message -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success" id="successMsg"><?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>

        <!-- Add New Service Form -->
        <form method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded shadow">
            <h4 class="mb-4 text-center text-success"><i class="fa fa-plus-circle me-2"></i> Add New Service</h4>
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa fa-info-circle me-2"></i> Service Details</div>
                <div class="row g-3">
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-briefcase"></i>
                        <input type="text" name="title" class="form-control" placeholder="Service Title" required>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-money-bill-wave"></i>
                        <input type="number" name="price" class="form-control" placeholder="Price (KES)" required>
                    </div>
                    <div class="col-12 input-icon">
                        <i class="fa fa-align-left"></i>
                        <textarea name="description" class="form-control" rows="2" placeholder="Describe your service..." required></textarea>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-location-dot"></i>
                        <select name="service_mode" class="form-select" style="max-width: 200px;" required>
                            <option value="At Provider Location">At Provider Location</option>
                            <option value="On-site">On-site (Go to Customer)</option>
                            <option value="Remote">Remote / Online</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa fa-image me-2"></i> Upload Images</div>
                <div class="input-icon mb-2">
                    <i class="fa fa-upload"></i>
                    <input type="file" name="images[]" class="form-control" multiple accept="image/*" onchange="previewImages(event)">
                </div>
                <div id="imagePreview" class="d-flex mt-2 flex-wrap"></div>
                <small class="text-muted">You can upload up to 3 images (total &lt; 5MB)</small>
            </div>
            <div class="text-center">
                <button type="submit" name="add_service" class="btn btn-success btn-lg px-5"><i class="fa fa-plus me-2"></i> Add Service</button>
            </div>
        </form>

        <!-- Existing Services -->
        <h5 class="mt-5 mb-3"><i class="fa fa-list me-2"></i>Your Services</h5>
        <div class="row g-3">
            <?php if (!empty($services)): ?>
                <?php foreach ($services as $s):
                    $imgs = array_filter([$s['image_1'], $s['image_2'], $s['image_3']]);
                    $modeColor = match ($s['service_mode']) {
                        'At Provider Location' => 'primary',
                        'On-site' => 'success',
                        'Remote' => 'warning',
                        default => 'secondary',
                    };
                ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card service-card p-3">
                            <?php if (!empty($imgs)): ?>
                                <img src="../Uploads/ServiceImages/<?= htmlspecialchars($imgs[0]) ?>" class="img-fluid mb-2 rounded">
                            <?php endif; ?>
                            <span class="badge bg-<?= $modeColor ?>"><?= htmlspecialchars($s['service_mode']) ?></span>
                            <h6><?= htmlspecialchars($s['title']) ?></h6>
                            <p class="text-light" style="font-size:0.85rem;"><?= htmlspecialchars($s['description']) ?></p>
                            <strong>Price: KES<?= htmlspecialchars($s['price']) ?></strong><br>
                            <div class="mt-2 d-flex gap-2">
                                <a href="editService.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light text-dark"><i class="fa fa-edit"></i> Edit</a>
                                <a href="?delete_id=<?= $s['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this service?');"><i class="fa fa-trash"></i> Delete</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">No services added yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <footer>&copy; <?= date("Y") ?> AccoFinda. All Rights Reserved</footer>

    <script>
        function previewImages(event) {
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            for (let i = 0; i < event.target.files.length; i++) {
                const file = event.target.files[i];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'preview me-2 mb-2';
                    preview.appendChild(img);
                }
                reader.readAsDataURL(file);
            }
        }

        // Auto-hide success message after 3 seconds
        window.addEventListener('DOMContentLoaded', () => {
            const success = document.getElementById('successMsg');
            if (success) {
                success.style.display = 'block';
                setTimeout(() => {
                    success.style.display = 'none';
                }, 3000);
            }
        });
    </script>
</body>

</html>