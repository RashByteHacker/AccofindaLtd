<?php
session_start();
require '../config.php';

if (!isset($_SESSION['email']) || !in_array(strtolower($_SESSION['role']), ['landlord', 'admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

$userEmail = $_SESSION['email'];
$userRole = strtolower($_SESSION['role']);
$message = "";
$messageClass = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['property_id'])) {
    // Process only the submitted property's units update
    $propertyId = intval($_POST['property_id']);
    $unit_detail_ids = $_POST['unit_detail_id'] ?? [];
    $unit_codes = $_POST['unit_code'] ?? [];
    $statuses = $_POST['status'] ?? [];
    $property_unit_ids = $_POST['property_unit_id'] ?? [];

    foreach ($unit_detail_ids as $index => $unit_detail_id) {
        $unit_code = $unit_codes[$index] ?? '';
        $status = $statuses[$index] ?? 'Vacant';

        if ($unit_detail_id) {
            // For updates, ensure property_id is also updated from property_units
            $stmt = $conn->prepare("
                UPDATE property_unit_details 
                SET unit_code = ?, status = ?, property_id = (
                    SELECT property_id FROM property_units WHERE unit_id = ?
                ) 
                WHERE unit_detail_id = ?
            ");
            $stmt->bind_param("ssii", $unit_code, $status, $property_unit_ids[$index], $unit_detail_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $property_unit_id = $property_unit_ids[$index] ?? 0;
            if ($property_unit_id) {
                // Fetch the property_id for the given property_unit_id
                $stmt = $conn->prepare("SELECT property_id FROM property_units WHERE unit_id = ?");
                $stmt->bind_param("i", $property_unit_id);
                $stmt->execute();
                $stmt->bind_result($prop_id);
                $stmt->fetch();
                $stmt->close();

                if ($prop_id) {
                    // Insert with the correct property_id
                    $stmt = $conn->prepare("
                        INSERT INTO property_unit_details (property_unit_id, property_id, unit_code, status) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iiss", $property_unit_id, $prop_id, $unit_code, $status);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    $_SESSION['success_' . $propertyId] = "Units updated successfully for property ID: {$propertyId}.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch properties
if ($userRole === 'landlord') {
    $stmt = $conn->prepare("SELECT * FROM properties WHERE landlord_email = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $userEmail);
} else {
    $stmt = $conn->prepare("SELECT * FROM properties ORDER BY created_at DESC");
}
$stmt->execute();
$propertiesResult = $stmt->get_result();

$properties = [];
while ($property = $propertiesResult->fetch_assoc()) {
    $properties[$property['property_id']] = $property;
}
$stmt->close();

$units = [];
$unitDetails = [];

if (count($properties) > 0) {
    $propertyIds = array_keys($properties);
    $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
    $types = str_repeat('i', count($propertyIds));

    $stmt = $conn->prepare("SELECT * FROM property_units WHERE property_id IN ($placeholders) ORDER BY property_id, room_type");
    $stmt->bind_param($types, ...$propertyIds);
    $stmt->execute();
    $unitsResult = $stmt->get_result();

    $propertyUnitIds = [];
    while ($unit = $unitsResult->fetch_assoc()) {
        $units[$unit['property_id']][] = $unit;
        $propertyUnitIds[] = $unit['unit_id'];
    }
    $stmt->close();

    if (count($propertyUnitIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($propertyUnitIds), '?'));
        $types = str_repeat('i', count($propertyUnitIds));

        $stmt = $conn->prepare("SELECT * FROM property_unit_details WHERE property_unit_id IN ($placeholders) ORDER BY property_unit_id, unit_detail_id");
        $stmt->bind_param($types, ...$propertyUnitIds);
        $stmt->execute();
        $detailsResult = $stmt->get_result();

        while ($detail = $detailsResult->fetch_assoc()) {
            $unitDetails[$detail['property_unit_id']][] = $detail;
        }
        $stmt->close();
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Property Units</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <style>
        body {
            background: linear-gradient(135deg, #939494ff, #52809aff);
            padding: 30px 0 80px 0;
            /* top and bottom padding */
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 750px;
            margin: auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 700;
            color: #f0e6ff;
        }

        .property-card {
            background-color: #656568ff;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.5);
            max-height: 600px;
            overflow-y: auto;
        }

        /* Scroll main properties container */
        #propertiesContainer {
            max-height: 720px;
            overflow-y: auto;
            padding-right: 10px;
        }

        #propertiesContainer::-webkit-scrollbar {
            width: 8px;
        }

        #propertiesContainer::-webkit-scrollbar-thumb {
            background: #4b5759ff;
            border-radius: 10px;
        }

        /* Styled section titles */
        .section-title {
            background: #000000ff;
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: 700;
            margin-bottom: 15px;
            box-shadow: 0 0 8px #ffffffff;
            text-align: center;
            font-size: 1.2rem;
            user-select: none;
        }

        /* Unit container scroll & style */
        .units-container {
            max-height: 380px;
            overflow-y: auto;
            border: 1px solid #000000ff;
            border-radius: 10px;
            padding: 15px;
            background: #000000ff;
            margin-bottom: 20px;
        }

        .units-container::-webkit-scrollbar {
            width: 7px;
        }

        .units-container::-webkit-scrollbar-thumb {
            background: #3c3c3dff;
            border-radius: 10px;
        }

        /* Each unit detail box */
        .unit-detail-row {
            background: #171819ff;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 17px;
            box-shadow: inset 0 0 5px #000000ff;
        }

        label {
            font-weight: 600;
            color: #ffffffff;
        }

        input.form-control,
        select.form-select {
            background: #ffffffff;
            border: none;
            color: #000000ff;
            font-weight: 600;
            box-shadow: none;
            transition: background 0.3s ease;
        }

        input.form-control:focus,
        select.form-select:focus {
            background: #ffffffff;
            outline: none;
            box-shadow: 0 0 8px #000000ff;
            color: #000000ff;
        }

        /* Submit button styling */
        .btn-submit {
            display: block;
            margin: 2rem auto;
            padding: 12px 40px;
            font-size: 1.25rem;
            background: #0b010cff;
            border: none;
            border-radius: 50px;
            color: #fff;
            transition: background 0.4s ease;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #432406ff;
        }

        /* Success message style */
        .alert-success {
            max-width: 720px;
            margin: 0 auto 1rem;
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.75rem 1.25rem;
            border-radius: 0.3rem;
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
            animation: fadeOut 1s ease 3s forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                height: 0;
                padding: 0;
                margin: 0;
                overflow: hidden;
            }
        }

        /* Navbar full width fixed top */
        nav.navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }

        /* Container inside navbar is centered with max-width */
        nav .container-fluid {
            max-width: 750px;
            margin: 0 auto;
        }

        /* Footer styling */
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #1f1f1f;
            color: #ccc;
            text-align: center;
            padding: 12px 0;
            font-size: 0.9rem;
            box-shadow: 0 -2px 5px rgba(0, 0, 0, 0.5);
            z-index: 1020;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <button onclick="history.back()" class="btn btn-outline-light">
                ← Back to Dashboard
            </button>
            <span class="navbar-brand mx-auto fw-bold" style="font-size: 1.5rem;">
                Manage Property Units and Status
            </span>
            <div style="width: 120px;"><!-- empty spacer for alignment --></div>
        </div>
    </nav>
    <!-- Search bar -->
    <div class="container mb-4" style="max-width: 750px; margin-top: 80px;">
        <div class="d-flex align-items-center gap-2">
            <!-- Search Input -->
            <input type="text" id="propertySearch" class="form-control form-control-sm"
                placeholder="Search properties by title, city, address, or type..."
                style="background: #fff; color: #000; font-weight: 600; border-radius: 8px; padding: 8px 12px; flex-grow: 1; border: 1px solid #ccc; font-size: 0.85rem;">

            <!-- Search Button -->
            <button id="searchBtn" class="btn btn-primary btn-sm">
                Search
            </button>

            <!-- Reset Button -->
            <button id="resetBtn" class="btn btn-secondary btn-sm">
                Reset
            </button>
        </div>
    </div>

    <div id="propertiesContainer" style="margin-top: 80px;">
        <?php if (empty($properties)): ?>
            <div class="alert alert-warning text-center shadow-sm"
                style="border: 1px solid black;">
                <i class="fa fa-info-circle"></i> No properties found for your account.
            </div>
        <?php else: ?>
            <?php
            $propertyCount = 1;
            foreach ($properties as $property):
                $propertyId = $property['property_id'];
                $unitsForProperty = $units[$propertyId] ?? [];
            ?>
                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST" class="property-card">
                    <input type="hidden" name="property_id" value="<?= $propertyId ?>">

                    <?php if (!empty($_SESSION['success_' . $propertyId])): ?>
                        <div class="alert alert-success" id="success-msg-<?= $propertyId ?>">
                            <?= htmlspecialchars($_SESSION['success_' . $propertyId]) ?>
                        </div>
                        <?php unset($_SESSION['success_' . $propertyId]); ?>
                    <?php endif; ?>

                    <div class="section-title">
                        Property <?= $propertyCount++ ?>: <?= htmlspecialchars($property['title']) ?>
                    </div>
                    <p><strong>Description:</strong> <?= htmlspecialchars($property['description']) ?></p>
                    <p><strong>Location:</strong> <?= htmlspecialchars($property['address'] . ', ' . $property['city'] . ', ' . $property['state'] . ' ' . $property['postal_code']) ?></p>
                    <p><strong>Property Type:</strong> <?= htmlspecialchars($property['property_type']) ?></p>
                    <p><strong>Status:</strong> <?= htmlspecialchars($property['status']) ?></p>
                    <p><strong>Added on:</strong> <?= !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'Unknown' ?></p>
                    <p><strong>Amenities:</strong> <?= htmlspecialchars($property['amenities']) ?></p>

                    <?php if (empty($unitsForProperty)): ?>
                        <p>No units found for this property.</p>
                    <?php else: ?>
                        <?php foreach ($unitsForProperty as $unit):
                            $details = $unitDetails[$unit['unit_id']] ?? [];
                            $count = max(intval($unit['units_available']), count($details));
                        ?>
                            <div class="mb-4 p-3 border rounded" style="background-color: #000000ff;">
                                <div class="section-title" style="background: #000000ff; font-size: 1rem; margin-bottom: 1rem;">
                                    <?= htmlspecialchars($unit['room_type']) ?> Units
                                </div>
                                <p><strong>Units Available:</strong> <?= intval($unit['units_available']) ?></p>

                                <div class="units-container">
                                    <?php for ($i = 0; $i < $count; $i++):
                                        $detail = $details[$i] ?? null;
                                    ?>
                                        <div class="unit-detail-row row g-3 align-items-center" style="margin-bottom: 20px;">
                                            <input type="hidden" name="property_unit_id[]" value="<?= $unit['unit_id'] ?>">
                                            <input type="hidden" name="unit_detail_id[]" value="<?= $detail['unit_detail_id'] ?? '' ?>">

                                            <div class="col-md-6">
                                                <label>Unit Code/Number</label>
                                                <input type="text" name="unit_code[]" class="form-control" required
                                                    value="<?= htmlspecialchars($detail['unit_code'] ?? '') ?>" placeholder="Unique Unit Code">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Status</label>
                                                <select name="status[]" class="form-select" required>
                                                    <?php
                                                    $statuses = ['Vacant', 'Booked', 'Occupied'];
                                                    $selectedStatus = $detail['status'] ?? 'Vacant';
                                                    foreach ($statuses as $status): ?>
                                                        <option value="<?= $status ?>" <?= ($status === $selectedStatus) ? 'selected' : '' ?>><?= $status ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-submit">Update Units</button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script>
        // Automatically fade out success messages after 4 seconds
        window.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.alert-success').forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 1s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 1000);
                }, 4000);
            });
        });

        const searchInput = document.getElementById('propertySearch');
        const searchBtn = document.getElementById('searchBtn');
        const resetBtn = document.getElementById('resetBtn');
        const propertyCards = document.querySelectorAll('.property-card');

        function filterProperties() {
            const filter = searchInput.value.toLowerCase();
            propertyCards.forEach(card => {
                const text = card.innerText.toLowerCase();
                card.style.display = text.includes(filter) ? '' : 'none';
            });
        }

        // Real-time filtering on pressing Enter
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') filterProperties();
        });

        // Search button click
        searchBtn.addEventListener('click', filterProperties);

        // Reset button click
        resetBtn.addEventListener('click', () => {
            searchInput.value = '';
            propertyCards.forEach(card => card.style.display = '');
        });
    </script>
</body>

</html>