<?php
session_start();
require '../config.php';

// Restrict access to landlords, admins, and managers
if (!isset($_SESSION['email']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

$message = "";
$messageClass = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $landlord_email = trim($_SESSION['email']);
    $landlord_id    = intval($_SESSION['id']); // assuming you store landlord_id in session after login

    // Property details
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $city          = trim($_POST['city'] ?? '');
    $county        = trim($_POST['county'] ?? '');
    $state         = trim($_POST['state'] ?? '');
    $property_type = $_POST['property_type'] ?? '';
    $status        = $_POST['status'] ?? '';
    $latitude      = $_POST['latitude'] ?? '';
    $longitude     = $_POST['longitude'] ?? '';
    $postal_code   = $_POST['postal_code'] ?? '';
    $amenities     = isset($_POST['amenities']) ? json_encode($_POST['amenities']) : json_encode([]);

    // Handle property image upload (first image as main)
    $propertyImagePath = "";
    if (!empty($_FILES['property_images']['name'][0])) {
        $targetDir = "../Uploads/PropertyImages/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $firstImageName = time() . "_" . basename($_FILES['property_images']['name'][0]);
        $targetFilePath = $targetDir . $firstImageName;
        if (move_uploaded_file($_FILES['property_images']['tmp_name'][0], $targetFilePath)) {
            $propertyImagePath = "Uploads/PropertyImages/" . $firstImageName;
        }
    }

    $conn->begin_transaction();
    try {
        // Insert property
        $availability_status = $_POST['availability_status'] ?? 'Available';

        $stmt1 = $conn->prepare("
            INSERT INTO properties 
            (landlord_id, landlord_email, title, location, description, address, city, county, latitude, longitude, state, postal_code, property_type, availability_status, status, image, created_at, amenities) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW(), ?)
        ");

        $stmt1->bind_param(
            "isssssssssssssss",
            $landlord_id,
            $landlord_email,
            $title,
            $city,   // location column
            $description,
            $address,
            $city,
            $county,
            $latitude,
            $longitude,
            $state,
            $postal_code,
            $property_type,
            $availability_status,
            $propertyImagePath,
            $amenities
        );

        if (!$stmt1->execute()) {
            throw new Exception("Error inserting property: " . $stmt1->error);
        }

        $property_id = $stmt1->insert_id;
        $stmt1->close();

        // Prepare unit insert
        $stmt2 = $conn->prepare("
            INSERT INTO property_units 
            (property_id, landlord_id, landlord_email, room_type, units_available, price, electricity_service, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        // Prepare unit_images insert
        $stmt3 = $conn->prepare("
            INSERT INTO unit_images (unit_id, image_path, uploaded_at) 
            VALUES (?, ?, NOW())
        ");

        foreach ($_POST['room_type'] as $index => $roomType) {
            $units_available     = intval($_POST['units_available'][$index] ?? 0);
            $unit_price          = floatval(str_replace([',', '$'], '', $_POST['price'][$index] ?? 0));
            $electricity_service = $_POST['electricity_service'][$index] ?? '';

            // Insert into property_units
            $stmt2->bind_param(
                "iissids",
                $property_id,
                $landlord_id,
                $landlord_email,
                $roomType,
                $units_available,
                $unit_price,
                $electricity_service
            );

            if (!$stmt2->execute()) {
                throw new Exception("Error inserting unit: " . $stmt2->error);
            }

            $unit_id = $stmt2->insert_id;

            // Handle multiple unit images (up to 5 per unit, max 5MB total)
            if (!empty($_FILES['unit_images']['name'][$index]) && is_array($_FILES['unit_images']['name'][$index])) {
                $targetDir = "../Uploads/UnitImages/";
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }

                $imageCount = count($_FILES['unit_images']['name'][$index]);
                $imageCount = min($imageCount, 5);

                $totalSize = 0;
                for ($i = 0; $i < $imageCount; $i++) {
                    $totalSize += $_FILES['unit_images']['size'][$index][$i];
                }

                if ($totalSize > 5 * 1024 * 1024) { // 5MB
                    throw new Exception("Total images for $roomType exceed 5MB limit.");
                }

                // Save each image for this unit
                for ($i = 0; $i < $imageCount; $i++) {
                    if (!empty($_FILES['unit_images']['name'][$index][$i])) {
                        $unitImageName = time() . "_" . basename($_FILES['unit_images']['name'][$index][$i]);
                        $targetFilePath = $targetDir . $unitImageName;
                        if (move_uploaded_file($_FILES['unit_images']['tmp_name'][$index][$i], $targetFilePath)) {
                            $unitImagePath = "Uploads/UnitImages/" . $unitImageName;

                            // Insert into unit_images table with correct unit_id
                            $stmt3->bind_param("is", $unit_id, $unitImagePath);
                            if (!$stmt3->execute()) {
                                throw new Exception("Error inserting unit image: " . $stmt3->error);
                            }
                        }
                    }
                }
            }
        }

        $stmt2->close();
        $stmt3->close();

        $conn->commit();
        $_SESSION['success'] = "Property and units added successfully!";
        header("Location: addProperty.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
        $messageClass = "danger";
    }
}

if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    $messageClass = "success";
    unset($_SESSION['success']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <title>Add New Property</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #484e4fff, #5c6bc0);
            margin: auto;
        }

        .container {
            max-width: 750px;
            /* Reduced form width */
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .section-title {
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 1.1rem;
            color: #000000ff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Input icon styles */
        .input-icon {
            position: relative;
        }

        .input-icon i {
            position: absolute;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .input-icon input,
        .input-icon select,
        .input-icon textarea {
            padding-left: 20px;
            font-size: 12px;
        }

        .upload-box {
            border: 2px dashed #ccc;
            padding: 30px;
            text-align: center;
            color: #777;
            border-radius: 10px;
            background-color: #fafafa;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <!-- Top Navbar (fixed) -->
    <nav class="navbar navbar-dark bg-dark fixed-top py-3">
        <div class="container-fluid">
            <button type="button" class="btn btn-outline-light me-3" onclick="history.back()" aria-label="Go back">
                <i class="fa fa-arrow-left"></i> Back
            </button>
            <span class="navbar-brand mx-auto fs-4 fw-bold text-light text-center" style="position: absolute; left: 50%; transform: translateX(-50%);">
                🏠 Add New Property
            </span>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container" style="padding-top: 120px; padding-bottom: 120px;">
        <form method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded shadow">
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageClass; ?>"><?= $message; ?></div>
            <?php endif; ?>

            <!-- Basic Information -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-info-circle"></i> Basic Information</div>
                <div class="row g-3">
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-heading"></i>
                        <input type="text" name="title" class="form-control" placeholder="Property Title" required>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-building"></i>
                        <select name="property_type" class="form-select" required>
                            <option value="">Property Type</option>
                            <option>Apartment</option>
                            <option>Flats</option>
                            <option>House</option>
                            <option>Own Compound</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-toggle-on"></i>
                        <select name="availability_status" class="form-select" required>
                            <option value="">Select Availability Status</option>
                            <option value="Available">Available</option>
                            <option value="Booked">Booked</option>
                            <option value="Occupied">Occupied</option>
                            <option value="Under Maintenance">Under Maintenance</option>
                        </select>
                    </div>

                </div>
            </div>

            <!-- Location Details -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-map-marker-alt"></i> Location Details</div>
                <div class="row g-3">
                    <!-- Address -->
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-location-dot"></i>
                        <input type="text" name="address" class="form-control" placeholder="Address" required>
                    </div>

                    <!-- City -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-city"></i>
                        <input type="text" name="city" class="form-control" placeholder="City" required>
                    </div>

                    <!-- County (NEW) -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-map"></i>
                        <input type="text" name="county" class="form-control" placeholder="County" required>
                    </div>

                    <!-- State -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-flag"></i>
                        <input type="text" name="state" class="form-control" placeholder="State">
                    </div>

                    <!-- Latitude -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-location-crosshairs"></i>
                        <input type="text" name="latitude" class="form-control" placeholder="Latitude">
                    </div>

                    <!-- Longitude -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-location-crosshairs"></i>
                        <input type="text" name="longitude" class="form-control" placeholder="Longitude">
                    </div>

                    <!-- Postal Code -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-mail-bulk"></i>
                        <input type="text" name="postal_code" class="form-control" placeholder="Postal Code">
                    </div>
                </div>
            </div>
            <!-- Units Section -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-door-open"></i> Units Information</div>
                <div id="roomTypeContainer" class="mb-3">
                    <div class="row g-3 room-type-entry align-items-end" data-index="0">
                        <div class="col-md-3 input-icon">
                            <i class="fa-solid fa-door-open"></i>
                            <select name="room_type[]" class="form-select" required>
                                <option value="">Select Room Type</option>
                                <option>Single Room</option>
                                <option>Bedsitter</option>
                                <option>One Bedroom</option>
                                <option>Two Bedroom</option>
                                <option>Three Bedroom</option>
                            </select>
                        </div>
                        <div class="col-md-2 input-icon">
                            <i class="fa-solid fa-building"></i>
                            <input type="number" name="units_available[]" class="form-control" placeholder="Units" required>
                        </div>
                        <div class="col-md-2 input-icon">
                            <i class="fa-solid fa-money-bill-wave"></i>
                            <input type="number" name="price[]" class="form-control" placeholder="Price (Ksh)" required>
                        </div>
                        <div class="col-md-3 input-icon">
                            <i class="fa-solid fa-bolt"></i>
                            <select name="electricity_service[]" class="form-select" required>
                                <option value="">Electricity Service</option>
                                <option>KPLC Postpaid</option>
                                <option>KPLC Prepaid</option>
                            </select>
                        </div>

                        <!-- Images manager -->
                        <div class="col-md-2 input-icon">
                            <i class="fa-solid fa-image"></i>
                            <div class="images-controls">
                                <button type="button"
                                    class="btn btn-sm add-image-btn"
                                    style="background-color: #000; color: #fff; border: 1px solid #fff; font-size: 12px; padding: 3px 8px; border-radius: 6px;">
                                    <i class="fa fa-plus"></i> Add Image
                                </button>
                                <small class="d-block mt-1">Max 5 images, total &lt; 5MB</small>
                                <ul class="image-filenames list-unstyled mt-2 mb-0" style="font-size:10px;"></ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="text-align: left;">
                    <button type="button" id="addRoomType" class="btn btn-primary btn-md mt-3 px-4 d-flex align-items-center gap-2" style="font-weight: 600; width: auto;">
                        <i class="fa fa-plus-circle"></i> Add Another Room Type
                    </button>
                </div>
            </div>
            <!-- Description -->
            <div class="card mb-4 p-3">
                <div class="section-title">
                    <i class="fa-solid fa-align-left"></i> Description
                </div>
                <textarea
                    name="description"
                    rows="4"
                    class="form-control"
                    placeholder="Describe your property..."><?php echo htmlspecialchars($description ?? ''); ?></textarea>
            </div>

            <!-- Amenities -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-list-check"></i> Amenities</div>
                <div class="row">
                    <?php
                    $amenitiesList = [
                        "WiFi",
                        "Parking",
                        "Gym/Fitness Center",
                        "Swimming Pool",
                        "Laundry",
                        "Air Conditioning",
                        "Heating",
                        "Dishwasher",
                        "Pets Allowed",
                        "Balcony/Patio",
                        "Elevator",
                        "Security System"
                    ];
                    foreach ($amenitiesList as $amenity) {
                        echo '<div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="amenities[]" value="' . $amenity . '">
                            <label class="form-check-label">' . $amenity . '</label>
                        </div>
                      </div>';
                    }
                    ?>
                </div>
            </div>

            <!-- Property Images -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-image"></i> Property Images</div>
                <input type="file" name="property_images[]" class="form-control" accept="image/*" multiple>
                <small>Upload multiple images (Max 10MB each)</small>
            </div>

            <!-- Submit -->
            <div class="text-center">
                <button type="submit" class="btn btn-primary btn-lg px-5">Add Property</button>
            </div>
        </form>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-3 mt-0" style="padding-top:10px;">
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved.
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const maxFiles = 5;
            const maxTotalSize = 5 * 1024 * 1024; // 5 MB
            const roomTypeContainer = document.getElementById("roomTypeContainer");

            document.addEventListener("click", function(e) {
                // Handle add image button
                if (e.target.closest(".add-image-btn")) {
                    const unitEntry = e.target.closest(".room-type-entry");
                    const index = unitEntry.getAttribute("data-index"); // keep each unit unique
                    let hiddenFileInput = unitEntry.querySelector(".hidden-unit-images");

                    // Create hidden input if not exists
                    if (!hiddenFileInput) {
                        hiddenFileInput = document.createElement("input");
                        hiddenFileInput.type = "file";
                        hiddenFileInput.name = "unit_images[" + index + "][]";
                        hiddenFileInput.classList.add("hidden-unit-images");
                        hiddenFileInput.multiple = true;
                        hiddenFileInput.style.display = "none";
                        unitEntry.appendChild(hiddenFileInput);
                    }

                    const tempInput = document.createElement("input");
                    tempInput.type = "file";
                    tempInput.accept = "image/*";
                    tempInput.multiple = true;
                    tempInput.style.display = "none";

                    tempInput.addEventListener("change", function() {
                        const fileList = Array.from(tempInput.files);
                        const fileNamesList = unitEntry.querySelector(".image-filenames");

                        let existingFiles = fileNamesList.querySelectorAll("li");
                        let existingCount = existingFiles.length;
                        let currentSize = 0;

                        existingFiles.forEach(li => {
                            currentSize += parseInt(li.getAttribute("data-size")) || 0;
                        });

                        for (const file of fileList) {
                            if (existingCount >= maxFiles) {
                                alert("❌ Maximum " + maxFiles + " images per unit.");
                                break;
                            }
                            if (currentSize + file.size > maxTotalSize) {
                                alert("❌ Total images size > 5 MB for this unit.");
                                break;
                            }

                            // Show filename + delete
                            const li = document.createElement("li");
                            li.setAttribute("data-size", file.size);
                            li.innerHTML = `
                        <span>${file.name}</span>
                        <button type="button" 
                            class="btn btn-link text-danger p-0 ms-2" 
                            style="font-size: 11px; line-height: 1; vertical-align: middle;">
                            X
                        </button>
                    `;

                            li.querySelector("button").addEventListener("click", function() {
                                li.remove();
                                const dt = new DataTransfer();
                                Array.from(hiddenFileInput.files)
                                    .filter(f => f.name !== file.name)
                                    .forEach(f => dt.items.add(f));
                                hiddenFileInput.files = dt.files;
                            });

                            fileNamesList.appendChild(li);

                            // Merge files into hidden input
                            const dt = new DataTransfer();
                            Array.from(hiddenFileInput.files).forEach(f => dt.items.add(f));
                            dt.items.add(file);
                            hiddenFileInput.files = dt.files;

                            existingCount++;
                            currentSize += file.size;
                        }
                    });

                    document.body.appendChild(tempInput);
                    tempInput.click();
                }

                // Add another room type
                if (e.target.id === "addRoomType" || e.target.closest("#addRoomType")) {
                    const firstEntry = roomTypeContainer.querySelector(".room-type-entry");
                    const newEntry = firstEntry.cloneNode(true);

                    // Assign unique index
                    const index = roomTypeContainer.querySelectorAll(".room-type-entry").length;
                    newEntry.setAttribute("data-index", index);

                    // Reset inputs
                    newEntry.querySelectorAll("input").forEach(input => input.value = "");
                    newEntry.querySelectorAll("select").forEach(select => select.selectedIndex = 0);
                    newEntry.querySelector(".image-filenames").innerHTML = "";
                    const oldHiddenInput = newEntry.querySelector(".hidden-unit-images");
                    if (oldHiddenInput) oldHiddenInput.remove();

                    roomTypeContainer.appendChild(newEntry);
                }
            });
        });
    </script>

</body>

</html>