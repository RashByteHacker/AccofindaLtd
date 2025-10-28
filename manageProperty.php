<?php
session_start();
require '../config.php';

// Check admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// ================= APPROVE / SUSPEND / UNSUSPEND =================
if (isset($_POST['action'], $_POST['property_id'])) {
    $propertyId = intval($_POST['property_id']);
    $adminId = $_SESSION['user_id'];

    // Determine new status based on action
    if ($_POST['action'] === 'approve') {
        $status = 'approved';
    } elseif ($_POST['action'] === 'suspend') {
        $status = 'suspended';
    } elseif ($_POST['action'] === 'unsuspend') {
        $status = 'approved';
    } else {
        $status = 'approved';
    }

    $stmt = $conn->prepare("
        UPDATE properties 
        SET status = ?, last_modified_by = ?, last_modified_at = NOW() 
        WHERE property_id = ?
    ");
    $stmt->bind_param("sii", $status, $adminId, $propertyId);
    $stmt->execute();
    $stmt->close();

    $messageText = $status === 'approved' ? "Property #$propertyId has been <strong>approved</strong>." : "Property #$propertyId has been <strong>suspended</strong>.";
    $type = $status === 'approved' ? 'success' : 'danger';

    // AJAX response
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode([
            'message' => $messageText,
            'type' => $type,
            'status' => $status
        ]);
        exit();
    }
}

// ================= GET PROPERTIES =================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = '';
if (!empty($search)) {
    $searchEscaped = $conn->real_escape_string($search);
    $where = "WHERE 
        p.property_id LIKE '%$searchEscaped%' OR
        p.landlord_email LIKE '%$searchEscaped%' OR
        u.full_name LIKE '%$searchEscaped%' OR
        u.phone_number LIKE '%$searchEscaped%' OR
        p.title LIKE '%$searchEscaped%' OR
        p.county LIKE '%$searchEscaped%' OR
        p.state LIKE '%$searchEscaped%' OR
        p.city LIKE '%$searchEscaped%' OR
        p.property_type LIKE '%$searchEscaped%' OR
        p.status LIKE '%$searchEscaped%'";
}

$limit = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$countResult = $conn->query("SELECT COUNT(*) as total FROM properties p LEFT JOIN users u ON p.landlord_email=u.email $where");
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

$query = "
    SELECT p.property_id, p.landlord_email, p.title, p.location, p.county, p.description, 
           p.address, p.city, p.state, p.postal_code, p.property_type, 
           p.availability_status, p.status, p.last_modified_by, p.last_modified_at, 
           p.created_at, p.amenities,
           u.phone_number, u.full_name AS landlord_name,
           admin.full_name AS modified_by_name, admin.email AS modified_by_email
    FROM properties p
    LEFT JOIN users u ON p.landlord_email=u.email
    LEFT JOIN users admin ON p.last_modified_by=admin.id
    $where
    ORDER BY p.created_at DESC
    LIMIT $limit OFFSET $offset
";
$properties = $conn->query($query);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Manage Properties</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #ffffffff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        header {
            background: #000000ff;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 22px;
        }

        header a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
        }

        .search-container {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .table-wrapper {
            margin-top: 15px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th,
        td {
            padding: 4px 6px;
            white-space: nowrap;
        }

        thead th {
            background: #000 !important;
            color: white !important;
            position: sticky;
            top: 0;
        }

        .actions button,
        .actions a {
            margin: 1px;
            padding: 3px 5px;
            font-size: 0.7rem;
            border-radius: 4px;
            text-decoration: none;
        }

        .status-approved {
            background-color: #d4edda;
            /* light green */
            color: #155724;
            /* dark green */
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            text-align: center;
        }

        .status-suspended {
            background-color: #f8d7da;
            /* light red */
            color: #721c24;
            /* dark red */
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            text-align: center;
        }

        footer {
            background: #000000ff;
            color: white;
            text-align: center;
            padding: 10px;
            margin-top: auto;
        }
    </style>
</head>

<body>
    <header class="d-flex align-items-center justify-content-between px-3" style="background: #343a40; color: white; padding: 10px 20px;">
        <!-- Back button on the left -->
        <button type="button" class="btn btn-light btn-sm d-flex align-items-center shadow-sm"
            style="font-weight: 500; border-radius: 6px; transition: transform 0.2s;"
            onclick="history.back()"
            onmouseover="this.style.transform='translateY(-2px)';"
            onmouseout="this.style.transform='translateY(0)';">
            <i class="fa fa-arrow-left me-2"></i> Back
        </button>

        <!-- Centered title -->
        <h1 class="m-0 position-absolute start-50 translate-middle-x" style="font-size: 22px;">Admin Dashboard</h1>

        <!-- Spacer on the right -->
        <div style="width: 75px;"></div>
    </header>
    <main class="container py-4">
        <div id="alert-container"></div>
        <h2>Manage Properties</h2>
        <div class="search-container mb-3">
            <input type="text" id="propertySearch" class="form-control"
                placeholder="Search properties...">
        </div>
        <div class="table-wrapper">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Landlord Email</th>
                        <th>Landlord Name</th>
                        <th>Phone</th>
                        <th>Title</th>
                        <th>Location</th>
                        <th>County</th>
                        <th>Status</th>
                        <th>Last Modified By</th>
                        <th>Last Modified At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($properties->num_rows > 0): ?>
                        <?php while ($row = $properties->fetch_assoc()): ?>
                            <tr>
                                <td><?= $row['property_id'] ?></td>
                                <td><?= htmlspecialchars($row['landlord_email']) ?></td>
                                <td><?= htmlspecialchars($row['landlord_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['phone_number'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($row['title']) ?></td>
                                <td><?= htmlspecialchars($row['location']) ?></td>
                                <td><?= htmlspecialchars($row['county']) ?></td>
                                <td class="status-<?= strtolower($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></td>
                                <td><?= $row['modified_by_name'] ? htmlspecialchars($row['modified_by_name']) : '—' ?></td>
                                <td><?= $row['last_modified_at'] ?: '—' ?></td>
                                <td class="actions">
                                    <button class="btn btn-success btn-sm"
                                        onclick="confirmUpdate(<?= $row['property_id'] ?>,'approve',this)">
                                        Approve
                                    </button>

                                    <button class="btn btn-warning btn-sm"
                                        onclick="confirmUpdate(<?= $row['property_id'] ?>,'suspend',this)">
                                        <?= ($row['status'] === 'suspended') ? 'Unsuspend' : 'Suspend' ?>
                                    </button>
                                    <a href="editProperty.php?property_id=<?= $row['property_id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                                    <a href="propertyUnitsDetail?id=<?= $row['property_id'] ?>" class="btn btn-info btn-sm">Update</a>
                                    <a href="?delete=<?= $row['property_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this property?');">Delete</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">No properties found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination mt-2">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="btn btn-light btn-sm <?= ($i == $page) ? 'active btn-dark' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </main>

    <footer>
        &copy; <?= date("Y") ?> Accofinda. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmUpdate(propertyId, action, btn) {
            // if button is Suspend → send suspend; if Unsuspend → send unsuspend
            if (action === 'suspend' || action === 'unsuspend' || action === 'approve') {
                const actionText = action.charAt(0).toUpperCase() + action.slice(1);
                if (confirm(`Are you sure you want to ${actionText} this property?`)) {
                    updateStatus(propertyId, action, btn);
                }
            }
        }

        function updateStatus(propertyId, action, btn) {
            const formData = new FormData();
            formData.append('property_id', propertyId);
            formData.append('action', action);

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '...';

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    // Show alert
                    const alertContainer = document.getElementById('alert-container');
                    alertContainer.innerHTML = `
        <div class="alert alert-${data.type} alert-dismissible fade show" role="alert">
          ${data.message}
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
                    setTimeout(() => {
                        document.querySelector('#alert-container .alert')?.remove();
                    }, 3000);

                    // Update status cell
                    const statusCell = btn.closest('tr').querySelector('td:nth-child(8)');
                    statusCell.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                    statusCell.className = 'status-' + data.status.toLowerCase();

                    // Toggle button text
                    if (action === 'suspend') {
                        btn.textContent = 'Unsuspend';
                        btn.setAttribute('onclick', `confirmUpdate(${propertyId},'unsuspend',this)`);
                    } else if (action === 'unsuspend') {
                        btn.textContent = 'Suspend';
                        btn.setAttribute('onclick', `confirmUpdate(${propertyId},'suspend',this)`);
                    } else {
                        btn.textContent = originalText;
                    }
                })
                .catch(err => {
                    console.error(err);
                    btn.textContent = originalText;
                })
                .finally(() => {
                    btn.disabled = false;
                });
        }
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.getElementById("propertySearch");
            const rows = document.querySelectorAll(".table tbody tr");

            searchInput.addEventListener("input", function() {
                let query = this.value.toLowerCase().trim();

                rows.forEach(row => {
                    let text = row.innerText.toLowerCase();
                    row.style.display = text.includes(query) ? "" : "none";
                });

                // Reset automatically if input is cleared
                if (query === "") {
                    rows.forEach(row => row.style.display = "");
                }
            });
        });
    </script>
</body>

</html>