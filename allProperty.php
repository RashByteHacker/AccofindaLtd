<?php
session_start();
require '../config.php';

// Access control: only admins and managers
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'manager'])) {
    header("Location: ../login.php");
    exit();
}

// --- Helper function for safe output
function e($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// --- Get property_id from URL
$property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
if ($property_id <= 0) {
    die("Invalid property ID.");
}

// Fetch property with owner info
$stmt = $conn->prepare("
    SELECT p.*, u.full_name AS owner_name, u.phone_number, u.email AS owner_email
    FROM properties p
    LEFT JOIN users u ON p.landlord_email=u.email
    WHERE p.property_id = ?
");
$stmt->bind_param("i", $property_id);
$stmt->execute();
$result = $stmt->get_result();
$property = $result->fetch_assoc();
$stmt->close();

if (!$property) {
    die("Property not found.");
}

// Fetch property units
$units_stmt = $conn->prepare("SELECT * FROM property_units WHERE property_id=?");
$units_stmt->bind_param("i", $property_id);
$units_stmt->execute();
$units_result = $units_stmt->get_result();
$units = [];
$statusCounts = [];

while ($unit = $units_result->fetch_assoc()) {
    // Fetch unit details
    $details_stmt = $conn->prepare("SELECT * FROM property_unit_details WHERE property_unit_id=?");
    $details_stmt->bind_param("i", $unit['unit_id']);
    $details_stmt->execute();
    $details_result = $details_stmt->get_result();
    $unit_details = [];

    while ($detail = $details_result->fetch_assoc()) {
        $unit_details[] = $detail;

        // Build status summary
        $roomType = $unit['room_type'] ?? 'Unknown';
        $status = strtolower($detail['status'] ?? 'unknown');

        if (!isset($statusCounts[$roomType])) {
            $statusCounts[$roomType] = ['occupied' => 0, 'booked' => 0, 'vacant' => 0];
        }

        if (in_array($status, ['occupied', 'booked', 'vacant'])) {
            $statusCounts[$roomType][$status]++;
        }
    }

    $details_stmt->close();
    $unit['unit_details'] = $unit_details;
    $units[] = $unit;
}
$units_stmt->close();

$property['units'] = $units;
$property['statusCounts'] = $statusCounts;
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property Details - Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body,
        html {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: #f8f9fa;
        }

        main {
            flex: 1 0 auto;
            padding-top: 80px;
            padding-bottom: 70px;
        }

        .navbar-brand {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        .card {
            background-color: #000 !important;
            color: #fff !important;
            border-radius: 10px;
        }

        .card .card-body {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .units-list,
        .unit-details {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark bg-dark fixed-top py-3">
        <div class="container-fluid position-relative">
            <a href="propertiesListed" class="btn btn-outline-light"><i class="fa fa-arrow-left me-1"></i> Back</a>
            <span class="navbar-brand fs-4 fw-bold">🏠 Property Details</span>
        </div>
    </nav>

    <main class="container">
        <?php
        $title = e($property['title'] ?? 'Untitled');
        $desc = e($property['description'] ?? 'No description available');
        $status = ucfirst(trim($property['status'] ?? 'Pending'));
        $statusClass = match (strtolower($status)) {
            'approved' => 'bg-success',
            'pending' => 'bg-warning text-dark',
            'suspended' => 'bg-danger',
            default => 'bg-secondary'
        };
        $createdAt = !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'Unknown date';
        ?>

        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap gap-4">
                <!-- Left: Units -->
                <div style="flex:1 1 45%; min-width:300px;">
                    <div class="d-flex align-items-center mb-3">
                        <h5 class="card-title mb-0 me-2"><i class="fa fa-building"></i> <?= $title ?> (ID: <?= $property_id ?>)</h5>
                        <span class="badge <?= $statusClass ?>" style="font-size:0.8rem; padding:0.4em 0.6em;"><?= $status ?></span>
                    </div>
                    <p><strong>Description:</strong> <?= $desc ?></p>
                    <p>
                        <strong>Owner:</strong> <?= e($property['owner_name'] ?? 'N/A') ?><br>
                        <strong>Email:</strong> <?= e($property['owner_email'] ?? 'N/A') ?><br>
                        <strong>Phone:</strong> <?= e($property['phone_number'] ?? 'N/A') ?><br>
                        <strong>Location:</strong> <?= e($property['address'] . ', ' . $property['city'] . ', ' . $property['state']) ?><br>
                        <strong>Added on:</strong> <?= $createdAt ?>
                    </p>

                    <?php if (!empty($property['units'])): ?>
                        <div class="units-list border rounded p-2 mb-2" style="background:rgba(255,255,255,0.05);">
                            <h6 class="mb-2"><i class="fa fa-door-open"></i> Units & Status</h6>
                            <table class="table table-dark table-striped table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Room Type</th>
                                        <th>Units</th>
                                        <th>Status</th>
                                        <th>Price</th>
                                        <th>Electricity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($property['units'] as $unit): ?>
                                        <tr>
                                            <td><?= e($unit['room_type'] ?? 'N/A') ?></td>
                                            <td><?= intval($unit['units_available'] ?? 0) ?></td>
                                            <td><?= e($unit['status'] ?? 'Available') ?></td>
                                            <td><?= number_format(floatval($unit['price'] ?? 0), 2) ?></td>
                                            <td><?= e($unit['electricity_service'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right: Unit Details -->
                <div style="flex:1 1 45%; min-width:300px; background-color:#1a2738; border-radius:8px; padding:15px; color:#eee;">
                    <h6 class="mb-2"><i class="fa fa-info-circle"></i> Unit Details</h6>

                    <?php if (!empty($property['units'])): ?>
                        <div style="max-height:220px; overflow-y:auto;">
                            <table class="table table-dark table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Room Type</th>
                                        <th>Room ID</th>
                                        <th>Code</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($property['units'] as $unit): ?>
                                        <?php foreach ($unit['unit_details'] as $detail): ?>
                                            <tr>
                                                <td><?= e($unit['room_type'] ?? 'N/A') ?></td>
                                                <td><?= e($detail['unit_detail_id'] ?? 'N/A') ?></td>
                                                <td><?= e($detail['unit_code'] ?? 'N/A') ?></td>
                                                <td><?= e(ucfirst($detail['status'] ?? 'N/A')) ?></td>
                                                <td><?= !empty($detail['last_updated']) ? date("M d, Y", strtotime($detail['last_updated'])) : 'N/A' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Room Type Summary -->
                        <h6 class="mt-4">Room Type Summary</h6>
                        <ul>
                            <?php foreach ($property['statusCounts'] as $roomType => $counts): ?>
                                <li>
                                    <strong><?= e($roomType) ?></strong>:
                                    Occupied = <?= $counts['occupied'] ?? 0 ?>,
                                    Booked = <?= $counts['booked'] ?? 0 ?>,
                                    Vacant = <?= $counts['vacant'] ?? 0 ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                    <?php else: ?>
                        <p class="fst-italic text-warning">No detailed unit data available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex justify-content-end gap-2 mt-3">
                <a href="../Shared/editProperty?property_id=<?= $property['property_id'] ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                <a href="../Shared/propertyUnitsDetail?property_id=<?= $property['property_id'] ?>" class="btn btn-sm btn-success">🔄 Update</a>
                <form method="POST" action="../Shared/deleteProperty" onsubmit="return confirm('Delete this property?');">
                    <input type="hidden" name="property_id" value="<?= $property['property_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                </form>
            </div>
        </div>
    </main>

    <footer class="bg-dark text-light text-center py-3">
        &copy; <?= date('Y') ?> Accofinda. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>