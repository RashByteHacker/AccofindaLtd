<?php
session_start();
require '../config.php';

// Access control
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'service provider') {
    header("Location: ../login.php");
    exit();
}

$provider_id = $_SESSION['id'] ?? 0;
$service_id = $_GET['id'] ?? 0;

// Fetch service data
$stmt = $conn->prepare("SELECT * FROM provider_services WHERE id = ? AND provider_id = ?");
$stmt->bind_param("ii", $service_id, $provider_id);
$stmt->execute();
$result = $stmt->get_result();
$service = $result->fetch_assoc();
$stmt->close();

if (!$service) {
    die("Service not found.");
}

$successMsg = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_service'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $service_mode = $_POST['service_mode'] ?? 'At Provider Location';

    // Handle image uploads
    $images = [$service['image_1'], $service['image_2'], $service['image_3']];
    if (!empty($_FILES['images']['name'][0])) {
        for ($i = 0; $i < 3; $i++) {
            if (!empty($_FILES['images']['name'][$i])) {
                $name = $_FILES['images']['name'][$i];
                $tmp = $_FILES['images']['tmp_name'][$i];
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $newName = uniqid() . '.' . $ext;
                $dest = '../uploads/' . $newName;
                if (move_uploaded_file($tmp, $dest)) {
                    $images[$i] = $newName;
                }
            }
        }
    }

    $stmt = $conn->prepare("
        UPDATE provider_services
        SET title = ?, description = ?, price = ?, service_mode = ?, image_1 = ?, image_2 = ?, image_3 = ?, updated_at = NOW()
        WHERE id = ? AND provider_id = ?
    ");
    $stmt->bind_param(
        "ssdssssii",
        $title,
        $description,
        $price,
        $service_mode,
        $images[0],
        $images[1],
        $images[2],
        $service_id,
        $provider_id
    );

    if ($stmt->execute()) {
        $successMsg = "Service updated successfully!";
        // Refresh service data
        $service['title'] = $title;
        $service['description'] = $description;
        $service['price'] = $price;
        $service['service_mode'] = $service_mode;
        $service['image_1'] = $images[0];
        $service['image_2'] = $images[1];
        $service['image_3'] = $images[2];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Service</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
        }

        .navbar {
            background: #000;
        }

        .navbar a,
        .navbar .navbar-brand {
            color: #fff !important;
        }

        .form-wrapper {
            max-width: 700px;
            margin: 40px auto;
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
            padding-left: 35px;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            background-color: #f0f8ff;
            padding: 8px 12px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            color: #007bff;
            font-weight: 600;
            font-size: 1rem;
        }

        img.preview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            margin-right: 10px;
            border-radius: 8px;
        }

        .alert-success {
            display: none;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg px-3">
        <a class="navbar-brand" href="#">Accofinda</a>
        <div class="ms-auto">
            <a href="myServices" class="btn btn-outline-primary btn-sm me-2"><i class="fa fa-list"></i> My Services</a>
            <a href="../logout" class="btn btn-danger btn-sm"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <div class="container form-wrapper">

        <!-- Success Message -->
        <?php if ($successMsg): ?>
            <div class="alert alert-success" id="successMsg"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded shadow">
            <h4 class="mb-4 text-center text-primary"><i class="fa fa-edit me-2"></i> Edit Service</h4>

            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa fa-info-circle me-2"></i> Service Details</div>
                <div class="row g-3">
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-briefcase"></i>
                        <input type="text" name="title" class="form-control" placeholder="Service Title" value="<?= htmlspecialchars($service['title']) ?>" required>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-money-bill-wave"></i>
                        <input type="number" step="0.01" name="price" class="form-control" placeholder="Price (USD)" value="<?= htmlspecialchars($service['price']) ?>" required>
                    </div>
                    <div class="col-12 input-icon">
                        <i class="fa fa-align-left"></i>
                        <textarea name="description" class="form-control" rows="2" placeholder="Describe your service..." required><?= htmlspecialchars($service['description']) ?></textarea>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa fa-location-dot"></i>
                        <select name="service_mode" class="form-select" required>
                            <option value="At Provider Location" <?= $service['service_mode'] == 'At Provider Location' ? 'selected' : '' ?>>At Provider Location</option>
                            <option value="On-site" <?= $service['service_mode'] == 'On-site' ? 'selected' : '' ?>>On-site (Go to Customer)</option>
                            <option value="Remote" <?= $service['service_mode'] == 'Remote' ? 'selected' : '' ?>>Remote / Online</option>
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
                <div id="imagePreview" class="d-flex mt-2 flex-wrap">
                    <?php foreach ([$service['image_1'], $service['image_2'], $service['image_3']] as $img):
                        if ($img) echo '<img src="../uploads/' . htmlspecialchars($img) . '" class="preview">';
                    endforeach; ?>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" name="edit_service" class="btn btn-primary btn-lg px-5"><i class="fa fa-save me-2"></i> Save Changes</button>
            </div>
        </form>
    </div>

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

        // Show success message
        window.addEventListener('DOMContentLoaded', () => {
            const msg = document.getElementById('successMsg');
            if (msg) {
                msg.style.display = 'block';
            }
        });
    </script>
</body>

</html>