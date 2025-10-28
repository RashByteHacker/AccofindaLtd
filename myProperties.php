<?php
session_start();
require '../config.php';

// ✅ Access control: only landlords
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: login");
    exit();
}

$landlordEmail = $_SESSION['email'] ?? '';
$property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;

// --- Helper function for safe output
function e($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// --- Fetch the selected property
$property = null;
if ($property_id > 0) {
    $stmt = $conn->prepare("SELECT p.*, u.phone_number 
                            FROM properties p 
                            LEFT JOIN users u ON p.landlord_email = u.email
                            WHERE p.property_id = ? AND p.landlord_email = ?");
    $stmt->bind_param("is", $property_id, $landlordEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $property = $result->fetch_assoc();
    $stmt->close();
}

// --- Fetch property units and details
$units = [];
if ($property) {
    $units_stmt = $conn->prepare("SELECT * FROM property_units WHERE property_id = ?");
    $units_stmt->bind_param("i", $property_id);
    $units_stmt->execute();
    $units_result = $units_stmt->get_result();

    while ($unit = $units_result->fetch_assoc()) {
        $unit_id = $unit['unit_id'] ?? 0;

        $details_stmt = $conn->prepare("SELECT * FROM property_unit_details WHERE property_unit_id = ?");
        $details_stmt->bind_param("i", $unit_id);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();

        $unit_details = [];
        while ($detail = $details_result->fetch_assoc()) {
            $unit_details[] = $detail;
        }
        $details_stmt->close();

        $unit['unit_details'] = $unit_details;
        $units[] = $unit;
    }
    $units_stmt->close();

    $property['units'] = $units;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <title>Property Details - Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #f8f9fa;
        }

        main {
            flex: 1;
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
            <a href="propertyView" class="btn btn-outline-light">
                <i class="fa fa-arrow-left"></i> Back
            </a>
            <span class="navbar-brand fs-4 fw-bold">🏠 Property Details</span>
        </div>
    </nav>

    <main class="container">
        <?php if (!$property): ?>
            <div class="alert alert-danger text-center shadow-sm">
                <i class="fa fa-times-circle"></i> Property not found or you don’t have access.
            </div>
        <?php else: ?>
            <?php
            $title       = e($property['title'] ?? 'Untitled');
            $description = e($property['description'] ?? 'No description available.');
            $address     = e($property['address'] ?? '');
            $city        = e($property['city'] ?? '');
            $state       = e($property['state'] ?? '');
            $postal_code = e($property['postal_code'] ?? '');
            $createdAt   = !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'Unknown date';

            $property_status = trim($property['status'] ?? 'Pending');
            $statusClass = match (strtolower($property_status)) {
                'approved'  => 'bg-success',
                'pending'   => 'bg-warning text-dark',
                'suspended' => 'bg-danger',
                default     => 'bg-secondary'
            };
            $statusLabel = ucfirst($property_status);

            // Count units summary
            $statusCounts = [];
            foreach ($property['units'] ?? [] as $unit) {
                $roomType = $unit['room_type'] ?? 'Unknown';
                if (!isset($statusCounts[$roomType])) {
                    $statusCounts[$roomType] = ['occupied' => 0, 'booked' => 0, 'vacant' => 0];
                }
                foreach ($unit['unit_details'] ?? [] as $detail) {
                    $s = strtolower($detail['status'] ?? 'vacant');
                    if (isset($statusCounts[$roomType][$s])) {
                        $statusCounts[$roomType][$s]++;
                    }
                }
            }
            ?>

            <div class="card mb-4">
                <div class="card-body d-flex flex-wrap gap-4">
                    <!-- Left Side -->
                    <div style="flex: 1 1 45%; min-width: 300px;">
                        <div class="d-flex align-items-center mb-3">
                            <h5 class="card-title mb-0 me-2">
                                <i class="fa fa-building"></i> <?= $title ?>
                            </h5>
                            <span class="badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
                        </div>
                        <p><strong>Description:</strong> <?= $description ?></p>
                        <p>
                            <strong>📍 Address:</strong> <?= $address ?>, <?= $city ?>, <?= $state ?> <?= $postal_code ?><br>
                            <strong>📞 Phone:</strong> <?= e($property['phone_number'] ?? 'N/A') ?><br>
                            <strong>🕒 Added on:</strong> <?= $createdAt ?>
                        </p>

                        <?php if (!empty($property['units'])): ?>
                            <div class="units-list border rounded p-2 bg-dark bg-opacity-25">
                                <h6><i class="fa fa-door-open"></i> Units and Status</h6>
                                <table class="table table-dark table-striped table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Room Type</th>
                                            <th>Units Available</th>
                                            <th>Status</th>
                                            <th>Price (Ksh)</th>
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
                        <?php else: ?>
                            <p class="fst-italic text-warning">No units available.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Right Side -->
                    <div style="flex: 1 1 45%; min-width: 300px; background-color: #1a2738; border-radius: 8px; padding: 15px; color: #eee;">
                        <h6><i class="fa fa-info-circle"></i> Unit Details</h6>
                        <?php if (!empty($property['units'])): ?>
                            <div style="max-height: 220px; overflow-y: auto;">
                                <table class="table table-dark table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>Room Type</th>
                                            <th>Room ID</th>
                                            <th>Room Code</th>
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

                            <h6 class="mt-3">Room Type Summary</h6>
                            <ul>
                                <?php foreach ($statusCounts as $roomType => $counts): ?>
                                    <li>
                                        <strong><?= e($roomType) ?></strong>: Occupied = <?= $counts['occupied'] ?? 0 ?>,
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

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="../Shared/editProperty?property_id=<?= $property_id ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                    <a href="../Shared/propertyUnitsDetail?property_id=<?= $property_id ?>" class="btn btn-sm btn-success">🔄 Update</a>
                    <form action="../Shared/deleteProperty" method="POST" onsubmit="return confirm('Are you sure you want to delete this property? This action cannot be undone.')">
                        <input type="hidden" name="property_id" value="<?= $property_id ?>">
                        <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <footer class="bg-dark text-light text-center py-3 fixed-bottom">
        &copy; <?= date('Y'); ?> Accofinda. All rights reserved.
    </footer>
</body>

</html>