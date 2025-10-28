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

$limit = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Count total
$countResult = $conn->query("SELECT COUNT(*) AS total FROM properties");
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// Fetch properties
$query = "
    SELECT p.*, u.full_name AS owner_name, u.phone_number, u.email AS owner_email
    FROM properties p
    LEFT JOIN users u ON p.landlord_email=u.email
    ORDER BY p.created_at DESC
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($query);
$properties = [];
while ($property = $result->fetch_assoc()) {
    $properties[] = $property;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <!-- 👇 Force desktop scaling on mobile -->
    <meta name="viewport" content="width=1200, initial-scale=1">
    <title>All Properties - Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar-brand {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .property-details {
            display: none;
            background: #1a2738;
            color: #eee;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }

        /* --- Table Styling --- */
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            width: 100% !important;
            table-layout: auto;
            white-space: nowrap;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #fdfdfd;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f7ff;
            transition: 0.2s;
        }

        /* Keep desktop layout on mobile */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        .table th,
        .table td {
            white-space: nowrap;
        }

        /* --- Footer at bottom --- */
        footer {
            margin-top: auto;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark bg-dark fixed-top py-3">
        <div class="container-fluid position-relative">
            <button class="btn btn-outline-light" onclick="history.back()"><i class="fa fa-arrow-left me-1"></i> Back</button>
            <span class="navbar-brand fs-4 fw-bold">🏠 All Properties</span>
        </div>
    </nav>

    <main class="container-fluid d-flex flex-column" style="padding-top:90px;">

        <!-- Search -->
        <div class="search-bar p-3 mb-4 rounded shadow-sm d-flex align-items-center" style="background:#000000ff; max-width:100%;">
            <input type="text" id="searchInput" class="form-control form-control-sm me-2"
                placeholder="Search properties or owners..." style="max-width:300px; border-radius: 0.25rem;">
        </div>

        <?php if (empty($properties)): ?>
            <p class="text-muted fst-italic">No properties found.</p>
        <?php else: ?>
            <div class="table-responsive flex-grow-1">
                <table class="table table-striped table-hover align-middle" id="propertyTable">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Owner</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($properties as $property): ?>
                            <?php
                            $status = ucfirst(trim($property['status'] ?? 'Pending'));
                            $statusClass = match (strtolower($status)) {
                                'approved' => 'bg-success',
                                'pending' => 'bg-warning text-dark',
                                'suspended' => 'bg-danger',
                                default => 'bg-secondary'
                            };
                            ?>
                            <tr>
                                <td>
                                    <a href="allProperty?property_id=<?= $property['property_id'] ?>">
                                        <?= e($property['property_id']) ?>
                                    </a>
                                </td>
                                <td><?= e($property['title'] ?? 'Untitled') ?></td>
                                <td><?= e($property['owner_name'] ?? 'N/A') ?></td>
                                <td><?= e($property['owner_email'] ?? 'N/A') ?></td>
                                <td><?= e($property['phone_number'] ?? 'N/A') ?></td>
                                <td><?= e($property['city'] ?? 'N/A') ?></td>
                                <td><span class="badge <?= $statusClass ?>"><?= $status ?></span></td>
                                <td><?= !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'Unknown' ?></td>
                            </tr>
                            <tr id="details-<?= $property['property_id'] ?>" class="property-details">
                                <td colspan="8">
                                    <h6><i class="fa fa-building"></i> <?= e($property['title']) ?></h6>
                                    <p><strong>Description:</strong> <?= e($property['description'] ?? 'N/A') ?></p>
                                    <p><strong>Location:</strong> <?= e($property['address'] . ', ' . $property['city'] . ', ' . $property['state']) ?></p>
                                    <div class="d-flex gap-2">
                                        <a href="../Shared/editProperty?property_id=<?= $property['property_id'] ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                                        <a href="../Shared/propertyUnitsDetail?property_id=<?= $property['property_id'] ?>" class="btn btn-sm btn-success">🔄 Update</a>
                                        <form method="POST" action="../Shared/deleteProperty" onsubmit="return confirm('Delete this property?');">
                                            <input type="hidden" name="property_id" value="<?= $property['property_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <!-- Pagination -->
            <?php if ($totalPages >= 1): ?>
                <nav aria-label="Property page navigation">
                    <ul class="pagination justify-content-start flex-wrap mt-4 ms-2">
                        <!-- First button -->
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=1">First</a>
                        </li>

                        <!-- Previous button -->
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $page - 1) ?>">Previous</a>
                        </li>

                        <!-- Page numbers (limit to 5 around current page) -->
                        <?php
                        $range = 2; // show 2 pages before & after current
                        $start = max(1, $page - $range);
                        $end   = min($totalPages, $page + $range);

                        if ($start > 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }

                        for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor;

                        if ($end < $totalPages) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <!-- Next button -->
                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>">Next</a>
                        </li>

                        <!-- Last button -->
                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $totalPages ?>">Last</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        <?php endif; ?>
    </main>

    <footer class="bg-dark text-light text-center py-3">
        &copy; <?= date('Y') ?> Accofinda. All rights reserved.
    </footer>

    <script>
        // Auto-search filter
        document.getElementById("searchInput").addEventListener("keyup", function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll("#propertyTable tbody tr");

            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(filter) ? "" : "none";
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>