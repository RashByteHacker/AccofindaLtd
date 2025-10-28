<?php
session_start();
require '../config.php';

// ✅ Restrict access to landlords only
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: login");
    exit();
}

$landlordEmail = $_SESSION['email'] ?? '';

// --- Helper function
function e($v)
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// --- Pagination setup
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- Search
$search = trim($_GET['search'] ?? '');
$searchSql = '';
$params = [$landlordEmail];
$types = "s";

if ($search !== '') {
    $searchSql = " AND (p.title LIKE ? OR p.city LIKE ? OR p.status LIKE ? OR u.full_name LIKE ? OR u.phone_number LIKE ?)";
    $searchParam = "%" . $search . "%";
    $params = [$landlordEmail, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    $types = "ssssss";
}

// --- Count total rows
$countSql = "SELECT COUNT(*) as total 
             FROM properties p
             JOIN users u ON p.landlord_email = u.email
             WHERE p.landlord_email = ? $searchSql";
$stmt = $conn->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalResult = $stmt->get_result()->fetch_assoc();
$totalRows = $totalResult['total'] ?? 0;
$totalPages = max(1, ceil($totalRows / $limit));
$stmt->close();

// --- Fetch paginated records
$sql = "SELECT p.property_id, p.title, p.city, p.status, p.created_at,
               u.full_name, u.email, u.phone_number
        FROM properties p
        JOIN users u ON p.landlord_email = u.email
        WHERE p.landlord_email = ? $searchSql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$properties = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Optimization: fetch all unit counts in ONE query
$unitCounts = [];
if (!empty($properties)) {
    $propertyIds = array_column($properties, 'property_id');
    $in = implode(',', array_fill(0, count($propertyIds), '?'));

    $sqlUnits = "SELECT pu.property_id, LOWER(TRIM(pud.status)) AS status, COUNT(*) as total
                 FROM property_units pu
                 LEFT JOIN property_unit_details pud 
                        ON pu.unit_id = pud.property_unit_id
                 WHERE pu.property_id IN ($in)
                 GROUP BY pu.property_id, pud.status";

    $stmt = $conn->prepare($sqlUnits);
    $types = str_repeat("i", count($propertyIds));
    $stmt->bind_param($types, ...$propertyIds);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $pid = $row['property_id'];
        $status = $row['status'] ?: 'unknown';
        $unitCounts[$pid][$status] = (int)$row['total'];
    }
    $stmt->close();
}

// --- If AJAX request: return only table body & pagination
if (isset($_GET['ajax'])) {
    ob_start();
    if (empty($properties)): ?>
        <tr>
            <td colspan="12" class="text-center text-muted">No Properties Found.</td>
        </tr>
        <?php else:
        foreach ($properties as $property):
            $pid = $property['property_id'];
            $vacant   = $unitCounts[$pid]['vacant']   ?? 0;
            $booked   = $unitCounts[$pid]['booked']   ?? 0;
            $occupied = $unitCounts[$pid]['occupied'] ?? 0;
            $total    = $vacant + $booked + $occupied;
        ?>
            <tr>
                <td>
                    <a href="myProperties?property_id=<?= e($property['property_id']) ?>"
                        class="fw-bold text-decoration-underline">
                        <?= e($property['property_id']) ?>
                    </a>
                </td>
                <td><?= e($property['title'] ?? 'Untitled') ?></td>
                <td><?= e($property['full_name'] ?? 'N/A') ?></td>
                <td><?= e($property['email'] ?? 'N/A') ?></td>
                <td><?= e($property['phone_number'] ?? 'N/A') ?></td>
                <td><?= e($property['city'] ?? 'N/A') ?></td>
                <td>
                    <span class="badge 
                        <?= match (strtolower($property['status'] ?? '')) {
                            'approved' => 'bg-success',
                            'pending' => 'bg-warning text-dark',
                            'suspended' => 'bg-danger',
                            default => 'bg-secondary'
                        } ?>">
                        <?= ucfirst($property['status'] ?? 'N/A') ?>
                    </span>
                </td>
                <td><span class="badge bg-info"><?= $vacant ?></span></td>
                <td><span class="badge bg-warning text-dark"><?= $booked ?></span></td>
                <td><span class="badge bg-success"><?= $occupied ?></span></td>
                <td><span class="badge bg-dark"><?= $total ?></span></td>
                <td><?= !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'N/A' ?></td>
            </tr>
    <?php endforeach;
    endif;
    $tbody = ob_get_clean();

    ob_start(); ?>
    <ul class="pagination pagination-sm justify-content-start">
        <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="?page=1&search=<?= e($search) ?>">First</a></li>
            <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= e($search) ?>">Prev</a></li>
        <?php endif; ?>
        <li class="page-item disabled">
            <span class="page-link small">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</span>
        </li>
        <?php if ($page < $totalPages): ?>
            <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= e($search) ?>">Next</a></li>
            <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&search=<?= e($search) ?>">Last</a></li>
        <?php endif; ?>
    </ul>
<?php
    $pagination = ob_get_clean();

    echo json_encode(['tbody' => $tbody, 'pagination' => $pagination]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Properties - Accofinda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        body {
            background-color: #f4f6f9;
        }

        .table-hover tbody tr:hover {
            background-color: #eef6ff;
        }

        .pagination .page-link {
            border-radius: 6px;
            margin: 0 2px;
            font-size: 0.8rem;
        }

        .search-box {
            max-width: 320px;
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-primary">📋 My Properties</h3>
            <input type="text" id="search" class="form-control search-box" placeholder="🔍Search...">
        </div>

        <div class="table-responsive shadow-sm">
            <table class="table table-striped table-hover bg-white align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Landlord</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>City</th>
                        <th>Status</th>
                        <th>Total Units</th>
                        <th>Vacant</th>
                        <th>Booked</th>
                        <th>Occupied</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if (empty($properties)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">No Properties Found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($properties as $property): ?>
                            <?php
                            $pid      = $property['property_id'];
                            $vacant   = $unitCounts[$pid]['vacant']   ?? 0;
                            $booked   = $unitCounts[$pid]['booked']   ?? 0;
                            $occupied = $unitCounts[$pid]['occupied'] ?? 0;
                            $total    = $vacant + $booked + $occupied;
                            ?>
                            <tr>
                                <td>
                                    <a href="myProperties?property_id=<?= e($property['property_id']) ?>"
                                        class="fw-bold text-decoration-underline">
                                        <?= e($property['property_id']) ?>
                                    </a>
                                </td>
                                <td><?= e($property['title'] ?? 'Untitled') ?></td>
                                <td><?= e($property['full_name'] ?? 'N/A') ?></td>
                                <td><?= e($property['email'] ?? 'N/A') ?></td>
                                <td><?= e($property['phone_number'] ?? 'N/A') ?></td>
                                <td><?= e($property['city'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge 
                                <?= match (strtolower($property['status'] ?? '')) {
                                    'approved' => 'bg-success',
                                    'pending' => 'bg-warning text-dark',
                                    'suspended' => 'bg-danger',
                                    default => 'bg-secondary'
                                } ?>">
                                        <?= ucfirst($property['status'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td><span class="badge bg-dark"><?= $total ?></span></td>
                                <td><span class="badge bg-info"><?= $vacant ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?= $booked ?></span></td>
                                <td><span class="badge bg-success"><?= $occupied ?></span></td>
                                <td><?= !empty($property['created_at']) ? date("M d, Y", strtotime($property['created_at'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <nav aria-label="Page navigation" class="mt-3">
            <div id="pagination-wrapper">
                <ul class="pagination pagination-sm justify-content-start">
                    <?php if ($page >= 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=1&search=<?= e($search) ?>">First</a></li>
                        <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= e($search) ?>">Prev</a></li>
                    <?php endif; ?>
                    <li class="page-item disabled">
                        <span class="page-link small">Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> records)</span>
                    </li>
                    <?php if ($page <= $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= e($search) ?>">Next</a></li>
                        <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&search=<?= e($search) ?>">Last</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
    </div>

    <script>
        $(function() {
            function loadData(page = 1, search = '') {
                $.get('propertyView', {
                    page: page,
                    search: search,
                    ajax: 1
                }, function(res) {
                    let data = JSON.parse(res);
                    $("#table-body").html(data.tbody);
                    $("#pagination-wrapper").html(data.pagination);

                    // bind pagination again (since it gets replaced)
                    $("#pagination-wrapper a").click(function(e) {
                        e.preventDefault();
                        const url = new URL(this.href);
                        loadData(url.searchParams.get("page"), $("#search").val());
                    });
                });
            }

            // Auto search
            $("#search").on("keyup", function() {
                loadData(1, $(this).val());
            });

            // Pagination click handler (initial)
            $("#pagination-wrapper a").click(function(e) {
                e.preventDefault();
                const url = new URL(this.href);
                loadData(url.searchParams.get("page"), $("#search").val());
            });
        });
    </script>
</body>

</html>