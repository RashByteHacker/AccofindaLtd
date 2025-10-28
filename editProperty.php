<?php
session_start();
require '../config.php';

// ✅ Check access
if (!isset($_SESSION['email']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

$userEmail = $_SESSION['email'];
$userRole  = strtolower($_SESSION['role']);

$propertyId = intval($_GET['property_id'] ?? 0);
if ($propertyId <= 0) {
    die("Invalid Property ID");
}

// ✅ Get landlord_id from users table
$landlord_id = null;
$stmtLandlord = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmtLandlord->bind_param("s", $userEmail);
$stmtLandlord->execute();
$stmtLandlord->bind_result($landlord_id);
$stmtLandlord->fetch();
$stmtLandlord->close();

if (!$landlord_id) {
    die("Landlord account not found.");
}

// Initialize message variables
$message = "";
$messageClass = "";

// ✅ Fetch property
$stmt = $conn->prepare("SELECT * FROM properties WHERE property_id = ?");
$stmt->bind_param("i", $propertyId);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$property) {
    die("Property not found.");
}

// ✅ Landlord ownership check
if ($userRole === 'landlord' && $property['landlord_email'] !== $userEmail) {
    die("Unauthorized access to this property.");
}

// ✅ Fetch units + their images
$stmt = $conn->prepare("
    SELECT u.*, 
           COALESCE(GROUP_CONCAT(i.image_path SEPARATOR '|'), '') AS unit_images
    FROM property_units u
    LEFT JOIN unit_images i ON u.unit_id = i.unit_id
    WHERE u.property_id = ?
    GROUP BY u.unit_id
");
$stmt->bind_param("i", $propertyId);
$stmt->execute();
$unitsResult = $stmt->get_result();
$units = [];
while ($row = $unitsResult->fetch_assoc()) {
    $row['unit_images'] = !empty($row['unit_images']) ? explode('|', $row['unit_images']) : [];
    $units[] = $row;
}
$stmt->close();

// ✅ Decode amenities
$selectedAmenities = json_decode($property['amenities'], true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect property fields
    $title               = trim($_POST['title'] ?? '');
    $availability_status = $_POST['availability_status'] ?? '';
    $location            = trim($_POST['location'] ?? '');
    $description         = trim($_POST['description'] ?? '');
    $address             = trim($_POST['address'] ?? '');
    $city                = trim($_POST['city'] ?? '');
    $county              = trim($_POST['county'] ?? '');
    $state               = trim($_POST['state'] ?? '');
    $latitude            = $_POST['latitude'] ?? '';
    $longitude           = $_POST['longitude'] ?? '';
    $postal_code         = $_POST['postal_code'] ?? '';
    $property_type       = $_POST['property_type'] ?? '';
    $amenities           = isset($_POST['amenities']) ? json_encode($_POST['amenities']) : json_encode([]);

    // Keep current status
    $status = $property['status'];

    // ✅ Handle property image upload
    $propertyImagePath = $property['image'];
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
        // ✅ Update property
        $stmt1 = $conn->prepare("
            UPDATE properties SET 
                title=?, availability_status=?, location=?, description=?, 
                address=?, city=?, county=?, latitude=?, longitude=?, 
                state=?, postal_code=?, property_type=?, image=?, amenities=?
            WHERE property_id=?
        ");
        $stmt1->bind_param(
            "ssssssssssssssi",
            $title,
            $availability_status,
            $location,
            $description,
            $address,
            $city,
            $county,
            $latitude,
            $longitude,
            $state,
            $postal_code,
            $property_type,
            $propertyImagePath,
            $amenities,
            $propertyId
        );
        if (!$stmt1->execute()) {
            throw new Exception("Error updating property: " . $stmt1->error);
        }
        $stmt1->close();

        // ✅ Handle units
        $roomTypes           = $_POST['room_type'] ?? [];
        $unitsAvailable      = $_POST['units_available'] ?? [];
        $prices              = $_POST['price'] ?? [];
        $electricityServices = $_POST['electricity_service'] ?? [];
        $unitIds             = $_POST['unit_id'] ?? [];
        $unitCodes           = $_POST['unit_code'] ?? [];
        $unitStatuses        = $_POST['unit_status'] ?? [];
        $entriesToAdd        = $_POST['entries_to_add'] ?? [];

        $stmtUpdateUnit = $conn->prepare("
            UPDATE property_units 
            SET room_type=?, units_available=?, price=?, electricity_service=? 
            WHERE unit_id=? AND property_id=?
        ");
        $stmtInsertUnit = $conn->prepare("
            INSERT INTO property_units (property_id, landlord_id, landlord_email, room_type, units_available, price, electricity_service, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtInsertDetail = $conn->prepare("
            INSERT INTO property_unit_details (property_unit_id, property_id, unit_code, status, last_updated) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtInsertUnitImage = $conn->prepare("
            INSERT INTO unit_images (unit_id, image_path, uploaded_at) VALUES (?, ?, NOW())
        ");

        foreach ($roomTypes as $index => $roomType) {
            $unitsAvail  = intval($unitsAvailable[$index] ?? 0);
            $priceVal    = floatval(str_replace([',', '$'], '', $prices[$index] ?? 0));
            $electricity = $electricityServices[$index] ?? '';
            $unitId      = intval($unitIds[$index] ?? 0);
            $unitCodeVal = trim($unitCodes[$index] ?? '');
            $unitStatus  = trim($unitStatuses[$index] ?? '');
            $toAddCount  = intval($entriesToAdd[$index] ?? 0);

            if ($unitId > 0) {
                // 🔹 Update existing unit
                $stmtUpdateUnit->bind_param("siisii", $roomType, $unitsAvail, $priceVal, $electricity, $unitId, $propertyId);
                if (!$stmtUpdateUnit->execute()) {
                    throw new Exception("Error updating unit: " . $stmtUpdateUnit->error);
                }

                // 🔹 Add unit details if needed
                if ($toAddCount > 0) {
                    for ($i = 1; $i <= $toAddCount; $i++) {
                        $generatedCode = $unitCodeVal !== '' ? $unitCodeVal : "U{$unitId}-" . time() . "-$i";
                        $stmtInsertDetail->bind_param("iiss", $unitId, $propertyId, $generatedCode, $unitStatus);
                        if (!$stmtInsertDetail->execute()) {
                            throw new Exception("Error inserting unit details: " . $stmtInsertDetail->error);
                        }
                    }
                }

                // 🔹 Handle new images for existing unit
                if (!empty($_FILES['unit_images']['name'][$index][0])) {
                    $targetDir = "../Uploads/UnitImages/";
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0777, true);
                    }
                    foreach ($_FILES['unit_images']['name'][$index] as $imgKey => $imgName) {
                        if (empty($imgName)) continue;
                        $fileName = time() . "_" . basename($imgName);
                        $targetFilePath = $targetDir . $fileName;
                        if (move_uploaded_file($_FILES['unit_images']['tmp_name'][$index][$imgKey], $targetFilePath)) {
                            $imagePath = "Uploads/UnitImages/" . $fileName;
                            $stmtInsertUnitImage->bind_param("is", $unitId, $imagePath);
                            if (!$stmtInsertUnitImage->execute()) {
                                throw new Exception("Error inserting unit image: " . $stmtInsertUnitImage->error);
                            }
                        }
                    }
                }
            } else {
                // 🔹 Insert new unit with landlord_id + landlord_email
                $stmtInsertUnit->bind_param("iissids", $propertyId, $landlord_id, $userEmail, $roomType, $unitsAvail, $priceVal, $electricity);
                if (!$stmtInsertUnit->execute()) {
                    throw new Exception("Error inserting unit: " . $stmtInsertUnit->error);
                }
                $newUnitId = $stmtInsertUnit->insert_id;

                if ($toAddCount > 0) {
                    for ($i = 1; $i <= $toAddCount; $i++) {
                        $generatedCode = $unitCodeVal !== '' ? $unitCodeVal : "U{$newUnitId}-" . time() . "-$i";
                        $stmtInsertDetail->bind_param("iiss", $newUnitId, $propertyId, $generatedCode, $unitStatus);
                        if (!$stmtInsertDetail->execute()) {
                            throw new Exception("Error inserting unit details: " . $stmtInsertDetail->error);
                        }
                    }
                }

                // 🔹 Handle images for new unit
                if (!empty($_FILES['unit_images']['name'][$index][0])) {
                    $targetDir = "../Uploads/UnitImages/";
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0777, true);
                    }
                    foreach ($_FILES['unit_images']['name'][$index] as $imgKey => $imgName) {
                        if (empty($imgName)) continue;
                        $fileName = time() . "_" . basename($imgName);
                        $targetFilePath = $targetDir . $fileName;
                        if (move_uploaded_file($_FILES['unit_images']['tmp_name'][$index][$imgKey], $targetFilePath)) {
                            $imagePath = "Uploads/UnitImages/" . $fileName;
                            $stmtInsertUnitImage->bind_param("is", $newUnitId, $imagePath);
                            if (!$stmtInsertUnitImage->execute()) {
                                throw new Exception("Error inserting unit image: " . $stmtInsertUnitImage->error);
                            }
                        }
                    }
                }
            }
        }

        $stmtUpdateUnit->close();
        $stmtInsertUnit->close();
        $stmtInsertDetail->close();
        $stmtInsertUnitImage->close();

        $conn->commit();
        $_SESSION['success'] = "Property, units, and images updated successfully!";
        header("Location: editProperty.php?property_id={$propertyId}");
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
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Edit Property</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <style>
        body {
            background: linear-gradient(135deg, #484e4fff, #5c6364ff);
        }

        .container {
            max-width: 750px;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .section-title {
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 1.1rem;
            color: #4a148c;
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

<body class="bg-light d-flex flex-column min-vh-100">

    <!-- Top Navbar -->
    <nav class="navbar navbar-dark bg-dark fixed-top py-3">
        <div class="container-fluid">
            <button class="btn btn-light me-4" onclick="history.back()">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <a class="navbar-brand fw-bold text-white" href="#">
                <i class="fa-solid fa-building"></i> Property Manager
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container" style="padding-top: 120px; padding-bottom: 120px;">
        <form method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded shadow">
            <h4 class="text-center mb-4 bg-dark text-white py-2 rounded">
                ✏️ Edit Property
            </h4>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageClass; ?>"><?= htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <!-- Basic Information -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-info-circle"></i> Basic Information</div>
                <div class="row g-3">
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-heading"></i>
                        <input type="text" name="title" class="form-control" placeholder="Property Title" required value="<?= htmlspecialchars($property['title']) ?>">
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-building"></i>
                        <select name="property_type" class="form-select" required>
                            <option value="">Property Type</option>
                            <?php
                            $types = ["Apartment", "Flats", "House", "Own Compound", "Other"];
                            foreach ($types as $type) {
                                $selected = ($property['property_type'] === $type) ? "selected" : "";
                                echo "<option value=\"$type\" $selected>$type</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-toggle-on"></i>
                        <select name="availability_status" class="form-select" required>
                            <option value="">Availability Status</option>
                            <?php
                            $statuses = ["Available", "Booked", "Occupied", "Under Maintenance"];
                            foreach ($statuses as $st) {
                                $selected = ($property['availability_status'] === $st) ? "selected" : "";
                                echo "<option value=\"$st\" $selected>$st</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Location Details -->
            <div class="card mb-4 p-3">
                <div class="section-title">
                    <i class="fa-solid fa-map-marker-alt"></i> Location Details
                </div>
                <div class="row g-3">
                    <!-- Address -->
                    <div class="col-md-6 input-icon">
                        <i class="fa-solid fa-location-dot"></i>
                        <input type="text" name="address" class="form-control" placeholder="Address" required value="<?= htmlspecialchars($property['address']) ?>">
                    </div>

                    <!-- City -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-city"></i>
                        <input type="text" name="city" class="form-control" placeholder="City" required value="<?= htmlspecialchars($property['city']) ?>">
                    </div>

                    <!-- County (NEW) -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-map"></i>
                        <input type="text" name="county" class="form-control" placeholder="County" required value="<?= htmlspecialchars($property['city'] ?? '') ?>">
                    </div>

                    <!-- State -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-flag"></i>
                        <input type="text" name="state" class="form-control" placeholder="State" value="<?= htmlspecialchars($property['state']) ?>">
                    </div>

                    <!-- Latitude -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-location-crosshairs"></i>
                        <input type="text" name="latitude" class="form-control" placeholder="Latitude" value="<?= htmlspecialchars($property['latitude']) ?>">
                    </div>

                    <!-- Longitude -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-location-crosshairs"></i>
                        <input type="text" name="longitude" class="form-control" placeholder="Longitude" value="<?= htmlspecialchars($property['longitude']) ?>">
                    </div>

                    <!-- Postal Code -->
                    <div class="col-md-3 input-icon">
                        <i class="fa-solid fa-mail-bulk"></i>
                        <input type="text" name="postal_code" class="form-control" placeholder="Postal Code" value="<?= htmlspecialchars($property['postal_code']) ?>">
                    </div>
                </div>
            </div>

            <!-- Units Section -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-door-open"></i> Units Information</div>
                <div id="roomTypeContainer" class="mb-3">
                    <?php foreach ($units as $index => $unit): ?>
                        <div class="row g-3 room-type-entry align-items-end mb-3">
                            <input type="hidden" name="unit_id[]" value="<?= $unit['unit_id'] ?>">

                            <!-- Room Type -->
                            <div class="col-md-3 input-icon">
                                <i class="fa-solid fa-door-open"></i>
                                <select name="room_type[]" class="form-select" required>
                                    <option value="">Select Room Type</option>
                                    <?php
                                    $roomTypes = ["Single Room", "Bedsitter", "One Bedroom", "Two Bedroom", "Three Bedroom"];
                                    foreach ($roomTypes as $rt) {
                                        $selected = ($unit['room_type'] === $rt) ? "selected" : "";
                                        echo "<option value=\"$rt\" $selected>$rt</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Units Available -->
                            <div class="col-md-2 input-icon">
                                <i class="fa-solid fa-building"></i>
                                <input type="number" name="units_available[]" class="form-control" placeholder="Units" required value="<?= htmlspecialchars($unit['units_available']) ?>">
                            </div>

                            <!-- Price -->
                            <div class="col-md-2 input-icon">
                                <i class="fa-solid fa-money-bill-wave"></i>
                                <input type="number" step="0.01" name="price[]" class="form-control" placeholder="Price (Ksh)" required value="<?= htmlspecialchars($unit['price']) ?>">
                            </div>

                            <!-- Electricity Service -->
                            <div class="col-md-3 input-icon">
                                <i class="fa-solid fa-bolt"></i>
                                <select name="electricity_service[]" class="form-select" required>
                                    <option value="">Electricity Service</option>
                                    <?php
                                    $elecOptions = ["KPLC Postpaid", "KPLC Prepaid"];
                                    foreach ($elecOptions as $eo) {
                                        $selected = ($unit['electricity_service'] === $eo) ? "selected" : "";
                                        echo "<option value=\"$eo\" $selected>$eo</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Unit Images -->
                            <div class="col-md-2 input-icon">
                                <i class="fa-solid fa-image"></i>
                                <div class="images-controls">
                                    <button type="button"
                                        class="btn btn-sm add-image-btn"
                                        style="background-color: #000000ff; color: #ffffffff; border: 1px solid #ffffffff; font-size: 12px; padding: 3px 8px; border-radius: 6px;">
                                        <i class="fa fa-plus"></i> Add Image
                                    </button>
                                    <small class="d-block mt-1">Max 5 images, total &lt; 5MB</small>
                                    <ul class="image-filenames list-unstyled mt-2 mb-0" style="font-size:10px;"></ul>

                                    <!-- Hidden input for this unit’s images -->
                                    <input type="file"
                                        name="unit_images[<?= $unit['unit_id'] ?>][]"
                                        class="hidden-unit-images d-none"
                                        accept="image/*"
                                        multiple>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="text-align: left;">
                    <button type="button" id="addRoomType" class="btn btn-primary btn-md mt-3 px-4 d-flex align-items-center gap-2" style="font-weight: 600; width: auto;">
                        <i class="fa fa-plus-circle"></i> Add Another Room Type
                    </button>
                </div>
            </div>

            <!-- Description -->
            <div class="card mb-4 p-3">
                <div class="section-title"><i class="fa-solid fa-align-left"></i> Description</div>
                <textarea
                    name="description"
                    rows="4"
                    class="form-control"
                    placeholder="Describe your property..."><?= htmlspecialchars($property['description']) ?></textarea>
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
                        $checked = in_array($amenity, $selectedAmenities) ? "checked" : "";
                        echo '<div class="col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="amenities[]" value="' . htmlspecialchars($amenity) . '" ' . $checked . '>
                            <label class="form-check-label">' . htmlspecialchars($amenity) . '</label>
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
                <small>Upload multiple images (Max 10MB each). Leave empty to keep current image.</small>
                <?php if (!empty($property['image'])): ?>
                    <img src="../<?= htmlspecialchars($property['image']) ?>" alt="Property Image" class="mt-3" style="max-width: 200px; border-radius: 8px;">
                <?php endif; ?>
            </div>

            <!-- Submit -->
            <div class="text-center">
                <button type="submit" class="btn btn-primary btn-lg px-5">Update Property</button>
            </div>
        </form>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light text-center py-3 mt-0" style="padding-top:10px;">
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved.
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const addButton = document.getElementById("addRoomType");
            const container = document.getElementById("roomTypeContainer");

            // Add new unit entry
            addButton.addEventListener("click", function() {
                const firstEntry = container.querySelector(".room-type-entry");
                if (!firstEntry) return;

                // Clone the row
                const newEntry = firstEntry.cloneNode(true);

                // Reset values
                newEntry.querySelectorAll("input, select").forEach(el => {
                    if (el.tagName === "SELECT") {
                        el.selectedIndex = 0;
                    } else if (el.type === "file") {
                        el.value = "";
                    } else {
                        el.value = "";
                    }
                });

                // Reset filenames list
                const filenamesList = newEntry.querySelector(".image-filenames");
                if (filenamesList) filenamesList.innerHTML = "";

                // Append new entry
                container.appendChild(newEntry);
            });

            // Handle Add Image button
            container.addEventListener("click", function(e) {
                const btn = e.target.closest(".add-image-btn");
                if (btn) {
                    const fileInput = btn.parentElement.querySelector(".unit-image-input");
                    if (fileInput) fileInput.click();
                }
            });

            // Show selected filenames (not preview)
            container.addEventListener("change", function(e) {
                if (e.target.classList.contains("unit-image-input")) {
                    const list = e.target.parentElement.querySelector(".image-filenames");
                    list.innerHTML = "";
                    Array.from(e.target.files).forEach(file => {
                        const li = document.createElement("li");
                        li.textContent = file.name;
                        list.appendChild(li);
                    });
                }
            });
        });
    </script>
</body>

</html>