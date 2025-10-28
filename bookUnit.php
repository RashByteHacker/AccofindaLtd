<?php
session_start();
require '../config.php';

// ✅ Ensure tenant is logged in
if (!isset($_SESSION['id']) || strtolower($_SESSION['role']) !== 'tenant') {
    die(json_encode(['success' => false, 'message' => 'Please log in as tenant']));
}

$tenant_id = intval($_SESSION['id']);

// ✅ Handle Booking Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unit_id'])) {
    header('Content-Type: application/json');
    $unit_id = intval($_POST['unit_id']);

    try {
        $conn->begin_transaction();

        // ✅ Prevent duplicate pending bookings by same tenant
        $stmt0 = $conn->prepare("
            SELECT 1 
            FROM booking_requests 
            WHERE tenant_id = ? AND status = 'Pending' 
            LIMIT 1 FOR UPDATE
        ");
        $stmt0->bind_param("i", $tenant_id);
        $stmt0->execute();
        $res0 = $stmt0->get_result();

        if ($res0->fetch_assoc()) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'You already have a pending booking request.']);
            exit();
        }
        $stmt0->close();

        // ✅ Lock unit for update and check availability
        $stmt = $conn->prepare("
            SELECT pud.unit_code, pu.unit_id, pu.room_type, pu.price, p.landlord_id AS owner_id, pud.status
            FROM property_unit_details pud
            JOIN property_units pu ON pud.property_unit_id = pu.unit_id
            JOIN properties p ON pud.property_id = p.property_id
            WHERE pud.unit_detail_id = ? FOR UPDATE
        ");
        $stmt->bind_param("i", $unit_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$unit = $res->fetch_assoc()) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Unit not found.']);
            exit();
        }
        $stmt->close();

        if ($unit['status'] !== 'Vacant') {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'This unit is not available.']);
            exit();
        }

        // ✅ Insert new booking request
        $stmtInsert = $conn->prepare("
            INSERT INTO booking_requests 
            (unit_detail_id, unit_code, tenant_id, owner_id, room_type, amount, status, payment_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'Pending', 'Unpaid', NOW())
        ");
        $stmtInsert->bind_param(
            "isiisd",  // ✅ Correct binding format
            $unit_id,
            $unit['unit_code'],
            $tenant_id,
            $unit['owner_id'],
            $unit['room_type'],
            $unit['price']
        );
        $stmtInsert->execute();
        $stmtInsert->close();

        // ✅ Mark unit as Booked
        $stmtUpdate = $conn->prepare("
            UPDATE property_unit_details 
            SET status = 'Booked', last_updated = NOW() 
            WHERE unit_detail_id = ?
        ");
        $stmtUpdate->bind_param("i", $unit_id);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Booking request successfully submitted!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error booking unit: ' . $e->getMessage()]);
    }
    exit;
}

// ✅ Load unit data for display
if (!isset($_GET['property_id']) || empty($_GET['property_id'])) {
    die("Invalid property.");
}
$property_id = intval($_GET['property_id']);

$stmt = $conn->prepare("
    SELECT 
        ud.unit_detail_id, 
        ud.unit_code, 
        ud.status, 
        ud.last_updated,
        pu.unit_id,
        pu.room_type, 
        pu.price
    FROM property_unit_details ud
    JOIN property_units pu ON ud.property_unit_id = pu.unit_id
    WHERE ud.property_id = ? 
      AND ud.status IN ('Vacant', 'Booked')
    ORDER BY pu.room_type, ud.unit_code
");
$stmt->bind_param("i", $property_id);
$stmt->execute();
$result = $stmt->get_result();
$units = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ✅ Load images for each unit
$unitImages = [];
if (!empty($units)) {
    $unitIds = array_column($units, 'unit_id');
    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $types = str_repeat('i', count($unitIds));

    $stmt = $conn->prepare("SELECT unit_id, image_path FROM unit_images WHERE unit_id IN ($placeholders)");
    $stmt->bind_param($types, ...$unitIds);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $unitImages[$row['unit_id']][] = $row['image_path'];
    }
    $stmt->close();
}

foreach ($units as &$unit) {
    $unit['images'] = $unitImages[$unit['unit_id']] ?? ["../Uploads/placeholder.png"];
}

// Group for front-end
$groupedUnits = [];
foreach ($units as $unit) {
    $groupedUnits[$unit['room_type']][] = $unit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Book Units</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            flex: 1;
        }

        .room-section {
            margin-bottom: 25px;
        }

        .room-title {
            background: #343a40;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 1rem;
        }

        .units-scroll {
            max-height: 400px;
            overflow-y: auto;
        }

        .units-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .units-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        .unit-card {
            border-radius: 12px;
            margin-bottom: 15px;
            padding: 0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            background-color: #1c1c1c;
            transition: transform 0.2s;
            height: 240px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            margin-left: 35px;
            max-width: 370px;
        }

        .unit-card:hover {
            transform: translateY(-3px);
        }

        .unit-card img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            cursor: pointer;
        }

        .unit-details {
            padding: 8px 10px;
            color: #fff;
            font-size: 0.85rem;
        }

        .unit-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .badge-status {
            font-size: 0.7rem;
        }

        .unit-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 10px 8px 10px;
        }

        .last-updated {
            font-size: 0.65rem;
            background-color: #343a40;
            color: #ccc;
            padding: 2px 5px;
            border-radius: 4px;
        }

        .btn-book {
            font-size: 0.7rem;
            padding: 2px 5px;
            min-width: 80px;
        }

        .unit-bottom {
            margin-top: 10px;
        }

        /* Dropdown item hover */
        .dropdown-menu .dropdown-item:hover {
            background-color: #0c3a42ff !important;
            /* dark gray/black hover */
            color: #f5fd0dff !important;
            /* blue text on hover */
        }


        footer {
            background: #343a40;
            color: white;
            padding: 10px 0;
            text-align: center;
            margin-top: auto;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="tenant.php" class="btn btn-outline-light btn-sm d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <span class="navbar-brand mx-auto fw-bold" style="position: absolute; left: 50%; transform: translateX(-50%);">
                AccoFinda
            </span>
            <div style="width: 75px;"></div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Booking success/error message -->
        <div id="booking-message" class="alert text-center fade" style="display:none;"></div>

        <h4 class="mb-4 text-white">Available Units for Property #<?= htmlspecialchars($property_id) ?></h4>

        <?php if (empty($groupedUnits)): ?>
            <p class="text-white">No units found for this property.</p>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($groupedUnits as $roomType => $unitsList): ?>
                    <?php
                    // Separate vacant and booked units
                    $vacantUnits = array_filter($unitsList, fn($u) => $u['status'] === 'Vacant');
                    $vacantCount = count($vacantUnits);

                    // Collect images
                    $allImages = [];
                    foreach ($unitsList as $u) {
                        foreach ($u['images'] as $img) {
                            $allImages[] = "../" . htmlspecialchars($img);
                        }
                    }
                    if (empty($allImages)) $allImages[] = "../assets/default-placeholder.png";
                    ?>

                    <div class="col-md-6 col-lg-4">
                        <div class="unit-card border rounded shadow-sm p-3"
                            style="background:#1c1c1e; color:#fff; min-height:360px; display:flex; flex-direction:column; justify-content:space-between;">

                            <!-- Top Info: Room Type & Vacant Count -->
                            <div class="unit-header d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold" style="font-size:1rem;"><?= htmlspecialchars($roomType) ?></span>
                                <span class="badge <?= $vacantCount > 0 ? 'bg-success' : 'bg-danger' ?>" style="font-size:0.75rem;">
                                    <?= $vacantCount ?> Vacant
                                </span>
                            </div>

                            <!-- Image Section -->
                            <img src="<?= $allImages[0] ?>"
                                alt="Room Type Image"
                                data-images='<?= json_encode($allImages) ?>'
                                class="unit-img rounded mb-3"
                                style="width:100%; height:180px; object-fit:cover; cursor:pointer;"
                                onclick="openImageGallery(this)">


                            <!-- Booking Dropdown as Buttons -->
                            <?php if (!empty($unitsList)): ?>
                                <div class="d-flex flex-column gap-2 mt-auto">
                                    <div class="dropdown">
                                        <button class="btn btn-secondary btn-sm dropdown-toggle w-100 text-start"
                                            type="button"
                                            id="dropdown<?= htmlspecialchars($roomType) ?>"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false"
                                            style="background:#343a40; color:#fff; border:none;">
                                            Select Room
                                        </button>

                                        <ul class="dropdown-menu w-90"
                                            aria-labelledby="dropdown<?= htmlspecialchars($roomType) ?>"
                                            style="background:#000; color:#fff; padding:0.2rem 0;">
                                            <?php foreach ($unitsList as $unit): ?>
                                                <?php $isVacant = $unit['status'] === 'Vacant'; ?>
                                                <li>
                                                    <button class="dropdown-item d-flex justify-content-between align-items-center btn-sm unit-select-btn"
                                                        style="padding:0.4rem 0.6rem; background:#000; color:#fff;"
                                                        <?= !$isVacant ? 'disabled' : '' ?>
                                                        data-unit-id="<?= $unit['unit_detail_id'] ?>"
                                                        data-unit-label="<?= htmlspecialchars($unit['unit_code']) ?> (KSh <?= number_format($unit['price'], 2) ?>)">

                                                        <!-- Unit name + price -->
                                                        <span><?= htmlspecialchars($unit['unit_code']) ?> (KSh <?= number_format($unit['price'], 2) ?>)</span>

                                                        <!-- Badge stays colored -->
                                                        <span class="badge <?= $isVacant ? 'bg-primary' : 'bg-danger' ?> text-light"
                                                            style="font-size:0.65rem; margin-left:0.5rem;">
                                                            <?= $isVacant ? 'Vacant' : 'Booked' ?>
                                                        </span>
                                                    </button>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>


                                    <!-- Book Button -->
                                    <button class="btn btn-primary btn-sm btn-book-roomtype"
                                        data-room-type="<?= htmlspecialchars($roomType) ?>"
                                        style="font-size:0.75rem; padding:0.35rem 0.5rem; width:30%; margin-left:0.5rem;">
                                        Book Now
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid rounded">
                </div>
                <div class="modal-footer justify-content-between">
                    <button id="prevImg" class="btn btn-light btn-sm">Prev</button>
                    <button id="nextImg" class="btn btn-light btn-sm">Next</button>
                    <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Image modal logic
        let currentImages = [];
        let currentIndex = 0;
        const modalImage = document.getElementById("modalImage");
        const modal = new bootstrap.Modal(document.getElementById("imageModal"));

        document.querySelectorAll('.unit-img').forEach(img => {
            img.addEventListener('click', function() {
                currentImages = JSON.parse(this.dataset.images);
                currentIndex = 0;
                modalImage.src = currentImages[currentIndex];
                modal.show();
            });
        });

        document.getElementById("prevImg").addEventListener("click", () => {
            if (currentImages.length > 0) {
                currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
                modalImage.src = currentImages[currentIndex];
            }
        });

        document.getElementById("nextImg").addEventListener("click", () => {
            if (currentImages.length > 0) {
                currentIndex = (currentIndex + 1) % currentImages.length;
                modalImage.src = currentImages[currentIndex];
            }
        });

        // Dropdown button selection
        document.querySelectorAll('.unit-select-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const unitLabel = this.dataset.unitLabel;
                const unitId = this.dataset.unitId;
                const dropdownButton = this.closest('.dropdown').querySelector('.dropdown-toggle');

                dropdownButton.textContent = unitLabel;
                dropdownButton.dataset.selectedUnit = unitId; // store selected unit id
            });
        });

        // Booking button logic
        document.querySelectorAll('.btn-book-roomtype').forEach(btn => {
            btn.addEventListener('click', async function() {
                const dropdownContainer = this.closest('.d-flex');
                const dropdownButton = dropdownContainer.querySelector('.dropdown-toggle');
                const unitId = dropdownButton.dataset.selectedUnit;

                if (!unitId) {
                    alert("Please select a vacant room to book.");
                    return;
                }

                if (!confirm("Are you sure you want to book this unit?")) return;

                const msg = document.getElementById('booking-message');
                msg.style.display = 'none';
                this.disabled = true;

                try {
                    const response = await fetch("bookUnit.php", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'unit_id=' + encodeURIComponent(unitId)
                    });

                    const data = await response.json();

                    msg.className = data.success ? "alert alert-success text-center" : "alert alert-danger text-center";
                    msg.innerText = data.message;
                    msg.style.display = 'block';

                    if (data.success) {
                        // Disable booked button
                        const unitBtn = dropdownContainer.querySelector(`.unit-select-btn[data-unit-id='${unitId}']`);
                        if (unitBtn) unitBtn.disabled = true;

                        // Update badge
                        const badge = this.closest('.unit-card').querySelector('.badge');
                        const remaining = dropdownContainer.querySelectorAll('.unit-select-btn:not([disabled])').length;
                        if (remaining > 0) {
                            badge.textContent = remaining + " Vacant";
                            badge.className = "badge bg-success";
                        } else {
                            badge.textContent = "No vacant rooms available";
                            badge.className = "badge bg-danger";
                            btn.disabled = true;
                        }

                        // Reset dropdown text
                        dropdownButton.textContent = "-- Select Room --";
                        delete dropdownButton.dataset.selectedUnit;

                        setTimeout(() => {
                            msg.style.display = 'none';
                        }, 3000);
                    } else {
                        setTimeout(() => {
                            msg.style.display = 'none';
                        }, 5000);
                        this.disabled = false;
                    }
                } catch (err) {
                    msg.className = "alert alert-danger text-center";
                    msg.innerText = "Network Error: " + err.message;
                    msg.style.display = 'block';
                    setTimeout(() => {
                        msg.style.display = 'none';
                    }, 5000);
                    this.disabled = false;
                }
            });
        });
    </script>

    <footer>
        <div class="container">
            &copy; <?= date("Y") ?> AccoFinda. All rights reserved.
        </div>
    </footer>
</body>

</html>