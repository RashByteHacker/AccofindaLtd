<?php
session_start();
require '../config.php';

// ✅ Ensure landlord is logged in
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'landlord') {
    header("Location: ../login.php");
    exit();
}

$landlord_email = $_SESSION['email'];

// ✅ Handle status update
if (isset($_POST['complete_request_id'])) {
    $reqId = intval($_POST['complete_request_id']);
    $stmt = $conn->prepare("
        UPDATE maintenance_requests 
        SET status = 'Completed' 
        WHERE request_id = ? 
          AND property_id IN (
              SELECT property_id FROM properties WHERE landlord_email = ?
          )
    ");
    $stmt->bind_param("is", $reqId, $landlord_email);
    $stmt->execute();
    $stmt->close();

    $_SESSION['successMsg'] = "Request #$reqId has been marked as completed.";
    header("Location: maintenanceRequest.php"); // ✅ stay on same page
    exit();
}

// ✅ Fetch landlord’s maintenance requests
$requests = [];
$query = "
    SELECT mr.request_id, mr.unit_code, mr.tenant_email, mr.tenant_name, mr.tenant_phone, 
           mr.title, mr.description, mr.image, mr.request_date, mr.status, 
           p.title AS property_title
    FROM maintenance_requests mr
    INNER JOIN properties p ON mr.property_id = p.property_id
    WHERE p.landlord_email = ?
    ORDER BY mr.request_date DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $landlord_email);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Landlord Maintenance Requests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: #a8aaacff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            background: #000;
        }

        .navbar-brand,
        .nav-link {
            color: #fff !important;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .footer {
            margin-top: auto;
            background: #000;
            color: white;
            text-align: center;
            padding: 10px;
        }

        .request-table th {
            background: #000;
            color: #fff;
        }

        .status-pending {
            color: orange;
            font-weight: bold;
        }

        .status-completed {
            color: green;
            font-weight: bold;
        }

        .status-inprogress {
            color: blue;
            font-weight: bold;
        }

        .request-table th,
        .request-table td {
            vertical-align: middle;
            font-size: 0.85rem;
        }

        .btn-sm {
            padding: 2px 6px;
            font-size: 0.75rem;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <!-- ✅ Top Navbar -->
    <nav class="navbar navbar-expand-lg px-3">
        <a href="landlord.php" class="btn btn-light btn-sm me-3">&larr; Back</a>
        <a class="navbar-brand" href="#">Maintenance Requests</a>
    </nav>

    <div class="container my-4">
        <!-- ✅ Success Message -->
        <?php if (!empty($_SESSION['successMsg'])): ?>
            <div class="alert alert-success" id="successAlert"><?= $_SESSION['successMsg']; ?></div>
            <?php unset($_SESSION['successMsg']); ?>
        <?php endif; ?>

        <!-- ✅ Requests Table -->
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0" style="font-size:1rem;">Tenant Maintenance Requests</h4>
                <!-- 🔍 Search Input -->
                <input type="text" id="searchInput" class="form-control form-control-sm"
                    placeholder="Search requests..." style="max-width:250px; font-size:0.85rem;">
            </div>

            <!-- 🔄 Scrollable Table -->
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="table table-bordered request-table table-sm" style="font-size:0.85rem;">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th>Request ID</th>
                            <th>Property</th>
                            <th>Unit</th>
                            <th>Tenant Name</th>
                            <th>Tenant Email</th>
                            <th>Tenant Phone</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Image</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="requestsTable">
                        <?php if (!empty($requests)): ?>
                            <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td><?= $r['request_id'] ?></td>
                                    <td><?= htmlspecialchars($r['property_title']) ?></td>
                                    <td><?= htmlspecialchars($r['unit_code']) ?></td>
                                    <td><?= htmlspecialchars($r['tenant_name']) ?></td>
                                    <td><?= htmlspecialchars($r['tenant_email']) ?></td>
                                    <td><?= htmlspecialchars($r['tenant_phone']) ?></td>
                                    <td><?= htmlspecialchars($r['title']) ?></td>
                                    <td><?= htmlspecialchars($r['description']) ?></td>
                                    <td>
                                        <?php if ($r['image']): ?>
                                            <a href="<?= $r['image'] ?>" target="_blank" style="font-size:0.8rem;">View</a>
                                        <?php else: ?>
                                            <span style="font-size:0.8rem;">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date("M d, Y H:i", strtotime($r['request_date'])) ?></td>
                                    <td class="status-<?= strtolower($r['status']) ?>">
                                        <?= htmlspecialchars($r['status']) ?>
                                    </td>
                                    <td>
                                        <?php if (strtolower($r['status']) !== 'completed'): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="complete_request_id" value="<?= $r['request_id'] ?>">
                                                <button type="submit"
                                                    class="btn btn-success btn-sm px-2 py-1"
                                                    style="font-size:0.75rem;"
                                                    onclick="return confirm('Mark request #<?= $r['request_id'] ?> as completed?');">
                                                    ✔ Complete
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.75rem;">✔ Done</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center">No requests found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ✅ Footer -->
    <div class="footer">
        &copy; <?= date("Y") ?>All Rights Reserved Accofinda
    </div>

    <script>
        // Auto-hide success alert after 5s
        setTimeout(() => {
            let alert = document.getElementById("successAlert");
            if (alert) alert.style.display = "none";
        }, 5000);
    </script>

    <!-- 🔍 Search Script -->
    <script>
        document.getElementById("searchInput").addEventListener("keyup", function() {
            let value = this.value.toLowerCase();
            let rows = document.querySelectorAll("#requestsTable tr");
            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(value) ? "" : "none";
            });
        });
    </script>
</body>

</html>